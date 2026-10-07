<?php
/**
 * Uncompressed tar (ustar + PAX) writer and reader. Both can stop and resume
 * at any byte position, including inside a file. No WordPress dependency.
 *
 * @package wp-free-site-cloner
 */

if ( ! class_exists( 'FSC_Tar' ) ) {

	/**
	 * Header helpers shared by writer and reader.
	 */
	class FSC_Tar {

		const BLOCK = 512;

		/** Largest size that fits the 11 octal digits of the ustar size field. */
		const MAX_OCTAL_SIZE = 8589934591;

		/**
		 * Bytes needed to pad $size up to a block boundary.
		 *
		 * @param int $size Entry data size.
		 * @return int
		 */
		public static function padding( $size ) {
			$rest = $size % self::BLOCK;
			return 0 === $rest ? 0 : self::BLOCK - $rest;
		}

		/**
		 * Zero-padded octal field terminated by NUL.
		 *
		 * @param int $value Value.
		 * @param int $len   Field length including the terminator.
		 * @return string
		 */
		private static function octal( $value, $len ) {
			return str_pad( decoct( (int) $value ), $len - 1, '0', STR_PAD_LEFT ) . "\0";
		}

		/**
		 * Build one raw 512 byte ustar header block.
		 *
		 * @param string $name  Name (max 100 bytes, longer names must go through PAX).
		 * @param int    $size  Data size (0 when it does not fit, PAX carries it then).
		 * @param int    $mtime Modification time.
		 * @param string $type  Type flag.
		 * @param int    $mode  Permission bits.
		 * @return string
		 */
		public static function raw_header( $name, $size, $mtime, $type, $mode ) {
			$h  = str_pad( substr( $name, 0, 100 ), 100, "\0" );
			$h .= self::octal( $mode & 07777, 8 );
			$h .= self::octal( 0, 8 );
			$h .= self::octal( 0, 8 );
			$h .= self::octal( $size, 12 );
			$h .= self::octal( max( 0, (int) $mtime ), 12 );
			$h .= '        ';
			$h .= $type;
			$h .= str_repeat( "\0", 100 );
			$h .= "ustar\0" . '00';
			$h .= str_pad( 'root', 32, "\0" );
			$h .= str_pad( 'root', 32, "\0" );
			$h .= self::octal( 0, 8 );
			$h .= self::octal( 0, 8 );
			$h .= str_repeat( "\0", 155 );
			$h .= str_repeat( "\0", 12 );

			$sum = 0;
			for ( $i = 0; $i < self::BLOCK; $i++ ) {
				$sum += ord( $h[ $i ] );
			}
			return substr_replace( $h, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
		}

		/**
		 * One PAX record "<len> key=value\n" where len counts the whole record.
		 *
		 * @param string $key   Key.
		 * @param string $value Value.
		 * @return string
		 */
		public static function pax_record( $key, $value ) {
			$body = ' ' . $key . '=' . $value . "\n";
			$len  = strlen( $body ) + 1;
			while ( strlen( $len . $body ) !== $len ) {
				$len = strlen( $len . $body );
			}
			return $len . $body;
		}

		/**
		 * Headers for one entry: an optional PAX extended header followed by the ustar header.
		 *
		 * @param string $name  Entry name.
		 * @param int    $size  Data size.
		 * @param int    $mtime Modification time.
		 * @param string $type  Type flag ('0' file, '5' directory).
		 * @param int    $mode  Permission bits.
		 * @return string
		 */
		public static function entry_headers( $name, $size, $mtime, $type = '0', $mode = 0644 ) {
			$pax = '';
			if ( strlen( $name ) > 100 ) {
				$pax .= self::pax_record( 'path', $name );
			}
			$field_size = $size;
			if ( $size > self::MAX_OCTAL_SIZE ) {
				$pax       .= self::pax_record( 'size', (string) $size );
				$field_size = 0;
			}
			$out = '';
			if ( '' !== $pax ) {
				$base  = basename( rtrim( $name, '/' ) );
				$out  .= self::raw_header( 'PaxHeader/' . substr( $base, 0, 80 ), strlen( $pax ), $mtime, 'x', 0644 );
				$out  .= $pax . str_repeat( "\0", self::padding( strlen( $pax ) ) );
			}
			$out .= self::raw_header( $name, $field_size, $mtime, $type, $mode );
			return $out;
		}

		/**
		 * Parse a numeric header field (octal, or GNU base-256 when the high bit is set).
		 *
		 * @param string $field Raw field.
		 * @return int
		 * @throws FSC_Exception When a base-256 value is out of the integer range.
		 */
		private static function parse_number( $field ) {
			if ( '' !== $field && ( ord( $field[0] ) & 0x80 ) ) {
				$n = ord( $field[0] ) & 0x7f;
				for ( $i = 1, $l = strlen( $field ); $i < $l; $i++ ) {
					$n = $n * 256 + ord( $field[ $i ] );
				}
				// A value that overflowed PHP's integer range is a float here;
				// casting it to int would wrap to a negative size and drive the
				// reader backwards. Reject it rather than return a bogus number.
				if ( is_float( $n ) || $n < 0 ) {
					throw new FSC_Exception( 'Corrupt tar archive: numeric header field out of range.' );
				}
				return (int) $n;
			}
			$field = trim( $field, " \0" );
			return '' === $field ? 0 : (int) octdec( $field );
		}

		/**
		 * Parse a 512 byte header block.
		 *
		 * @param string $block  Raw block.
		 * @param int    $offset Archive offset, for error messages.
		 * @return array|null Header fields, or null for an all-zero (end) block.
		 * @throws FSC_Exception On a checksum mismatch.
		 */
		public static function parse_header( $block, $offset = 0 ) {
			if ( strlen( $block ) !== self::BLOCK ) {
				throw new FSC_Exception( sprintf( 'Truncated tar header at offset %d.', $offset ) );
			}
			if ( trim( $block, "\0" ) === '' ) {
				return null;
			}
			$stored   = self::parse_number( substr( $block, 148, 8 ) );
			$unsigned = 0;
			$signed   = 0;
			$check    = substr_replace( $block, '        ', 148, 8 );
			for ( $i = 0; $i < self::BLOCK; $i++ ) {
				$c         = ord( $check[ $i ] );
				$unsigned += $c;
				$signed   += $c > 127 ? $c - 256 : $c;
			}
			if ( $stored !== $unsigned && $stored !== $signed ) {
				throw new FSC_Exception( sprintf( 'Corrupt tar archive: bad header checksum at offset %d.', $offset ) );
			}
			$name   = rtrim( substr( $block, 0, 100 ), "\0" );
			$magic  = substr( $block, 257, 5 );
			$prefix = 'ustar' === $magic ? rtrim( substr( $block, 345, 155 ), "\0" ) : '';
			if ( '' !== $prefix ) {
				$name = $prefix . '/' . $name;
			}
			$type = $block[156];
			if ( "\0" === $type ) {
				$type = '0';
			}
			return array(
				'name'     => $name,
				'mode'     => self::parse_number( substr( $block, 100, 8 ) ),
				'size'     => self::parse_number( substr( $block, 124, 12 ) ),
				'mtime'    => self::parse_number( substr( $block, 136, 12 ) ),
				'type'     => $type,
				'linkname' => rtrim( substr( $block, 157, 100 ), "\0" ),
			);
		}

		/**
		 * Parse PAX extended header data into key => value.
		 *
		 * @param string $data Raw PAX data.
		 * @return array
		 */
		public static function parse_pax( $data ) {
			$out = array();
			$pos = 0;
			$len = strlen( $data );
			while ( $pos < $len ) {
				$sp = strpos( $data, ' ', $pos );
				if ( false === $sp ) {
					break;
				}
				$reclen = (int) substr( $data, $pos, $sp - $pos );
				if ( $reclen <= 0 || $pos + $reclen > $len ) {
					break;
				}
				$record = substr( $data, $sp + 1, $reclen - ( $sp - $pos ) - 2 );
				$eq     = strpos( $record, '=' );
				if ( false !== $eq ) {
					$out[ substr( $record, 0, $eq ) ] = substr( $record, $eq + 1 );
				}
				$pos += $reclen;
			}
			return $out;
		}
	}

	/**
	 * Appending tar writer. The caller persists size() and the entry state
	 * returned by begin_file() and passes them back to resume.
	 */
	class FSC_Tar_Writer {

		/** @var resource */
		private $fp;

		/**
		 * Open (or create) an archive and truncate it to the last checkpoint.
		 *
		 * @param string $path        Archive path.
		 * @param int    $resume_size Size of the archive at the last checkpoint (0 for a new one).
		 * @throws FSC_Exception When the file cannot be opened.
		 */
		public function __construct( $path, $resume_size = 0 ) {
			$fp = @fopen( $path, 'c+b' );
			if ( ! $fp ) {
				throw new FSC_Exception( sprintf( 'Cannot open archive for writing: %s', basename( $path ) ) );
			}
			$this->fp = $fp;
			ftruncate( $this->fp, (int) $resume_size );
			fseek( $this->fp, (int) $resume_size );
		}

		/**
		 * Current archive size, flushed.
		 *
		 * @return int
		 */
		public function size() {
			fflush( $this->fp );
			return ftell( $this->fp );
		}

		/**
		 * Write bytes, failing loudly on a short write (disk full).
		 *
		 * @param string $data Data.
		 * @throws FSC_Exception On a short write.
		 */
		private function write( $data ) {
			$len = strlen( $data );
			if ( 0 === $len ) {
				return;
			}
			$written = fwrite( $this->fp, $data );
			if ( $written !== $len ) {
				throw new FSC_Exception( 'Writing to the archive failed (disk full?).' );
			}
		}

		/**
		 * Add an in-memory file.
		 *
		 * @param string   $name  Entry name.
		 * @param string   $data  Content.
		 * @param int|null $mtime Modification time.
		 */
		public function add_string( $name, $data, $mtime = null ) {
			$this->write( FSC_Tar::entry_headers( $name, strlen( $data ), null === $mtime ? time() : $mtime ) );
			$this->write( $data );
			$this->write( str_repeat( "\0", FSC_Tar::padding( strlen( $data ) ) ) );
		}

		/**
		 * Add a directory entry.
		 *
		 * @param string   $name  Directory name (a trailing slash is added).
		 * @param int|null $mtime Modification time.
		 */
		public function add_dir( $name, $mtime = null ) {
			$name = rtrim( $name, '/' ) . '/';
			$this->write( FSC_Tar::entry_headers( $name, 0, null === $mtime ? time() : $mtime, '5', 0755 ) );
		}

		/**
		 * Write the headers of a file entry. The declared size is frozen here.
		 *
		 * @param string   $src   Source file path.
		 * @param string   $name  Entry name.
		 * @param int|null $mtime Modification time (defaults to the file's).
		 * @return array Entry state to pass to write_chunk() (JSON-serializable).
		 * @throws FSC_Exception When the file cannot be read.
		 */
		public function begin_file( $src, $name, $mtime = null ) {
			clearstatcache( true, $src );
			$size = @filesize( $src );
			if ( false === $size || ! is_readable( $src ) ) {
				throw new FSC_Exception( sprintf( 'Cannot read file: %s', $name ) );
			}
			if ( null === $mtime ) {
				$mtime = (int) @filemtime( $src );
			}
			$this->write( FSC_Tar::entry_headers( $name, $size, $mtime ) );
			return array(
				'src'  => $src,
				'name' => $name,
				'size' => $size,
				'done' => 0,
			);
		}

		/**
		 * Copy up to $max_bytes of the current file. A file that shrank since
		 * begin_file() is zero-padded to the declared size; growth is ignored.
		 *
		 * @param array $entry     State from begin_file(), updated in place.
		 * @param int   $max_bytes Upper bound for this call.
		 * @return bool True when the entry is complete (padding written).
		 */
		public function write_chunk( array &$entry, $max_bytes ) {
			$remaining = $entry['size'] - $entry['done'];
			if ( $remaining > 0 ) {
				$want = min( $remaining, max( 1, (int) $max_bytes ) );
				$in   = @fopen( $entry['src'], 'rb' );
				$got  = 0;
				if ( $in ) {
					if ( $entry['done'] > 0 ) {
						fseek( $in, $entry['done'] );
					}
					while ( $got < $want ) {
						$buf = fread( $in, min( 1048576, $want - $got ) );
						if ( false === $buf || '' === $buf ) {
							break;
						}
						$this->write( $buf );
						$got += strlen( $buf );
					}
					fclose( $in );
				}
				if ( $got < $want ) {
					$this->write( str_repeat( "\0", $want - $got ) );
					$entry['short'] = true;
				}
				$entry['done'] += $want;
			}
			if ( $entry['done'] >= $entry['size'] ) {
				$this->write( str_repeat( "\0", FSC_Tar::padding( $entry['size'] ) ) );
				return true;
			}
			return false;
		}

		/**
		 * Write the two zero end blocks.
		 */
		public function finish() {
			$this->write( str_repeat( "\0", FSC_Tar::BLOCK * 2 ) );
			fflush( $this->fp );
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

	/**
	 * Tar reader with a JSON-serializable cursor:
	 *   offset => archive offset of the next header,
	 *   entry  => current entry (name, type, size, mtime, mode, data, read) or null.
	 */
	class FSC_Tar_Reader {

		/** @var resource */
		private $fp;

		/** @var int */
		private $size;

		/**
		 * Open an archive.
		 *
		 * @param string $path Archive path.
		 * @throws FSC_Exception When the file cannot be opened.
		 */
		public function __construct( $path ) {
			$fp = @fopen( $path, 'rb' );
			if ( ! $fp ) {
				throw new FSC_Exception( sprintf( 'Cannot open archive: %s', basename( $path ) ) );
			}
			$this->fp   = $fp;
			$this->size = (int) filesize( $path );
		}

		/**
		 * Archive size in bytes.
		 *
		 * @return int
		 */
		public function archive_size() {
			return $this->size;
		}

		/**
		 * Fresh cursor.
		 *
		 * @return array
		 */
		public static function new_cursor() {
			return array(
				'offset' => 0,
				'entry'  => null,
			);
		}

		/**
		 * Read exactly $len bytes at $offset.
		 *
		 * @param int $offset Offset.
		 * @param int $len    Length.
		 * @return string
		 */
		private function read_at( $offset, $len ) {
			fseek( $this->fp, $offset );
			$out = '';
			while ( strlen( $out ) < $len ) {
				$buf = fread( $this->fp, $len - strlen( $out ) );
				if ( false === $buf || '' === $buf ) {
					break;
				}
				$out .= $buf;
			}
			return $out;
		}

		/**
		 * Normalise a type flag to file, dir, symlink, hardlink or other.
		 *
		 * @param string $flag Type flag.
		 * @return string
		 */
		private static function type_name( $flag ) {
			switch ( $flag ) {
				case '0':
				case '7':
					return 'file';
				case '5':
					return 'dir';
				case '2':
					return 'symlink';
				case '1':
					return 'hardlink';
			}
			return 'other';
		}

		/**
		 * Advance to the next real entry (PAX and GNU long name headers are consumed).
		 * Any unread data of the current entry is skipped.
		 *
		 * @param array $cursor Cursor, updated in place.
		 * @return array|null Entry, or null at the end of the archive.
		 * @throws FSC_Exception On a corrupt or truncated archive.
		 */
		public function next_entry( array &$cursor ) {
			$pax       = array();
			$long_name = null;
			while ( true ) {
				$offset = (int) $cursor['offset'];
				if ( $offset >= $this->size ) {
					$cursor['entry'] = null;
					return null;
				}
				$block = $this->read_at( $offset, FSC_Tar::BLOCK );
				if ( strlen( $block ) < FSC_Tar::BLOCK ) {
					throw new FSC_Exception( sprintf( 'Truncated tar archive at offset %d.', $offset ) );
				}
				$h = FSC_Tar::parse_header( $block, $offset );
				if ( null === $h ) {
					$cursor['entry'] = null;
					return null;
				}
				if ( $h['size'] < 0 ) {
					throw new FSC_Exception( sprintf( 'Corrupt tar archive: negative entry size at offset %d.', $offset ) );
				}
				$data = $offset + FSC_Tar::BLOCK;
				if ( 'x' === $h['type'] || 'g' === $h['type'] || 'L' === $h['type'] || 'K' === $h['type'] ) {
					if ( $h['size'] > 16777216 ) {
						throw new FSC_Exception( sprintf( 'Oversized extended tar header at offset %d.', $offset ) );
					}
					$raw              = $this->read_at( $data, $h['size'] );
					$cursor['offset'] = $data + $h['size'] + FSC_Tar::padding( $h['size'] );
					if ( $cursor['offset'] <= $offset ) {
						throw new FSC_Exception( sprintf( 'Corrupt tar archive: header at offset %d does not advance.', $offset ) );
					}
					if ( 'x' === $h['type'] ) {
						$pax = array_merge( $pax, FSC_Tar::parse_pax( $raw ) );
					} elseif ( 'L' === $h['type'] ) {
						$long_name = rtrim( $raw, "\0" );
					}
					continue;
				}
				$name = $h['name'];
				if ( null !== $long_name ) {
					$name = $long_name;
				}
				if ( isset( $pax['path'] ) ) {
					$name = $pax['path'];
				}
				$size = isset( $pax['size'] ) ? (int) $pax['size'] : $h['size'];
				if ( $size < 0 || ( isset( $pax['size'] ) && ! ctype_digit( (string) $pax['size'] ) ) ) {
					throw new FSC_Exception( sprintf( 'Corrupt tar archive: invalid size of entry at offset %d.', $offset ) );
				}
				$type = self::type_name( $h['type'] );
				if ( 'dir' === $type || 'symlink' === $type || 'hardlink' === $type ) {
					$size = 'dir' === $type ? 0 : $size;
				}
				$mtime = isset( $pax['mtime'] ) ? (int) $pax['mtime'] : $h['mtime'];
				if ( $data + $size > $this->size ) {
					throw new FSC_Exception( sprintf( 'Truncated tar archive: entry "%s" extends past the end of the file.', $name ) );
				}
				$cursor['offset'] = $data + $size + FSC_Tar::padding( $size );
				$cursor['entry']  = array(
					'name'  => $name,
					'type'  => $type,
					'size'  => $size,
					'mtime' => $mtime,
					'mode'  => $h['mode'],
					'link'  => isset( $pax['linkpath'] ) ? $pax['linkpath'] : $h['linkname'],
					'data'  => $data,
					'read'  => 0,
				);
				return $cursor['entry'];
			}
		}

		/**
		 * Read the next piece of the current entry.
		 *
		 * @param array $cursor Cursor, updated in place.
		 * @param int   $max    Maximum bytes.
		 * @return string Empty string when the entry is exhausted.
		 * @throws FSC_Exception On a truncated archive.
		 */
		public function read( array &$cursor, $max ) {
			if ( empty( $cursor['entry'] ) ) {
				return '';
			}
			$e    = $cursor['entry'];
			$want = min( (int) $max, $e['size'] - $e['read'] );
			if ( $want <= 0 ) {
				return '';
			}
			$buf = $this->read_at( $e['data'] + $e['read'], $want );
			if ( strlen( $buf ) !== $want ) {
				throw new FSC_Exception( sprintf( 'Truncated tar archive while reading "%s".', $e['name'] ) );
			}
			$cursor['entry']['read'] += $want;
			return $buf;
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
}
