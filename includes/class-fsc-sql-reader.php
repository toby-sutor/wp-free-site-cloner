<?php
/**
 * Streaming SQL statement splitter. Resumes at a byte offset. Handles quoted
 * strings with backslash and doubled-quote escapes, backtick identifiers,
 * "-- ", "#" and block comments (MySQL executable comments are kept) and the
 * DELIMITER command. No WordPress dependency.
 *
 * @package wp-free-site-cloner
 */

/**
 * Statement reader. Typical use:
 *   $r = new FSC_SQL_Reader( $file, $offset );
 *   while ( null !== ( $sql = $r->next() ) ) { ...; $offset = $r->offset(); }
 */
class FSC_SQL_Reader {

	const S_NORMAL  = 0;
	const S_QUOTE   = 1;
	const S_LINE    = 2;
	const S_BLOCK   = 3;
	const READ_SIZE = 1048576;

	/** @var resource */
	private $fp;

	/** @var string Buffered data; unconsumed part starts at index $start (file offset $base). */
	private $buf = '';

	/** @var int */
	private $start = 0;

	/** @var int */
	private $base;

	/** @var int Offset where the last returned statement started. */
	private $last_start;

	/** @var bool */
	private $eof = false;

	/** @var string */
	private $delimiter;

	/** @var int */
	private $max_len;

	/**
	 * Constructor.
	 *
	 * @param string $path      SQL file.
	 * @param int    $offset    Byte offset to resume from (must be a statement boundary returned by offset()).
	 * @param string $delimiter Active delimiter at that offset.
	 * @param int    $max_len   Maximum statement size in bytes.
	 * @throws FSC_Exception When the file cannot be opened.
	 */
	public function __construct( $path, $offset = 0, $delimiter = ';', $max_len = 268435456 ) {
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			throw new FSC_Exception( sprintf( 'Cannot open SQL file %s.', basename( $path ) ) );
		}
		$this->fp         = $fp;
		$this->base       = (int) $offset;
		$this->last_start = $this->base;
		$this->delimiter  = '' === (string) $delimiter ? ';' : (string) $delimiter;
		$this->max_len    = (int) $max_len;
		if ( $this->base > 0 ) {
			fseek( $this->fp, $this->base );
		}
	}

	/**
	 * Byte offset right after the last returned statement.
	 *
	 * @return int
	 */
	public function offset() {
		return $this->base;
	}

	/**
	 * Byte offset where the last returned statement (including leading comments) started.
	 *
	 * @return int
	 */
	public function statement_offset() {
		return $this->last_start;
	}

	/**
	 * Active delimiter (persist together with offset()).
	 *
	 * @return string
	 */
	public function delimiter() {
		return $this->delimiter;
	}

	/**
	 * Read more data into the buffer.
	 *
	 * @return bool False at end of file.
	 * @throws FSC_Exception When a statement exceeds the size limit.
	 */
	private function fill() {
		if ( $this->eof ) {
			return false;
		}
		$data = fread( $this->fp, self::READ_SIZE );
		if ( false === $data || '' === $data ) {
			$this->eof = true;
			return false;
		}
		$this->buf .= $data;
		if ( strlen( $this->buf ) - $this->start > $this->max_len ) {
			throw new FSC_Exception( sprintf( 'SQL statement at byte %d is larger than %d bytes.', $this->base, $this->max_len ) );
		}
		return true;
	}

	/**
	 * Make sure $n bytes from $i are buffered.
	 *
	 * @param int $i Index.
	 * @param int $n Bytes.
	 * @return bool False when the file ends before that.
	 */
	private function have( $i, $n ) {
		while ( strlen( $this->buf ) < $i + $n ) {
			if ( ! $this->fill() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Mark everything before buffer index $to as consumed.
	 *
	 * @param int $to Buffer index.
	 */
	private function consume( $to ) {
		$this->base += $to - $this->start;
		$this->start = $to;
	}

	/**
	 * Next statement without its delimiter, or null at the end of the file.
	 *
	 * @return string|null
	 * @throws FSC_Exception When a statement exceeds the size limit.
	 */
	public function next() {
		if ( $this->start >= self::READ_SIZE ) {
			$this->buf   = (string) substr( $this->buf, $this->start );
			$this->start = 0;
		}
		$out   = '';
		$seg   = $this->start;
		$i     = $this->start;
		$state = self::S_NORMAL;
		$quote = '';
		$keep  = true;
		$blank = true;

		$this->last_start = $this->base;
		$d                = $this->delimiter;
		$dl               = strlen( $d );
		$special          = "'\"`#-/" . $d[0];

		while ( true ) {
			$len = strlen( $this->buf );
			if ( $i >= $len ) {
				if ( $this->fill() ) {
					continue;
				}
				if ( $seg >= 0 ) {
					$out .= substr( $this->buf, $seg );
				}
				$this->consume( $len );
				$out = trim( $out );
				return '' === $out ? null : $out;
			}

			if ( self::S_NORMAL === $state ) {
				if ( $blank ) {
					$i += strspn( $this->buf, " \t\r\n", $i );
					if ( $i >= $len ) {
						continue;
					}
					$c = $this->buf[ $i ];
					if ( ( 'D' === $c || 'd' === $c ) && $this->have( $i, 10 ) && 0 === strncasecmp( substr( $this->buf, $i, 10 ), 'DELIMITER', 9 ) && ctype_space( $this->buf[ $i + 9 ] ) ) {
						$nl = strpos( $this->buf, "\n", $i );
						while ( false === $nl && $this->fill() ) {
							$nl = strpos( $this->buf, "\n", $i );
						}
						$end = false === $nl ? strlen( $this->buf ) : $nl + 1;
						$new = trim( substr( $this->buf, $i + 10, $end - $i - 10 ) );
						$this->consume( $end );
						if ( '' !== $new ) {
							$this->delimiter = $new;
						}
						return $this->next();
					}
				}
				$j = $i + strcspn( $this->buf, $special, $i );
				if ( $j > $i ) {
					$blank = false;
					$i     = $j;
					continue;
				}
				$c = $this->buf[ $i ];
				if ( $c === $d[0] && $this->have( $i, $dl ) && 0 === substr_compare( $this->buf, $d, $i, $dl ) ) {
					if ( $seg >= 0 ) {
						$out .= substr( $this->buf, $seg, $i - $seg );
					}
					$this->consume( $i + $dl );
					$out = trim( $out );
					if ( '' === $out ) {
						$seg              = $this->start;
						$i                = $this->start;
						$blank            = true;
						$this->last_start = $this->base;
						continue;
					}
					return $out;
				}
				if ( "'" === $c || '"' === $c || '`' === $c ) {
					$state = self::S_QUOTE;
					$quote = $c;
					$blank = false;
					++$i;
					continue;
				}
				if ( '#' === $c ) {
					$out  .= substr( $this->buf, $seg, $i - $seg );
					$seg   = -1;
					$state = self::S_LINE;
					++$i;
					continue;
				}
				if ( '-' === $c ) {
					$this->have( $i, 3 );
					$n1 = isset( $this->buf[ $i + 1 ] ) ? $this->buf[ $i + 1 ] : '';
					$n2 = isset( $this->buf[ $i + 2 ] ) ? $this->buf[ $i + 2 ] : '';
					if ( '-' === $n1 && ( '' === $n2 || ord( $n2 ) <= 32 ) ) {
						$out  .= substr( $this->buf, $seg, $i - $seg );
						$seg   = -1;
						$state = self::S_LINE;
						$i    += 2;
						continue;
					}
					$blank = false;
					++$i;
					continue;
				}
				if ( '/' === $c ) {
					$this->have( $i, 4 );
					$n1 = isset( $this->buf[ $i + 1 ] ) ? $this->buf[ $i + 1 ] : '';
					if ( '*' === $n1 ) {
						$n2   = isset( $this->buf[ $i + 2 ] ) ? $this->buf[ $i + 2 ] : '';
						$n3   = isset( $this->buf[ $i + 3 ] ) ? $this->buf[ $i + 3 ] : '';
						$keep = ( '!' === $n2 || '+' === $n2 || ( 'M' === $n2 && '!' === $n3 ) );
						if ( $keep ) {
							$blank = false;
						} else {
							$out .= substr( $this->buf, $seg, $i - $seg );
							$seg  = -1;
						}
						$state = self::S_BLOCK;
						$i    += 2;
						continue;
					}
				}
				$blank = false;
				++$i;
				continue;
			}

			if ( self::S_QUOTE === $state ) {
				$stops = '`' === $quote ? '`' : $quote . '\\';
				$j     = $i + strcspn( $this->buf, $stops, $i );
				if ( $j >= $len ) {
					$i = $len;
					continue;
				}
				if ( '\\' === $this->buf[ $j ] ) {
					$this->have( $j, 2 );
					$i = $j + 2;
					continue;
				}
				if ( $this->have( $j, 2 ) && $this->buf[ $j + 1 ] === $quote ) {
					$i = $j + 2;
					continue;
				}
				$i     = $j + 1;
				$state = self::S_NORMAL;
				continue;
			}

			if ( self::S_LINE === $state ) {
				$nl = strpos( $this->buf, "\n", $i );
				if ( false === $nl ) {
					if ( ! $this->fill() ) {
						$i = strlen( $this->buf );
					}
					continue;
				}
				$seg   = $nl;
				$i     = $nl + 1;
				$state = self::S_NORMAL;
				continue;
			}

			// Block comment.
			$end = strpos( $this->buf, '*/', $i );
			if ( false === $end ) {
				if ( ! $this->fill() ) {
					$i = strlen( $this->buf );
				}
				continue;
			}
			$i     = $end + 2;
			$state = self::S_NORMAL;
			if ( ! $keep ) {
				$out .= ' ';
				$seg  = $i;
			}
			$keep = true;
		}
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
	 * Destructor.
	 */
	public function __destruct() {
		$this->close();
	}
}
