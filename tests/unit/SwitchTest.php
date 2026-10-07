<?php
/**
 * Staged import switch (M2): unit plan, journaled move into the live
 * wp-content, crash resume at every point, rollback, copy fallback,
 * maintenance file, token lifetime, and the L4/L5 helpers.
 *
 * @package wp-free-site-cloner
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', rtrim( sys_get_temp_dir(), '/' ) . '/fsc-test-abspath-' . getmypid() . '/' );
}

/**
 * @covers FSC_Extractor
 * @covers FSC_Importer
 * @covers FSC_Source_Util
 * @covers FSC_DB
 */
class SwitchTest extends FSC_Test_Case {

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
	 * rel => content of every file below a root (links as "link:<target>").
	 *
	 * @param string $root Root.
	 * @return array
	 */
	private static function tree( $root ) {
		$out = array();
		if ( ! is_dir( $root ) ) {
			return $out;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $f ) {
			$rel = substr( $f->getPathname(), strlen( $root ) + 1 );
			if ( $f->isLink() ) {
				$out[ $rel ] = 'link:' . readlink( $f->getPathname() );
			} elseif ( $f->isFile() ) {
				$out[ $rel ] = file_get_contents( $f->getPathname() );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Live site, staged archive, switch paths.
	 *
	 * @return array array( sw, live tree before, stage tree before ).
	 */
	private function fixture() {
		$live  = $this->dir . '/wp-content';
		$stage = $this->dir . '/storage/tmp/stage-0123456789abcdef';
		self::put(
			$live,
			array(
				'plugins/a/a.php'             => 'old a',
				'plugins/a/only-old.php'      => 'old extra',
				'plugins/keep/keep.php'       => 'not in archive',
				'plugins/hello.php'           => 'old hello',
				'themes/t/style.css'          => 'old theme',
				'uploads/2024/05/old.jpg'     => 'old may',
				'uploads/2023/01/z.jpg'       => 'old 2023',
				'uploads/elementor/css/a.css' => 'old css',
				'index.php'                   => 'old index',
			)
		);
		self::put(
			$stage,
			array(
				'plugins/a/a.php'             => 'new a',
				'plugins/b/b.php'             => 'new b',
				'plugins/hello.php'           => 'new hello',
				'themes/t/style.css'          => 'new theme',
				'themes/t/sub/x.php'          => 'new theme file',
				'uploads/2024/05/new.jpg'     => 'new may',
				'uploads/2024/06/j.jpg'       => 'new june',
				'uploads/2024/k.txt'          => 'year file',
				'uploads/elementor/css/b.css' => 'new css',
				'mu-plugins/m.php'            => 'new mu',
				'languages/de_DE.mo'          => 'mo',
				'w3tc-config/a.json'          => '{}',
				'index.php'                   => 'new index',
			)
		);
		$sw = array(
			'stage'   => $stage,
			'live'    => $live,
			'old'     => $this->dir . '/storage/tmp/old-0123456789abcdef',
			'journal' => $this->dir . '/storage/tmp/0123456789abcdef.swapj',
			'units'   => FSC_Extractor::plan_units( $stage ),
		);
		return array( $sw, self::tree( $live ), self::tree( $stage ) );
	}

	/**
	 * Live tree a finished switch must produce.
	 *
	 * @return array
	 */
	private static function expected_live() {
		$e = array(
			'index.php'                   => 'new index',
			'languages/de_DE.mo'          => 'mo',
			'mu-plugins/m.php'            => 'new mu',
			'plugins/a/a.php'             => 'new a',
			'plugins/b/b.php'             => 'new b',
			'plugins/hello.php'           => 'new hello',
			'plugins/keep/keep.php'       => 'not in archive',
			'themes/t/style.css'          => 'new theme',
			'themes/t/sub/x.php'          => 'new theme file',
			'uploads/2023/01/z.jpg'       => 'old 2023',
			'uploads/2024/05/new.jpg'     => 'new may',
			'uploads/2024/06/j.jpg'       => 'new june',
			'uploads/2024/k.txt'          => 'year file',
			'uploads/elementor/css/b.css' => 'new css',
			'w3tc-config/a.json'          => '{}',
		);
		ksort( $e );
		return $e;
	}

	public function test_plan_units_granularity() {
		list( $sw ) = $this->fixture();
		$this->assertSame(
			array( 'index.php', 'languages/de_DE.mo', 'mu-plugins/m.php', 'plugins/a', 'plugins/b', 'plugins/hello.php', 'themes/t', 'uploads/2024/05', 'uploads/2024/06', 'uploads/2024/k.txt', 'uploads/elementor', 'w3tc-config' ),
			$sw['units']
		);
	}

	public function test_switch_refuses_an_out_of_range_journal() {
		list( $sw ) = $this->fixture();
		$n          = count( $sw['units'] );
		// A torn append (power loss, partial write) can leave a record whose
		// index is past the plan; it must not read as "every unit switched".
		file_put_contents( $sw['journal'], "0 a\n0 b\n0 c\n" . ( $n + 5 ) . " a\n" );
		$copied = false;
		foreach ( array( false, true ) as $rollback ) {
			try {
				FSC_Extractor::switch_step( $sw, INF, $rollback, $copied );
				$this->fail( 'accepted a journal index past the unit count' );
			} catch ( FSC_Exception $e ) {
				$this->assertStringContainsString( 'journal is damaged', $e->getMessage() );
			}
		}
	}

	public function test_forward_switch_and_full_rollback() {
		list( $sw, $live0, $stage0 ) = $this->fixture();
		$copied = false;
		$this->assertTrue( FSC_Extractor::switch_step( $sw, INF, false, $copied ) );
		$this->assertFalse( $copied );
		$this->assertSame( self::expected_live(), self::tree( $sw['live'] ) );
		// Replaced entries wait in the old folder.
		$this->assertSame( 'old extra', file_get_contents( $sw['old'] . '/plugins/a/only-old.php' ) );
		$this->assertSame( 'old may', file_get_contents( $sw['old'] . '/uploads/2024/05/old.jpg' ) );
		$this->assertSame( count( $sw['units'] ), FSC_Extractor::switched_count( $sw['journal'] ) );
		// Forward again is a no-op.
		$this->assertTrue( FSC_Extractor::switch_step( $sw, INF, false, $copied ) );
		$this->assertSame( self::expected_live(), self::tree( $sw['live'] ) );

		$this->assertTrue( FSC_Extractor::switch_step( $sw, INF, true, $copied ) );
		$this->assertSame( $live0, self::tree( $sw['live'] ) );
		$this->assertSame( $stage0, self::tree( $sw['stage'] ) );
		$this->assertFalse( FSC_Extractor::has_files( $sw['old'] ) );
		$this->assertSame( array( 0, 'z' ), FSC_Extractor::journal_last( $sw['journal'] ) );
		// Rollback again is a no-op; forward after a rollback is refused.
		$this->assertTrue( FSC_Extractor::switch_step( $sw, INF, true, $copied ) );
		$this->expectException( FSC_Exception::class );
		FSC_Extractor::switch_step( $sw, INF, false, $copied );
	}

	/**
	 * "Kill" the switch at every point where a request could die (after each
	 * journal record, rename, copied file, deleted source), then resume it
	 * to the end, or roll it back; the same for a rollback killed midway.
	 *
	 * @param bool $copy Use the copy fallback.
	 */
	private function crash_everywhere( $copy ) {
		$points = 0;
		FSC_Extractor::$crash_hook = function () use ( &$points ) {
			++$points;
		};
		self::rrmdir( $this->dir );
		mkdir( $this->dir, 0755, true );
		list( $sw0 ) = $this->fixture();
		$copied      = false;
		FSC_Extractor::switch_step( $sw0, INF, false, $copied, $copy );
		$total = $points;
		$this->assertGreaterThan( 3 * count( $sw0['units'] ), $total );
		try {
			for ( $kill = 1; $kill <= $total; $kill++ ) {
				foreach ( array( 'forward', 'rollback', 'rollback-killed' ) as $mode ) {
					self::rrmdir( $this->dir );
					mkdir( $this->dir, 0755, true );
					list( $sw, $live0, $stage0 ) = $this->fixture();
					$n                           = 0;
					FSC_Extractor::$crash_hook   = function () use ( &$n, $kill ) {
						if ( ++$n === $kill ) {
							throw new RuntimeException( 'killed' );
						}
					};
					try {
						FSC_Extractor::switch_step( $sw, INF, false, $copied, $copy );
						$this->fail( "not killed at $kill" );
					} catch ( RuntimeException $e ) {
						$this->assertSame( 'killed', $e->getMessage() );
					}
					FSC_Extractor::$crash_hook = null;
					if ( 'rollback-killed' === $mode ) {
						// Kill the rollback too, after a third of its steps.
						$m                         = 0;
						FSC_Extractor::$crash_hook = function () use ( &$m, $kill ) {
							if ( ++$m === 1 + intdiv( $kill, 3 ) ) {
								throw new RuntimeException( 'killed' );
							}
						};
						try {
							FSC_Extractor::switch_step( $sw, INF, true, $copied, $copy );
						} catch ( RuntimeException $e ) {
							$this->assertSame( 'killed', $e->getMessage() );
						}
						FSC_Extractor::$crash_hook = null;
					}
					$guard = 0;
					do {
						$done = FSC_Extractor::switch_step( $sw, 0, 'forward' !== $mode, $copied, $copy );
					} while ( ! $done && $guard++ < 1000 );
					$this->assertTrue( $done, "$mode after kill $kill" );
					if ( 'forward' === $mode ) {
						$this->assertSame( self::expected_live(), self::tree( $sw['live'] ), "forward after kill $kill" );
					} else {
						$this->assertSame( $live0, self::tree( $sw['live'] ), "$mode after kill $kill" );
						$this->assertSame( $stage0, self::tree( $sw['stage'] ), "$mode after kill $kill (stage)" );
						$this->assertFalse( FSC_Extractor::has_files( $sw['old'] ), "$mode after kill $kill (old)" );
					}
				}
			}
		} finally {
			FSC_Extractor::$crash_hook = null;
		}
	}

	public function test_crash_at_every_point_resumes_or_rolls_back() {
		$this->crash_everywhere( false );
	}

	public function test_copy_fallback_resumes_file_by_file() {
		list( $sw ) = $this->fixture();
		$copied     = false;
		$calls      = 0;
		do {
			++$calls;
			$done = FSC_Extractor::switch_step( $sw, 0, false, $copied, true );
		} while ( ! $done && $calls < 1000 );
		$this->assertTrue( $copied );
		$this->assertGreaterThan( count( $sw['units'] ), $calls );
		$this->assertSame( self::expected_live(), self::tree( $sw['live'] ) );
		$this->crash_everywhere( true );
	}

	public function test_check_unit_symlinks_and_protected_paths() {
		$live = $this->dir . '/wp-content';
		self::put(
			$live,
			array(
				'plugins/a/a.php'     => 'a',
				'plugins/self/me.php' => 'me',
			)
		);
		self::put( $this->dir . '/elsewhere', array( 'x/y.txt' => 'y' ) );
		symlink( $this->dir . '/elsewhere', $live . '/uploads' );
		symlink( $this->dir . '/elsewhere/x', $live . '/plugins/linked' );
		$this->assertSame( '', FSC_Extractor::check_unit( $live, 'plugins/a' ) );
		$this->assertSame( '', FSC_Extractor::check_unit( $live, 'plugins/new' ) );
		$this->assertSame( '', FSC_Extractor::check_unit( $live, 'mu-plugins/m.php' ) );
		$this->assertNotSame( '', FSC_Extractor::check_unit( $live, 'uploads/2024/05' ) );
		$this->assertNotSame( '', FSC_Extractor::check_unit( $live, 'plugins/linked' ) );
		$this->assertSame( 'it holds this plugin or its storage', FSC_Extractor::check_unit( $live, 'plugins/self', array( realpath( $live . '/plugins/self' ) ) ) );
		$this->assertSame( 'it holds this plugin or its storage', FSC_Extractor::check_unit( $live, 'plugins', array( realpath( $live . '/plugins/self' ) ) ) );
		file_put_contents( $live . '/themes', 'a file' );
		$this->assertSame( 'a file on this site is in the way', FSC_Extractor::check_unit( $live, 'themes/t' ) );
	}

	public function test_purge_tree_never_follows_links() {
		self::put( $this->dir . '/outside', array( 'keep.txt' => 'keep' ) );
		self::put( $this->dir . '/old', array( 'a/b.txt' => 'b' ) );
		symlink( $this->dir . '/outside', $this->dir . '/old/a/link' );
		$this->assertTrue( FSC_Extractor::purge_tree( $this->dir . '/old', INF ) );
		$this->assertDirectoryDoesNotExist( $this->dir . '/old' );
		$this->assertSame( 'keep', file_get_contents( $this->dir . '/outside/keep.txt' ) );
		// A symlinked unit is moved as a link, never followed.
		$copied = false;
		symlink( $this->dir . '/outside', $this->dir . '/ln' );
		$this->assertTrue( FSC_Extractor::move_tree( $this->dir . '/ln', $this->dir . '/moved/ln', INF, $copied, true ) );
		$this->assertTrue( is_link( $this->dir . '/moved/ln' ) );
		$this->assertSame( 'keep', file_get_contents( $this->dir . '/outside/keep.txt' ) );
	}

	public function test_maintenance_file_lets_only_import_steps_through() {
		@mkdir( ABSPATH, 0755, true );
		$f = ABSPATH . '.maintenance';
		@unlink( $f );
		$job = FSC_Job::create( $this->dir . '/job.json', 'import', array( 'phase' => 'swap' ) );
		FSC_Importer::maintenance_on( $job );
		$this->assertFileExists( $f );
		$code    = file_get_contents( $f );
		$upgrade = function ( array $post, $script ) use ( $f ) {
			$saved_post   = $_POST;
			$saved_server = $_SERVER;
			$_POST        = $post;
			$_SERVER['SCRIPT_FILENAME'] = $script;
			$upgrading = null;
			include $f;
			$_POST   = $saved_post;
			$_SERVER = $saved_server;
			return $upgrading;
		};
		if ( ! defined( 'WP_CLI' ) ) {
			$this->assertGreaterThan( time() - 60, $upgrade( array(), '/var/www/html/index.php' ) );
			$this->assertGreaterThan( time() - 60, $upgrade( array( 'action' => 'fsc_import_step' ), '/var/www/html/index.php' ) );
			$this->assertGreaterThan( time() - 60, $upgrade( array( 'action' => 'other' ), '/var/www/html/wp-admin/admin-ajax.php' ) );
			$this->assertSame( 0, $upgrade( array( 'action' => 'fsc_import_step' ), '/var/www/html/wp-admin/admin-ajax.php' ) );
		}
		FSC_Importer::maintenance_on( $job );
		$this->assertSame( $code, file_get_contents( $f ) );
		FSC_Importer::maintenance_off();
		$this->assertFileDoesNotExist( $f );
		// Somebody else's .maintenance is never touched.
		file_put_contents( $f, '<?php $upgrading = 1;' );
		FSC_Importer::maintenance_on( $job );
		FSC_Importer::maintenance_off();
		$this->assertSame( '<?php $upgrading = 1;', file_get_contents( $f ) );
		unlink( $f );
		@rmdir( ABSPATH );
	}

	public function test_l1_token_lifetime_and_revocation() {
		$token = str_repeat( 'ab', 32 );
		$job   = FSC_Job::create(
			$this->dir . '/job.json',
			'import',
			array(
				'token_hash' => hash( 'sha256', $token ),
				'expires'    => time() + FSC_Importer::TOKEN_TTL,
			)
		);
		$this->assertTrue( FSC_Importer::check_token( $job, $token ) );
		$this->assertFalse( FSC_Importer::check_token( $job, str_repeat( 'cd', 32 ) ) );
		$job->data['expires'] = time() - 1;
		$this->assertFalse( FSC_Importer::check_token( $job, $token ), 'idle longer than the TTL' );
		$job->data['expires'] = time() + 100;
		foreach ( array( 'done', 'error' ) as $status ) {
			$job->data['status']      = $status;
			$job->data['finished_at'] = time() - 10;
			$this->assertTrue( FSC_Importer::check_token( $job, $token ), "$status within the grace period" );
			$job->data['finished_at'] = time() - FSC_Importer::TOKEN_GRACE - 1;
			$this->assertFalse( FSC_Importer::check_token( $job, $token ), "$status after the grace period" );
		}
		$this->assertSame( 7200, FSC_Importer::TOKEN_TTL );
		$this->assertSame( 120, FSC_Importer::TOKEN_GRACE );
	}

	public function test_space_estimate() {
		$this->assertEqualsWithDelta( 1000 * 1.05 + 67108864, FSC_Importer::space_needed( array( 'files_bytes' => 1000 ), 5000, false ), 0.01 );
		$this->assertEqualsWithDelta( 5000 * 1.05 + 67108864, FSC_Importer::space_needed( array(), 5000, false ), 0.01 );
		$this->assertEqualsWithDelta( 3000 * 1.05 + 67108864, FSC_Importer::space_needed( array( 'files_bytes' => 1000, 'sql_size' => 2000 ), 5000, true ), 0.01 );
	}

	public function test_l5_gunzip_bomb_is_refused() {
		if ( ! function_exists( 'gzopen' ) ) {
			$this->markTestSkipped( 'zlib missing' );
		}
		$gz = $this->dir . '/bomb.sql.gz';
		$fp = gzopen( $gz, 'wb9' );
		$z  = str_repeat( "\0", 1048576 );
		for ( $i = 0; $i < 20; $i++ ) {
			gzwrite( $fp, $z );
		}
		gzclose( $fp );
		$this->assertLessThan( 1048576 * 20 / FSC_Source_Util::GZ_MAX_RATIO, filesize( $gz ) );
		$cursor = array();
		try {
			$guard = 0;
			while ( ! FSC_Source_Util::gunzip_step( $gz, $this->dir . '/out.sql', $cursor, INF ) && $guard++ < 100 ) {
				continue;
			}
			$this->fail( 'The bomb must be refused.' );
		} catch ( FSC_Exception $e ) {
			$this->assertStringContainsString( 'expands to more than', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->dir . '/out.sql' );
		// An explicit limit, and a normal dump within limits.
		$ok = $this->dir . '/ok.sql.gz';
		file_put_contents( $ok, gzencode( str_repeat( "INSERT INTO `wp_x` VALUES (1,'abc');\n", 1000 ) ) );
		$cursor = array();
		$this->assertTrue( FSC_Source_Util::gunzip_step( $ok, $this->dir . '/ok.sql', $cursor, INF ) );
		$this->assertSame( 37000, filesize( $this->dir . '/ok.sql' ) );
		$cursor = array();
		$this->expectException( FSC_Exception::class );
		FSC_Source_Util::gunzip_step( $ok, $this->dir . '/ok2.sql', $cursor, INF, 1000 );
	}

	public function test_l4_snippets_carry_no_values() {
		$this->assertSame( 'INSERT INTO `fsctmp_users` (`ID`,`user_pass`) ...', FSC_DB::snippet( "INSERT INTO `fsctmp_users` (`ID`,`user_pass`) VALUES (1,'\$wp\$2y\$10\$secret')" ) );
		$this->assertSame( 'UPDATE `fsctmp_options` ...', FSC_DB::snippet( "UPDATE `fsctmp_options` SET option_value = 'api-key-123' WHERE option_name = 'k'" ) );
		$this->assertSame( 'SELECT `a` FROM `t` ...', FSC_DB::snippet( "SELECT `a` FROM `t` WHERE `a` LIKE '%http://old%'" ) );
		$this->assertSame( 'SHOW FULL TABLES LIKE ...', FSC_DB::snippet( "SHOW FULL TABLES LIKE 'wp\\_%'" ) );
		$this->assertSame( 'INSERT INTO `t` ...', FSC_DB::snippet( "INSERT INTO `t`\n\tVALUES (0x736563726574)" ) );
		$this->assertSame( 'REPLACE INTO t (a) ...', FSC_DB::snippet( "REPLACE INTO t (a) VALUE ('x')" ) );
		$this->assertSame( 'RENAME TABLE `a` TO `b`', FSC_DB::snippet( 'RENAME TABLE `a` TO `b`' ) );
		$this->assertSame( str_repeat( 'x', 120 ) . ' ...', FSC_DB::snippet( str_repeat( 'x', 200 ) ) );
		$this->assertSame( "Duplicate entry '...' for key 'user_email'", FSC_DB::safe_error( "Duplicate entry 'admin@example.com' for key 'user_email'" ) );
		$this->assertSame( "Incorrect string value: '...' for column `x`.`t`.`c` at row 1", FSC_DB::safe_error( "Incorrect string value: '\\xF0\\x9F\\x98' for column `x`.`t`.`c` at row 1" ) );
		$this->assertSame( "Table 'x' already exists", FSC_DB::safe_error( "Table 'x' already exists" ) );
	}

	public function test_session_refuses_unsafe_charset() {
		// Records every statement FSC_DB::session() runs through wpdb (no mysqli dbh).
		$wpdb = new class() {
			public $dbh     = null;
			public $queries = array();
			public function suppress_errors( $s = true ) {
				return false; }
			public function query( $sql ) {
				$this->queries[] = $sql;
				return 0; }
		};
		$GLOBALS['wpdb'] = $wpdb;

		// A safe charset is applied with SET NAMES.
		FSC_DB::session( 'utf8mb4' );
		$this->assertContains( 'SET NAMES utf8mb4', $wpdb->queries );

		// A charset not on the allowlist (e.g. one stored in an old job cursor and
		// re-applied on resume) is refused before any SET NAMES reaches the server.
		foreach ( array( 'gbk', 'big5', 'sjis', 'cp932', 'gb18030', 'ucs2', 'utf16' ) as $cs ) {
			$wpdb->queries = array();
			try {
				FSC_DB::session( $cs );
				$this->fail( "session() accepted unsafe charset $cs" );
			} catch ( FSC_Exception $e ) {
				$this->assertStringContainsString( 'charset', strtolower( $e->getMessage() ) );
			}
			foreach ( $wpdb->queries as $q ) {
				$this->assertStringNotContainsString( 'SET NAMES', $q, $cs );
			}
		}
		unset( $GLOBALS['wpdb'] );
	}
}
