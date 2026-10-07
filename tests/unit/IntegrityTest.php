<?php
/**
 * Archive integrity: archive_size patch, checksum trailer, segment hashing,
 * truncation and 0.9.0 archives without a trailer.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_Integrity
 * @covers FSC_Source_Native
 * @covers FSC_Job
 */
class IntegrityTest extends FSC_Test_Case {

	/**
	 * Manifest in the exporter's key order.
	 *
	 * @param bool $with_size Include archive_size (0.9.1+).
	 * @return string
	 */
	private static function manifest_json( $with_size = true ) {
		$m = array(
			'format'         => 'fsc',
			'format_version' => 1,
		);
		if ( $with_size ) {
			$m['archive_size'] = FSC_Integrity::SIZE_PLACEHOLDER;
		}
		$m += array(
			'plugin_version' => 'test',
			'home'           => 'https://old.example',
			'siteurl'        => 'https://old.example',
			'table_prefix'   => 'wp_',
			'tables'         => array( '{{FSC_PREFIX}}options', '{{FSC_PREFIX}}posts' ),
			'file_count'     => 3,
			'sql_sha256'     => '',
		);
		return json_encode( $m, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Build a native archive the way the exporter does (resumable checksum phase simulated
	 * through a JSON round trip of the cursor after every segment).
	 *
	 * @param string $path    Archive path.
	 * @param int    $seg     Segment size.
	 * @param int    $payload Bytes of file content.
	 * @param bool   $trailer Write the 0.9.1 trailer (false: 0.9.0 layout).
	 * @return array Checksum cursor.
	 */
	private function build( $path, $seg = 4096, $payload = 20000, $trailer = true ) {
		$src = $this->dir . '/src';
		@mkdir( $src );
		file_put_contents( $src . '/db.sql', "CREATE TABLE `{{FSC_PREFIX}}options` (id int);\n" );
		file_put_contents( $src . '/a.bin', substr( str_repeat( hash( 'sha256', 'x', true ), (int) ceil( $payload / 32 ) ), 0, $payload ) );
		file_put_contents( $src . '/b.txt', 'hello' );

		$json = self::manifest_json( $trailer );
		$w    = new FSC_Tar_Writer( $path, 0 );
		$w->add_string( FSC_Source_Native::MANIFEST, $json, 1700000000 );
		foreach ( array( array( 'db.sql', FSC_Source_Native::SQL ), array( 'a.bin', 'wp-content/uploads/a.bin' ), array( 'b.txt', 'wp-content/b.txt' ) ) as $f ) {
			$e = $w->begin_file( $src . '/' . $f[0], $f[1] );
			$w->write_chunk( $e, PHP_INT_MAX );
		}
		$covered = $w->size();
		$w->close();
		if ( ! $trailer ) {
			$w = new FSC_Tar_Writer( $path, $covered );
			$w->finish();
			$w->close();
			return array();
		}
		$final = FSC_Integrity::final_size( $covered, $seg );
		FSC_Integrity::patch_size( $path, FSC_Tar::BLOCK + FSC_Integrity::size_offset_in( $json ), $final );
		$c = array(
			'covered'       => $covered,
			'final'         => $final,
			'segment_bytes' => $seg,
			'next'          => 0,
			'segments'      => array(),
		);
		$n = FSC_Integrity::segment_count( $covered, $seg );
		while ( $c['next'] < $n ) {
			$c['segments'][ $c['next'] ] = FSC_Integrity::hash_segment( $path, $c['next'], $seg, $covered );
			++$c['next'];
			$c = json_decode( json_encode( $c ), true );
		}
		$w = new FSC_Tar_Writer( $path, $covered );
		$w->add_string( FSC_Integrity::TRAILER, FSC_Integrity::trailer_json( $covered, $c['segments'], $seg ), 1700000000 );
		$w->finish();
		$this->assertSame( $final, $w->size() );
		$w->close();
		return $c;
	}

	/**
	 * Verify the whole archive like the import step: segment by segment.
	 *
	 * @param string $path Archive.
	 * @return int|null Index of the first bad segment, or null.
	 */
	private static function first_bad_segment( $path ) {
		$t = FSC_Integrity::read_trailer( $path, 512 );
		foreach ( $t['segments'] as $k => $expected ) {
			if ( ! hash_equals( $expected, FSC_Integrity::hash_segment( $path, $k, $t['segment_bytes'], $t['covered'] ) ) ) {
				return $k;
			}
		}
		return null;
	}

	public function test_archive_size_digits_are_at_a_fixed_offset() {
		$json = self::manifest_json();
		$this->assertStringStartsWith( '{"format":"fsc","format_version":1,"archive_size":"00000000000000000000"', $json );
		$this->assertSame( 51, FSC_Integrity::size_offset_in( $json ) );
		$path = $this->dir . '/a.tar';
		$this->build( $path );
		$raw = file_get_contents( $path );
		$this->assertSame( "fsc-manifest.json\0", substr( $raw, 0, 18 ) );
		$this->assertSame( FSC_Integrity::size_digits( filesize( $path ) ), substr( $raw, 563, 20 ) );
		// Header size field is untouched by the patch and still matches the JSON length.
		$h = FSC_Tar::parse_header( substr( $raw, 0, 512 ) );
		$this->assertSame( strlen( $json ), $h['size'] );
		$m = json_decode( substr( $raw, 512, $h['size'] ), true );
		$this->assertSame( (string) filesize( $path ), ltrim( $m['archive_size'], '0' ) );
		$this->assertNull( FSC_Integrity::size_offset_in( '{"archive_size":"12"}' ) );
	}

	public function test_final_size_is_exact_across_segment_count_boundaries() {
		foreach ( array( 100, 4000, 4096, 9 * 512, 20000, 40960, 41000, 60000 ) as $payload ) {
			$path = $this->dir . '/s' . $payload . '.tar';
			$c    = $this->build( $path, 512, $payload );
			clearstatcache();
			$this->assertSame( $c['final'], filesize( $path ), "payload $payload" );
			$this->assertSame( str_repeat( "\0", 1024 ), substr( file_get_contents( $path ), -1024 ) );
		}
		$this->assertSame( 2, FSC_Integrity::segment_count( 67108864 + 1, 67108864 ) );
		$this->assertSame( 1, FSC_Integrity::segment_count( 67108864, 67108864 ) );
	}

	public function test_patch_is_idempotent_and_refuses_a_wrong_offset() {
		$path = $this->dir . '/p.tar';
		$this->build( $path );
		$before = md5_file( $path );
		FSC_Integrity::patch_size( $path, 563, filesize( $path ) );
		FSC_Integrity::patch_size( $path, 563, filesize( $path ) );
		$this->assertSame( $before, md5_file( $path ) );
		$this->expectException( FSC_Exception::class );
		FSC_Integrity::patch_size( $path, 0, 5 );
	}

	public function test_trailer_round_trip_and_segment_hashes() {
		$path = $this->dir . '/t.tar';
		$c    = $this->build( $path, 4096, 20000 );
		$t    = FSC_Integrity::read_trailer( $path, 512 );
		$this->assertSame( $c['covered'], $t['covered'] );
		$this->assertSame( 4096, $t['segment_bytes'] );
		$this->assertSame( $c['segments'], $t['segments'] );
		$raw = file_get_contents( $path );
		foreach ( $t['segments'] as $k => $h ) {
			$this->assertSame( hash( 'sha256', substr( $raw, $k * 4096, min( 4096, $t['covered'] - $k * 4096 ) ) ), $h );
		}
		// The trailer header starts at covered_bytes and is the last entry before the end blocks.
		$this->assertSame( "fsc-checksum.json\0", substr( $raw, $t['covered'], 18 ) );
		$tj = json_decode( rtrim( substr( $raw, $t['covered'] + 512, strlen( $raw ) - 1024 - $t['covered'] - 512 ), "\0" ), true );
		$this->assertSame( array( 'version', 'algo', 'segment_bytes', 'covered_bytes', 'segments' ), array_keys( $tj ) );
		$this->assertNull( self::first_bad_segment( $path ) );
		$this->assertTrue( FSC_Integrity::has_checksums( $path ) );
	}

	public function test_segment_hashing_resumes_to_the_same_result() {
		$path = $this->dir . '/r.tar';
		$c    = $this->build( $path, 1024, 30000 );
		$all  = array();
		for ( $k = 0; $k < FSC_Integrity::segment_count( $c['covered'], 1024 ); $k++ ) {
			$all[] = FSC_Integrity::hash_segment( $path, $k, 1024, $c['covered'] );
		}
		$this->assertSame( $all, $c['segments'] );
		$this->assertGreaterThan( 20, count( $all ) );
	}

	public function test_flipped_byte_is_found_in_the_right_segment() {
		$path = $this->dir . '/f.tar';
		$c    = $this->build( $path, 4096, 30000 );
		$pos  = (int) ( $c['covered'] / 2 );
		$fp   = fopen( $path, 'r+b' );
		fseek( $fp, $pos );
		$b = fread( $fp, 1 );
		fseek( $fp, $pos );
		fwrite( $fp, chr( ord( $b ) ^ 0x01 ) );
		fclose( $fp );
		// Size and end blocks are still fine: only the checksums notice.
		FSC_Integrity::check_size( $path, json_decode( substr( file_get_contents( $path ), 512, strlen( self::manifest_json() ) ), true ) );
		$k = self::first_bad_segment( $path );
		$this->assertSame( intdiv( $pos, 4096 ), $k );
		$msg = FSC_Integrity::damaged_message( $k, 67108864 );
		$this->assertStringContainsString( 'damaged around ' . number_format( floor( $k * 64 ) ) . ' MB', $msg );
		$this->assertStringContainsString( 'segment ' . ( $k + 1 ) . ')', $msg );
	}

	public function test_flipped_byte_in_the_manifest_is_found() {
		$path = $this->dir . '/m.tar';
		$this->build( $path, 4096, 10000 );
		$raw        = file_get_contents( $path );
		$raw[ 600 ] = 'X' === $raw[ 600 ] ? 'Y' : 'X';
		file_put_contents( $path, $raw );
		$this->assertSame( 0, self::first_bad_segment( $path ) );
	}

	public function test_truncation_is_reported_with_received_and_expected_bytes() {
		$path = $this->dir . '/x.tar';
		$this->build( $path, 4096, 30000 );
		$full = filesize( $path );
		$fp   = fopen( $path, 'r+b' );
		ftruncate( $fp, 20480 );
		fclose( $fp );
		$r = FSC_Source_Native::quick_check( $path );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( $full, $r['expected'] );
		$this->assertSame( 20480, $r['actual'] );
		$pct = number_format( floor( 20480 * 1000 / $full ) / 10, 1 );
		$this->assertSame( 'The archive is incomplete: 20,480 of ' . number_format( $full ) . ' bytes (' . $pct . '%). Download it again, or copy it by FTP/SFTP.', $r['message'] );
		try {
			( new FSC_Source_Native( $path ) )->meta();
			$this->fail( 'meta() accepted a truncated archive' );
		} catch ( FSC_Exception $e ) {
			$this->assertStringContainsString( 'incomplete: 20,480 of', $e->getMessage() );
		}
	}

	public function test_longer_file_and_unfinished_export_are_refused() {
		$path = $this->dir . '/l.tar';
		$this->build( $path );
		file_put_contents( $path, str_repeat( "\0", 512 ), FILE_APPEND );
		$r = FSC_Source_Native::quick_check( $path );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'wrong size', $r['message'] );

		$this->expectException( FSC_Exception::class );
		$this->expectExceptionMessage( 'never finished' );
		FSC_Integrity::check_size( $path, array( 'archive_size' => FSC_Integrity::SIZE_PLACEHOLDER ) );
	}

	public function test_complete_archive_passes_quick_check_and_imports_its_files() {
		$path = $this->dir . '/ok.tar';
		$this->build( $path );
		$r = FSC_Source_Native::quick_check( $path );
		$this->assertTrue( $r['ok'], $r['message'] );
		$this->assertSame( filesize( $path ), $r['expected'] );
		$meta = ( new FSC_Source_Native( $path ) )->meta();
		$this->assertSame( filesize( $path ), $meta['archive_size'] );
		$this->assertSame( 2, $meta['table_count'] );
		// The trailer is not a wp-content entry: extraction never sees it.
		$src    = new FSC_Source_Native( $path );
		$cursor = array();
		$paths  = array();
		while ( $e = $src->files_next( $cursor ) ) {
			$paths[] = $e['path'];
		}
		$this->assertSame( array( 'uploads/a.bin', 'b.txt' ), $paths );
		// seen_files counts extracted and skipped file entries alike.
		$target = $this->dir . '/target';
		mkdir( $target );
		$x = new FSC_Extractor(
			$target,
			new FSC_Source_Native( $path ),
			function ( $rel ) {
				return 'b.txt' === $rel;
			}
		);
		$c = FSC_Extractor::new_cursor();
		$this->assertTrue( $x->step( $c, microtime( true ) + 10 ) );
		$this->assertSame( 2, $c['seen_files'] );
		$this->assertSame( 1, $c['files'] );
	}

	public function test_old_archive_without_trailer() {
		$path = $this->dir . '/old.tar';
		$this->build( $path, 4096, 10000, false );
		$this->assertNull( FSC_Integrity::read_trailer( $path ) );
		$this->assertFalse( FSC_Integrity::has_checksums( $path ) );
		$r = FSC_Source_Native::quick_check( $path );
		$this->assertTrue( $r['ok'], $r['message'] );
		$this->assertNull( $r['expected'] );
		$this->assertNull( ( new FSC_Source_Native( $path ) )->meta()['archive_size'] );
		// Truncated old archive: only the end blocks can tell.
		$fp = fopen( $path, 'r+b' );
		ftruncate( $fp, filesize( $path ) - 1024 );
		fclose( $fp );
		$r = FSC_Source_Native::quick_check( $path );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'does not end like a finished', $r['message'] );
	}

	public function test_new_archive_with_trailer_removed_has_no_trailer_but_expects_one() {
		$path = $this->dir . '/nt.tar';
		$c    = $this->build( $path );
		// Cut the trailer and rewrite the end blocks: archive_size says it is 0.9.1+.
		$w = new FSC_Tar_Writer( $path, $c['covered'] );
		$w->finish();
		$w->close();
		$this->assertNull( FSC_Integrity::read_trailer( $path ) );
		$m = json_decode( substr( file_get_contents( $path ), 512, strlen( self::manifest_json() ) ), true );
		$this->assertNotNull( FSC_Integrity::expected_size( $m ) );
	}

	public function test_damaged_trailer_is_rejected() {
		$path = $this->dir . '/dt.tar';
		$c    = $this->build( $path );
		$raw  = file_get_contents( $path );
		// Break the JSON but keep the header valid.
		$raw[ $c['covered'] + 512 ] = '[';
		file_put_contents( $path, $raw );
		$this->expectException( FSC_Exception::class );
		$this->expectExceptionMessage( 'invalid' );
		FSC_Integrity::read_trailer( $path, 512 );
	}

	public function test_build_steps_states() {
		$labels = array(
			'verify'   => 'V',
			'database' => 'D',
			'files'    => 'F',
		);
		$states = function ( $steps ) {
			return array_column( $steps, 'state' );
		};
		$this->assertSame( array( 'done', 'active', 'pending' ), $states( FSC_Job::build_steps( $labels, 'database', 'running' ) ) );
		$s = FSC_Job::build_steps( $labels, 'database', 'error', array( 'verify' => 'why' ) );
		$this->assertSame( array( 'skipped', 'failed', 'pending' ), $states( $s ) );
		$this->assertSame( 'why', $s[0]['note'] );
		$this->assertArrayNotHasKey( 'note', $s[1] );
		$this->assertSame( array( 'skipped', 'done', 'done' ), $states( FSC_Job::build_steps( $labels, 'files', 'done', array( 'verify' => 'x' ) ) ) );
		$this->assertSame( array( 'key' => 'verify', 'label' => 'V', 'state' => 'active' ), FSC_Job::build_steps( $labels, 'verify', 'running' )[0] );
	}

	public function test_last_progress_at_moves_only_when_the_cursor_moves() {
		$job = FSC_Job::create( $this->dir . '/job.json', 'export', array( 'phase' => 'x' ) );
		$job->data['last_progress_at'] = 1;
		$job->run(
			function ( $job ) {
				return true;
			},
			1
		);
		// Status changed to done: counts as progress.
		$this->assertGreaterThan( 1, $job->data['last_progress_at'] );
		$job2 = FSC_Job::create( $this->dir . '/job2.json', 'export', array( 'phase' => 'x' ) );
		$job2->data['last_progress_at'] = 1;
		$n = 0;
		$job2->run(
			function ( $job ) use ( &$n ) {
				return ++$n >= 3;
			},
			5
		);
		$this->assertSame( 3, $n );
		$job3 = FSC_Job::create( $this->dir . '/job3.json', 'export', array( 'phase' => 'x' ) );
		$job3->data['last_progress_at'] = 1;
		$job3->data['status']           = 'running';
		$m = 0;
		$job3->run(
			function ( $job ) use ( &$m ) {
				++$m;
				return false;
			},
			0.05
		);
		$this->assertSame( 1, $job3->data['last_progress_at'] );
		$job4 = FSC_Job::create( $this->dir . '/job4.json', 'export', array( 'phase' => 'x' ) );
		$job4->data['last_progress_at'] = 1;
		$job4->run(
			function ( $job ) {
				$job->set( 'n', (int) $job->get( 'n' ) + 1 );
				return false;
			},
			0.05
		);
		$this->assertGreaterThan( 1, $job4->data['last_progress_at'] );
	}

	public function test_l5_trailer_segment_size_is_bounded() {
		// Archives with 4 KB segments are valid for the tests only; the default bound is 1 MB .. 64 MB.
		$path = $this->dir . '/small.tar';
		$this->build( $path, 4096, 20000 );
		$this->assertNotNull( FSC_Integrity::read_trailer( $path, 512 ) );
		try {
			FSC_Integrity::read_trailer( $path );
			$this->fail( 'A 4 KB segment size must be refused by default.' );
		} catch ( FSC_Exception $e ) {
			$this->assertSame( 'segment size out of range', $e->getMessage() );
		}
		// 1 MB segments pass; above 64 MB is refused.
		$ok = $this->dir . '/mb.tar';
		$this->build( $ok, FSC_Integrity::MIN_SEGMENT, 20000 );
		$this->assertSame( FSC_Integrity::MIN_SEGMENT, FSC_Integrity::read_trailer( $ok )['segment_bytes'] );
		$big = $this->dir . '/big.tar';
		$this->build( $big, FSC_Integrity::MAX_SEGMENT * 2, 20000 );
		$this->expectException( FSC_Exception::class );
		FSC_Integrity::read_trailer( $big );
	}

	public function test_l5_segment_hashing_respects_the_deadline_and_resumes() {
		$path = $this->dir . '/seg.tar';
		$seg  = 3 * 1048576;
		$c    = $this->build( $path, $seg, 4 * 1048576 );
		$this->assertSame( 2, count( $c['segments'] ) );
		$state = null;
		$calls = 0;
		do {
			++$calls;
			// Deadline already passed: one 1 MB read per call where PHP can carry the hash state.
			$got   = FSC_Integrity::hash_segment_step( $path, 0, $seg, $c['covered'], $state, 0 );
			$state = json_decode( json_encode( $state ), true );
		} while ( null === $got && $calls < 10 );
		$this->assertSame( $c['segments'][0], $got );
		$this->assertNull( $state );
		$this->assertSame( FSC_Integrity::can_resume_hash() ? 3 : 1, $calls );
		$state = null;
		$this->assertSame( $c['segments'][1], FSC_Integrity::hash_segment_step( $path, 1, $seg, $c['covered'], $state, INF ) );
		// A state of another segment or a broken context starts the segment again.
		$state = array(
			'k'   => 0,
			'off' => 5,
			'ctx' => base64_encode( 'garbage' ),
		);
		$this->assertSame( $c['segments'][1], FSC_Integrity::hash_segment_step( $path, 1, $seg, $c['covered'], $state, INF ) );
	}

	public function test_l5_job_fails_after_repeated_crashes_at_the_same_point() {
		FSC_Job::create( $this->dir . '/job.json', 'import', array( 'phase' => 'db' ) );
		$unit = function ( $job ) {
			$job->set( 'n', (int) $job->get( 'n', 0 ) + 1 );
			return false;
		};
		// Requests killed before their first checkpoint: in_step stays true and the cursor does not move.
		for ( $i = 1; $i <= 2; $i++ ) {
			$j = FSC_Job::load( $this->dir . '/job.json' );
			$j->data['in_step'] = true;
			$j->save();
			$j->run(
				function ( $job, $deadline, &$state ) {
					$state['yield'] = true;
					return false;
				},
				1
			);
			$this->assertSame( 'running', $j->data['status'], "crash $i" );
			$this->assertSame( $i, $j->data['crash_count'] );
			$j->data['in_step'] = true;
			$j->save();
		}
		$j = FSC_Job::load( $this->dir . '/job.json' );
		$j->run( $unit, 1 );
		$this->assertSame( 'error', $j->data['status'] );
		$this->assertStringContainsString( '3 times in a row', $j->data['error'] );
		$this->assertArrayHasKey( 'finished_at', $j->data );

		// Progress between crashes resets the counter.
		$k = FSC_Job::create( $this->dir . '/job2.json', 'import', array( 'phase' => 'db' ) );
		for ( $i = 0; $i < 5; $i++ ) {
			$k->data['in_step'] = true;
			$k->save();
			$k->run(
				function ( $job, $deadline, &$state ) use ( $i ) {
					$job->set( 'n', $i );
					$state['yield'] = true;
					return false;
				},
				1
			);
			$this->assertSame( 'running', $k->data['status'] );
		}
	}

	public function test_errors_other_than_fsc_exception_get_a_generic_message() {
		$log = $this->dir . '/php-error.log';
		$old = ini_set( 'error_log', $log );
		$job = FSC_Job::create( $this->dir . '/job.json', 'import', array( 'phase' => 'db' ) );
		$job->run(
			function () {
				throw new TypeError( 'secret detail /var/www/html/wp-content/x.php' );
			},
			1
		);
		ini_set( 'error_log', $old );
		$this->assertSame( 'error', $job->data['status'] );
		$this->assertMatchesRegularExpression( '/^Internal error \(reference [0-9a-f]{8}\)\. The details were written to the PHP error log\.$/', $job->data['error'] );
		$this->assertStringNotContainsString( 'secret', implode( ' ', array_column( $job->log_since( 0 ), 'msg' ) ) );
		$this->assertStringContainsString( 'secret detail', (string) file_get_contents( $log ) );
		$job2 = FSC_Job::create( $this->dir . '/job2.json', 'import', array( 'phase' => 'db' ) );
		$job2->run(
			function () {
				throw new FSC_Exception( 'Shown as is.' );
			},
			1
		);
		$this->assertSame( 'Shown as is.', $job2->data['error'] );
	}

	public function test_paths_and_storage_name_are_redacted() {
		$this->assertSame( 'x in private-*** y', FSC_Job::redact_paths( 'x in private-0123456789abcdef0123456789abcdef y' ) );
		$this->assertSame( 'plain', FSC_Job::redact_paths( 'plain' ) );
	}
}
