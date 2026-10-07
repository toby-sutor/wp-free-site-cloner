<?php
/**
 * Archive integrity for native .tar archives: the fixed-width archive_size
 * field in the manifest and the fsc-checksum.json trailer with one SHA-256
 * per segment. No WordPress dependency apart from __() (stubbed by the unit
 * test bootstrap).
 *
 * Layout (see internal/API.md): the manifest is the first entry (data at
 * offset 512); the trailer is the last entry before the two zero blocks and
 * covers bytes [0, covered_bytes), where covered_bytes is the offset of the
 * trailer's own header.
 *
 * @package wp-free-site-cloner
 */

/**
 * Integrity helpers.
 */
class FSC_Integrity {

	/** Segment size used by the exporter. */
	const SEGMENT = 67108864;

	/** Trailer entry name. */
	const TRAILER = 'fsc-checksum.json';

	/** Manifest key of the total archive size. */
	const SIZE_KEY = 'archive_size';

	/** Unpatched archive_size value. */
	const SIZE_PLACEHOLDER = '00000000000000000000';

	/** How far before the end blocks the trailer header is searched for. */
	const SEARCH_WINDOW = 8389120;

	/** Smallest segment size accepted from a trailer (bounds the segment count). */
	const MIN_SEGMENT = 1048576;

	/** Largest segment size accepted from a trailer (bounds the work per segment). */
	const MAX_SEGMENT = 67108864;

	/**
	 * Number of segments for $covered bytes.
	 *
	 * @param int $covered       Covered bytes.
	 * @param int $segment_bytes Segment size.
	 * @return int
	 */
	public static function segment_count( $covered, $segment_bytes ) {
		$covered       = (int) $covered;
		$segment_bytes = (int) $segment_bytes;
		if ( $covered <= 0 || $segment_bytes <= 0 ) {
			return 0;
		}
		return intdiv( $covered + $segment_bytes - 1, $segment_bytes );
	}

	/**
	 * Trailer JSON (compact; every hash has 64 characters, so the length only
	 * depends on covered_bytes, the segment size and the segment count).
	 *
	 * @param int   $covered       Covered bytes.
	 * @param array $segments      Hex hashes.
	 * @param int   $segment_bytes Segment size.
	 * @return string
	 */
	public static function trailer_json( $covered, array $segments, $segment_bytes = self::SEGMENT ) {
		return json_encode(
			array(
				'version'       => 1,
				'algo'          => 'sha256',
				'segment_bytes' => (int) $segment_bytes,
				'covered_bytes' => (int) $covered,
				'segments'      => array_values( array_map( 'strval', $segments ) ),
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Final archive size when the trailer header starts at $covered.
	 *
	 * @param int $covered       Covered bytes (512-aligned).
	 * @param int $segment_bytes Segment size.
	 * @return int
	 */
	public static function final_size( $covered, $segment_bytes = self::SEGMENT ) {
		$n    = self::segment_count( $covered, $segment_bytes );
		$json = self::trailer_json( $covered, $n > 0 ? array_fill( 0, $n, str_repeat( '0', 64 ) ) : array(), $segment_bytes );
		$len  = strlen( $json );
		return (int) $covered + strlen( FSC_Tar::entry_headers( self::TRAILER, $len, 0 ) ) + $len + FSC_Tar::padding( $len ) + 2 * FSC_Tar::BLOCK;
	}

	/**
	 * The 20 digit archive_size value.
	 *
	 * @param int $size Size in bytes.
	 * @return string
	 */
	public static function size_digits( $size ) {
		return str_pad( (string) max( 0, (int) $size ), 20, '0', STR_PAD_LEFT );
	}

	/**
	 * Offset of the archive_size digits inside the manifest JSON, or null.
	 *
	 * @param string $json Manifest JSON.
	 * @return int|null
	 */
	public static function size_offset_in( $json ) {
		$needle = '"' . self::SIZE_KEY . '":"';
		$pos    = strpos( $json, $needle );
		if ( false === $pos || ! preg_match( '/^\d{20}"/', substr( $json, $pos + strlen( $needle ), 21 ) ) ) {
			return null;
		}
		return $pos + strlen( $needle );
	}

	/**
	 * Write the archive_size digits in place (idempotent).
	 *
	 * @param string $path   Archive.
	 * @param int    $offset File offset of the 20 digits.
	 * @param int    $size   Final size.
	 * @throws FSC_Exception When the field is not where it should be.
	 */
	public static function patch_size( $path, $offset, $size ) {
		$fp = @fopen( $path, 'r+b' );
		if ( ! $fp ) {
			throw new FSC_Exception( 'Cannot open the archive to record its size.' );
		}
		fseek( $fp, (int) $offset );
		$cur = fread( $fp, 20 );
		if ( ! is_string( $cur ) || ! preg_match( '/^\d{20}$/', $cur ) ) {
			fclose( $fp );
			throw new FSC_Exception( 'The archive_size field of the manifest was not found.' );
		}
		$digits = self::size_digits( $size );
		if ( $cur !== $digits ) {
			fseek( $fp, (int) $offset );
			$ok = 20 === fwrite( $fp, $digits );
			fflush( $fp );
			if ( ! $ok ) {
				fclose( $fp );
				throw new FSC_Exception( 'Writing the archive size failed (disk full?).' );
			}
		}
		fclose( $fp );
	}

	/**
	 * SHA-256 of segment $k.
	 *
	 * @param string $path          Archive.
	 * @param int    $k             Segment index (0-based).
	 * @param int    $segment_bytes Segment size.
	 * @param int    $covered       Covered bytes.
	 * @return string Hex hash.
	 * @throws FSC_Exception When the file is shorter than the segment.
	 */
	public static function hash_segment( $path, $k, $segment_bytes, $covered ) {
		$start = (int) $k * (int) $segment_bytes;
		$len   = min( (int) $segment_bytes, (int) $covered - $start );
		if ( $len <= 0 ) {
			throw new FSC_Exception( 'Checksum segment out of range.' );
		}
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			throw new FSC_Exception( 'Cannot open the archive to compute checksums.' );
		}
		fseek( $fp, $start );
		$ctx  = hash_init( 'sha256' );
		$left = $len;
		while ( $left > 0 ) {
			$buf = fread( $fp, (int) min( 1048576, $left ) );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			hash_update( $ctx, $buf );
			$left -= strlen( $buf );
		}
		fclose( $fp );
		if ( $left > 0 ) {
			throw new FSC_Exception( 'The archive is shorter than its checksum list says.' );
		}
		return hash_final( $ctx );
	}

	/**
	 * Whether a hash context survives serialize() (PHP 8+), so a segment can
	 * be hashed across requests.
	 *
	 * @return bool
	 */
	public static function can_resume_hash() {
		static $ok = null;
		if ( null === $ok ) {
			try {
				$ok = is_string( serialize( hash_init( 'sha256' ) ) );
			} catch ( Throwable $e ) {
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Hash segment $k in 1 MB reads until the deadline. Where PHP can
	 * serialize hash contexts (8+), an unfinished segment is carried over in
	 * $state (offset + context) to the next call; on PHP 7 a segment is always
	 * finished in one call (at most MAX_SEGMENT bytes).
	 *
	 * @param string     $path          Archive.
	 * @param int        $k             Segment index.
	 * @param int        $segment_bytes Segment size.
	 * @param int        $covered       Covered bytes.
	 * @param array|null $state         Carry-over state, updated in place (null when the segment is done).
	 * @param float      $deadline      microtime deadline.
	 * @return string|null Hex hash, or null when the segment is not finished yet.
	 * @throws FSC_Exception When the file is shorter than the segment.
	 */
	public static function hash_segment_step( $path, $k, $segment_bytes, $covered, &$state, $deadline ) {
		$start = (int) $k * (int) $segment_bytes;
		$len   = min( (int) $segment_bytes, (int) $covered - $start );
		if ( $len <= 0 ) {
			throw new FSC_Exception( 'Checksum segment out of range.' );
		}
		$resume = self::can_resume_hash();
		$ctx    = null;
		$off    = 0;
		if ( $resume && is_array( $state ) && isset( $state['k'], $state['off'], $state['ctx'] ) && (int) $state['k'] === (int) $k ) {
			$ctx = @unserialize( (string) base64_decode( (string) $state['ctx'] ), array( 'allowed_classes' => array( 'HashContext' ) ) );
			$off = (int) $state['off'];
			if ( ! ( $ctx instanceof HashContext ) || $off <= 0 || $off >= $len ) {
				$ctx = null;
				$off = 0;
			}
		}
		if ( null === $ctx ) {
			$ctx = hash_init( 'sha256' );
		}
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			throw new FSC_Exception( 'Cannot open the archive to compute checksums.' );
		}
		fseek( $fp, $start + $off );
		while ( $off < $len ) {
			$buf = fread( $fp, (int) min( 1048576, $len - $off ) );
			if ( false === $buf || '' === $buf ) {
				fclose( $fp );
				throw new FSC_Exception( 'The archive is shorter than its checksum list says.' );
			}
			hash_update( $ctx, $buf );
			$off += strlen( $buf );
			if ( $resume && $off < $len && microtime( true ) >= $deadline ) {
				fclose( $fp );
				$state = array(
					'k'   => (int) $k,
					'off' => $off,
					'ctx' => base64_encode( serialize( $ctx ) ),
				);
				return null;
			}
		}
		fclose( $fp );
		$state = null;
		return hash_final( $ctx );
	}

	/**
	 * Find and parse the trailer.
	 *
	 * @param string $path        Archive.
	 * @param int    $min_segment Smallest accepted segment size (MIN_SEGMENT; tests use smaller archives).
	 * @return array|null array( covered, segment_bytes, segments ), or null when the archive has no trailer.
	 * @throws FSC_Exception When a trailer is present but unusable.
	 */
	public static function read_trailer( $path, $min_segment = self::MIN_SEGMENT ) {
		clearstatcache( true, $path );
		$size = (int) @filesize( $path );
		if ( $size < 4 * FSC_Tar::BLOCK || 0 !== $size % FSC_Tar::BLOCK ) {
			return null;
		}
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			throw new FSC_Exception( 'Cannot open the archive.' );
		}
		$end    = $size - 2 * FSC_Tar::BLOCK;
		$lowest = max( 0, $end - self::SEARCH_WINDOW );
		$sig    = self::TRAILER . "\0";
		$chunk  = 65536;
		$hi     = $end;
		$found  = null;
		while ( $hi > $lowest && null === $found ) {
			$lo = max( $lowest, $hi - $chunk );
			fseek( $fp, $lo );
			$buf = (string) fread( $fp, $hi - $lo );
			for ( $i = strlen( $buf ) - FSC_Tar::BLOCK; $i >= 0; $i -= FSC_Tar::BLOCK ) {
				if ( 0 !== substr_compare( $buf, $sig, $i, strlen( $sig ) ) ) {
					continue;
				}
				$h = null;
				try {
					$h = FSC_Tar::parse_header( substr( $buf, $i, FSC_Tar::BLOCK ), $lo + $i );
				} catch ( FSC_Exception $e ) {
					$h = null;
				}
				$at = $lo + $i;
				if ( $h && self::TRAILER === $h['name'] && $at + FSC_Tar::BLOCK + $h['size'] + FSC_Tar::padding( $h['size'] ) === $end ) {
					$found = array( $at, $h['size'] );
					break;
				}
			}
			$hi = $lo;
		}
		if ( null === $found ) {
			fclose( $fp );
			return null;
		}
		list( $at, $len ) = $found;
		fseek( $fp, $at + FSC_Tar::BLOCK );
		$json = $len > 0 ? (string) fread( $fp, $len ) : '';
		fclose( $fp );
		$t = json_decode( $json, true );
		if ( ! is_array( $t ) || 1 !== ( isset( $t['version'] ) ? $t['version'] : null ) || 'sha256' !== ( isset( $t['algo'] ) ? $t['algo'] : null )
			|| ! isset( $t['segment_bytes'], $t['covered_bytes'], $t['segments'] ) || ! is_int( $t['segment_bytes'] ) || ! is_int( $t['covered_bytes'] ) || ! is_array( $t['segments'] ) ) {
			throw new FSC_Exception( 'invalid' );
		}
		$seg = $t['segment_bytes'];
		// Bounded segment size: at most covered / MIN_SEGMENT hashes, at most MAX_SEGMENT bytes per hash.
		if ( $seg < max( FSC_Tar::BLOCK, (int) $min_segment ) || $seg > self::MAX_SEGMENT ) {
			throw new FSC_Exception( 'segment size out of range' );
		}
		if ( $t['covered_bytes'] !== $at || count( $t['segments'] ) !== self::segment_count( $at, $seg ) ) {
			throw new FSC_Exception( 'inconsistent' );
		}
		foreach ( $t['segments'] as $hex ) {
			if ( ! is_string( $hex ) || ! preg_match( '/^[0-9a-f]{64}$/', $hex ) ) {
				throw new FSC_Exception( 'bad hash' );
			}
		}
		return array(
			'covered'       => $at,
			'segment_bytes' => $seg,
			'segments'      => array_values( $t['segments'] ),
		);
	}

	/**
	 * Whether a native archive was written with checksums (0.9.1+): cheap, reads the first KB.
	 *
	 * @param string $path Archive.
	 * @return bool
	 */
	public static function has_checksums( $path ) {
		if ( 'tar' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return false;
		}
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			return false;
		}
		$head = (string) fread( $fp, 1024 );
		fclose( $fp );
		return 0 === strpos( $head, FSC_Source_Native::MANIFEST . "\0" ) && false !== strpos( $head, '"' . self::SIZE_KEY . '":"' );
	}

	/**
	 * Number with thousands separators (localized inside WordPress).
	 *
	 * @param int|float $n        Number.
	 * @param int       $decimals Decimals.
	 * @return string
	 */
	public static function num( $n, $decimals = 0 ) {
		return function_exists( 'number_format_i18n' ) ? number_format_i18n( $n, $decimals ) : number_format( $n, $decimals );
	}

	/**
	 * Truncation / completeness check of a native archive, from its size, the
	 * manifest's archive_size and the end blocks.
	 *
	 * @param string     $path     Archive.
	 * @param array|null $manifest Parsed manifest (null when unknown).
	 * @throws FSC_Exception With a message for the admin when the archive is incomplete.
	 */
	public static function check_size( $path, $manifest ) {
		clearstatcache( true, $path );
		$size = (int) @filesize( $path );
		if ( is_array( $manifest ) && isset( $manifest[ self::SIZE_KEY ] ) ) {
			$raw = $manifest[ self::SIZE_KEY ];
			if ( ! is_string( $raw ) || ! preg_match( '/^\d{20}$/', $raw ) ) {
				throw new FSC_Exception( __( 'The archive manifest is damaged (invalid archive_size). Download it again, or copy it by FTP/SFTP.', 'wp-free-site-cloner' ) );
			}
			$expected = (int) ltrim( $raw, '0' );
			if ( 0 === $expected ) {
				throw new FSC_Exception( __( 'The archive was never finished: the export that wrote it did not complete. Export the site again.', 'wp-free-site-cloner' ) );
			}
			if ( $size < $expected ) {
				throw new FSC_Exception(
					sprintf(
						/* translators: 1: bytes received, 2: bytes expected, 3: percentage */
						__( 'The archive is incomplete: %1$s of %2$s bytes (%3$s%%). Download it again, or copy it by FTP/SFTP.', 'wp-free-site-cloner' ),
						self::num( $size ),
						self::num( $expected ),
						self::num( floor( $size * 1000 / $expected ) / 10, 1 )
					)
				);
			}
			if ( $size > $expected ) {
				throw new FSC_Exception(
					sprintf(
						/* translators: 1: actual bytes, 2: bytes expected */
						__( 'The archive has the wrong size: %1$s bytes instead of %2$s. Download it again, or copy it by FTP/SFTP.', 'wp-free-site-cloner' ),
						self::num( $size ),
						self::num( $expected )
					)
				);
			}
		}
		$tail = '';
		if ( $size >= 1024 ) {
			$fp = @fopen( $path, 'rb' );
			if ( $fp ) {
				fseek( $fp, $size - 1024 );
				$tail = (string) fread( $fp, 1024 );
				fclose( $fp );
			}
		}
		if ( $size < 2048 || 0 !== $size % FSC_Tar::BLOCK || str_repeat( "\0", 1024 ) !== $tail ) {
			throw new FSC_Exception(
				sprintf(
					/* translators: %s: bytes received */
					__( 'The archive is incomplete: it does not end like a finished WP Free Site Cloner archive (%s bytes received). Download it again, or copy it by FTP/SFTP.', 'wp-free-site-cloner' ),
					self::num( $size )
				)
			);
		}
	}

	/**
	 * Expected size from a manifest, or null.
	 *
	 * @param array|null $manifest Manifest.
	 * @return int|null
	 */
	public static function expected_size( $manifest ) {
		if ( is_array( $manifest ) && isset( $manifest[ self::SIZE_KEY ] ) && is_string( $manifest[ self::SIZE_KEY ] ) && preg_match( '/^\d{20}$/', $manifest[ self::SIZE_KEY ] ) ) {
			return (int) ltrim( $manifest[ self::SIZE_KEY ], '0' );
		}
		return null;
	}

	/**
	 * Message for a checksum mismatch.
	 *
	 * @param int $k             Segment index (0-based).
	 * @param int $segment_bytes Segment size.
	 * @return string
	 */
	public static function damaged_message( $k, $segment_bytes ) {
		return sprintf(
			/* translators: 1: position in MB, 2: segment number */
			__( 'The archive is damaged around %1$s MB (checksum mismatch in segment %2$d). Download it again, or copy it by FTP/SFTP.', 'wp-free-site-cloner' ),
			self::num( floor( (int) $k * (int) $segment_bytes / 1048576 ) ),
			(int) $k + 1
		);
	}
}
