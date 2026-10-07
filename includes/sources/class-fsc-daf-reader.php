<?php
/**
 * Pure PHP reader for Duplicator's DupArchive (.daf) format. No WordPress
 * dependency. See internal/formats/duplicator.md.
 *
 * Archive header: <A><V>ver</V><X>u16 flags</X><P>pass hash</P></A>
 * File record:    <F><FS>size</FS><MT>mtime</MT><P>perms</P><X>u16</X><HA>crc</HA><RPL>len</RPL><RP>path</RP></F>
 *                 followed by globs <G><OS>orig</OS><SS>stored</SS><HA>crc</HA></G>{stored bytes}
 * Dir record:     <D><MT>mtime</MT><P>perms</P><RPL>len</RPL><RP>path</RP></D>
 * Stored bytes are raw DEFLATE when the file's compress flag is set.
 *
 * Cursor (JSON-safe): array( offset => next record offset, entry => null |
 * array( name, size, compressed, read, glob => offset of the current glob
 * header, gpos => bytes of that glob already returned ) ).
 *
 * @package wp-free-site-cloner
 */

/**
 * DupArchive reader.
 */
class FSC_Daf_Reader {

	const FLAG_COMPRESS = 1;
	const FLAG_CRYPT    = 2;

	/** Largest original size of one glob we accept (Duplicator writes 1 MiB). */
	const MAX_GLOB = 8388608;

	/** @var string */
	private $path;

	/** @var resource */
	private $fp;

	/** @var int */
	private $size;

	/** @var array|null */
	private $header = null;

	/** @var array|null Last decoded glob: array( offset, data ). */
	private $cache = null;

	/**
	 * Constructor.
	 *
	 * @param string $path Archive.
	 * @throws FSC_Exception When unreadable.
	 */
	public function __construct( $path ) {
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			throw new FSC_Exception( 'Cannot open the .daf archive.' );
		}
		clearstatcache( true, $path );
		$this->path = $path;
		$this->fp   = $fp;
		$this->size = (int) filesize( $path );
	}

	/**
	 * Close.
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

	/**
	 * Archive size.
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
			'offset' => null,
			'entry'  => null,
		);
	}

	/**
	 * Bytes at $offset (may be shorter at the end of the file).
	 *
	 * @param int $offset Offset.
	 * @param int $len    Length.
	 * @return string
	 */
	private function read_at( $offset, $len ) {
		if ( $offset >= $this->size || $len <= 0 ) {
			return '';
		}
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
	 * Corrupt archive exception.
	 *
	 * @param int    $offset Offset.
	 * @param string $what   Detail.
	 * @return FSC_Exception
	 */
	private static function corrupt( $offset, $what ) {
		return new FSC_Exception( sprintf( 'The .daf archive is corrupt or truncated at byte %d (%s).', $offset, $what ) );
	}

	/**
	 * Expect a literal at $pos.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Position, advanced.
	 * @param string $lit Literal.
	 * @return bool
	 */
	private static function lit( $buf, &$pos, $lit ) {
		if ( substr( $buf, $pos, strlen( $lit ) ) !== $lit ) {
			return false;
		}
		$pos += strlen( $lit );
		return true;
	}

	/**
	 * Text tag <T>value</T> at $pos.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Position, advanced.
	 * @param string $tag Tag.
	 * @return string|null Value or null when absent/unterminated.
	 */
	private static function tag( $buf, &$pos, $tag ) {
		$open = '<' . $tag . '>';
		if ( substr( $buf, $pos, strlen( $open ) ) !== $open ) {
			return null;
		}
		$close = '</' . $tag . '>';
		$end   = strpos( $buf, $close, $pos + strlen( $open ) );
		if ( false === $end || $end - $pos > 4096 ) {
			return null;
		}
		$val = substr( $buf, $pos + strlen( $open ), $end - $pos - strlen( $open ) );
		$pos = $end + strlen( $close );
		return $val;
	}

	/**
	 * Raw 2-byte flag tag <X>..</X> at $pos.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Position, advanced.
	 * @return int|null
	 */
	private static function flags( $buf, &$pos ) {
		if ( '<X>' !== substr( $buf, $pos, 3 ) || '</X>' !== substr( $buf, $pos + 5, 4 ) ) {
			return null;
		}
		$v    = unpack( 'v', substr( $buf, $pos + 3, 2 ) );
		$pos += 9;
		return (int) $v[1];
	}

	/**
	 * Parse the archive header.
	 *
	 * @return array array( version, compressed, encrypted, length ).
	 * @throws FSC_Exception When it is not a DupArchive.
	 */
	public function header() {
		if ( null !== $this->header ) {
			return $this->header;
		}
		$buf = $this->read_at( 0, 4096 );
		$pos = 0;
		if ( ! self::lit( $buf, $pos, '<A>' ) ) {
			throw new FSC_Exception( 'This is not a Duplicator .daf archive.' );
		}
		$ver = self::tag( $buf, $pos, 'V' );
		if ( null === $ver || ! preg_match( '/^\d+(\.\d+)*$/', $ver ) ) {
			throw new FSC_Exception( 'This is not a Duplicator .daf archive (no version).' );
		}
		$compressed = false;
		$encrypted  = false;
		for ( $i = 0; $i < 16 && '</A>' !== substr( $buf, $pos, 4 ); $i++ ) {
			if ( '<X>' === substr( $buf, $pos, 3 ) ) {
				$f = self::flags( $buf, $pos );
				if ( null === $f ) {
					throw self::corrupt( $pos, 'archive flags' );
				}
				$compressed = (bool) ( $f & self::FLAG_COMPRESS );
				$encrypted  = $encrypted || (bool) ( $f & self::FLAG_CRYPT );
				continue;
			}
			if ( ! preg_match( '/^<([A-Z]{1,3})>/', substr( $buf, $pos, 5 ), $m ) ) {
				throw self::corrupt( $pos, 'archive header' );
			}
			$val = self::tag( $buf, $pos, $m[1] );
			if ( null === $val ) {
				throw self::corrupt( $pos, 'archive header' );
			}
			if ( 'C' === $m[1] ) {
				$compressed = in_array( strtolower( $val ), array( 'true', '1' ), true );
			} elseif ( 'P' === $m[1] && '' !== $val ) {
				$encrypted = true;
			}
		}
		if ( ! self::lit( $buf, $pos, '</A>' ) ) {
			throw self::corrupt( $pos, 'archive header end' );
		}
		$this->header = array(
			'version'    => $ver,
			'compressed' => $compressed,
			'encrypted'  => $encrypted,
			'length'     => $pos,
		);
		return $this->header;
	}

	/**
	 * Parse the record at $offset.
	 *
	 * @param int $offset Offset.
	 * @return array|null array( type file|dir, name, size, mtime, compressed, data => offset after the record ) or null at EOF.
	 * @throws FSC_Exception On corrupt data.
	 */
	public function record_at( $offset ) {
		if ( $offset >= $this->size ) {
			return null;
		}
		$buf  = $this->read_at( $offset, 8192 );
		$pos  = 0;
		$kind = substr( $buf, 0, 3 );
		if ( '<F>' !== $kind && '<D>' !== $kind ) {
			throw self::corrupt( $offset, 'expected a file or directory record' );
		}
		$pos   = 3;
		$size  = 0;
		$flags = 0;
		if ( '<F>' === $kind ) {
			$size = self::tag( $buf, $pos, 'FS' );
			if ( null === $size || ! ctype_digit( $size ) ) {
				throw self::corrupt( $offset, 'file size' );
			}
		}
		$mtime = self::tag( $buf, $pos, 'MT' );
		$perms = self::tag( $buf, $pos, 'P' );
		if ( null === $mtime || null === $perms ) {
			throw self::corrupt( $offset, 'record fields' );
		}
		if ( '<F>' === $kind ) {
			if ( '<X>' === substr( $buf, $pos, 3 ) ) {
				$flags = self::flags( $buf, $pos );
				if ( null === $flags ) {
					throw self::corrupt( $offset, 'file flags' );
				}
			} else {
				$flags = $this->header()['compressed'] ? self::FLAG_COMPRESS : 0;
			}
			if ( null === self::tag( $buf, $pos, 'HA' ) ) {
				throw self::corrupt( $offset, 'file hash' );
			}
		}
		$rpl = self::tag( $buf, $pos, 'RPL' );
		if ( null === $rpl || ! ctype_digit( $rpl ) || (int) $rpl > 65535 ) {
			throw self::corrupt( $offset, 'path length' );
		}
		$rpl  = (int) $rpl;
		$need = $pos + 4 + $rpl + 9;
		if ( $need > strlen( $buf ) ) {
			$buf = $this->read_at( $offset, $need );
		}
		if ( ! self::lit( $buf, $pos, '<RP>' ) || strlen( $buf ) < $pos + $rpl ) {
			throw self::corrupt( $offset, 'path' );
		}
		$name = substr( $buf, $pos, $rpl );
		$pos += $rpl;
		$end  = '<F>' === $kind ? '</RP></F>' : '</RP></D>';
		if ( ! self::lit( $buf, $pos, $end ) ) {
			throw self::corrupt( $offset, 'record end' );
		}
		return array(
			'type'       => '<F>' === $kind ? 'file' : 'dir',
			'name'       => $name,
			'size'       => (int) $size,
			'mtime'      => (int) $mtime,
			'compressed' => (bool) ( $flags & self::FLAG_COMPRESS ),
			'encrypted'  => (bool) ( $flags & self::FLAG_CRYPT ),
			'data'       => $offset + $pos,
		);
	}

	/**
	 * Parse the glob header at $offset.
	 *
	 * @param int $offset Offset.
	 * @return array array( orig, stored, crc, data => offset of the stored bytes ).
	 * @throws FSC_Exception On corrupt data.
	 */
	public function glob_at( $offset ) {
		$buf = $this->read_at( $offset, 256 );
		$pos = 0;
		if ( ! self::lit( $buf, $pos, '<G>' ) ) {
			throw self::corrupt( $offset, 'expected a data block' );
		}
		$os = self::tag( $buf, $pos, 'OS' );
		$ss = self::tag( $buf, $pos, 'SS' );
		if ( null === $os || null === $ss || ! ctype_digit( $os ) || ! ctype_digit( $ss ) || (int) $os > self::MAX_GLOB || (int) $ss > 2 * self::MAX_GLOB ) {
			throw self::corrupt( $offset, 'data block sizes' );
		}
		$ha = '';
		if ( '<HA>' === substr( $buf, $pos, 4 ) ) {
			$ha = (string) self::tag( $buf, $pos, 'HA' );
		}
		if ( ! self::lit( $buf, $pos, '</G>' ) ) {
			throw self::corrupt( $offset, 'data block end' );
		}
		if ( $offset + $pos + (int) $ss > $this->size ) {
			throw self::corrupt( $offset, 'data block beyond the end of the file' );
		}
		return array(
			'orig'   => (int) $os,
			'stored' => (int) $ss,
			'crc'    => strtolower( $ha ),
			'data'   => $offset + $pos,
		);
	}

	/**
	 * Offset right after the last glob of a file entry whose data starts at
	 * $glob with $done original bytes before it.
	 *
	 * @param int $glob Offset of a glob header.
	 * @param int $done Original bytes before that glob.
	 * @param int $size File size.
	 * @return int
	 * @throws FSC_Exception On corrupt data.
	 */
	private function skip_globs( $glob, $done, $size ) {
		while ( $done < $size ) {
			$g = $this->glob_at( $glob );
			if ( $g['orig'] <= 0 ) {
				throw self::corrupt( $glob, 'empty data block' );
			}
			$done += $g['orig'];
			$glob  = $g['data'] + $g['stored'];
		}
		return $glob;
	}

	/**
	 * Advance to the next record. Unread data of the previous file is skipped.
	 *
	 * @param array $cursor Cursor, updated in place.
	 * @return array|null array( name, type, size, mtime ) or null at the end.
	 * @throws FSC_Exception On corrupt or encrypted archives.
	 */
	public function next_entry( array &$cursor ) {
		$h = $this->header();
		if ( $h['encrypted'] ) {
			throw new FSC_Exception( 'This .daf archive is encrypted, which is not supported.' );
		}
		if ( null === $cursor['offset'] ) {
			$cursor['offset'] = $h['length'];
		}
		if ( ! empty( $cursor['entry'] ) ) {
			$e                = $cursor['entry'];
			$cursor['offset'] = $this->skip_globs( (int) $e['glob'], (int) $e['read'] - (int) $e['gpos'], (int) $e['size'] );
			$cursor['entry']  = null;
		}
		$at = (int) $cursor['offset'];
		$r  = $this->record_at( $at );
		if ( null === $r ) {
			return null;
		}
		if ( 'dir' === $r['type'] ) {
			$cursor['offset'] = $r['data'];
		} else {
			if ( $r['encrypted'] ) {
				throw new FSC_Exception( 'This .daf archive contains encrypted files, which is not supported.' );
			}
			$cursor['entry'] = array(
				'name'       => $r['name'],
				'size'       => $r['size'],
				'compressed' => $r['compressed'],
				'read'       => 0,
				'glob'       => $r['data'],
				'gpos'       => 0,
			);
		}
		return array(
			'name'   => $r['name'],
			'type'   => $r['type'],
			'size'   => $r['size'],
			'mtime'  => $r['mtime'],
			'offset' => $at,
		);
	}

	/**
	 * Decoded data of the glob at $offset (cached for repeated partial reads).
	 *
	 * @param int  $offset     Glob header offset.
	 * @param bool $compressed Compress flag of the file.
	 * @param int  $max_orig   Bytes still allowed for the file this glob belongs to.
	 * @return array array( data, next => offset after the glob ).
	 * @throws FSC_Exception On corrupt data.
	 */
	private function glob_data( $offset, $compressed, $max_orig ) {
		if ( null !== $this->cache && $this->cache['offset'] === $offset ) {
			return $this->cache;
		}
		$g = $this->glob_at( $offset );
		// Refuse a block that claims more data than the file has left before
		// reading or inflating it, so a tiny record cannot cost a lot of memory.
		if ( $g['orig'] > (int) $max_orig ) {
			throw self::corrupt( $offset, 'data block larger than the file it belongs to' );
		}
		$raw = $this->read_at( $g['data'], $g['stored'] );
		if ( strlen( $raw ) !== $g['stored'] ) {
			throw self::corrupt( $offset, 'truncated data block' );
		}
		$data = $raw;
		if ( $compressed ) {
			// Bounded: a crafted block cannot inflate beyond its declared size.
			$data = @gzinflate( $raw, $g['orig'] + 1 );
			if ( false === $data ) {
				if ( $g['stored'] !== $g['orig'] ) {
					throw self::corrupt( $offset, 'cannot decompress data block' );
				}
				$data = $raw;
			}
		}
		if ( strlen( $data ) !== $g['orig'] ) {
			throw self::corrupt( $offset, 'data block size mismatch' );
		}
		if ( preg_match( '/^[0-9a-f]{8}$/', $g['crc'] ) && sprintf( '%08x', crc32( $data ) & 0xFFFFFFFF ) !== $g['crc'] ) {
			throw self::corrupt( $offset, 'CRC mismatch' );
		}
		$this->cache = array(
			'offset' => $offset,
			'data'   => $data,
			'next'   => $g['data'] + $g['stored'],
		);
		return $this->cache;
	}

	/**
	 * Read up to $max bytes of the current file ('' when exhausted). Returns
	 * at most the rest of one data block per call.
	 *
	 * @param array $cursor Cursor, updated in place.
	 * @param int   $max    Maximum bytes.
	 * @return string
	 * @throws FSC_Exception On corrupt data.
	 */
	public function read( array &$cursor, $max ) {
		if ( empty( $cursor['entry'] ) ) {
			return '';
		}
		$e = &$cursor['entry'];
		if ( $e['read'] >= $e['size'] ) {
			return '';
		}
		$max = (int) $e['size'] - ( (int) $e['read'] - (int) $e['gpos'] );
		$g   = $this->glob_data( (int) $e['glob'], (bool) $e['compressed'], $max );
		$out = (string) substr( $g['data'], (int) $e['gpos'], max( 1, (int) $max ) );
		if ( '' === $out ) {
			throw self::corrupt( (int) $e['glob'], 'data block shorter than expected' );
		}
		$e['gpos'] += strlen( $out );
		$e['read'] += strlen( $out );
		if ( $e['gpos'] >= strlen( $g['data'] ) ) {
			$e['glob'] = $g['next'];
			$e['gpos'] = 0;
		}
		if ( $e['read'] > $e['size'] ) {
			throw self::corrupt( (int) $e['glob'], 'file data longer than its size' );
		}
		return $out;
	}

	/**
	 * Current position (for progress).
	 *
	 * @param array $cursor Cursor.
	 * @return int
	 */
	public static function position( array $cursor ) {
		if ( ! empty( $cursor['entry'] ) ) {
			return (int) $cursor['entry']['glob'];
		}
		return (int) $cursor['offset'];
	}
}
