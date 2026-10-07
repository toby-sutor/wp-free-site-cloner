<?php
/**
 * Search-replace replay tests: a batch that is interrupted after some row
 * updates and then run again must not replace any row twice, even when the
 * new URL contains the old one.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_SR_Journal
 */
class SrJournalTest extends FSC_Test_Case {

	/** @var array In-memory table: id => row. */
	private $table;

	/** @var int Updates allowed before the simulated crash (-1: none). */
	private $crash_after;

	/** @var bool Crash before (true) or after (false) the write of the failing update. */
	private $crash_before_write;

	private function engine() {
		return new FSC_Search_Replace( FSC_Search_Replace::build_pairs( array( array( 'http://a.test', 'http://a.test/sub' ) ) ) );
	}

	private function seed() {
		$this->table = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$this->table[ $i ] = array(
				'id'   => (string) $i,
				'body' => 0 === $i % 5 ? 'no url here ' . $i : 'see http://a.test/p/' . $i . ' and ' . serialize( array( 'u' => 'http://a.test/s/' . $i ) ),
				'json' => 0 === $i % 3 ? null : '{"u":"http:\/\/a.test\/j\/' . $i . '"}',
			);
		}
		return $this->table;
	}

	/**
	 * Keyset batch like FSC_DB_Import::replace_step: rows after $last whose text contains the old URL, by id.
	 */
	private function select( $last, $limit ) {
		$out = array();
		foreach ( $this->table as $id => $row ) {
			if ( $id <= $last ) {
				continue;
			}
			if ( false !== strpos( (string) $row['body'], 'a.test' ) || false !== strpos( (string) $row['json'], 'a.test' ) ) {
				$out[] = $row;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Run the whole table through batches; each batch may crash, then it is run again
	 * in a fresh "request" (new journal object) with the cursor from before the batch.
	 */
	private function run_all( $journal_file, $use_journal, $crash_pattern ) {
		$engine = $this->engine();
		$last   = 0;
		$batch  = 0;
		$guard  = 0;
		while ( $guard++ < 200 ) {
			$rows = $this->select( $last, 4 );
			if ( ! $rows ) {
				return;
			}
			$this->crash_after        = isset( $crash_pattern[ $batch ] ) ? $crash_pattern[ $batch ][0] : -1;
			$this->crash_before_write = isset( $crash_pattern[ $batch ] ) ? $crash_pattern[ $batch ][1] : false;
			unset( $crash_pattern[ $batch ] );
			$j       = new FSC_SR_Journal( $journal_file );
			$updates = 0;
			$update  = function ( $row, $new ) use ( &$updates ) {
				if ( $updates === $this->crash_after && $this->crash_before_write ) {
					throw new RuntimeException( 'crash before write' );
				}
				foreach ( $new as $c => $v ) {
					$this->table[ (int) $row['id'] ][ $c ] = $v;
				}
				if ( $updates === $this->crash_after ) {
					throw new RuntimeException( 'crash after write' );
				}
				++$updates;
			};
			try {
				if ( $use_journal ) {
					$j->begin( 'fsctmp_posts', $last );
					$j->process(
						$rows,
						array( 'body', 'json' ),
						function ( $row ) {
							return json_encode( array( $row['id'] ) );
						},
						$engine,
						$update
					);
				} else {
					foreach ( $rows as $row ) {
						$new = array();
						foreach ( array( 'body', 'json' ) as $c ) {
							if ( null !== $row[ $c ] && $engine->replace( $row[ $c ] ) !== $row[ $c ] ) {
								$new[ $c ] = $engine->replace( $row[ $c ] );
							}
						}
						if ( $new ) {
							$update( $row, $new );
						}
					}
				}
			} catch ( RuntimeException $e ) {
				// The request died: the cursor ($last) is not saved, the same batch runs again.
				$j->close();
				++$batch;
				continue;
			}
			$j->close();
			$end  = end( $rows );
			$last = (int) $end['id'];
			++$batch;
		}
		$this->fail( 'did not finish' );
	}

	private function expected( array $original ) {
		$engine = $this->engine();
		$out    = array();
		foreach ( $original as $id => $row ) {
			$out[ $id ] = array(
				'id'   => $row['id'],
				'body' => $engine->replace( $row['body'] ),
				'json' => null === $row['json'] ? null : $engine->replace( $row['json'] ),
			);
		}
		return $out;
	}

	public function test_without_journal_a_replay_double_replaces() {
		$orig = $this->seed();
		$this->run_all( $this->dir . '/j', false, array( 0 => array( 2, false ) ) );
		$this->assertNotSame( $this->expected( $orig ), $this->table );
		$this->assertStringContainsString( 'http://a.test/sub/sub/', implode( ' ', array_column( $this->table, 'body' ) ) );
	}

	public function test_replay_after_crash_after_write_never_double_replaces() {
		$orig = $this->seed();
		// Batch 0 dies after its 3rd update was written, its replay dies again after 1, batch 3 after its 1st.
		$this->run_all( $this->dir . '/j', true, array( 0 => array( 2, false ), 1 => array( 0, false ), 4 => array( 0, false ) ) );
		$this->assertSame( $this->expected( $orig ), $this->table );
		$this->assertStringNotContainsString( '/sub/sub', json_encode( $this->table, JSON_UNESCAPED_SLASHES ) );
	}

	public function test_replay_after_crash_before_write_applies_the_row_once() {
		$orig = $this->seed();
		$this->run_all( $this->dir . '/j', true, array( 0 => array( 1, true ), 1 => array( 0, true ), 2 => array( 3, true ) ) );
		$this->assertSame( $this->expected( $orig ), $this->table );
	}

	public function test_crash_on_every_row() {
		$orig    = $this->seed();
		$pattern = array();
		for ( $b = 0; $b < 150; $b += 2 ) {
			$pattern[ $b ] = array( 0, false );
		}
		$this->run_all( $this->dir . '/j', true, $pattern );
		$this->assertSame( $this->expected( $orig ), $this->table );
	}

	public function test_torn_last_line_and_other_batch_are_ignored() {
		$f = $this->dir . '/j';
		$j = new FSC_SR_Journal( $f );
		$this->assertFalse( $j->begin( 't', array( '5' ) ) );
		$j->record( 'a', array( 'c' => 'x' ) );
		$j->record( 'b', array( 'c' => 'y' ) );
		$j->close();
		file_put_contents( $f, '["c",["c"],"', FILE_APPEND );

		$j = new FSC_SR_Journal( $f );
		$this->assertTrue( $j->begin( 't', array( '5' ) ) );
		$this->assertTrue( $j->is_done( 'a', array( 'c' => 'anything' ) ) );
		// "b" is the last complete line: done only when its values match.
		$this->assertTrue( $j->is_done( 'b', array( 'c' => 'y' ) ) );
		$this->assertFalse( $j->is_done( 'b', array( 'c' => 'old' ) ) );
		$this->assertFalse( $j->is_done( 'c', array( 'c' => 'z' ) ) );
		$j->close();

		$j = new FSC_SR_Journal( $f );
		$this->assertFalse( $j->begin( 't', array( '9' ) ) );
		$this->assertFalse( $j->is_done( 'a', array( 'c' => 'x' ) ) );
		$j->close();
		$this->assertSame( 1, substr_count( file_get_contents( $f ), "\n" ) );
	}
}
