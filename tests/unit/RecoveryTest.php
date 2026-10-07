<?php
/**
 * Automatic recovery of an abandoned import switch (FSC_Recovery) and the
 * .maintenance file that triggers it.
 *
 * @package wp-free-site-cloner
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', rtrim( sys_get_temp_dir(), '/' ) . '/fsc-test-abspath-' . getmypid() . '/' );
}

/**
 * @covers FSC_Recovery
 * @covers FSC_Importer
 */
class RecoveryTest extends FSC_Test_Case {

	const ID = '0123456789abcdef';

	protected function tearDown(): void {
		FSC_Extractor::$crash_hook = null;
		parent::tearDown();
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
	 * A storage, a live wp-content, a staged import and a job in the switch,
	 * killed at switch point $kill (0 = all files moved).
	 *
	 * @param int   $kill Kill point.
	 * @param array $data Extra job data.
	 * @return array array( storage, live dir, live tree before, maintenance file, points seen ).
	 */
	private function switch_fixture( $kill, array $data = array() ) {
		$st = new FSC_Storage( $this->dir . '/base' );
		$st->ensure();
		$live  = $this->dir . '/wp-content';
		$stage = FSC_Importer::stage_dir( $st, self::ID );
		self::put(
			$live,
			array(
				'plugins/a/a.php'         => 'old a',
				'plugins/a/only-old.php'  => 'old extra',
				'plugins/keep/keep.php'   => 'not in archive',
				'themes/t/style.css'      => 'old theme',
				'uploads/2024/05/old.jpg' => 'old may',
				'index.php'               => 'old index',
			)
		);
		self::put(
			$stage,
			array(
				'plugins/a/a.php'         => 'new a',
				'plugins/b/b.php'         => 'new b',
				'themes/t/style.css'      => 'new theme',
				'uploads/2024/05/new.jpg' => 'new may',
				'uploads/2024/06/j.jpg'   => 'new june',
				'index.php'               => 'new index',
			)
		);
		$live0 = self::tree( $live );
		$units = FSC_Extractor::plan_units( $stage );
		file_put_contents( $st->tmp_file( self::ID, 'moves' ), json_encode( $units ) );
		mkdir( FSC_Importer::old_dir( $st, self::ID ), 0700, true );
		FSC_Job::create(
			$st->job_path(),
			'import',
			array_merge(
				array(
					'id'      => self::ID,
					'phase'   => 'swap',
					'in_step' => true,
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
		$maint = $this->dir . '/abs/.maintenance';
		mkdir( dirname( $maint ), 0755, true );
		file_put_contents( $maint, FSC_Importer::maintenance_code( '' ) );
		$n                         = 0;
		FSC_Extractor::$crash_hook = function () use ( &$n, $kill ) {
			if ( ++$n === $kill ) {
				throw new RuntimeException( 'killed' );
			}
		};
		try {
			FSC_Extractor::switch_step( FSC_Importer::switch_paths( $st, self::ID, $live ), INF, false, $copied );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'killed', $e->getMessage() );
		}
		FSC_Extractor::$crash_hook = null;
		return array( $st, $live, $live0, $maint, $n );
	}

	/**
	 * Recovery options: stale for 300 s, "now" far in the future.
	 *
	 * @param string $maint Maintenance file.
	 * @param array  $more  Overrides.
	 * @return array
	 */
	private function opts( $maint, array $more = array() ) {
		return array_merge(
			array(
				'stale'       => 300,
				'deadline'    => INF,
				'maintenance' => $maint,
				'now'         => time() + 1000,
				'db'          => function () {
					throw new LogicException( 'The database must not be asked before the RENAME started.' );
				},
			),
			$more
		);
	}

	public function test_rollback_after_a_crash_at_every_switch_point() {
		list( , , , , $total ) = $this->switch_fixture( 0 );
		$this->assertGreaterThan( 10, $total );
		for ( $kill = 1; $kill <= $total + 1; $kill++ ) {
			self::rrmdir( $this->dir );
			mkdir( $this->dir, 0755, true );
			list( $st, $live, $live0, $maint ) = $this->switch_fixture( $kill > $total ? 0 : $kill );
			$this->assertSame( FSC_Recovery::DONE, FSC_Recovery::recover( $st, $live, $this->opts( $maint ) ), "kill $kill" );
			$this->assertSame( $live0, self::tree( $live ), "live after kill $kill" );
			$this->assertFalse( FSC_Extractor::has_files( FSC_Importer::old_dir( $st, self::ID ) ), "old after kill $kill" );
			$this->assertFileDoesNotExist( $maint );
			$this->assertFileExists( FSC_Recovery::flag_path( $st ) );
			$job = FSC_Job::load( $st->job_path() );
			$this->assertSame( 'error', $job->data['status'] );
			$this->assertStringContainsString( 'the site was not changed', $job->data['error'] );
			$this->assertSame( 'rollback', $job->data['recovered'] );
			$this->assertEmpty( $job->data['swapped'] ?? null );
			// Idempotent: nothing left to recover.
			$this->assertSame( FSC_Recovery::NONE, FSC_Recovery::recover( $st, $live, $this->opts( $maint ) ) );
		}
	}

	public function test_fresh_or_locked_switch_is_left_alone() {
		list( $st, $live, $live0, $maint ) = $this->switch_fixture( 7 );
		$mid                               = self::tree( $live );
		$this->assertNotSame( $live0, $mid );
		$this->assertSame( FSC_Recovery::FRESH, FSC_Recovery::recover( $st, $live, $this->opts( $maint, array( 'now' => time() ) ) ) );
		$other = FSC_Job::load( $st->job_path() );
		$this->assertTrue( $other->lock( 0 ) );
		$this->assertSame( FSC_Recovery::BUSY, FSC_Recovery::recover( $st, $live, $this->opts( $maint ) ) );
		$other->unlock();
		$this->assertSame( $mid, self::tree( $live ) );
		$this->assertFileExists( $maint );
		$this->assertSame( FSC_Recovery::DONE, FSC_Recovery::recover( $st, $live, $this->opts( $maint ) ) );
		$this->assertSame( $live0, self::tree( $live ) );
	}

	public function test_rollback_spread_over_several_requests() {
		list( $st, $live, $live0, $maint ) = $this->switch_fixture( 0 );
		$r     = FSC_Recovery::recover( $st, $live, $this->opts( $maint, array( 'deadline' => 0 ) ) );
		$this->assertSame( FSC_Recovery::PENDING, $r );
		$this->assertFileExists( $maint );
		$job = FSC_Job::load( $st->job_path() );
		$this->assertSame( 'rollback', $job->data['cursor']['swap']['stage'], 'a resumed step continues the rollback' );
		$guard = 0;
		while ( FSC_Recovery::PENDING === $r && $guard++ < 100 ) {
			// The job was just saved by the recovery itself: that is not "fresh" progress.
			$r = FSC_Recovery::recover( $st, $live, $this->opts( $maint, array( 'deadline' => 0, 'now' => time() ) ) );
		}
		$this->assertSame( FSC_Recovery::DONE, $r );
		$this->assertSame( $live0, self::tree( $live ) );
		$this->assertFileDoesNotExist( $maint );
	}

	public function test_after_the_commit_point_only_maintenance_ends() {
		list( $st, $live, , $maint ) = $this->switch_fixture(
			0,
			array(
				'swapped' => true,
				'phase'   => 'finalize',
			)
		);
		$new = self::tree( $live );
		$this->assertSame( FSC_Recovery::DONE, FSC_Recovery::recover( $st, $live, $this->opts( $maint ) ) );
		$this->assertSame( $new, self::tree( $live ) );
		$this->assertFileDoesNotExist( $maint );
		$job = FSC_Job::load( $st->job_path() );
		$this->assertSame( 'running', $job->data['status'] );
		$this->assertSame( 'finalize', $job->data['phase'] );
		$this->assertSame( 'finish', $job->data['recovery'] );
		$this->assertFileExists( FSC_Recovery::flag_path( $st ) );
	}

	public function test_interrupted_rename_is_decided_by_the_database() {
		// Temp tables gone: the RENAME ran.
		list( $st, $live, , $maint ) = $this->switch_fixture( 0, array( 'swapping' => true ) );
		$new                         = self::tree( $live );
		$no_tables                   = function () {
			return false;
		};
		$this->assertSame( FSC_Recovery::DONE, FSC_Recovery::recover( $st, $live, $this->opts( $maint, array( 'db' => $no_tables ) ) ) );
		$this->assertSame( $new, self::tree( $live ) );
		$job = FSC_Job::load( $st->job_path() );
		$this->assertTrue( $job->data['swapped'] );
		$this->assertSame( 'finalize', $job->data['phase'] );

		// Temp tables still there: it did not run.
		self::rrmdir( $this->dir );
		mkdir( $this->dir, 0755, true );
		list( $st, $live, $live0, $maint ) = $this->switch_fixture( 0, array( 'swapping' => true ) );
		$tables                            = function () {
			return true;
		};
		$this->assertSame( FSC_Recovery::DONE, FSC_Recovery::recover( $st, $live, $this->opts( $maint, array( 'db' => $tables ) ) ) );
		$this->assertSame( $live0, self::tree( $live ) );
		$this->assertSame( 'error', FSC_Job::load( $st->job_path() )->data['status'] );
	}

	public function test_unreachable_database_retries_then_ends_maintenance() {
		list( $st, $live, , $maint ) = $this->switch_fixture( 0, array( 'swapping' => true ) );
		$new                         = self::tree( $live );
		$t                           = time() + 1000;
		$o                           = $this->opts(
			$maint,
			array(
				'db' => function () {
					return null;
				},
			)
		);
		$this->assertSame( FSC_Recovery::RETRY, FSC_Recovery::recover( $st, $live, array( 'now' => $t ) + $o ) );
		$this->assertFileExists( $maint );
		// Not again within RETRY_AFTER.
		$this->assertSame( FSC_Recovery::RETRY, FSC_Recovery::recover( $st, $live, array( 'now' => $t + 5 ) + $o ) );
		$this->assertSame( 1, FSC_Job::load( $st->job_path() )->data['recover_attempts'] );
		$this->assertSame( FSC_Recovery::RETRY, FSC_Recovery::recover( $st, $live, array( 'now' => $t + 31 ) + $o ) );
		$this->assertSame( FSC_Recovery::FAILED, FSC_Recovery::recover( $st, $live, array( 'now' => $t + 62 ) + $o ) );
		$this->assertFileDoesNotExist( $maint );
		$this->assertSame( $new, self::tree( $live ), 'nothing is moved while the commit state is unknown' );
		$job = FSC_Job::load( $st->job_path() );
		$this->assertSame( 'running', $job->data['status'] );
		$log = (string) file_get_contents( $job->log_path() );
		$this->assertStringContainsString( 'WARNING: Maintenance mode was ended', $log );
		$this->assertStringNotContainsString( $this->dir, $log );
		// Once the database answers, the plugin finishes the decision.
		$this->assertSame(
			FSC_Recovery::DONE,
			FSC_Recovery::recover(
				$st,
				$live,
				array(
					'now' => $t + 100,
					'db'  => function () {
						return false;
					},
				) + $o
			)
		);
		$this->assertTrue( FSC_Job::load( $st->job_path() )->data['swapped'] );
	}

	public function test_failed_job_that_was_not_cleaned_is_rolled_back() {
		list( $st, $live, $live0, $maint ) = $this->switch_fixture( 9, array( 'status' => 'error', 'error' => 'boom' ) );
		$this->assertSame( FSC_Recovery::DONE, FSC_Recovery::recover( $st, $live, $this->opts( $maint, array( 'now' => time() ) ) ) );
		$this->assertSame( $live0, self::tree( $live ) );
		$job = FSC_Job::load( $st->job_path() );
		$this->assertSame( 'boom', $job->data['error'] );
	}

	public function test_nothing_to_recover() {
		$st = new FSC_Storage( $this->dir . '/base' );
		$st->ensure();
		$o = $this->opts( $this->dir . '/.maintenance' );
		$this->assertSame( FSC_Recovery::NONE, FSC_Recovery::recover( $st, $this->dir, $o ) );
		FSC_Job::create( $st->job_path(), 'export', array( 'phase' => 'files' ) );
		$this->assertSame( FSC_Recovery::NONE, FSC_Recovery::recover( $st, $this->dir, $o ) );
		FSC_Job::create( $st->job_path(), 'import', array( 'phase' => 'files' ) );
		$this->assertSame( FSC_Recovery::NONE, FSC_Recovery::recover( $st, $this->dir, $o ) );
		FSC_Job::create(
			$st->job_path(),
			'import',
			array(
				'phase'   => 'swap',
				'status'  => 'error',
				'cleaned' => true,
				'cursor'  => array( 'swap' => array( 'stage' => 'rolled_back' ) ),
			)
		);
		$this->assertSame( FSC_Recovery::NONE, FSC_Recovery::recover( $st, $this->dir, $o ) );
	}

	public function test_maintenance_file_runs_recovery_only_when_stale() {
		$f    = $this->dir . '/.maintenance';
		$stub = $this->dir . '/stub.php';
		$run  = function ( $code, $age, array $post = array() ) use ( $f ) {
			file_put_contents( $f, $code );
			touch( $f, time() - $age );
			clearstatcache();
			$saved_post                 = $_POST;
			$saved_server               = $_SERVER;
			$_POST                      = $post;
			$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/' . ( empty( $post ) ? 'index.php' : 'wp-admin/admin-ajax.php' );
			$upgrading                  = null;
			include $f;
			$_POST   = $saved_post;
			$_SERVER = $saved_server;
			return $upgrading;
		};
		$code = FSC_Importer::maintenance_code( var_export( $stub, true ) );
		file_put_contents( $stub, '<?php file_put_contents( ' . var_export( $this->dir . '/ran', true ) . ', "x", FILE_APPEND ); return 0;' );
		if ( defined( 'WP_CLI' ) ) {
			$this->markTestSkipped( 'WP_CLI is defined.' );
		}
		// Fresh: maintenance, the recovery script is not even loaded.
		$this->assertGreaterThanOrEqual( time() - 1, $run( $code, 10 ) );
		$this->assertFileDoesNotExist( $this->dir . '/ran' );
		// Stale: the script decides (0 = load the site).
		$this->assertSame( 0, $run( $code, 301 ) );
		$this->assertSame( 'x', file_get_contents( $this->dir . '/ran' ) );
		// The import's own step passes without recovery.
		$this->assertSame( 0, $run( $code, 301, array( 'action' => 'fsc_import_step' ) ) );
		$this->assertSame( 'x', file_get_contents( $this->dir . '/ran' ) );
		// Script keeps maintenance.
		file_put_contents( $stub, '<?php return time();' );
		$this->assertGreaterThanOrEqual( time() - 1, $run( $code, 400 ) );
		// Script cannot decide, or is missing: WordPress' own rule from the file time.
		file_put_contents( $stub, '<?php return null;' );
		$this->assertLessThanOrEqual( time() - 400, $run( $code, 400 ) );
		unlink( $stub );
		$this->assertLessThanOrEqual( time() - 400, $run( $code, 400 ) );
		$this->assertLessThanOrEqual( time() - 700, $run( FSC_Importer::maintenance_code( '' ), 700 ) );
		$this->assertStringNotContainsString( 'private-', $code );
	}

	public function test_parse_db_host() {
		$this->assertSame( array( 'localhost', null, null ), FSC_Recovery::parse_db_host( 'localhost' ) );
		$this->assertSame( array( '127.0.0.1', 3307, null ), FSC_Recovery::parse_db_host( '127.0.0.1:3307' ) );
		$this->assertSame( array( 'localhost', null, '/run/mysqld/mysqld.sock' ), FSC_Recovery::parse_db_host( 'localhost:/run/mysqld/mysqld.sock' ) );
		$this->assertSame( array( null, null, '/tmp/my.sock' ), FSC_Recovery::parse_db_host( ':/tmp/my.sock' ) );
		$this->assertSame( array( '[::1]', 3306, null ), FSC_Recovery::parse_db_host( '[::1]:3306' ) );
		$this->assertSame( array( '[::1]', null, null ), FSC_Recovery::parse_db_host( '[::1]' ) );
		$this->assertSame( array( 'db.example', null, null ), FSC_Recovery::parse_db_host( 'db.example' ) );
	}

	public function test_temp_prefix_matches_the_importer() {
		$this->assertSame( 'fsctmp_', FSC_Recovery::TEMP_PREFIX );
		$this->assertStringContainsString( "const TEMP = '" . FSC_Recovery::TEMP_PREFIX . "';", (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-fsc-db-import.php' ) );
	}
}
