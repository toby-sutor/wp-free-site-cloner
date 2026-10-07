<?php
/**
 * Tar writer/reader tests.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_Tar
 * @covers FSC_Tar_Writer
 * @covers FSC_Tar_Reader
 */
class TarTest extends FSC_Test_Case {

	private function read_all( $archive ) {
		$r      = new FSC_Tar_Reader( $archive );
		$cursor = FSC_Tar_Reader::new_cursor();
		$out    = array();
		while ( $e = $r->next_entry( $cursor ) ) {
			$data = '';
			while ( '' !== ( $b = $r->read( $cursor, 100000 ) ) ) {
				$data .= $b;
			}
			$out[ $e['name'] ] = array( $e['type'], $data );
		}
		return $out;
	}

	public function test_header_checksum_and_layout() {
		$h = FSC_Tar::raw_header( 'a.txt', 5, 1700000000, '0', 0644 );
		$this->assertSame( 512, strlen( $h ) );
		$this->assertSame( "ustar\0" . '00', substr( $h, 257, 8 ) );
		$sum = 0;
		$chk = substr_replace( $h, '        ', 148, 8 );
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $chk[ $i ] );
		}
		$this->assertSame( sprintf( '%06o', $sum ) . "\0 ", substr( $h, 148, 8 ) );
		$p = FSC_Tar::parse_header( $h );
		$this->assertSame( 'a.txt', $p['name'] );
		$this->assertSame( 5, $p['size'] );
		$this->assertSame( 1700000000, $p['mtime'] );
	}

	public function test_bad_checksum_is_rejected() {
		$h      = FSC_Tar::raw_header( 'a.txt', 5, 0, '0', 0644 );
		$h[0]   = 'b';
		$this->expectException( FSC_Exception::class );
		FSC_Tar::parse_header( $h );
	}

	public function test_pax_record_length_is_self_consistent() {
		foreach ( array( 1, 5, 90, 94, 95, 96, 990, 995, 5000 ) as $n ) {
			$rec = FSC_Tar::pax_record( 'path', str_repeat( 'x', $n ) );
			$len = (int) substr( $rec, 0, strpos( $rec, ' ' ) );
			$this->assertSame( strlen( $rec ), $len, "value length $n" );
		}
	}

	public function test_round_trip_with_long_names() {
		$archive = $this->dir . '/a.tar';
		$src     = $this->dir . '/src.bin';
		file_put_contents( $src, random_bytes( 70000 ) );
		$name100 = 'wp-content/' . str_repeat( 'd', 89 );
		$name150 = 'wp-content/uploads/' . str_repeat( 'x/', 40 ) . str_repeat( 'n', 51 ) . '.txt';
		$name300 = 'wp-content/' . str_repeat( 'deep-directory/', 18 ) . str_repeat( 'f', 20 ) . '.bin';
		$this->assertSame( 100, strlen( $name100 ) );
		$this->assertGreaterThan( 100, strlen( $name150 ) );
		$this->assertGreaterThan( 255, strlen( $name300 ) );

		$w = new FSC_Tar_Writer( $archive );
		$w->add_string( 'fsc-manifest.json', '{"a":1}', 1700000000 );
		$w->add_dir( 'wp-content/uploads' );
		$w->add_string( $name100, 'hundred' );
		$w->add_string( $name150, 'long' );
		$e = $w->begin_file( $src, $name300 );
		while ( ! $w->write_chunk( $e, 1000 ) ) {
			continue;
		}
		$w->add_string( 'wp-content/empty.txt', '' );
		$w->add_string( 'wp-content/ümlaut-файл.txt', 'utf8' );
		$w->finish();
		$w->close();

		$this->assertSame( 0, filesize( $archive ) % 512 );
		$all = $this->read_all( $archive );
		$this->assertSame( array( 'fsc-manifest.json', 'wp-content/uploads/', $name100, $name150, $name300, 'wp-content/empty.txt', 'wp-content/ümlaut-файл.txt' ), array_keys( $all ) );
		$this->assertSame( 'dir', $all['wp-content/uploads/'][0] );
		$this->assertSame( 'long', $all[ $name150 ][1] );
		$this->assertSame( file_get_contents( $src ), $all[ $name300 ][1] );
		$this->assertSame( '', $all['wp-content/empty.txt'][1] );
		$this->assertSame( 'utf8', $all['wp-content/ümlaut-файл.txt'][1] );
	}

	/**
	 * GNU tar or bsdtar, when present, must read our archives.
	 */
	public function test_system_tar_can_list_archive() {
		$tar = trim( (string) @shell_exec( 'command -v tar 2>/dev/null' ) );
		if ( '' === $tar ) {
			$this->markTestSkipped( 'tar binary not available' );
		}
		$archive = $this->dir . '/sys.tar';
		$long    = 'wp-content/' . str_repeat( 'long-dir-name/', 20 ) . 'file.txt';
		$w       = new FSC_Tar_Writer( $archive );
		$w->add_string( $long, 'hello' );
		$w->finish();
		$w->close();
		$out = (string) shell_exec( escapeshellarg( $tar ) . ' -tf ' . escapeshellarg( $archive ) . ' 2>&1' );
		$this->assertSame( $long, trim( $out ) );
	}

	/**
	 * Writing across several "requests": each step reopens the writer at the
	 * checkpointed size, including a simulated crash that wrote extra bytes after
	 * the last checkpoint.
	 */
	public function test_writer_resumes_mid_file_across_steps() {
		$archive = $this->dir . '/r.tar';
		$src     = $this->dir . '/big.bin';
		$content = random_bytes( 1024 * 1024 + 123 );
		file_put_contents( $src, $content );

		$state = array(
			'size'  => 0,
			'entry' => null,
		);
		$w     = new FSC_Tar_Writer( $archive, 0 );
		$w->add_string( 'fsc-manifest.json', '{}' );
		$state['entry'] = $w->begin_file( $src, 'wp-content/' . str_repeat( 'p', 120 ) . '.bin' );
		$state['size']  = $w->size();
		$w->close();
		$state = json_decode( json_encode( $state ), true );

		$steps = 0;
		while ( true ) {
			++$steps;
			$w     = new FSC_Tar_Writer( $archive, $state['size'] );
			$entry = $state['entry'];
			$done  = $w->write_chunk( $entry, 100000 );
			if ( 3 === $steps ) {
				// Crash: bytes hit the disk but the checkpoint is not saved.
				$w->write_chunk( $entry, 5000 );
				$w->close();
				continue;
			}
			$state = json_decode( json_encode( array( 'size' => $w->size(), 'entry' => $entry ) ), true );
			$w->close();
			if ( $done ) {
				break;
			}
		}
		$w = new FSC_Tar_Writer( $archive, $state['size'] );
		$w->add_string( 'wp-content/after.txt', 'after' );
		$w->finish();
		$w->close();

		$this->assertGreaterThan( 10, $steps );
		$all = $this->read_all( $archive );
		$this->assertSame( $content, $all[ 'wp-content/' . str_repeat( 'p', 120 ) . '.bin' ][1] );
		$this->assertSame( 'after', $all['wp-content/after.txt'][1] );
	}

	public function test_file_that_shrinks_is_padded_to_declared_size() {
		$archive = $this->dir . '/s.tar';
		$src     = $this->dir . '/shrink.txt';
		file_put_contents( $src, str_repeat( 'a', 3000 ) );
		$w = new FSC_Tar_Writer( $archive );
		$e = $w->begin_file( $src, 'wp-content/shrink.txt' );
		$w->write_chunk( $e, 1000 );
		file_put_contents( $src, 'tiny' );
		while ( ! $w->write_chunk( $e, 1000 ) ) {
			continue;
		}
		$w->add_string( 'wp-content/next.txt', 'next' );
		$w->finish();
		$w->close();
		$all = $this->read_all( $archive );
		$this->assertSame( 3000, strlen( $all['wp-content/shrink.txt'][1] ) );
		$this->assertSame( 'next', $all['wp-content/next.txt'][1] );
	}

	/**
	 * Reading across several "requests" by persisting the cursor as JSON.
	 */
	public function test_reader_resumes_by_offset_and_mid_entry() {
		$archive = $this->dir . '/m.tar';
		$files   = array();
		$w       = new FSC_Tar_Writer( $archive );
		for ( $i = 0; $i < 20; $i++ ) {
			$name           = 'wp-content/f' . $i . '-' . str_repeat( 'z', $i * 10 ) . '.bin';
			$files[ $name ] = random_bytes( 1000 + $i * 777 );
			$w->add_string( $name, $files[ $name ] );
		}
		$w->finish();
		$w->close();

		$cursor = FSC_Tar_Reader::new_cursor();
		$got    = array();
		$name   = null;
		$guard  = 0;
		while ( $guard++ < 10000 ) {
			$r      = new FSC_Tar_Reader( $archive );
			$cursor = json_decode( json_encode( $cursor ), true );
			if ( empty( $cursor['entry'] ) || $cursor['entry']['read'] >= $cursor['entry']['size'] ) {
				$e = $r->next_entry( $cursor );
				if ( ! $e ) {
					break;
				}
				$name         = $e['name'];
				$got[ $name ] = '';
			}
			$got[ $name ] .= $r->read( $cursor, 333 );
			$r->close();
		}
		$this->assertSame( $files, $got );
	}

	public function test_truncated_archive_throws() {
		$archive = $this->dir . '/t.tar';
		$w       = new FSC_Tar_Writer( $archive );
		$w->add_string( 'wp-content/a.bin', str_repeat( 'a', 5000 ) );
		$w->finish();
		$w->close();
		$fp = fopen( $archive, 'c+b' );
		ftruncate( $fp, 2000 );
		fclose( $fp );
		$r      = new FSC_Tar_Reader( $archive );
		$cursor = FSC_Tar_Reader::new_cursor();
		$this->expectException( FSC_Exception::class );
		$r->next_entry( $cursor );
	}

	/**
	 * Build a header whose size field is a GNU base-256 number that overflows
	 * the integer range (2^64 - 2048), which a plain (int) cast would wrap to a
	 * negative size.
	 */
	private function overflow_size_header( $name, $type ) {
		$h   = FSC_Tar::raw_header( $name, 0, 0, $type, 0644 );
		$h   = substr_replace( $h, "\x80\x00\x00\x00\xFF\xFF\xFF\xFF\xFF\xFF\xF8\x00", 124, 12 );
		$h   = substr_replace( $h, '        ', 148, 8 );
		$sum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $h[ $i ] );
		}
		return substr_replace( $h, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}

	public function test_base256_size_overflow_is_rejected() {
		$this->expectException( FSC_Exception::class );
		FSC_Tar::parse_header( $this->overflow_size_header( 'd', '0' ) );
	}

	/**
	 * A crafted extended header with an overflowing base-256 size used to wrap
	 * to a negative size and send the reader's offset backwards, looping forever.
	 * The reader must now stop with an exception instead.
	 */
	public function test_overflowing_size_does_not_loop_the_reader() {
		$archive = $this->dir . '/loop.tar';
		$data    = FSC_Tar::raw_header( 'a', 0, 0, 'g', 0644 );
		$data   .= FSC_Tar::raw_header( 'b', 0, 0, 'g', 0644 );
		$data   .= FSC_Tar::raw_header( 'c', 0, 0, 'g', 0644 );
		$data   .= $this->overflow_size_header( 'd', 'g' );
		$data   .= str_repeat( "\0", 1024 );
		file_put_contents( $archive, $data );
		$r      = new FSC_Tar_Reader( $archive );
		$cursor = FSC_Tar_Reader::new_cursor();
		$threw  = false;
		$guard  = 0;
		try {
			while ( $guard++ < 1000 ) {
				if ( ! $r->next_entry( $cursor ) ) {
					break;
				}
			}
		} catch ( FSC_Exception $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw, 'The reader must reject the overflowing size.' );
		$this->assertLessThan( 1000, $guard, 'The reader must not loop.' );
	}

	public function test_reads_gnu_longname_and_ustar_prefix() {
		$archive = $this->dir . '/g.tar';
		$long    = 'wp-content/' . str_repeat( 'g', 150 ) . '.txt';
		$data    = FSC_Tar::raw_header( '././@LongLink', strlen( $long ) + 1, 0, 'L', 0644 );
		$data   .= str_pad( $long . "\0", 512, "\0" );
		$data   .= FSC_Tar::raw_header( substr( $long, 0, 100 ), 2, 0, '0', 0644 ) . str_pad( 'ok', 512, "\0" );
		// ustar prefix field: prefix "wp-content/pre", name "fix.txt".
		$h     = FSC_Tar::raw_header( 'fix.txt', 3, 0, '0', 0644 );
		$h     = substr_replace( $h, str_pad( 'wp-content/pre', 155, "\0" ), 345, 155 );
		$h     = substr_replace( $h, '        ', 148, 8 );
		$sum   = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $h[ $i ] );
		}
		$h     = substr_replace( $h, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
		$data .= $h . str_pad( 'pfx', 512, "\0" ) . str_repeat( "\0", 1024 );
		file_put_contents( $archive, $data );
		$all = $this->read_all( $archive );
		$this->assertSame( 'ok', $all[ $long ][1] );
		$this->assertSame( 'pfx', $all['wp-content/pre/fix.txt'][1] );
	}
}
