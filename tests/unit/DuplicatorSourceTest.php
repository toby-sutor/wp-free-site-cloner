<?php
/**
 * Duplicator source tests (.daf reader, .zip mapping) with synthetic archives.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_Source_Duplicator
 * @covers FSC_Daf_Reader
 */
class DuplicatorSourceTest extends FSC_Test_Case {

	const DUMP = "/* DUPLICATOR-PRO (PHP MULTI-THREADED BUILD MODE) MYSQL SCRIPT CREATED ON : 2026-09-30 14:46:59 */\n\n/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\nCREATE TABLE IF NOT EXISTS `wp_options` (\n  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`option_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;\n\n/***** TABLE CREATION END *****/\nINSERT IGNORE INTO `wp_options` VALUES \n(\"2\",\"siteurl\",\"http://old.test\",\"on\"),\n(\"3\",\"wp_user_roles\",\"a:1:{s:1:\\\"x\\\";b:1;}\",\"on\");\n\n/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n\n/* Duplicator WordPress Timestamp: 2026-09-30 14:47:00*/\n/* DUPLICATOR_MYSQLDUMP_EOF */\n";

	private static function archive_txt( array $extra = array() ) {
		return json_encode(
			array_merge(
				array(
					'CL_-=_-='       => 'Duplicator\\Installer\\Package\\ArchiveDescriptor',
					'version_dup'    => '5.0.5',
					'version_wp'     => '6.6',
					'secure_on'      => 0,
					'secure_pass'    => '',
					'wp_tableprefix' => 'wp_',
					'mu_mode'        => 0,
					'exportOnlyDB'   => false,
					'wpInfo'         => array(
						'is_multisite' => false,
						'configs'      => array(
							'realValues' => array(
								'siteUrl'       => 'http://old.test',
								'homeUrl'       => 'http://old.test/home',
								'uploadBaseUrl' => 'http://old.test/wp-content/uploads',
								'originalPaths' => array(
									'abs'       => '/var/www/old',
									'wpcontent' => '/var/www/old/wp-content',
								),
							),
						),
					),
				),
				$extra
			),
			JSON_PRETTY_PRINT
		);
	}

	/**
	 * Entries as array( name, data|null for a directory ).
	 */
	private static function entries( $meta = null, $dump = self::DUMP ) {
		return array(
			array( 'wp-admin/', null ),
			array( 'wp-content/', null ),
			array( 'wp-content/uploads/2026/', null ),
			array( 'index.php', '<?php // core' ),
			array( 'wp-content/index.php', '<?php // Silence is golden.' ),
			array( 'wp-content/uploads/2026/big.bin', str_repeat( "0123456789abcdef\x00\xff", 3000 ) ),
			array( 'wp-content/uploads/empty.txt', '' ),
			array( 'wp-content/duplicator-backups/x_archive.daf', 'nested' ),
			array( 'wp-content/backups-dup-lite/y.zip', 'nested' ),
			array( 'dup-installer/main.installer.php', '<?php' ),
			array( 'dup-installer/dup_descriptors_abc1234-30144657/archive.txt', null === $meta ? self::archive_txt() : $meta ),
			array( 'dup-installer/dup_descriptors_abc1234-30144657/db_dumps/20260930144657-dump.sql.gz', gzencode( $dump ) ),
		);
	}

	private static function daf_file( $name, $data, $glob, $flags, $mangle = false ) {
		$compress = (bool) ( $flags & 1 );
		$out      = '<F><FS>' . strlen( $data ) . '</FS><MT>1790779624</MT><P>0644</P><X>' . pack( 'v', $flags ) . '</X><HA>' . sprintf( '%08x', crc32( $data ) ) . '</HA><RPL>' . strlen( $name ) . '</RPL><RP>' . $name . '</RP></F>';
		foreach ( '' === $data ? array() : str_split( $data, $glob ) as $chunk ) {
			$stored = $compress ? gzdeflate( $chunk, 2 ) : $chunk;
			$crc    = $mangle ? 'deadbeef' : sprintf( '%08x', crc32( $chunk ) );
			$out   .= '<G><OS>' . strlen( $chunk ) . '</OS><SS>' . strlen( $stored ) . '</SS><HA>' . $crc . '</HA></G>' . $stored;
		}
		return $out;
	}

	/**
	 * Build a .daf file.
	 */
	private static function build_daf( $file, array $entries, $glob = 1000, $archive_flags = 1, $password = '' ) {
		$body = self::daf_file( '__dup__archive__index.json', str_pad( '{"extraPos":0}', 2000, "\0" ), 100000, 0 );
		foreach ( $entries as $e ) {
			if ( null === $e[1] ) {
				$path  = rtrim( $e[0], '/' );
				$body .= '<D><MT>1786937810</MT><P>0755</P><RPL>' . strlen( $path ) . '</RPL><RP>' . $path . '</RP></D>';
			} else {
				$body .= self::daf_file( $e[0], $e[1], $glob, $archive_flags & 1, isset( $e[2] ) );
			}
		}
		$head = '<A><V>5.0.1</V><X>' . pack( 'v', $archive_flags ) . '</X><P>' . $password . '</P></A>';
		file_put_contents( $file, $head . $body );
	}

	private static function roundtrip( array $c ) {
		return json_decode( json_encode( $c ), true );
	}

	private function daf( array $entries = null, $glob = 1000, $flags = 1, $password = '' ) {
		$file = $this->dir . '/site_archive.daf';
		self::build_daf( $file, null === $entries ? self::entries() : $entries, $glob, $flags, $password );
		return $file;
	}

	public function test_daf_reader_lists_records_and_reads_globs() {
		foreach ( array( 1, 0 ) as $flags ) {
			$r = new FSC_Daf_Reader( $this->daf( null, 1000, $flags ) );
			$h = $r->header();
			$this->assertSame( '5.0.1', $h['version'] );
			$this->assertSame( (bool) $flags, $h['compressed'] );
			$this->assertFalse( $h['encrypted'] );
			$c    = FSC_Daf_Reader::new_cursor();
			$seen = array();
			while ( null !== ( $e = $r->next_entry( $c ) ) ) {
				$seen[ $e['name'] ] = $e['type'];
				if ( 'wp-content/uploads/2026/big.bin' === $e['name'] ) {
					$data = '';
					while ( '' !== ( $buf = $r->read( $c, 1 << 20 ) ) ) {
						$data .= $buf;
					}
					$this->assertSame( str_repeat( "0123456789abcdef\x00\xff", 3000 ), $data );
				}
			}
			$this->assertSame( 'dir', $seen['wp-content/uploads/2026'] );
			$this->assertSame( 'file', $seen['wp-content/uploads/empty.txt'] );
			$this->assertSame( 'file', $seen['__dup__archive__index.json'] );
			$this->assertCount( 13, $seen );
		}
	}

	public function test_daf_resume_mid_glob_and_skip_unread() {
		$file = $this->daf( null, 777 );
		$want = str_repeat( "0123456789abcdef\x00\xff", 3000 );
		$r    = new FSC_Daf_Reader( $file );
		$c    = FSC_Daf_Reader::new_cursor();
		do {
			$e = $r->next_entry( $c );
		} while ( 'wp-content/uploads/2026/big.bin' !== $e['name'] );
		$got = $r->read( $c, 100 );
		while ( true ) {
			$c   = self::roundtrip( $c );
			$r   = new FSC_Daf_Reader( $file );
			$buf = $r->read( $c, 333 );
			if ( '' === $buf ) {
				break;
			}
			$got .= $buf;
		}
		$this->assertSame( $want, $got );

		// Stop half way through the file; the next entry must still be found.
		$r = new FSC_Daf_Reader( $file );
		$c = FSC_Daf_Reader::new_cursor();
		do {
			$e = $r->next_entry( $c );
		} while ( 'wp-content/uploads/2026/big.bin' !== $e['name'] );
		$r->read( $c, 5000 );
		$c = self::roundtrip( $c );
		$r = new FSC_Daf_Reader( $file );
		$this->assertSame( 'wp-content/uploads/empty.txt', $r->next_entry( $c )['name'] );
	}

	public function test_daf_glob_crc_mismatch() {
		$entries   = self::entries();
		$entries[] = array( 'wp-content/uploads/bad.txt', 'payload', 'mangle' );
		$r         = new FSC_Daf_Reader( $this->daf( $entries ) );
		$c         = FSC_Daf_Reader::new_cursor();
		do {
			$e = $r->next_entry( $c );
		} while ( 'wp-content/uploads/bad.txt' !== $e['name'] );
		$this->expectException( FSC_Exception::class );
		$this->expectExceptionMessage( 'CRC mismatch' );
		$r->read( $c, 100 );
	}

	public function test_daf_source_meta_files_and_sql() {
		$file = $this->daf( null, 500 );
		$this->assertTrue( FSC_Source_Duplicator::detect( $file ) );
		$this->assertFalse( FSC_Source_Wpress::detect( $file ) );
		$src = new FSC_Source_Duplicator( $file );
		$m   = $src->meta();
		$this->assertSame( 'duplicator', $m['format'] );
		$this->assertSame( 'http://old.test/home', $m['home'] );
		$this->assertSame( 'http://old.test', $m['siteurl'] );
		$this->assertSame( '/var/www/old', $m['abspath'] );
		$this->assertSame( '/var/www/old/wp-content', $m['content_dir'] );
		$this->assertSame( 'http://old.test/wp-content/uploads', $m['uploads_url'] );
		$this->assertSame( 'wp_', $m['prefix'] );
		$this->assertSame( 'wp_', $m['sql_prefix'] );
		$this->assertFalse( $m['multisite'] );
		$this->assertSame( '6.6', $m['wp_version'] );
		$this->assertStringContainsString( '.daf', $m['format_name'] );
		$this->assertNotEmpty( $m['warnings'] );

		// Files: fresh instance and JSON cursor per call, like separate requests.
		$c     = array();
		$files = array();
		$dirs  = array();
		$cur   = null;
		for ( $i = 0; $i < 10000; $i++ ) {
			$src = new FSC_Source_Duplicator( $file );
			if ( null === $cur ) {
				$e = $src->files_next( $c );
				if ( null === $e ) {
					break;
				}
				if ( 'dir' === $e['type'] ) {
					$dirs[] = $e['path'];
				} else {
					$cur             = $e['path'];
					$files[ $cur ]   = '';
				}
			} else {
				$buf = $src->files_read( $c, 1234 );
				if ( '' === $buf ) {
					$cur = null;
				} else {
					$files[ $cur ] .= $buf;
				}
			}
			$c = self::roundtrip( $c );
		}
		$this->assertSame( array( 'uploads/2026' ), $dirs );
		$this->assertSame( array( 'index.php', 'uploads/2026/big.bin', 'uploads/empty.txt' ), array_keys( $files ) );
		$this->assertSame( str_repeat( "0123456789abcdef\x00\xff", 3000 ), $files['uploads/2026/big.bin'] );
		$this->assertSame( 1.0, $src->files_progress( $c ) );

		// SQL: copied and gunzipped in several calls.
		$dest   = $this->dir . '/tmp.sql';
		$cursor = array();
		$calls  = 0;
		do {
			$src    = new FSC_Source_Duplicator( $file );
			$done   = $src->extract_sql( $dest, $cursor, 0 );
			$cursor = self::roundtrip( $cursor );
			++$calls;
		} while ( ! $done && $calls < 50 );
		$this->assertTrue( $done );
		$this->assertSame( self::DUMP, file_get_contents( $dest ) );
		$this->assertFileDoesNotExist( $dest . '.gz' );
	}

	public function test_gunzip_resumes_in_chunks() {
		$data = random_bytes( 3 * FSC_Source_Util::GZ_CHUNK + 12345 );
		$gz   = $this->dir . '/x.gz';
		file_put_contents( $gz, gzencode( $data, 1 ) );
		$this->assertSame( strlen( $data ), FSC_Source_Util::gz_isize( $gz ) );
		$c     = array( 'out' => 0 );
		$calls = 0;
		while ( ! FSC_Source_Util::gunzip_step( $gz, $this->dir . '/x', $c, 0 ) ) {
			++$calls;
		}
		$this->assertGreaterThanOrEqual( 3, $calls );
		$this->assertSame( sha1( $data ), sha1_file( $this->dir . '/x' ) );
	}

	public function test_refusals() {
		$cases = array(
			array( self::entries( self::archive_txt( array( 'secure_on' => 1 ) ) ), 'password protected' ),
			array( self::entries( null, "SELECT 1;\n" ), 'incomplete' ),
		);
		foreach ( $cases as $case ) {
			try {
				$file = $this->daf( $case[0] );
				$src  = new FSC_Source_Duplicator( $file );
				$src->meta();
				$c = array();
				while ( ! $src->extract_sql( $this->dir . '/r.sql', $c, microtime( true ) + 5 ) ) {
					continue;
				}
				$this->fail( 'expected refusal: ' . $case[1] );
			} catch ( FSC_Exception $e ) {
				$this->assertStringContainsString( $case[1], $e->getMessage() );
			}
		}
		try {
			( new FSC_Source_Duplicator( $this->daf( null, 1000, 1, '$6$rounds=50000$salt$hash' ) ) )->meta();
			$this->fail( 'expected refusal of an encrypted archive' );
		} catch ( FSC_Exception $e ) {
			$this->assertStringContainsString( 'encrypted', $e->getMessage() );
		}
		$this->assertTrue( ( new FSC_Source_Duplicator( $this->daf( self::entries( self::archive_txt( array( 'mu_mode' => 1 ) ) ) ) ) )->meta()['multisite'] );

		file_put_contents( $this->dir . '/not.daf', 'PK' . str_repeat( 'x', 100 ) );
		$this->assertFalse( FSC_Source_Duplicator::detect( $this->dir . '/not.daf' ) );
	}

	/**
	 * A single .daf record with an explicit file size and one data block whose
	 * declared original size may differ from it (crafted bomb).
	 */
	private static function daf_with_block( $file, $name, $fs, $orig, $flags = 1 ) {
		$data   = str_repeat( "\0", $orig );
		$stored = ( $flags & 1 ) ? gzdeflate( $data, 2 ) : $data;
		$rec    = '<F><FS>' . $fs . '</FS><MT>0</MT><P>0644</P><X>' . pack( 'v', $flags ) . '</X><HA></HA><RPL>' . strlen( $name ) . '</RPL><RP>' . $name . '</RP></F>';
		$rec   .= '<G><OS>' . $orig . '</OS><SS>' . strlen( $stored ) . '</SS><HA></HA></G>' . $stored;
		file_put_contents( $file, '<A><V>5.0.1</V><X>' . pack( 'v', $flags ) . '</X></A>' . $rec );
	}

	public function test_daf_block_declared_size_is_bounded() {
		// A block whose original size is above MAX_GLOB is rejected outright.
		$big = $this->dir . '/big.daf';
		self::daf_with_block( $big, 'wp-content/uploads/a.bin', FSC_Daf_Reader::MAX_GLOB + 1, FSC_Daf_Reader::MAX_GLOB + 1 );
		$r = new FSC_Daf_Reader( $big );
		$c = FSC_Daf_Reader::new_cursor();
		$r->next_entry( $c );
		try {
			$r->read( $c, 1 << 20 );
			$this->fail( 'oversized data block accepted' );
		} catch ( FSC_Exception $e ) {
			$this->assertStringContainsString( 'data block sizes', $e->getMessage() );
		}

		// A block that claims far more data than the file holds is refused
		// before it is read or inflated, so a 10-byte record cannot cost much.
		$bomb = $this->dir . '/bomb.daf';
		self::daf_with_block( $bomb, 'wp-content/uploads/b.bin', 10, 2 * 1024 * 1024 );
		$before = memory_get_usage();
		$r      = new FSC_Daf_Reader( $bomb );
		$c      = FSC_Daf_Reader::new_cursor();
		$r->next_entry( $c );
		try {
			$r->read( $c, 1 << 20 );
			$this->fail( 'block larger than the file accepted' );
		} catch ( FSC_Exception $e ) {
			$this->assertStringContainsString( 'larger than the file', $e->getMessage() );
		}
		$this->assertLessThan( 1024 * 1024, memory_get_usage() - $before, 'the oversized block must not be inflated' );
	}

	public function test_extraction_is_bounded_by_the_archive_budget() {
		// Highly compressible content that expands past max( 16 MiB, 100x size ).
		$entries = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$entries[] = array( 'wp-content/uploads/z' . $i . '.bin', str_repeat( "\0", 1 << 20 ) );
		}
		$file = $this->dir . '/budget.daf';
		self::build_daf( $file, $entries, 1 << 20, 1 );
		$this->assertLessThan( 1 << 20, filesize( $file ), 'the crafted bomb must stay small on disk' );

		$target = $this->dir . '/stage';
		mkdir( $target );
		$cursor = FSC_Extractor::new_cursor();
		$steps  = 0;
		try {
			do {
				$x      = new FSC_Extractor( $target, new FSC_Source_Duplicator( $file ) );
				$cursor = self::roundtrip( $cursor );
				$done   = $x->step( $cursor, 0 );
			} while ( ! $done && ++$steps < 1000 );
			$this->fail( 'the decompression bomb was extracted in full' );
		} catch ( FSC_Exception $e ) {
			$this->assertStringContainsString( 'expands to more than', $e->getMessage() );
		}
		$this->assertLessThan( 20 << 20, $cursor['bytes'], 'extraction must stop near the budget, not write every entry' );
	}

	public function test_zip_large_entry_crc_is_checked() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ext-zip is not available' );
		}
		$file = $this->dir . '/corrupt.zip';
		$big  = str_repeat( 'x', 6 * 1024 * 1024 ); // Over 4 MiB: read through the stream, not getFromIndex().
		$z    = new ZipArchive();
		$this->assertTrue( $z->open( $file, ZipArchive::CREATE ) );
		$z->addFromString( 'wp-content/big.bin', $big );
		$z->setCompressionName( 'wp-content/big.bin', ZipArchive::CM_STORE );
		$z->close();
		// Clean archive extracts: the running CRC matches the stored one.
		$ok = $this->dir . '/ok';
		mkdir( $ok );
		$cursor = FSC_Extractor::new_cursor();
		$this->assertTrue( ( new FSC_Extractor( $ok, new FSC_Source_Duplicator( $file ) ) )->step( $cursor, microtime( true ) + 10 ) );
		$this->assertSame( $big, file_get_contents( $ok . '/big.bin' ) );

		// Flip one stored data byte: the content no longer matches the CRC.
		$raw         = file_get_contents( $file );
		$pos         = strpos( $raw, str_repeat( 'x', 4096 ) ) + 1000000;
		$raw[ $pos ] = 'y';
		file_put_contents( $file, $raw );

		$target = $this->dir . '/stage';
		mkdir( $target );
		try {
			$cursor = FSC_Extractor::new_cursor();
			( new FSC_Extractor( $target, new FSC_Source_Duplicator( $file ) ) )->step( $cursor, microtime( true ) + 10 );
			$this->fail( 'a >4 MiB entry with a wrong CRC extracted without error' );
		} catch ( FSC_Exception $e ) {
			$this->assertStringContainsString( 'corrupt or truncated', $e->getMessage() );
		}
	}

	public function test_content_path_mapping() {
		$this->assertSame( 'uploads/2026/a.png', FSC_Source_Duplicator::content_path( 'wp-content/uploads/2026/a.png' ) );
		$this->assertSame( 'plugins/akismet', FSC_Source_Duplicator::content_path( 'wp-content/plugins/akismet/' ) );
		$this->assertSame( 'index.php', FSC_Source_Duplicator::content_path( './wp-content/index.php' ) );
		foreach ( array( 'wp-content/', 'wp-config.php', 'wp-admin/index.php', 'dup-installer/main.installer.php', 'wp-content/duplicator-backups/a.zip', 'wp-content/backups-dup-lite/x', 'wp-contentx/a', 'x/wp-content/a' ) as $n ) {
			$this->assertNull( FSC_Source_Duplicator::content_path( $n ), $n );
		}
		$this->assertSame( 'meta', FSC_Source_Duplicator::classify( 'dup-installer/dup_descriptors_cbfcbb9-30144657/archive.txt' ) );
		$this->assertSame( 'dump_gz', FSC_Source_Duplicator::classify( 'dup-installer/dup_descriptors_cbfcbb9-30144657/db_dumps/20260930144657-dump.sql.gz' ) );
		$this->assertSame( 'dump_sql', FSC_Source_Duplicator::classify( 'dup-installer/dup-database__abc.sql' ) );
		$this->assertNull( FSC_Source_Duplicator::classify( 'wp-content/dup-installer/dup_descriptors_x/archive.txt' ) );
	}

	public function test_zip_source() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$file = $this->dir . '/site_archive.zip';
			file_put_contents( $file, "PK\x03\x04" . str_repeat( "\0", 100 ) );
			$this->assertTrue( FSC_Source_Duplicator::detect( $file ) );
			try {
				( new FSC_Source_Duplicator( $file ) )->meta();
				$this->fail( 'expected a ZipArchive error' );
			} catch ( FSC_Exception $e ) {
				$this->assertStringContainsString( 'PHP zip extension', $e->getMessage() );
			}
			return;
		}
		$file = $this->dir . '/site_archive.zip';
		$z    = new ZipArchive();
		$this->assertTrue( $z->open( $file, ZipArchive::CREATE ) );
		foreach ( self::entries() as $e ) {
			if ( null === $e[1] ) {
				$z->addEmptyDir( rtrim( $e[0], '/' ) );
			} else {
				$z->addFromString( $e[0], $e[1] );
			}
		}
		$z->addFromString( 'wp-content/uploads/link', 'target' );
		$z->setExternalAttributesName( 'wp-content/uploads/link', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
		$z->close();

		$this->assertTrue( FSC_Source_Duplicator::detect( $file ) );
		$plain = $this->dir . '/other.zip';
		$z     = new ZipArchive();
		$z->open( $plain, ZipArchive::CREATE );
		$z->addFromString( 'readme.txt', 'x' );
		$z->close();
		$this->assertFalse( FSC_Source_Duplicator::detect( $plain ) );

		$m = ( new FSC_Source_Duplicator( $file ) )->meta();
		$this->assertStringContainsString( '.zip', $m['format_name'] );
		$this->assertSame( 'http://old.test/home', $m['home'] );

		$c     = array();
		$seen  = array();
		$data  = '';
		for ( $i = 0; $i < 1000; $i++ ) {
			$src = new FSC_Source_Duplicator( $file );
			$e   = $src->files_next( $c );
			if ( null === $e ) {
				break;
			}
			$seen[ $e['path'] ] = $e['type'];
			if ( 'uploads/2026/big.bin' === $e['path'] ) {
				while ( true ) {
					$c   = self::roundtrip( $c );
					$src = new FSC_Source_Duplicator( $file );
					$buf = $src->files_read( $c, 4000 );
					if ( '' === $buf ) {
						break;
					}
					$data .= $buf;
				}
			}
			$c = self::roundtrip( $c );
		}
		$this->assertSame( str_repeat( "0123456789abcdef\x00\xff", 3000 ), $data );
		$this->assertSame( 'symlink', $seen['uploads/link'] );
		$this->assertSame( 'file', $seen['index.php'] );
		$this->assertSame( 'dir', $seen['uploads/2026'] );
		$this->assertArrayNotHasKey( 'duplicator-backups/x_archive.daf', $seen );

		$dest   = $this->dir . '/zip.sql';
		$cursor = array();
		$src    = new FSC_Source_Duplicator( $file );
		while ( ! $src->extract_sql( $dest, $cursor, 0 ) ) {
			$cursor = self::roundtrip( $cursor );
			$src    = new FSC_Source_Duplicator( $file );
		}
		$this->assertSame( self::DUMP, file_get_contents( $dest ) );
	}
}
