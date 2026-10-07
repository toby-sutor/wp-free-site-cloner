<?php
/**
 * Native source + extractor tests.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_Extractor
 * @covers FSC_Source_Native
 */
class ExtractorTest extends FSC_Test_Case {

	private function build_archive( array $entries, $sql = "SELECT 1;\n", array $manifest = array() ) {
		$archive = $this->dir . '/site.tar';
		// Rebuild with raw headers so special types can be injected.
		$w = new FSC_Tar_Writer( $archive, 0 );
		$w->add_string(
			'fsc-manifest.json',
			json_encode(
				array_merge(
					array(
						'format'         => 'fsc',
						'format_version' => 1,
						'home'           => 'http://old.test',
						'siteurl'        => 'http://old.test/wp',
						'table_prefix'   => 'wp_',
						'abspath'        => '/var/www/old/',
						'content_dir'    => '/var/www/old/wp-content',
						'file_count'     => count( $entries ),
						'sql_sha256'     => hash( 'sha256', $sql ),
					),
					$manifest
				)
			)
		);
		$w->add_string( 'fsc-database.sql', $sql );
		$w->close();
		$fp = fopen( $archive, 'ab' );
		foreach ( $entries as $name => $e ) {
			$type = is_array( $e ) ? $e[0] : '0';
			$data = is_array( $e ) ? $e[1] : $e;
			$size = '0' === $type ? strlen( $data ) : 0;
			$h    = FSC_Tar::entry_headers( $name, $size, 1600000000, $type, 0644 );
			if ( '2' === $type ) {
				$h = FSC_Tar::raw_header( $name, 0, 0, '2', 0777 );
				$h = substr_replace( $h, str_pad( $data, 100, "\0" ), 157, 100 );
				$h = substr_replace( $h, '        ', 148, 8 );
				$s = 0;
				for ( $i = 0; $i < 512; $i++ ) {
					$s += ord( $h[ $i ] );
				}
				$h = substr_replace( $h, sprintf( '%06o', $s ) . "\0 ", 148, 8 );
			}
			fwrite( $fp, $h );
			if ( $size ) {
				fwrite( $fp, $data . str_repeat( "\0", FSC_Tar::padding( $size ) ) );
			}
		}
		fwrite( $fp, str_repeat( "\0", 1024 ) );
		fclose( $fp );
		return $archive;
	}

	public function test_manifest_values_are_typed_strictly() {
		$archive = $this->build_archive(
			array( 'wp-content/a.txt' => 'a' ),
			"SELECT 1;\n",
			array(
				'home'        => array( 'http://evil' ),
				'file_count'  => array( 1, 2 ),
				'files_bytes' => '123',
				'multisite'   => array(),
			)
		);
		$meta = ( new FSC_Source_Native( $archive ) )->meta();
		$this->assertSame( '', $meta['home'] );
		$this->assertSame( 0, $meta['file_count'] );
		$this->assertSame( 123, $meta['files_bytes'] );
		$this->assertTrue( $meta['multisite'], 'an unknown multisite value is refused, not treated as single site' );
		$plain = ( new FSC_Source_Native( $this->build_archive( array( 'wp-content/a.txt' => 'a' ), "SELECT 1;\n", array( 'multisite' => false ) ) ) )->meta();
		$this->assertFalse( $plain['multisite'] );
		$this->assertSame( 'wp_', $plain['prefix'] );
		$this->assertSame( 1, $plain['file_count'] );
	}

	public function test_native_table_prefix_is_validated() {
		foreach ( array( array( 'x' ), 'wp_`evil', 'wp prefix', '', 'wp-123' ) as $bad ) {
			$archive = $this->build_archive( array( 'wp-content/a.txt' => 'a' ), "SELECT 1;\n", array( 'table_prefix' => $bad ) );
			try {
				( new FSC_Source_Native( $archive ) )->meta();
				$this->fail( 'accepted an invalid table prefix: ' . var_export( $bad, true ) );
			} catch ( FSC_Exception $e ) {
				$this->assertStringContainsString( 'valid table prefix', $e->getMessage() );
			}
		}
		$ok = $this->build_archive( array( 'wp-content/a.txt' => 'a' ), "SELECT 1;\n", array( 'table_prefix' => 'wp_2024_' ) );
		$this->assertSame( 'wp_2024_', ( new FSC_Source_Native( $ok ) )->meta()['prefix'] );
	}

	public function test_native_skips_backup_folders() {
		$archive = $this->build_archive(
			array(
				'wp-content/updraft/backup-2026.zip'          => 'destination backup, keep it',
				'wp-content/ai1wm-backups/site.wpress'        => 'another backup',
				'wp-content/duplicator-backups/x.daf'         => 'another backup',
				'wp-content/uploads/keep.txt'                 => 'real content',
				'wp-content/plugins/akismet/akismet.php'      => 'real content',
			)
		);
		$target = $this->dir . '/wp-content';
		mkdir( $target );
		$src    = new FSC_Source_Native( $archive );
		$paths  = array();
		$cursor = array();
		while ( $e = $src->files_next( $cursor ) ) {
			$paths[] = $e['path'];
		}
		sort( $paths );
		$this->assertSame( array( 'plugins/akismet/akismet.php', 'uploads/keep.txt' ), $paths );

		$cursor = FSC_Extractor::new_cursor();
		$this->assertTrue( ( new FSC_Extractor( $target, new FSC_Source_Native( $archive ) ) )->step( $cursor, microtime( true ) + 10 ) );
		$this->assertDirectoryDoesNotExist( $target . '/updraft' );
		$this->assertDirectoryDoesNotExist( $target . '/ai1wm-backups' );
		$this->assertDirectoryDoesNotExist( $target . '/duplicator-backups' );
		$this->assertSame( 'real content', file_get_contents( $target . '/uploads/keep.txt' ) );
	}

	public function test_detect_and_meta() {
		$archive = $this->build_archive( array( 'wp-content/a.txt' => 'a' ) );
		$this->assertTrue( FSC_Source_Native::detect( $archive ) );
		$src  = new FSC_Source_Native( $archive );
		$meta = $src->meta();
		$this->assertSame( 'native', $meta['format'] );
		$this->assertSame( 'http://old.test', $meta['home'] );
		$this->assertSame( 'wp_', $meta['prefix'] );
		$this->assertSame( '{{FSC_PREFIX}}', $meta['sql_prefix'] );

		$other = $this->dir . '/plain.tar';
		$w     = new FSC_Tar_Writer( $other );
		$w->add_string( 'readme.txt', 'x' );
		$w->finish();
		$w->close();
		$this->assertFalse( FSC_Source_Native::detect( $other ) );
		$this->assertFalse( FSC_Source_Native::detect( $this->dir . '/nothing.zip' ) );
	}

	public function test_extract_sql_resumable() {
		$sql     = str_repeat( "INSERT INTO t VALUES (1,'x');\n", 400000 );
		$archive = $this->build_archive( array(), $sql );
		$dest    = $this->dir . '/out.sql';
		$cursor  = array();
		$n       = 0;
		do {
			$src    = new FSC_Source_Native( $archive );
			$cursor = json_decode( json_encode( $cursor ), true );
			$done   = $src->extract_sql( $dest, $cursor, 0 );
			++$n;
		} while ( ! $done && $n < 100 );
		$this->assertTrue( $done );
		$this->assertGreaterThan( 2, $n );
		$this->assertSame( hash( 'sha256', $sql ), hash_file( 'sha256', $dest ) );
	}

	public function test_extraction_and_security_rules() {
		$big     = random_bytes( 9 * 1024 * 1024 + 7 );
		$long    = 'wp-content/uploads/' . str_repeat( 'long-name/', 25 ) . 'img.jpg';
		$archive = $this->build_archive(
			array(
				'wp-content/uploads/'                        => array( '5', '' ),
				'wp-content/uploads/a.txt'                   => 'hello',
				$long                                         => 'deep',
				'wp-content/uploads/big.bin'                 => $big,
				'wp-content/../evil.txt'                     => 'evil',
				'wp-content/uploads/../../evil2.txt'         => 'evil',
				'wp-content//etc/passwd-ish'                 => 'absolute after prefix strip',
				'wp-content/link'                            => array( '2', '/etc/passwd' ),
				'wp-content/hard'                            => array( '1', '' ),
				'wp-content/plugins/wp-free-site-cloner/x.php' => 'skip me',
				'wp-content/wp-config.php'                   => 'skip me',
				'wp-content/C:/win.txt'                      => 'drive',
				'wp-content/sub\\..\\..\\bs.txt'              => 'backslash',
			)
		);
		$target = $this->dir . '/wp-content';
		mkdir( $target );
		$skip   = function ( $rel ) {
			return 0 === strpos( $rel, 'plugins/wp-free-site-cloner/' ) || 'wp-config.php' === basename( $rel );
		};
		$cursor = FSC_Extractor::new_cursor();
		$steps  = 0;
		$msgs   = array();
		do {
			$src    = new FSC_Source_Native( $archive );
			$x      = new FSC_Extractor( $target, $src, $skip );
			$cursor = json_decode( json_encode( $cursor ), true );
			$done   = $x->step( $cursor, 0 );
			$msgs   = array_merge( $msgs, $x->messages );
			++$steps;
		} while ( ! $done && $steps < 1000 );

		$this->assertTrue( $done );
		$this->assertGreaterThan( 2, $steps, 'big file must span several steps' );
		$this->assertSame( 'hello', file_get_contents( $target . '/uploads/a.txt' ) );
		$this->assertSame( 'deep', file_get_contents( $target . '/' . substr( $long, 11 ) ) );
		$this->assertSame( $big, file_get_contents( $target . '/uploads/big.bin' ) );
		$this->assertFileDoesNotExist( $target . '/etc/passwd-ish' );
		$this->assertFileDoesNotExist( $this->dir . '/evil.txt' );
		$this->assertFileDoesNotExist( $this->dir . '/evil2.txt' );
		$this->assertFileDoesNotExist( $this->dir . '/bs.txt' );
		$this->assertFalse( file_exists( $target . '/link' ) || is_link( $target . '/link' ) );
		$this->assertFileDoesNotExist( $target . '/hard' );
		$this->assertFileDoesNotExist( $target . '/plugins/wp-free-site-cloner/x.php' );
		$this->assertFileDoesNotExist( $target . '/wp-config.php' );
		$this->assertFileDoesNotExist( $target . '/C:/win.txt' );
		$this->assertSame( 1600000000, filemtime( $target . '/uploads/a.txt' ) );
		$this->assertSame( 3, $cursor['files'] );
		$joined = implode( "\n", $msgs );
		$this->assertStringContainsString( 'Rejected symlink entry', $joined );
		$this->assertStringContainsString( 'Rejected hardlink entry', $joined );
		$this->assertStringContainsString( 'Rejected unsafe or overlong path', $joined );
	}

	public function test_existing_symlinked_dir_pointing_outside_is_not_followed() {
		$outside = $this->dir . '/outside';
		mkdir( $outside );
		$target = $this->dir . '/wp-content';
		mkdir( $target );
		symlink( $outside, $target . '/uploads' );
		$archive = $this->build_archive( array( 'wp-content/uploads/x.txt' => 'x' ) );
		$cursor  = FSC_Extractor::new_cursor();
		$x       = new FSC_Extractor( $target, new FSC_Source_Native( $archive ) );
		$this->assertTrue( $x->step( $cursor, microtime( true ) + 5 ) );
		$this->assertFileDoesNotExist( $outside . '/x.txt' );
		$this->assertSame( 1, $cursor['skipped'] );
	}

	public function test_safe_relative() {
		$this->assertSame( 'a/b.txt', FSC_Extractor::safe_relative( './a//b.txt' ) );
		$this->assertNull( FSC_Extractor::safe_relative( '/abs' ) );
		$this->assertNull( FSC_Extractor::safe_relative( 'a/../../b' ) );
		$this->assertNull( FSC_Extractor::safe_relative( "a\0b" ) );
		$this->assertNull( FSC_Extractor::safe_relative( 'D:\\x' ) );
		$this->assertNull( FSC_Extractor::safe_relative( '..\\x' ) );
		$this->assertNull( FSC_Extractor::safe_relative( '' ) );
		$this->assertSame( 'a/b', FSC_Extractor::safe_relative( 'a\\b' ) );
	}

	public function test_importer_skip_rules_and_overlong_names() {
		$archive = $this->build_archive(
			array(
				'wp-content/wp-config.php'                                  => 'top level: skip',
				'wp-content/plugins/duplicator/tpl/wp-config.php'           => 'template: keep',
				'wp-content/object-cache.php'                               => 'drop-in: skip',
				'wp-content/plugins/cache/object-cache.php'                 => 'keep',
				'wp-content/fsc-storage/private-0123/x.tar'                 => 'skip',
				'wp-content/plugins/wp-free-site-cloner/evil.php'           => 'skip',
				'wp-content/Plugins/WP-Free-Site-Cloner/evil2.php'          => 'skip (case-insensitive filesystems)',
				'wp-content/plugins/WP-FREE-SITE-CLONER'                    => 'skip',
				'wp-content/plugins/wp-free-site-cloner-other/ok.php'       => 'keep',
				'wp-content/uploads/' . str_repeat( 'n', 256 ) . '.jpg'     => 'overlong segment',
				'wp-content/uploads/' . str_repeat( 'd/', 2100 ) . 'x.txt'  => 'overlong path',
				"wp-content/uploads/ctl\x01name.txt"                       => 'control byte ok',
				'wp-content/uploads/after.txt'                              => 'still extracted',
			)
		);
		$target = $this->dir . '/wp-content';
		mkdir( $target );
		$cursor = FSC_Extractor::new_cursor();
		$x      = new FSC_Extractor( $target, new FSC_Source_Native( $archive ), FSC_Importer::skip_callback( 'wp-free-site-cloner/wp-free-site-cloner.php' ) );
		$this->assertTrue( $x->step( $cursor, microtime( true ) + 10 ) );
		$this->assertFileDoesNotExist( $target . '/wp-config.php' );
		$this->assertSame( 'template: keep', file_get_contents( $target . '/plugins/duplicator/tpl/wp-config.php' ) );
		$this->assertFileDoesNotExist( $target . '/object-cache.php' );
		$this->assertSame( 'keep', file_get_contents( $target . '/plugins/cache/object-cache.php' ) );
		$this->assertDirectoryDoesNotExist( $target . '/fsc-storage' );
		$this->assertFileDoesNotExist( $target . '/plugins/wp-free-site-cloner/evil.php' );
		$this->assertDirectoryDoesNotExist( $target . '/Plugins' );
		$this->assertFileDoesNotExist( $target . '/plugins/WP-FREE-SITE-CLONER' );
		$this->assertSame( 'keep', file_get_contents( $target . '/plugins/wp-free-site-cloner-other/ok.php' ) );
		$this->assertSame( 'still extracted', file_get_contents( $target . '/uploads/after.txt' ) );
		$this->assertSame( 'control byte ok', file_get_contents( $target . "/uploads/ctl\x01name.txt" ) );
		$log = implode( "\n", $x->messages );
		$this->assertStringContainsString( 'Rejected unsafe or overlong path', $log );
		$this->assertStringContainsString( 'too long for this server', $log );
		$this->assertStringNotContainsString( "\x01", $log );
	}

	public function test_truncated_native_archive_is_refused_at_inspect() {
		$archive = $this->build_archive( array( 'wp-content/uploads/a.txt' => str_repeat( 'a', 3000 ) ) );
		$src     = new FSC_Source_Native( $archive );
		$this->assertSame( 'http://old.test', $src->meta()['home'] );
		foreach ( array( filesize( $archive ) - 700, filesize( $archive ) - 1024, 4096 ) as $cut ) {
			$copy = $this->dir . '/cut.tar';
			copy( $archive, $copy );
			$fp = fopen( $copy, 'r+b' );
			ftruncate( $fp, $cut );
			fclose( $fp );
			try {
				( new FSC_Source_Native( $copy ) )->meta();
				$this->fail( 'truncated archive accepted at ' . $cut );
			} catch ( FSC_Exception $e ) {
				$this->assertStringContainsString( 'incomplete', $e->getMessage() );
			}
		}
	}

	public function test_negative_pax_size_is_corrupt() {
		$archive = $this->dir . '/neg.tar';
		$pax     = FSC_Tar::pax_record( 'size', '-512' );
		$data    = FSC_Tar::raw_header( 'PaxHeader/x', strlen( $pax ), 0, 'x', 0644 ) . $pax . str_repeat( "\0", FSC_Tar::padding( strlen( $pax ) ) )
			. FSC_Tar::raw_header( 'x', 0, 0, '0', 0644 ) . str_repeat( "\0", 1024 );
		file_put_contents( $archive, $data );
		$r = new FSC_Tar_Reader( $archive );
		$c = FSC_Tar_Reader::new_cursor();
		$this->expectException( FSC_Exception::class );
		$r->next_entry( $c );
	}
}
