<?php
/**
 * .wpress source tests with synthetic archives.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_Source_Wpress
 * @covers FSC_Source_Util
 */
class WpressSourceTest extends FSC_Test_Case {

	/**
	 * One 4377-byte header.
	 */
	private static function header( $name, $size, $mtime, $dir, $crc ) {
		return pack( 'a255a14a12a4088a8', $name, (string) $size, (string) $mtime, $dir, $crc );
	}

	/**
	 * Build a .wpress file.
	 *
	 * @param string $file    Target.
	 * @param array  $entries List of array( dir, name, data [, crc override] ).
	 * @param int    $eof     1 or 2.
	 */
	private static function build( $file, array $entries, $eof = 2 ) {
		$out = '';
		foreach ( $entries as $e ) {
			$crc  = isset( $e[3] ) ? $e[3] : sprintf( '%08x', crc32( $e[2] ) );
			$out .= self::header( $e[1], strlen( $e[2] ), 1790779610, $e[0], $crc ) . $e[2];
		}
		if ( 1 === $eof ) {
			$out .= str_repeat( "\0", FSC_Source_Wpress::HEADER );
		} else {
			$out .= pack( 'a255a14a4100a8', '', (string) strlen( $out ), '', sprintf( '%08x', crc32( $out ) ) );
		}
		file_put_contents( $file, $out );
	}

	private static function package( array $extra = array() ) {
		return json_encode(
			array_merge(
				array(
					'SiteURL'   => 'http://old.test',
					'HomeURL'   => 'http://old.test/blog',
					'Plugin'    => array( 'Version' => '7.111' ),
					'WordPress' => array(
						'Version'    => '6.6',
						'Absolute'   => '/var/www/old/',
						'Content'    => '/var/www/old/wp-content',
						'UploadsURL' => 'http://old.test/wp-content/uploads/',
					),
					'Database'  => array( 'Prefix' => 'abc_' ),
				),
				$extra
			)
		);
	}

	private function sample( $eof = 2, array $extra = array(), array $more = array() ) {
		$file = $this->dir . '/site.wpress';
		$big  = str_repeat( 'The quick brown fox. ', 5000 );
		self::build(
			$file,
			array_merge(
				array(
					array( '.', 'package.json', self::package( $extra ) ),
					array( '.', 'index.php', "<?php\n// Silence is golden.\n" ),
					array( 'uploads/2026/09', 'a.txt', 'hello' ),
					array( 'uploads', 'empty.txt', '' ),
					array( 'ai1wm-backups', 'old.wpress', 'x' ),
					array( 'plugins/all-in-one-wp-migration/storage', 'log.txt', 'y' ),
					array( 'uploads/big', 'big.txt', $big ),
				),
				$more,
				array( array( '.', 'database.sql', "DROP TABLE IF EXISTS `SERVMASK_PREFIX_options`;\n" ) )
			),
			$eof
		);
		return $file;
	}

	private static function roundtrip( array $c ) {
		return json_decode( json_encode( $c ), true );
	}

	public function test_parse_header_fields_and_path_mapping() {
		$h = FSC_Source_Wpress::parse_header( self::header( 'a.txt', 1220, 1790779610, 'uploads/2026/09', '2495be5b' ) );
		$this->assertSame( 'file', $h['type'] );
		$this->assertSame( 'a.txt', $h['name'] );
		$this->assertSame( 1220, $h['size'] );
		$this->assertSame( 1790779610, $h['mtime'] );
		$this->assertSame( 'uploads/2026/09/a.txt', $h['rel'] );
		$this->assertSame( '2495be5b', $h['crc'] );
		$this->assertFalse( $h['root'] );

		$h = FSC_Source_Wpress::parse_header( self::header( 'package.json', 5, 1, '.', '' ) );
		$this->assertSame( 'package.json', $h['rel'] );
		$this->assertTrue( $h['root'] );
		$this->assertSame( '', $h['crc'] );

		// Garbage after the NUL padding of the size field is rejected.
		$bad = self::header( 'a.txt', 12, 1, '.', '' );
		$bad[ 255 + 5 ] = 'x';
		try {
			FSC_Source_Wpress::parse_header( $bad );
			$this->fail( 'expected exception' );
		} catch ( FSC_Exception $e ) {
			$this->assertStringContainsString( 'invalid entry header', $e->getMessage() );
		}
		// Non-numeric size.
		$this->expectException( FSC_Exception::class );
		FSC_Source_Wpress::parse_header( self::header( 'a.txt', 'abc', 1, '.', '' ) );
	}

	public function test_both_end_block_variants() {
		$v1 = FSC_Source_Wpress::parse_header( str_repeat( "\0", 4377 ) );
		$this->assertSame( array( 'eof', 1 ), array( $v1['type'], $v1['variant'] ) );
		$v2 = FSC_Source_Wpress::parse_header( pack( 'a255a14a4100a8', '', '77731182', '', '704a7374' ) );
		$this->assertSame( array( 'eof', 2, 77731182, '704a7374' ), array( $v2['type'], $v2['variant'], $v2['offset'], $v2['crc'] ) );

		foreach ( array( 1, 2 ) as $variant ) {
			$file = $this->sample( $variant );
			$src  = new FSC_Source_Wpress( $file );
			$this->assertSame( 'http://old.test/blog', $src->meta()['home'] );
			$c     = array();
			$paths = array();
			while ( null !== ( $e = $src->files_next( $c ) ) ) {
				$paths[] = $e['path'];
			}
			$this->assertSame( array( 'index.php', 'uploads/2026/09/a.txt', 'uploads/empty.txt', 'uploads/big/big.txt' ), $paths, "variant $variant" );
			$this->assertSame( 1.0, $src->files_progress( $c ) );
		}
	}

	public function test_detect() {
		$file = $this->sample();
		$this->assertTrue( FSC_Source_Wpress::detect( $file ) );
		$this->assertFalse( FSC_Source_Native::detect( $file ) );
		$this->assertFalse( FSC_Source_Duplicator::detect( $file ) );
		copy( $file, $this->dir . '/site.tar' );
		$this->assertFalse( FSC_Source_Wpress::detect( $this->dir . '/site.tar' ) );
		file_put_contents( $this->dir . '/junk.wpress', str_repeat( 'junk', 2000 ) );
		$this->assertFalse( FSC_Source_Wpress::detect( $this->dir . '/junk.wpress' ) );
		file_put_contents( $this->dir . '/short.wpress', 'abc' );
		$this->assertFalse( FSC_Source_Wpress::detect( $this->dir . '/short.wpress' ) );
	}

	public function test_meta() {
		$m = ( new FSC_Source_Wpress( $this->sample() ) )->meta();
		$this->assertSame( 'wpress', $m['format'] );
		$this->assertSame( 'http://old.test', $m['siteurl'] );
		$this->assertSame( '/var/www/old/', $m['abspath'] );
		$this->assertSame( '/var/www/old/wp-content', $m['content_dir'] );
		$this->assertSame( 'http://old.test/wp-content/uploads/', $m['uploads_url'] );
		$this->assertSame( 'abc_', $m['prefix'] );
		$this->assertSame( 'SERVMASK_PREFIX_', $m['sql_prefix'] );
		$this->assertSame( 'SERVMASK_PREFIX_', $m['key_placeholder'] );
		$this->assertFalse( $m['multisite'] );
		$this->assertSame( '6.6', $m['wp_version'] );
		$this->assertStringContainsString( '7.111', $m['format_name'] );

		$this->assertSame( array(), $m['active_plugins'] );

		$m = ( new FSC_Source_Wpress( $this->sample( 2, array( 'InternalSiteURL' => 'http://internal.test', 'Plugins' => array( 'classic-editor/classic-editor.php', 7 ), 'Template' => 'parent', 'Stylesheet' => 'child' ) ) ) )->meta();
		$this->assertSame( 'http://internal.test', $m['siteurl'] );
		$this->assertSame( array( 'classic-editor/classic-editor.php' ), $m['active_plugins'] );
		$this->assertSame( array( 'template' => 'parent', 'stylesheet' => 'child' ), $m['theme'] );

		$ms = $this->sample( 2, array(), array() );
		self::build(
			$ms,
			array(
				array( '.', 'package.json', self::package() ),
				array( '.', 'multisite.json', '{"Network":true}' ),
				array( '.', 'database.sql', '' ),
			)
		);
		$this->assertTrue( ( new FSC_Source_Wpress( $ms ) )->meta()['multisite'] );
	}

	public function test_refuses_encrypted_compressed_and_truncated() {
		$cases = array(
			array( array( 'Encrypted' => true, 'EncryptedSignature' => 'abc' ), 'encrypted' ),
			array( array( 'Compression' => array( 'Enabled' => true, 'Type' => 'gzip' ) ), 'compressed' ),
		);
		foreach ( $cases as $case ) {
			try {
				( new FSC_Source_Wpress( $this->sample( 2, $case[0] ) ) )->meta();
				$this->fail( 'expected refusal: ' . $case[1] );
			} catch ( FSC_Exception $e ) {
				$this->assertStringContainsString( $case[1], $e->getMessage() );
			}
		}
		// Compression present but disabled is fine.
		$m = ( new FSC_Source_Wpress( $this->sample( 2, array( 'Compression' => array( 'Enabled' => false ) ) ) ) )->meta();
		$this->assertSame( 'wpress', $m['format'] );

		$file = $this->sample();
		$data = file_get_contents( $file );
		file_put_contents( $file, substr( $data, 0, -5000 ) );
		try {
			( new FSC_Source_Wpress( $file ) )->meta();
			$this->fail( 'expected refusal of a truncated archive' );
		} catch ( FSC_Exception $e ) {
			$this->assertStringContainsString( 'incomplete', $e->getMessage() );
		}
	}

	public function test_resume_mid_entry_with_crc() {
		$file = $this->sample();
		$big  = str_repeat( 'The quick brown fox. ', 5000 );
		$c    = array();
		$src  = new FSC_Source_Wpress( $file );
		do {
			$e = $src->files_next( $c );
		} while ( 'uploads/big/big.txt' !== $e['path'] );
		$this->assertSame( strlen( $big ), $e['size'] );
		$got = $src->files_read( $c, 1000 );
		// Continue in "new requests": fresh instance, JSON cursor, odd read sizes.
		while ( true ) {
			$c   = self::roundtrip( $c );
			$src = new FSC_Source_Wpress( $file );
			$buf = $src->files_read( $c, 7777 );
			if ( '' === $buf ) {
				break;
			}
			$got .= $buf;
		}
		$this->assertSame( $big, $got );
		$this->assertNull( $src->files_next( $c ) );
	}

	public function test_crc_mismatch_is_detected() {
		$file = $this->dir . '/bad.wpress';
		self::build(
			$file,
			array(
				array( '.', 'package.json', self::package() ),
				array( 'uploads', 'x.txt', 'payload', 'deadbeef' ),
			)
		);
		$src = new FSC_Source_Wpress( $file );
		$c   = array();
		$src->files_next( $c );
		$this->expectException( FSC_Exception::class );
		$this->expectExceptionMessage( 'CRC mismatch in uploads/x.txt' );
		$src->files_read( $c, 3 );
		$src->files_read( $c, 100 );
	}

	public function test_extract_sql_resumable() {
		$sql  = str_repeat( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (1,'SERVMASK_PREFIX_user_roles','x');\n", 2000 );
		$file = $this->dir . '/db.wpress';
		self::build(
			$file,
			array(
				array( '.', 'package.json', self::package() ),
				array( 'uploads', 'a.txt', 'a' ),
				array( 'uploads', 'b.txt', 'b' ),
				array( '.', 'database.sql', $sql ),
			)
		);
		$dest   = $this->dir . '/out.sql';
		$cursor = array();
		$calls  = 0;
		do {
			$src    = new FSC_Source_Wpress( $file );
			$done   = $src->extract_sql( $dest, $cursor, 0 );
			$cursor = self::roundtrip( $cursor );
			++$calls;
		} while ( ! $done && $calls < 100 );
		$this->assertTrue( $done );
		$this->assertGreaterThan( 2, $calls );
		$this->assertSame( $sql, file_get_contents( $dest ) );
	}

	public function test_crc32_combine() {
		$a = random_bytes( 1000 );
		$b = random_bytes( 4099 );
		$this->assertSame( crc32( $a . $b ), FSC_Source_Util::crc32_combine( crc32( $a ), crc32( $b ), strlen( $b ) ) );
		$this->assertSame( crc32( $a ), FSC_Source_Util::crc32_combine( crc32( $a ), crc32( '' ), 0 ) );
	}

	public function test_excluded_paths() {
		foreach ( array( 'ai1wm-backups/x.wpress', 'duplicator-backups', 'backups-dup-lite/a/b', 'fsc-storage/x', 'cache/page.html', 'plugins/all-in-one-wp-migration/storage/x', 'uploads/backwpup-1a2b-backups/x.zip', 'Uploads/WP-Staging/Backups/a', 'wpvividbackups/x' ) as $p ) {
			$this->assertTrue( FSC_Source_Util::excluded( $p ), $p );
		}
		foreach ( array( 'uploads/cache/x.png', 'plugins/all-in-one-wp-migration/lib/x.php', 'themes/duplicator-backups-theme/x' ) as $p ) {
			$this->assertFalse( FSC_Source_Util::excluded( $p ), $p );
		}
	}
}
