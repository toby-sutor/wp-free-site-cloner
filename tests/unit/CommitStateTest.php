<?php
/**
 * The commit point of the import switch: FSC_Recovery::commit_state() and
 * the paths that depend on it (clean-up, cancel, replace, failed steps, the
 * switch step itself).
 *
 * @package wp-free-site-cloner
 */

/**
 * Stand-in for wpdb without a mysqli handle: FSC_DB then goes through
 * query() and get_results(). Knows table names, a process list and how a
 * RENAME TABLE ends.
 */
class FSC_Test_Wpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var null */
	public $dbh = null;

	/** @var string */
	public $last_error = '';

	/** @var string[] Table names. */
	public $tables = array();

	/** @var array[] Rows of SHOW FULL PROCESSLIST (Id, User, Host, db, Command, Time, State, Info). */
	public $processlist = array();

	/** @var bool False: no statement works and the connection cannot be re-established. */
	public $connected = true;

	/** @var string How a RENAME TABLE ends: ok, lost (runs, the client sees an error), queued (keeps waiting, the client sees an error), refused. */
	public $rename = 'ok';

	/** @var string[] Statements seen. */
	public $queries = array();

	public function suppress_errors( $suppress = true ) {
		return false;
	}

	public function check_connection( $allow_bail = true ) {
		return $this->connected;
	}

	/**
	 * Statements seen that start with $start.
	 *
	 * @param string $start Start of the statement.
	 * @return string[]
	 */
	public function seen( $start ) {
		return array_values(
			array_filter(
				$this->queries,
				function ( $q ) use ( $start ) {
					return 0 === strpos( $q, $start );
				}
			)
		);
	}

	/**
	 * Apply the pairs of a RENAME TABLE statement.
	 *
	 * @param string $sql Statement.
	 */
	public function apply_rename( $sql ) {
		preg_match_all( '/`([^`]+)` TO `([^`]+)`/', $sql, $m, PREG_SET_ORDER );
		foreach ( $m as $pair ) {
			$this->tables   = array_values( array_diff( $this->tables, array( $pair[1] ) ) );
			$this->tables[] = $pair[2];
		}
	}

	public function query( $sql ) {
		$this->queries[]  = $sql;
		$this->last_error = '';
		if ( ! $this->connected ) {
			$this->last_error = 'MySQL server has gone away';
			return false;
		}
		if ( preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`$/', $sql, $m ) ) {
			$this->tables = array_values( array_diff( $this->tables, array( $m[1] ) ) );
			return true;
		}
		if ( 0 === strpos( $sql, 'RENAME TABLE' ) ) {
			if ( 'ok' === $this->rename || 'lost' === $this->rename ) {
				$this->apply_rename( $sql );
			}
			if ( 'queued' === $this->rename ) {
				$this->processlist[] = array( '7', 'wp', 'localhost', 'wordpress', 'Query', '3', 'Waiting for table metadata lock', $sql );
			}
			if ( 'ok' !== $this->rename ) {
				$this->last_error = 'refused' === $this->rename ? 'Table is read only' : 'Lost connection to server during query';
				return false;
			}
		}
		return true;
	}

	public function get_results( $sql, $output = null ) {
		$this->queries[]  = $sql;
		$this->last_error = '';
		if ( ! $this->connected ) {
			$this->last_error = 'MySQL server has gone away';
			return null;
		}
		if ( 'SELECT DATABASE()' === $sql ) {
			return array( array( 'DATABASE()' => 'wordpress' ) );
		}
		if ( 'SHOW FULL PROCESSLIST' === $sql ) {
			return $this->processlist;
		}
		if ( preg_match( "/^SHOW (FULL )?TABLES LIKE '(.*)'$/", $sql, $m ) ) {
			// The pattern as the server reads it: "\\" is one backslash, "\_" a literal underscore.
			$like = str_replace( '\\\\', '\\', $m[2] );
			$re   = '';
			for ( $i = 0, $n = strlen( $like ); $i < $n; $i++ ) {
				$c = $like[ $i ];
				if ( '\\' === $c && $i + 1 < $n ) {
					$re .= preg_quote( $like[ ++$i ], '/' );
				} elseif ( '%' === $c ) {
					$re .= '.*';
				} elseif ( '_' === $c ) {
					$re .= '.';
				} else {
					$re .= preg_quote( $c, '/' );
				}
			}
			$out = array();
			foreach ( $this->tables as $t ) {
				if ( preg_match( '/^' . $re . '$/', $t ) ) {
					$out[] = '' === $m[1] ? array( 'Tables_in_wordpress' => $t ) : array(
						'Tables_in_wordpress' => $t,
						'Table_type'          => 'BASE TABLE',
					);
				}
			}
			return $out;
		}
		return array();
	}
}

/**
 * Every test runs in its own process: the importer and the plugin class
 * read the wp-content, WordPress and storage paths from constants.
 *
 * @covers FSC_Recovery
 * @covers FSC_Importer
 * @covers FSC_DB_Import
 * @covers FSC_Plugin
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CommitStateTest extends FSC_Test_Case {

	const ID = '0123456789abcdef';

	/** @var FSC_Storage */
	private $st;

	/** @var FSC_Test_Wpdb */
	private $db;

	/** @var array Live wp-content before the switch (rel => content). */
	private $before = array();

	/** @var array Live wp-content after all files were moved. */
	private $after = array();

	protected function setUp(): void {
		parent::setUp();
		define( 'WP_CONTENT_DIR', $this->dir . '/wp-content' );
		define( 'ABSPATH', $this->dir . '/abs/' );
		define( 'FSC_STORAGE_DIR', $this->dir . '/base' );
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		mkdir( ABSPATH, 0755, true );
		$this->st = FSC_Storage::default_storage();
		$this->st->ensure();
		$this->db        = new FSC_Test_Wpdb();
		$GLOBALS['wpdb'] = $this->db;
	}

	/**
	 * Write files below a root.
	 *
	 * @param string $root  Root.
	 * @param array  $files rel => content.
	 */
	private static function put( $root, array $files ) {
		foreach ( $files as $rel => $content ) {
			$p = $root . '/' . $rel;
			if ( ! is_dir( dirname( $p ) ) ) {
				mkdir( dirname( $p ), 0755, true );
			}
			file_put_contents( $p, $content );
		}
	}

	/**
	 * rel => content of every file below a root.
	 *
	 * @param string $root Root.
	 * @return array
	 */
	private static function tree( $root ) {
		$out = array();
		if ( ! is_dir( $root ) ) {
			return $out;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() ) {
				$out[ substr( $f->getPathname(), strlen( $root ) + 1 ) ] = file_get_contents( $f->getPathname() );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * An import in the switch: every file is moved, the live tables and the
	 * temp tables exist, maintenance mode is on. The RENAME TABLE has not
	 * been sent unless $data says so.
	 *
	 * @param array $data Extra job data.
	 * @return FSC_Job
	 */
	private function switching_job( array $data = array() ) {
		$stage = FSC_Importer::stage_dir( $this->st, self::ID );
		self::put(
			WP_CONTENT_DIR,
			array(
				'plugins/a/a.php'       => 'old a',
				'plugins/keep/keep.php' => 'not in archive',
				'themes/t/style.css'    => 'old theme',
			)
		);
		self::put(
			$stage,
			array(
				'plugins/a/a.php'    => 'new a',
				'plugins/b/b.php'    => 'new b',
				'themes/t/style.css' => 'new theme',
			)
		);
		$this->before = self::tree( WP_CONTENT_DIR );
		$units        = FSC_Extractor::plan_units( $stage );
		file_put_contents( $this->st->tmp_file( self::ID, 'moves' ), json_encode( $units ) );
		mkdir( FSC_Importer::old_dir( $this->st, self::ID ), 0700, true );
		$job = FSC_Job::create(
			$this->st->job_path(),
			'import',
			array_merge(
				array(
					'id'      => self::ID,
					'phase'   => 'swap',
					'swapped' => false,
					'meta'    => array(
						'format'  => 'fsc',
						'home'    => 'http://old.example',
						'siteurl' => 'http://old.example',
						'prefix'  => 'wp_',
					),
					'cursor'  => array(
						'swap' => array(
							'stage' => 'move',
							'units' => count( $units ),
							'moved' => 0,
						),
					),
				),
				$data
			)
		);
		$copied = false;
		$this->assertTrue( FSC_Extractor::switch_step( FSC_Importer::switch_paths( $this->st, self::ID ), INF, false, $copied ) );
		$this->after = self::tree( WP_CONTENT_DIR );
		$this->assertNotSame( $this->before, $this->after );
		FSC_Importer::maintenance_on( $job );
		FSC_Recovery::flag( $this->st, true );
		$this->db->tables = array( 'wp_options', 'wp_posts', 'fsctmp_options', 'fsctmp_posts' );
		return $job;
	}

	/** The server ran the RENAME TABLE of the switch. */
	private function tables_swapped() {
		$this->db->tables = array( 'wp_options', 'wp_posts', 'fscold_options', 'fscold_posts' );
	}

	/** The job as it is in the job file. */
	private function saved() {
		return FSC_Job::load( $this->st->job_path() )->data;
	}

	/** The whole job log. */
	private function log_text() {
		return (string) @file_get_contents( $this->st->job_path() . '.log' );
	}

	/** Nothing of the switch was moved, removed or dropped. */
	private function assertSwitchUntouched() {
		$this->assertSame( $this->after, self::tree( WP_CONTENT_DIR ), 'no file is moved back' );
		$this->assertTrue( FSC_Extractor::has_files( FSC_Importer::old_dir( $this->st, self::ID ) ), 'the previous files are kept' );
		$this->assertFileDoesNotExist( FSC_Importer::old_dir( $this->st, self::ID ) . '/RESTORE-FAILED.txt' );
		$this->assertFileExists( $this->st->tmp_file( self::ID, 'moves' ) );
		$this->assertFileExists( $this->st->tmp_file( self::ID, 'swapj' ) );
		$this->assertFileExists( FSC_Recovery::flag_path( $this->st ) );
		$this->assertSame( array(), $this->db->seen( 'DROP TABLE' ), 'no table is dropped' );
	}

	/** The switch was rolled back and its leftovers removed. */
	private function assertRolledBack() {
		$this->assertSame( $this->before, self::tree( WP_CONTENT_DIR ) );
		$this->assertSame( array( 'wp_options', 'wp_posts' ), $this->db->tables );
		$this->assertDirectoryDoesNotExist( FSC_Importer::stage_dir( $this->st, self::ID ) );
		$this->assertDirectoryDoesNotExist( FSC_Importer::old_dir( $this->st, self::ID ) );
		$this->assertFileDoesNotExist( ABSPATH . '.maintenance' );
	}

	/* ---- FSC_Recovery::commit_state() ---- */

	public function test_job_state_decides_without_the_database() {
		$never = function () {
			throw new LogicException( 'The database must not be asked.' );
		};
		$job   = FSC_Job::create( $this->st->job_path(), 'import', array( 'phase' => 'finalize', 'swapping' => true, 'swapped' => true ) );
		$this->assertSame( FSC_Recovery::COMMITTED, FSC_Recovery::commit_state( $job, $never ) );
		$job = FSC_Job::create( $this->st->job_path(), 'import', array( 'phase' => 'swap' ) );
		$this->assertSame( FSC_Recovery::NOT_COMMITTED, FSC_Recovery::commit_state( $job, $never ) );
		// A rollback was decided and carried out earlier: its dropped temp tables prove nothing.
		foreach ( array( 'cleaned' => true, 'recovered' => 'rollback' ) as $k => $v ) {
			$job = FSC_Job::create( $this->st->job_path(), 'import', array( 'phase' => 'swap', 'status' => 'error', 'swapping' => true, $k => $v ) );
			$this->assertSame( FSC_Recovery::NOT_COMMITTED, FSC_Recovery::commit_state( $job, $never ), $k );
		}
	}

	public function test_temp_tables_gone_means_committed() {
		$job  = FSC_Job::create( $this->st->job_path(), 'import', array( 'phase' => 'swap', 'swapping' => true ) );
		$gone = function () {
			return false;
		};
		$this->assertSame( FSC_Recovery::COMMITTED, FSC_Recovery::commit_state( $job, $gone ) );
		$d = $this->saved();
		$this->assertTrue( $d['swapped'] );
		$this->assertSame( 'finalize', $d['phase'] );
		$this->assertSame( 'running', $d['status'] );
		$this->assertStringContainsString( 'table swap had completed', $this->log_text() );
	}

	public function test_committed_job_that_failed_meanwhile_runs_again() {
		$job = FSC_Job::create( $this->st->job_path(), 'import', array( 'phase' => 'swap', 'swapping' => true, 'crash_count' => 3, 'crash_sig' => 'x' ) );
		$job->fail( 'The server ended the request 3 times in a row at the same point.' );
		$job->save();
		$this->assertSame(
			FSC_Recovery::COMMITTED,
			FSC_Recovery::commit_state(
				$job,
				function () {
					return false;
				}
			)
		);
		$d = $this->saved();
		$this->assertSame( 'running', $d['status'] );
		$this->assertNull( $d['error'] );
		$this->assertSame( 'finalize', $d['phase'] );
		$this->assertTrue( $d['swapped'] );
		$this->assertArrayNotHasKey( 'finished_at', $d );
		$this->assertArrayNotHasKey( 'crash_count', $d );
	}

	public function test_temp_tables_present_means_not_committed_for_good() {
		$job   = FSC_Job::create( $this->st->job_path(), 'import', array( 'phase' => 'swap', 'swapping' => true ) );
		$asked = 0;
		$db    = function () use ( &$asked ) {
			++$asked;
			// The second answer would be "gone": the rollback has dropped the temp tables by then.
			return 1 === $asked;
		};
		$this->assertSame( FSC_Recovery::NOT_COMMITTED, FSC_Recovery::commit_state( $job, $db ) );
		$this->assertFalse( $this->saved()['swapping'] );
		$this->assertSame( FSC_Recovery::NOT_COMMITTED, FSC_Recovery::commit_state( FSC_Job::load( $this->st->job_path() ), $db ) );
		$this->assertSame( 1, $asked );
		$this->assertArrayNotHasKey( 'swapped', $this->saved() );
	}

	public function test_no_answer_means_unknown() {
		$job    = FSC_Job::create( $this->st->job_path(), 'import', array( 'phase' => 'swap', 'swapping' => true ) );
		$before = $this->saved();
		$this->assertSame( FSC_Recovery::UNKNOWN, FSC_Recovery::commit_state( $job, null ) );
		$this->assertSame(
			FSC_Recovery::UNKNOWN,
			FSC_Recovery::commit_state(
				$job,
				function () {
					return null;
				}
			)
		);
		$this->assertSame(
			FSC_Recovery::UNKNOWN,
			FSC_Recovery::commit_state(
				$job,
				function () {
					throw new RuntimeException( 'connection refused' );
				}
			)
		);
		$this->assertSame( $before, $this->saved() );
		$this->assertSame( $before, $job->data );
	}

	public function test_not_committed_that_cannot_be_saved_is_unknown() {
		$job = FSC_Job::create( $this->st->job_path(), 'import', array( 'phase' => 'swap', 'swapping' => true ) );
		// The job file cannot be replaced (stands for a full disk).
		unlink( $this->st->job_path() );
		mkdir( $this->st->job_path() );
		touch( $this->st->job_path() . '/x' );
		$this->assertSame(
			FSC_Recovery::UNKNOWN,
			FSC_Recovery::commit_state(
				$job,
				function () {
					return true;
				}
			)
		);
		$this->assertTrue( $job->data['swapping'] );
	}

	/* ---- FSC_Recovery::temp_state() ---- */

	/**
	 * A statement runner with canned answers.
	 *
	 * @param array|null $tables Rows of SHOW TABLES.
	 * @param array|null $list   Rows of SHOW FULL PROCESSLIST.
	 * @param array      $seen   Statements seen.
	 * @return callable
	 */
	private static function runner( $tables, $list, &$seen = array() ) {
		return function ( $sql ) use ( $tables, $list, &$seen ) {
			$seen[] = $sql;
			if ( 'SELECT DATABASE()' === $sql ) {
				return array( array( 'wordpress' ) );
			}
			return 'SHOW FULL PROCESSLIST' === $sql ? $list : $tables;
		};
	}

	public function test_temp_state_reads_the_temp_options_table() {
		$seen = array();
		$this->assertTrue( FSC_Recovery::temp_state( self::runner( array( array( 'fsctmp_options' ) ), array(), $seen ) ) );
		$this->assertSame( "SHOW TABLES LIKE 'fsctmp\\_options'", end( $seen ) );
		$this->assertFalse( FSC_Recovery::temp_state( self::runner( array(), array() ) ) );
		$this->assertNull( FSC_Recovery::temp_state( self::runner( null, array() ) ) );
		// Without a readable process list the table alone decides.
		$this->assertTrue( FSC_Recovery::temp_state( self::runner( array( array( 'fsctmp_options' ) ), null ) ) );
		$this->assertFalse( FSC_Recovery::temp_state( self::runner( array(), null ) ) );
	}

	public function test_temp_state_cannot_tell_while_a_rename_is_still_queued() {
		$tables = array( array( 'fsctmp_options' ) );
		$row    = function ( $db, $info ) {
			return array( '12', 'wp', 'localhost', $db, 'Query', '40', 'Waiting for table metadata lock', $info );
		};
		$rename = 'RENAME TABLE `wp_options` TO `fscold_options`, `fsctmp_options` TO `wp_options`';
		$this->assertNull( FSC_Recovery::temp_state( self::runner( $tables, array( $row( 'wordpress', $rename ) ) ) ) );
		$this->assertNull( FSC_Recovery::temp_state( self::runner( array(), array( $row( 'wordpress', '  rename table `fsctmp_options` TO `wp_options`' ) ) ) ) );
		// Not the switch of this site.
		$this->assertTrue( FSC_Recovery::temp_state( self::runner( $tables, array( $row( 'otherdb', $rename ) ) ) ) );
		$this->assertTrue( FSC_Recovery::temp_state( self::runner( $tables, array( $row( 'wordpress', 'RENAME TABLE `a` TO `b`' ) ) ) ) );
		$this->assertTrue( FSC_Recovery::temp_state( self::runner( $tables, array( $row( 'wordpress', 'SHOW FULL PROCESSLIST' ), $row( 'wordpress', null ) ) ) ) );
	}

	/* ---- FSC_Importer::cleanup() ---- */

	public function test_cleanup_keeps_a_switch_the_database_completed() {
		$job = $this->switching_job( array( 'swapping' => true ) );
		$this->tables_swapped();
		$this->assertFalse( FSC_Importer::cleanup( $job, $this->st ) );
		$this->assertSwitchUntouched();
		$this->assertSame( array( 'plugins/a/a.php' => 'new a' ), array_intersect_key( self::tree( WP_CONTENT_DIR ), array( 'plugins/a/a.php' => 1 ) ) );
		$d = $this->saved();
		$this->assertTrue( $d['swapped'] );
		$this->assertSame( 'finalize', $d['phase'] );
		$this->assertStringNotContainsString( 'Moved the previous files back', $this->log_text() );
	}

	public function test_cleanup_rolls_back_when_the_temp_tables_are_still_there() {
		$job = $this->switching_job( array( 'swapping' => true ) );
		$this->assertTrue( FSC_Importer::cleanup( $job, $this->st ) );
		$this->assertRolledBack();
		$this->assertFalse( $this->saved()['swapping'] );
		$this->assertStringContainsString( 'Moved the previous files back', $this->log_text() );
		$this->assertFileDoesNotExist( FSC_Recovery::flag_path( $this->st ) );
	}

	public function test_cleanup_touches_nothing_while_the_database_cannot_tell() {
		$job    = $this->switching_job( array( 'swapping' => true ) );
		$before = $this->saved();
		// No connection.
		$this->db->connected = false;
		$this->assertFalse( FSC_Importer::cleanup( $job, $this->st ) );
		$this->assertSwitchUntouched();
		$this->assertFileExists( ABSPATH . '.maintenance' );
		// The RENAME of a killed request still waits in the server.
		$this->db->connected   = true;
		$this->db->processlist = array( array( '7', 'wp', 'localhost', 'wordpress', 'Query', '9', 'Waiting for table metadata lock', 'RENAME TABLE `wp_options` TO `fscold_options`, `fsctmp_options` TO `wp_options`' ) );
		$this->assertFalse( FSC_Importer::cleanup( $job, $this->st ) );
		$this->assertSwitchUntouched();
		$this->assertFileExists( ABSPATH . '.maintenance' );
		$this->assertSame( $before, $this->saved() );
	}

	public function test_cleanup_before_the_rename_does_not_ask() {
		$job = $this->switching_job();
		$this->assertTrue( FSC_Importer::cleanup( $job, $this->st ) );
		$this->assertRolledBack();
		$this->assertSame( array(), $this->db->seen( 'SHOW FULL PROCESSLIST' ) );
		$this->assertSame( array(), $this->db->seen( 'SHOW TABLES' ) );
	}

	/* ---- FSC_Plugin::cancel(), clear_previous() ---- */

	/**
	 * Call $fn and return the FSC_Exception it throws.
	 *
	 * @param callable $fn Call.
	 * @return FSC_Exception
	 */
	private function refused( $fn ) {
		try {
			$fn();
		} catch ( FSC_Exception $e ) {
			return $e;
		}
		$this->fail( 'An FSC_Exception was expected.' );
	}

	public function test_cancel_is_refused_while_the_commit_state_is_unknown() {
		$this->switching_job( array( 'swapping' => true ) );
		$before              = $this->saved();
		$this->db->connected = false;
		$plugin              = FSC_Plugin::instance();
		$e                   = $this->refused( array( $plugin, 'cancel' ) );
		$this->assertSame( 423, $e->getCode() );
		$this->assertStringContainsString( 'cannot be cancelled right now', $e->getMessage() );
		$this->assertSwitchUntouched();
		$this->assertSame( $before, $this->saved() );
		$this->assertTrue( FSC_Job::load( $this->st->job_path() )->lock( 0 ), 'the lock was released' );
	}

	public function test_cancel_is_refused_when_the_database_completed_the_switch() {
		$this->switching_job( array( 'swapping' => true ) );
		$this->tables_swapped();
		$e = $this->refused( array( FSC_Plugin::instance(), 'cancel' ) );
		$this->assertSame( 409, $e->getCode() );
		$this->assertStringContainsString( 'already active', $e->getMessage() );
		$this->assertSwitchUntouched();
		$d = $this->saved();
		$this->assertTrue( $d['swapped'] );
		$this->assertSame( 'finalize', $d['phase'] );
		$this->assertSame( 'running', $d['status'] );
	}

	public function test_cancel_rolls_back_when_the_temp_tables_are_still_there() {
		$this->switching_job( array( 'swapping' => true ) );
		$this->assertTrue( FSC_Plugin::instance()->cancel() );
		$this->assertRolledBack();
		$this->assertNull( FSC_Job::load( $this->st->job_path() ) );
	}

	public function test_replace_is_refused_while_unknown_or_committed() {
		$this->switching_job( array( 'swapping' => true ) );
		$this->db->connected = false;
		$plugin              = FSC_Plugin::instance();
		$replace             = function () use ( $plugin ) {
			$plugin->clear_previous( true );
		};
		$e                   = $this->refused( $replace );
		$this->assertSame( 423, $e->getCode() );
		$this->assertStringContainsString( 'cannot be replaced right now', $e->getMessage() );
		$this->assertSwitchUntouched();
		$this->assertNotNull( FSC_Job::load( $this->st->job_path() ) );

		$this->db->connected = true;
		$this->tables_swapped();
		$e = $this->refused( $replace );
		$this->assertSame( 409, $e->getCode() );
		$this->assertSwitchUntouched();
		$this->assertTrue( $this->saved()['swapped'] );
	}

	/* ---- FSC_Plugin::run_step(): a job that fails with the RENAME sent ---- */

	/**
	 * A switching job whose requests were killed after the RENAME was sent,
	 * one kill before the crash-loop limit.
	 *
	 * @return FSC_Job
	 */
	private function crash_looping_job() {
		$job                      = $this->switching_job( array( 'swapping' => true, 'in_step' => true ) );
		$job->data['crash_count'] = FSC_Job::MAX_CRASHES - 1;
		$job->data['crash_sig']   = $job->progress_signature();
		$job->save();
		return $job;
	}

	public function test_crash_loop_after_a_completed_rename_goes_on_to_finalize() {
		$job = $this->crash_looping_job();
		$this->tables_swapped();
		$out = FSC_Plugin::instance()->run_step( $job );
		$this->assertSame( 'running', $out['status'] );
		$this->assertTrue( $out['swapped'] );
		$this->assertSwitchUntouched();
		$d = $this->saved();
		$this->assertSame( 'finalize', $d['phase'] );
		$this->assertNull( $d['error'] );
		$this->assertArrayNotHasKey( 'cleaned', $d );
		$this->assertStringNotContainsString( 'The site was not changed', $this->log_text() );
	}

	public function test_crash_loop_with_the_temp_tables_still_there_is_rolled_back() {
		$out = FSC_Plugin::instance()->run_step( $this->crash_looping_job() );
		$this->assertSame( 'error', $out['status'] );
		$this->assertRolledBack();
		$this->assertTrue( $this->saved()['cleaned'] );
		$this->assertStringContainsString( 'The site was not changed', $this->log_text() );
	}

	public function test_crash_loop_with_an_unknown_commit_state_keeps_everything() {
		$job                 = $this->crash_looping_job();
		$this->db->connected = false;
		$out                 = FSC_Plugin::instance()->run_step( $job );
		$this->assertSame( 'error', $out['status'] );
		$this->assertSwitchUntouched();
		$this->assertFileExists( ABSPATH . '.maintenance' );
		$d = $this->saved();
		$this->assertArrayNotHasKey( 'cleaned', $d );
		$this->assertTrue( $d['swapping'] );
		$this->assertStringNotContainsString( 'The site was not changed', $this->log_text() );
		$this->assertStringContainsString( 'did not confirm', $this->log_text() );

		// The automatic recovery decides once the database answers.
		$this->db->connected = true;
		$this->tables_swapped();
		$r = FSC_Recovery::recover(
			$this->st,
			WP_CONTENT_DIR,
			array(
				'stale'       => 300,
				'deadline'    => INF,
				'maintenance' => ABSPATH . '.maintenance',
				'db'          => array( 'FSC_DB_Import', 'temp_exists' ),
			)
		);
		$this->assertSame( FSC_Recovery::DONE, $r );
		$this->assertSame( $this->after, self::tree( WP_CONTENT_DIR ) );
		$d = $this->saved();
		$this->assertSame( 'running', $d['status'] );
		$this->assertSame( 'finalize', $d['phase'] );
		$this->assertTrue( $d['swapped'] );
		$this->assertFileDoesNotExist( ABSPATH . '.maintenance' );
	}

	/* ---- The switch step itself ---- */

	/**
	 * One unit of the import.
	 *
	 * @param FSC_Job $job   Job.
	 * @param array   $state Unit state.
	 * @return bool
	 */
	private function unit( FSC_Job $job, &$state = array() ) {
		$state = array();
		return FSC_Importer::unit( $job, $this->st, microtime( true ) + 20, $state );
	}

	public function test_rename_that_ran_but_reported_a_lost_connection_is_committed() {
		$job              = $this->switching_job();
		$this->db->rename = 'lost';
		$this->assertFalse( $this->unit( $job ) );
		$this->assertTrue( $job->data['swapped'] );
		$this->assertSame( 'finalize', $job->data['phase'] );
		$this->assertSame( 'move', $job->data['cursor']['swap']['stage'], 'no rollback was started' );
		$this->assertSame( $this->after, self::tree( WP_CONTENT_DIR ) );
		$this->assertFileDoesNotExist( ABSPATH . '.maintenance' );
		$this->assertCount( 1, $this->db->seen( 'RENAME TABLE' ) );
		$this->assertStringContainsString( 'Lost connection', $this->log_text() );
		$this->assertStringNotContainsString( 'Moving the previous files back', $this->log_text() );
	}

	public function test_refused_rename_is_rolled_back() {
		$job              = $this->switching_job();
		$this->db->rename = 'refused';
		$this->assertFalse( $this->unit( $job ) );
		$this->assertSame( 'rollback', $job->data['cursor']['swap']['stage'] );
		$this->assertFalse( $job->data['swapping'] );
		$this->assertEmpty( $job->data['swapped'] );
		try {
			$this->unit( $job );
			$this->fail( 'The rolled back switch must fail the job.' );
		} catch ( FSC_Exception $e ) {
			$this->assertStringContainsString( 'the site was not changed', $e->getMessage() );
		}
		$this->assertSame( $this->before, self::tree( WP_CONTENT_DIR ) );
	}

	public function test_rename_still_queued_after_a_lost_connection_is_waited_for() {
		$job              = $this->switching_job();
		$this->db->rename = 'queued';
		$state            = array();
		$this->assertFalse( $this->unit( $job, $state ) );
		$this->assertNotEmpty( $state['yield'], 'the request ends' );
		$this->assertTrue( $job->data['swapping'] );
		$this->assertEmpty( $job->data['swapped'] );
		$this->assertSame( 'move', $job->data['cursor']['swap']['stage'] );
		$this->assertSwitchUntouched();
		$this->assertFileExists( ABSPATH . '.maintenance' );
		// The next step asks again and does not send a second RENAME.
		$this->assertFalse( $this->unit( $job, $state ) );
		$this->assertNotEmpty( $state['yield'] );
		$this->assertCount( 1, $this->db->seen( 'RENAME TABLE' ) );
		$this->assertSame( 1, substr_count( $this->log_text(), 'cannot tell yet' ), 'logged once' );
		$this->assertSwitchUntouched();
		// The server runs the queued statement: the next step finds the switch complete.
		$this->db->apply_rename( $this->db->processlist[0][7] );
		$this->db->processlist = array();
		$this->assertFalse( $this->unit( $job, $state ) );
		$this->assertTrue( $job->data['swapped'] );
		$this->assertSame( 'finalize', $job->data['phase'] );
		$this->assertSame( $this->after, self::tree( WP_CONTENT_DIR ) );
		$this->assertCount( 1, $this->db->seen( 'RENAME TABLE' ) );
		$this->assertArrayNotHasKey( 'commit_unknown', $job->data );
	}

	public function test_queued_rename_that_gave_up_is_sent_again() {
		$job              = $this->switching_job();
		$this->db->rename = 'queued';
		$this->assertFalse( $this->unit( $job ) );
		// The statement ended without running (its lock wait timed out).
		$this->db->processlist = array();
		$this->db->rename      = 'ok';
		$this->assertFalse( $this->unit( $job ) );
		$this->assertTrue( $job->data['swapped'] );
		$this->assertSame( 'finalize', $job->data['phase'] );
		$this->assertCount( 2, $this->db->seen( 'RENAME TABLE' ) );
		$this->assertSame( array( 'wp_options', 'wp_posts' ), array_values( array_filter( $this->db->tables, function ( $t ) {
			return 0 === strpos( $t, 'wp_' );
		} ) ) );
		$this->assertSame( array( 'SET SESSION lock_wait_timeout = 30' ), array_values( array_unique( $this->db->seen( 'SET SESSION lock_wait_timeout' ) ) ) );
	}
}
