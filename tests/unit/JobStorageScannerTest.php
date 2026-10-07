<?php
/**
 * Job runner, storage and file scanner tests.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_Job
 * @covers FSC_Storage
 * @covers FSC_File_Scanner
 */
class JobStorageScannerTest extends FSC_Test_Case {

	public function test_budget() {
		$this->assertSame( 20.0, FSC_Job::budget( null, 0 ) );
		$this->assertSame( 15.0, FSC_Job::budget( null, 30 ) );
		$this->assertSame( 20.0, FSC_Job::budget( null, 300 ) );
		$this->assertSame( 5.0, FSC_Job::budget( 5, 300 ) );
		$this->assertSame( 15.0, FSC_Job::budget( 50, 30 ) );
		$this->assertSame( 1.0, FSC_Job::budget( 0.1, 30 ) );
		$this->assertSame( 1.0, FSC_Job::budget( null, 1 ) );
	}

	public function test_create_save_load_and_log() {
		$path = $this->dir . '/job.json';
		$job  = FSC_Job::create( $path, 'export', array( 'archive' => 'a.tar' ) );
		$job->set( 'n', 3 );
		$job->log( 'first' );
		$job->log( 'second' );
		$job->save();
		$again = FSC_Job::load( $path );
		$this->assertSame( 'export', $again->data['type'] );
		$this->assertSame( 3, $again->get( 'n' ) );
		$this->assertSame( 'a.tar', $again->data['archive'] );
		$log = $again->log_since( 1 );
		$this->assertCount( 1, $log );
		$this->assertSame( 'second', $log[0]['msg'] );
		$this->assertCount( 0, glob( $this->dir . '/*.tmp' ) );
		file_put_contents( $path, '{corrupt' );
		$this->assertNull( FSC_Job::load( $path ) );
		$this->assertNull( FSC_Job::load( $this->dir . '/missing.json' ) );
	}

	public function test_lock_is_exclusive() {
		$path = $this->dir . '/job.json';
		$a    = FSC_Job::create( $path, 'export' );
		$b    = FSC_Job::load( $path );
		$this->assertTrue( $a->lock() );
		$this->assertFalse( $b->lock() );
		$a->unlock();
		$this->assertTrue( $b->lock() );
		$b->unlock();
	}

	public function test_run_loop_checkpoints_and_detects_crash() {
		$path = $this->dir . '/job.json';
		$job  = FSC_Job::create( $path, 'export' );
		$unit = function ( $job, $deadline, &$state ) {
			$job->set( 'n', $job->get( 'n', 0 ) + 1 );
			return $job->get( 'n' ) >= 5;
		};
		$this->assertTrue( $job->run( $unit, 5 ) );
		$this->assertSame( 'done', $job->data['status'] );
		$this->assertSame( 5, FSC_Job::load( $path )->get( 'n' ) );

		// Simulate a request that died inside a step.
		$job = FSC_Job::create( $path, 'import' );
		$job->data['in_step'] = true;
		$job->save();
		$seen = null;
		$job  = FSC_Job::load( $path );
		$job->run(
			function ( $job, $deadline, &$state ) use ( &$seen ) {
				if ( null === $seen ) {
					$seen = $state['resumed_after_crash'];
				}
				return true;
			},
			5
		);
		$this->assertTrue( $seen );
		$this->assertFalse( FSC_Job::load( $path )->data['in_step'] );
	}

	public function test_run_loop_catches_errors() {
		$job = FSC_Job::create( $this->dir . '/job.json', 'import' );
		$job->run(
			function () {
				throw new FSC_Exception( 'boom' );
			},
			5
		);
		$this->assertSame( 'error', $job->data['status'] );
		$this->assertSame( 'boom', $job->data['error'] );
	}

	public function test_storage_protection_and_names() {
		$s = new FSC_Storage( $this->dir . '/fsc-storage' );
		$s->ensure();
		$this->assertFileExists( $this->dir . '/fsc-storage/.htaccess' );
		$this->assertFileExists( $this->dir . '/fsc-storage/web.config' );
		$this->assertFileExists( $this->dir . '/fsc-storage/index.php' );
		$this->assertMatchesRegularExpression( '#/fsc-storage/private-[a-f0-9]{32}$#', $s->dir() );
		$this->assertFileExists( $s->dir() . '/.htaccess' );
		$this->assertFileExists( $s->dir() . '/index.php' );
		$this->assertFileExists( $s->dir() . '/tmp/.htaccess' );
		$this->assertFileExists( $s->dir() . '/tmp/index.php' );
		$this->assertSame( $s->dir() . '/tmp/job.json', $s->job_path() );
		$this->assertStringStartsWith( $s->dir() . '/tmp/', $s->tmp_file( 'abc123', 'sql' ) );
		$ht = file_get_contents( $this->dir . '/fsc-storage/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', $ht );
		$this->assertStringContainsString( 'Deny from all', $ht );

		$name = FSC_Storage::new_archive_name( 'www.Example.com:8080', 1700000000 );
		$this->assertMatchesRegularExpression( '/^www\.example\.com-8080-20231114-221320-[0-9a-f]{16}\.tar$/', $name );
		$this->assertTrue( FSC_Storage::is_valid_name( $name ) );
		foreach ( array( '../x.tar', 'a/b.tar', '.htaccess', 'x.php', 'x.tar.part', '', '..tar', "a\0.tar", 'shell.php.tar', 'x.PHTML.zip', 'a.phar.daf', 'b.php7.wpress', 'c.pl.tar', "a\\b.tar", str_repeat( 'a', 300 ) . '.tar' ) as $bad ) {
			$this->assertFalse( FSC_Storage::is_valid_name( $bad ), $bad );
		}

		file_put_contents( $this->dir . '/outside.tar', 'x' );
		symlink( $this->dir . '/outside.tar', $s->dir() . '/link.tar' );
		file_put_contents( $s->dir() . '/real.tar', 'x' );
		$this->assertNull( $s->resolve( 'link.tar' ) );
		$this->assertNull( $s->resolve( '../outside.tar' ) );
		$this->assertNotNull( $s->resolve( 'real.tar' ) );
		$names = array_column( $s->list_archives(), 'name' );
		$this->assertSame( array( 'real.tar' ), $names );
		$this->assertTrue( $s->delete( 'real.tar' ) );
		$this->assertFalse( $s->delete( 'link.tar' ) );
		$this->assertFileExists( $this->dir . '/outside.tar' );
	}

	public function test_chunked_upload() {
		$s    = new FSC_Storage( $this->dir . '/fsc-storage' );
		$data = random_bytes( 10000 );
		$tmp  = $this->dir . '/chunk';
		$pos  = 0;
		while ( $pos < strlen( $data ) ) {
			$piece = substr( $data, $pos, 3000 );
			file_put_contents( $tmp, $piece );
			$res  = $s->upload_chunk( 'up.wpress', $pos, $tmp, strlen( $data ) );
			$pos += strlen( $piece );
			$this->assertSame( $pos, $res['received'] );
		}
		$this->assertTrue( $res['complete'] );
		$this->assertSame( 'up.wpress', $res['name'] );
		$this->assertSame( $data, file_get_contents( $s->dir() . '/up.wpress' ) );
		$this->assertFileDoesNotExist( $this->dir . '/fsc-storage/up.wpress' );

		// Same name again: gets a suffix, never overwrites.
		file_put_contents( $tmp, 'abc' );
		$res = $s->upload_chunk( 'up.wpress', 0, $tmp, 3 );
		$this->assertSame( 'up-1.wpress', $res['name'] );

		// Offset mismatch is reported with the expected offset.
		file_put_contents( $tmp, 'abc' );
		$s->upload_chunk( 'x.zip', 0, $tmp, 10 );
		try {
			$s->upload_chunk( 'x.zip', 7, $tmp, 10 );
			$this->fail( 'expected exception' );
		} catch ( FSC_Exception $e ) {
			$this->assertSame( 409, $e->getCode() );
			$this->assertStringContainsString( 'expected 3', $e->getMessage() );
		}
		$this->expectException( FSC_Exception::class );
		$s->upload_chunk( '../evil.tar', 0, $tmp, 3 );
	}

	public function test_private_dir_is_random_found_again_and_not_created_by_reads() {
		$base = $this->dir . '/fsc-storage';
		$s    = new FSC_Storage( $base );
		// Reads never create anything (the unauthenticated import step only reads).
		$this->assertNull( $s->resolve( 'x.tar' ) );
		$this->assertSame( array(), $s->list_archives() );
		$this->assertFalse( is_file( $s->job_path() ) );
		$this->assertDirectoryDoesNotExist( $base );

		$s->ensure();
		$dir = $s->dir();
		$again = new FSC_Storage( $base );
		$this->assertSame( $dir, $again->dir() );
		$again->ensure();
		$this->assertCount( 1, glob( $base . '/private-*' ) );

		// A second private dir (creation race) loses to the first by name.
		$other = $base . '/private-' . str_repeat( 'f', 32 );
		mkdir( $other );
		$third = new FSC_Storage( $base );
		$this->assertSame( min( $dir, $other ), $third->dir() );

		// Nothing but deny files and the private dir in the web-visible base.
		$left = array_values( array_diff( scandir( $base ), array( '.', '..', '.htaccess', 'web.config', 'index.php' ) ) );
		foreach ( $left as $name ) {
			$this->assertMatchesRegularExpression( '/^private-[a-f0-9]{32}$/', $name );
		}
	}

	public function test_adopt_and_legacy_tmp_migration() {
		$base = $this->dir . '/fsc-storage';
		mkdir( $base . '/tmp', 0755, true );
		file_put_contents( $base . '/tmp/job.json', '{"id":"abc"}' );
		file_put_contents( $base . '/ftp-upload.wpress', 'w' );
		file_put_contents( $base . '/notes.txt', 'n' );
		symlink( $this->dir, $base . '/link.tar' );
		$s = new FSC_Storage( $base );
		$s->ensure();
		$this->assertDirectoryDoesNotExist( $base . '/tmp' );
		$this->assertSame( '{"id":"abc"}', file_get_contents( $s->job_path() ) );
		$this->assertSame( 1, $s->adopt() );
		$this->assertFileDoesNotExist( $base . '/ftp-upload.wpress' );
		$this->assertSame( 'w', file_get_contents( $s->dir() . '/ftp-upload.wpress' ) );
		$this->assertFileExists( $base . '/notes.txt' );
		$this->assertTrue( is_link( $base . '/link.tar' ) );
		$this->assertSame( array( 'ftp-upload.wpress' ), array_column( $s->list_archives(), 'name' ) );
	}

	public function test_upload_refuses_risky_names_before_writing() {
		$s   = new FSC_Storage( $this->dir . '/fsc-storage' );
		$tmp = $this->dir . '/chunk';
		file_put_contents( $tmp, '<?php echo 1;' );
		foreach ( array( 'shell.php', 'shell.php.part', 'shell.php.tar', 'x.tar.php', 'a/../b.tar' ) as $bad ) {
			try {
				$s->upload_chunk( $bad, 0, $tmp, 13 );
				$this->fail( 'accepted ' . $bad );
			} catch ( FSC_Exception $e ) {
				$this->assertStringContainsString( 'Invalid file name', $e->getMessage() );
			}
		}
		$this->assertDirectoryDoesNotExist( $this->dir . '/fsc-storage' );
	}

	public function test_scanner_resumable_with_excludes() {
		$root = $this->dir . '/wp-content';
		$want = array();
		foreach ( array( 'uploads/2024/01', 'plugins/p1/inc', 'themes/t', 'cache/x', 'fsc-storage', 'empty' ) as $d ) {
			mkdir( $root . '/' . $d, 0755, true );
		}
		for ( $i = 0; $i < 50; $i++ ) {
			$rel = 'uploads/2024/01/img-' . $i . ( 0 === $i % 10 ? ' with space & ü' : '' ) . '.jpg';
			file_put_contents( $root . '/' . $rel, str_repeat( 'x', $i ) );
			$want[ $rel ] = $i;
		}
		file_put_contents( $root . '/plugins/p1/inc/a.php', 'a' );
		$want['plugins/p1/inc/a.php'] = 1;
		file_put_contents( $root . '/cache/x/c.html', 'c' );
		file_put_contents( $root . '/fsc-storage/old.tar', 'o' );
		file_put_contents( $root . '/debug.log', 'd' );
		file_put_contents( $root . '/index.php', '<?php' );
		$want['index.php'] = 5;
		symlink( $root, $root . '/themes/t/loop' );

		$list   = $this->dir . '/list';
		$cursor = FSC_File_Scanner::new_cursor();
		$steps  = 0;
		do {
			$sc     = new FSC_File_Scanner( $root, $list, FSC_File_Scanner::default_excludes() );
			$cursor = json_decode( json_encode( $cursor ), true );
			$done   = $sc->step( $cursor, 0 );
			++$steps;
		} while ( ! $done && $steps < 100 );
		$this->assertTrue( $done );
		$this->assertGreaterThan( 3, $steps );

		$files = array();
		$dirs  = array();
		foreach ( file( $list ) as $line ) {
			$row = FSC_File_Scanner::parse_line( $line );
			if ( 'F' === $row['type'] ) {
				$files[ $row['path'] ] = $row['size'];
			} else {
				$dirs[] = $row['path'];
			}
		}
		ksort( $files );
		ksort( $want );
		$this->assertSame( $want, $files );
		$this->assertContains( 'empty', $dirs );
		$this->assertNotContains( 'cache', $dirs );
		$this->assertNotContains( 'themes/t/loop', $dirs );
		$this->assertSame( count( $want ), $cursor['files'] );
		$this->assertSame( array_sum( $want ), $cursor['bytes'] );
	}

	public function test_scanner_wildcard_excludes_and_symlink_policy() {
		$root = $this->dir . '/wp-content';
		foreach ( array( 'uploads/backwpup-abc-backups', 'uploads/backwpupx', 'uploads/2024', 'uploads/wp-staging/backups', 'uploads/wp-staging/keep', 'fsc-storage/private-x', 'plugins/wp-free-site-cloner/vendor', 'plugins/other', 'litespeed/css' ) as $d ) {
			mkdir( $root . '/' . $d, 0755, true );
		}
		file_put_contents( $root . '/uploads/backwpup-abc-backups/b.zip', 'b' );
		file_put_contents( $root . '/uploads/backwpupx/keep.txt', 'k' );
		file_put_contents( $root . '/uploads/2024/a.jpg', 'a' );
		file_put_contents( $root . '/uploads/wp-staging/backups/s.wpstg', 's' );
		file_put_contents( $root . '/uploads/wp-staging/keep/k.txt', 'k' );
		file_put_contents( $root . '/fsc-storage/private-x/db.sql', 'secret' );
		file_put_contents( $root . '/plugins/wp-free-site-cloner/vendor/v.php', 'v' );
		file_put_contents( $root . '/plugins/other/o.php', 'o' );
		file_put_contents( $root . '/litespeed/css/c.css', 'c' );
		mkdir( $this->dir . '/outside' );
		file_put_contents( $this->dir . '/outside/o.txt', 'outside' );
		symlink( $root . '/fsc-storage', $root . '/uploads/storage-link' );
		symlink( $root . '/fsc-storage/private-x/db.sql', $root . '/uploads/sql-link.sql' );
		symlink( $this->dir . '/outside', $root . '/uploads/ext' );
		symlink( $this->dir . '/missing', $root . '/uploads/broken' );

		$ex   = array_merge( FSC_File_Scanner::default_excludes(), array( 'plugins/wp-free-site-cloner' ) );
		$sc   = new FSC_File_Scanner( $root, $this->dir . '/list', $ex );
		$this->assertTrue( $sc->is_excluded( 'uploads/backwpup-abc-backups/b.zip' ) );
		$this->assertTrue( $sc->is_excluded( 'uploads/backwpup' ) );
		$this->assertFalse( $sc->is_excluded( 'uploads/backwpupx' ) );
		$this->assertFalse( $sc->is_excluded( 'backwpup-x' ) );
		$cursor = FSC_File_Scanner::new_cursor();
		$this->assertTrue( $sc->step( $cursor, microtime( true ) + 30 ) );
		$files = array();
		foreach ( file( $this->dir . '/list' ) as $line ) {
			$row = FSC_File_Scanner::parse_line( $line );
			if ( 'F' === $row['type'] ) {
				$files[] = $row['path'];
			}
		}
		sort( $files );
		$this->assertSame( array( 'plugins/other/o.php', 'uploads/2024/a.jpg', 'uploads/backwpupx/keep.txt', 'uploads/ext/o.txt', 'uploads/wp-staging/keep/k.txt' ), $files );
		$log = implode( "\n", $sc->messages );
		$this->assertStringContainsString( 'Skipped symlink to an excluded path: uploads/storage-link', $log );
		$this->assertStringContainsString( 'Skipped symlink to an excluded path: uploads/sql-link.sql', $log );
		$this->assertStringContainsString( 'Skipped broken symlink: uploads/broken', $log );
		$this->assertStringContainsString( 'Following symlink that points outside wp-content: uploads/ext', $log );
	}
}
