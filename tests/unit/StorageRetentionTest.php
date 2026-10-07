<?php
/**
 * Storage modes, configured base validation, retention and stale-file purge.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_Storage
 * @covers FSC_Source_Wpress
 */
class StorageRetentionTest extends FSC_Test_Case {

	private static function mode( $path ) {
		clearstatcache( true, $path );
		return fileperms( $path ) & 0777;
	}

	private function storage() {
		$st = new FSC_Storage( $this->dir . '/fsc-storage' );
		$st->ensure();
		return $st;
	}

	public function test_ensure_creates_private_modes() {
		$st = $this->storage();
		$this->assertSame( 0700, self::mode( $st->base_dir() ) );
		$this->assertSame( 0700, self::mode( $st->dir() ) );
		$this->assertSame( 0700, self::mode( $st->tmp_dir() ) );
		foreach ( array( $st->base_dir(), $st->dir(), $st->tmp_dir() ) as $d ) {
			foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $f ) {
				$this->assertSame( 0600, self::mode( $d . '/' . $f ), $d . '/' . $f );
			}
		}
		$info = $st->info();
		$this->assertSame( '0700', $info['dir_mode'] );
		$this->assertSame( '0600', $info['file_mode'] );
		$this->assertFalse( $info['open_modes'] );
		$this->assertFalse( $info['custom'] );
		$this->assertNull( $info['error'] );
	}

	public function test_harden_fixes_existing_modes_and_skips_symlinks() {
		$st = $this->storage();
		file_put_contents( $st->dir() . '/site.tar', 'x' );
		chmod( $st->dir() . '/site.tar', 0644 );
		file_put_contents( $st->tmp_dir() . '/0123456789abcdef.sql', 'x' );
		chmod( $st->tmp_dir() . '/0123456789abcdef.sql', 0664 );
		chmod( $st->dir(), 0755 );
		file_put_contents( $this->dir . '/outside.txt', 'x' );
		chmod( $this->dir . '/outside.txt', 0644 );
		symlink( $this->dir . '/outside.txt', $st->dir() . '/link.tar' );
		$st->harden();
		$this->assertSame( 0700, self::mode( $st->dir() ) );
		$this->assertSame( 0600, self::mode( $st->dir() . '/site.tar' ) );
		$this->assertSame( 0600, self::mode( $st->tmp_dir() . '/0123456789abcdef.sql' ) );
		$this->assertSame( 0644, self::mode( $this->dir . '/outside.txt' ) );
	}

	public function test_set_modes_are_applied() {
		$st = new FSC_Storage( $this->dir . '/fsc-storage' );
		$st->set_modes( 0755, 0644 );
		$st->ensure();
		$this->assertSame( 0755, self::mode( $st->dir() ) );
		$this->assertSame( 0644, self::mode( $st->dir() . '/index.php' ) );
		$info = $st->info();
		$this->assertTrue( $info['open_modes'] );
	}

	public function test_upload_part_and_archive_are_private() {
		$st    = $this->storage();
		$chunk = $this->dir . '/chunk';
		file_put_contents( $chunk, 'abc' );
		$res = $st->upload_chunk( 'site.tar', 0, $chunk, 6 );
		$this->assertFalse( $res['complete'] );
		$this->assertSame( 0600, self::mode( $st->dir() . '/site.tar.part' ) );
		$res = $st->upload_chunk( 'site.tar', 3, $chunk, 6 );
		$this->assertTrue( $res['complete'] );
		$this->assertSame( 0600, self::mode( $st->dir() . '/site.tar' ) );
	}

	public function test_check_base() {
		mkdir( $this->dir . '/real' );
		file_put_contents( $this->dir . '/file', 'x' );
		symlink( $this->dir . '/real', $this->dir . '/link' );
		$this->assertNull( FSC_Storage::check_base( $this->dir . '/real' ) );
		$this->assertNull( FSC_Storage::check_base( $this->dir . '/real/' ) );
		$this->assertNull( FSC_Storage::check_base( $this->dir . '/new' ) );
		$this->assertNotNull( FSC_Storage::check_base( '' ) );
		$this->assertNotNull( FSC_Storage::check_base( '/' ) );
		$this->assertNotNull( FSC_Storage::check_base( 'relative/dir' ) );
		$this->assertNotNull( FSC_Storage::check_base( $this->dir . '/real/../real' ) );
		$this->assertNotNull( FSC_Storage::check_base( $this->dir . '/./real' ) );
		$this->assertNotNull( FSC_Storage::check_base( $this->dir . '/file' ) );
		$this->assertNotNull( FSC_Storage::check_base( $this->dir . '/link' ) );
		$this->assertNotNull( FSC_Storage::check_base( $this->dir . '/missing/new' ) );
		$this->assertNotNull( FSC_Storage::check_base( array( '/x' ) ) );
	}

	public function test_old_archives_and_purge() {
		$st  = $this->storage();
		$now = 2000000000;
		foreach ( array( 'a.tar' => 10, 'b.tar' => 3, 'c.wpress' => 10 ) as $name => $days ) {
			file_put_contents( $st->dir() . '/' . $name, 'x' );
			touch( $st->dir() . '/' . $name, $now - $days * 86400 );
		}
		$old = array_map(
			function ( $a ) {
				return $a['name'];
			},
			$st->old_archives( 7, $now )
		);
		sort( $old );
		$this->assertSame( array( 'a.tar', 'c.wpress' ), $old );
		$this->assertSame( array(), $st->old_archives( 0, $now ) );
		$this->assertSame( array(), $st->purge_archives( 0, array(), $now ) );
		$this->assertSame( array( 'a.tar' ), $st->purge_archives( 7, array( 'c.wpress' ), $now ) );
		$this->assertFileExists( $st->dir() . '/b.tar' );
		$this->assertFileExists( $st->dir() . '/c.wpress' );
		$this->assertFileDoesNotExist( $st->dir() . '/a.tar' );
	}

	public function test_delete_all_keeps_names_in_use() {
		$st = $this->storage();
		foreach ( array( 'a.tar', 'b.tar', 'c.zip' ) as $name ) {
			file_put_contents( $st->dir() . '/' . $name, 'x' );
		}
		file_put_contents( $st->dir() . '/up.tar.part', 'x' );
		$res = $st->delete_all( array( 'b.tar' ) );
		sort( $res['deleted'] );
		$this->assertSame( array( 'a.tar', 'c.zip' ), $res['deleted'] );
		$this->assertSame( array( 'b.tar' ), $res['kept'] );
		$this->assertFileExists( $st->dir() . '/b.tar' );
		$this->assertFileExists( $st->dir() . '/up.tar.part' );
		$this->assertFileExists( $st->dir() . '/index.php' );
	}

	public function test_purge_stale_files() {
		$st  = $this->storage();
		$now = 2000000000;
		$old = $now - 25 * 3600;
		$new = $now - 3600;
		$files = array(
			'x.tar.part'                    => $old,
			'y.tar.part'                    => $old,
			'z.tar.part'                    => $new,
			'old.tar'                       => $old,
			'tmp/aaaaaaaaaaaaaaaa.sql'      => $old,
			'tmp/aaaaaaaaaaaaaaaa.srj'      => $old,
			'tmp/bbbbbbbbbbbbbbbb.sql'      => $old,
			'tmp/cccccccccccccccc.list'     => $new,
			'tmp/job.json'                  => $old,
			'tmp/job.json.log'              => $old,
			'tmp/job.json.log.1'            => $old,
			'tmp/job.json.lock'             => $old,
			'tmp/job.json.0badc0de.tmp'     => $old,
			'tmp/notes.txt'                 => $old,
		);
		foreach ( $files as $rel => $mtime ) {
			file_put_contents( $st->dir() . '/' . $rel, 'x' );
			touch( $st->dir() . '/' . $rel, $mtime );
		}
		touch( $st->dir() . '/index.php', $old );
		$deleted = $st->purge_stale( 86400, 'bbbbbbbbbbbbbbbb', array( 'y.tar' ), $now );
		sort( $deleted );
		$this->assertSame(
			array( 'tmp/aaaaaaaaaaaaaaaa.sql', 'tmp/aaaaaaaaaaaaaaaa.srj', 'tmp/job.json.0badc0de.tmp', 'x.tar.part' ),
			$deleted
		);
		foreach ( array( 'y.tar.part', 'z.tar.part', 'old.tar', 'index.php', 'tmp/bbbbbbbbbbbbbbbb.sql', 'tmp/cccccccccccccccc.list', 'tmp/job.json', 'tmp/job.json.log', 'tmp/job.json.log.1', 'tmp/job.json.lock', 'tmp/notes.txt' ) as $kept ) {
			$this->assertFileExists( $st->dir() . '/' . $kept );
		}
	}

	public function test_purge_without_private_dir_is_a_noop() {
		$st = new FSC_Storage( $this->dir . '/none' );
		$this->assertSame( array(), $st->purge_stale( 0 ) );
		$this->assertSame( array(), $st->purge_archives( 1 ) );
		$st->harden();
		$this->assertDirectoryDoesNotExist( $this->dir . '/none' );
	}

	/**
	 * A non-string Database.Prefix in package.json must not become "Array".
	 */
	public function test_wpress_non_string_prefix_is_ignored() {
		foreach ( array( array( 'x' ), 123, true ) as $bad ) {
			$file = $this->dir . '/site.wpress';
			$pkg  = json_encode(
				array(
					'SiteURL'  => 'http://old.test',
					'HomeURL'  => 'http://old.test',
					'Database' => array( 'Prefix' => $bad ),
				)
			);
			$out  = '';
			foreach ( array( array( '.', 'package.json', $pkg ), array( '.', 'database.sql', "SELECT 1;\n" ) ) as $e ) {
				$out .= pack( 'a255a14a12a4088a8', $e[1], (string) strlen( $e[2] ), '1790779610', $e[0], sprintf( '%08x', crc32( $e[2] ) ) ) . $e[2];
			}
			$out .= pack( 'a255a14a4100a8', '', (string) strlen( $out ), '', sprintf( '%08x', crc32( $out ) ) );
			file_put_contents( $file, $out );
			$src  = new FSC_Source_Wpress( $file );
			$meta = $src->meta();
			$this->assertSame( '', $meta['prefix'], var_export( $bad, true ) );
			$this->assertNotEmpty( $meta['warnings'] );
		}
	}
}
