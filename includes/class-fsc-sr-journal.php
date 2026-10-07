<?php
/**
 * Replay-safe search-replace batches. No WordPress dependency.
 *
 * A batch (rows selected after a cursor) runs row UPDATEs one by one; the
 * cursor is only saved when the whole batch is done. When a request dies in
 * between, the same batch runs again. Replacing a row a second time is wrong
 * whenever the new value still contains the old one (http://a.test ->
 * http://a.test/sub). So before each UPDATE a journal line with the row key
 * and a hash of the new values is appended. On replay every journaled row is
 * skipped, except the last one, which may or may not have been written: it is
 * skipped only when its current values match the journaled hash.
 *
 * Journal file: first line {"t":table,"k":batch key}, then one JSON line
 * [row key, columns, hash] per updated row.
 *
 * @package wp-free-site-cloner
 */

/**
 * Journal for one search-replace batch.
 */
class FSC_SR_Journal {

	/** @var string */
	private $path;

	/** @var array Row keys whose UPDATE certainly ran. */
	private $done = array();

	/** @var array|null array( key, columns, hash ) of the last journaled row, which may not have run. */
	private $last = null;

	/** @var resource|null */
	private $fp = null;

	/**
	 * Constructor.
	 *
	 * @param string $path Journal file.
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Close the file.
	 */
	public function close() {
		if ( $this->fp ) {
			fclose( $this->fp );
			$this->fp = null;
		}
	}

	/**
	 * Start a batch, or pick up the journal of the same batch after a crash.
	 *
	 * @param string $table Table.
	 * @param mixed  $key   Batch key (the cursor the rows were selected after).
	 * @return bool True when a journal of this batch exists (replay).
	 * @throws FSC_Exception When the journal cannot be written.
	 */
	public function begin( $table, $key ) {
		$this->close();
		$this->done = array();
		$this->last = null;
		$header     = json_encode(
			array(
				't' => (string) $table,
				'k' => $key,
			)
		) . "\n";
		$replay     = false;
		$raw        = is_file( $this->path ) ? (string) @file_get_contents( $this->path ) : '';
		if ( '' !== $raw && 0 === strpos( $raw, $header ) ) {
			$replay = true;
			$rows   = array();
			$lines  = explode( "\n", substr( $raw, strlen( $header ) ) );
			// The part after the last newline is empty, or a torn line whose UPDATE never ran.
			array_pop( $lines );
			foreach ( $lines as $line ) {
				$row = json_decode( $line, true );
				if ( is_array( $row ) && 3 === count( $row ) ) {
					$rows[] = $row;
				}
			}
			if ( $rows ) {
				$this->last = array_pop( $rows );
				foreach ( $rows as $row ) {
					$this->done[ (string) $row[0] ] = true;
				}
			}
			$this->fp = @fopen( $this->path, 'ab' );
		} else {
			$this->fp = @fopen( $this->path, 'wb' );
			if ( $this->fp && fwrite( $this->fp, $header ) !== strlen( $header ) ) {
				$this->close();
			}
		}
		if ( ! $this->fp ) {
			throw new FSC_Exception( 'Cannot write the search-replace journal (disk full or not writable?).' );
		}
		return $replay;
	}

	/**
	 * Hash of column values.
	 *
	 * @param array $values column => value (string or null).
	 * @return string
	 */
	public static function hash( array $values ) {
		ksort( $values, SORT_STRING );
		$s = '';
		foreach ( $values as $c => $v ) {
			$s .= strlen( (string) $c ) . ':' . $c . '=' . ( null === $v ? 'N' : 'S' . strlen( $v ) . ':' . $v ) . ';';
		}
		return sha1( $s );
	}

	/**
	 * Whether the row was already updated in an earlier run of this batch.
	 *
	 * @param string $key     Row key.
	 * @param array  $current column => current value.
	 * @return bool
	 */
	public function is_done( $key, array $current ) {
		$key = (string) $key;
		if ( null !== $this->last && (string) $this->last[0] === $key ) {
			$vals = array();
			foreach ( (array) $this->last[1] as $c ) {
				$vals[ $c ] = array_key_exists( $c, $current ) ? $current[ $c ] : null;
			}
			return self::hash( $vals ) === $this->last[2];
		}
		return isset( $this->done[ $key ] );
	}

	/**
	 * Journal a row right before its UPDATE.
	 *
	 * @param string $key Row key.
	 * @param array  $new column => new value.
	 * @throws FSC_Exception On a write error.
	 */
	public function record( $key, array $new ) {
		$line = json_encode( array( (string) $key, array_map( 'strval', array_keys( $new ) ), self::hash( $new ) ) ) . "\n";
		if ( ! $this->fp || fwrite( $this->fp, $line ) !== strlen( $line ) ) {
			throw new FSC_Exception( 'Writing the search-replace journal failed (disk full?).' );
		}
		fflush( $this->fp );
	}

	/**
	 * Replace in one batch of rows.
	 *
	 * @param array              $rows    Rows (column => value), in cursor order.
	 * @param array              $text    Columns to replace in.
	 * @param callable           $key_fn  fn( array $row, int $index ): string, stable row key.
	 * @param FSC_Search_Replace $engine  Engine.
	 * @param callable           $update  fn( array $row, array $new_values ): void, writes the row.
	 * @return int Rows changed.
	 */
	public function process( array $rows, array $text, $key_fn, FSC_Search_Replace $engine, $update ) {
		$changed = 0;
		foreach ( array_values( $rows ) as $i => $row ) {
			$key     = (string) call_user_func( $key_fn, $row, $i );
			$current = array();
			foreach ( $text as $c ) {
				$current[ $c ] = isset( $row[ $c ] ) ? $row[ $c ] : null;
			}
			if ( $this->is_done( $key, $current ) ) {
				continue;
			}
			$new = array();
			foreach ( $current as $c => $v ) {
				if ( null === $v ) {
					continue;
				}
				$r = $engine->replace( $v );
				if ( $r !== $v ) {
					$new[ $c ] = $r;
				}
			}
			if ( $new ) {
				$this->record( $key, $new );
				call_user_func( $update, $row, $new );
				++$changed;
			}
		}
		return $changed;
	}
}
