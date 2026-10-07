<?php
/**
 * Helpers shared by the foreign archive sources. No WordPress dependency.
 *
 * @package wp-free-site-cloner
 */

/**
 * Source helpers.
 */
class FSC_Source_Util {

	/** Bytes per gunzip read. */
	const GZ_CHUNK = 1048576;

	/** Largest accepted expansion of a gzip file (output / input). */
	const GZ_MAX_RATIO = 100;

	/** Output always accepted regardless of the ratio (tiny dumps compress well). */
	const GZ_MIN_CAP = 16777216;

	/** Free space kept on the disk while gunzipping. */
	const GZ_DISK_MARGIN = 67108864;

	/**
	 * Directories and files (relative to wp-content) never extracted from a
	 * foreign archive: backup dirs of migration plugins and the native
	 * exporter's built-in excludes.
	 *
	 * @return array
	 */
	public static function excludes() {
		return FSC_File_Scanner::default_excludes();
	}

	/**
	 * Whether a wp-content relative path is excluded.
	 *
	 * @param string $rel Relative path (forward slashes).
	 * @return bool
	 */
	public static function excluded( $rel ) {
		$rel = strtolower( trim( str_replace( '\\', '/', (string) $rel ), '/' ) );
		while ( 0 === strpos( $rel, './' ) ) {
			$rel = substr( $rel, 2 );
		}
		static $scanner = null;
		if ( null === $scanner ) {
			$scanner = new FSC_File_Scanner( '/', '', array_map( 'strtolower', self::excludes() ) );
		}
		return '' !== $rel && $scanner->is_excluded( $rel );
	}

	/**
	 * Multiply a GF(2) 32x32 matrix with a vector (zlib crc32_combine helper).
	 *
	 * @param array $mat Matrix (32 rows).
	 * @param int   $vec Vector.
	 * @return int
	 */
	private static function gf2_times( array $mat, $vec ) {
		$sum = 0;
		$i   = 0;
		$vec = $vec & 0xFFFFFFFF;
		while ( $vec ) {
			if ( $vec & 1 ) {
				$sum ^= $mat[ $i ];
			}
			$vec >>= 1;
			++$i;
		}
		return $sum;
	}

	/**
	 * Square a GF(2) matrix.
	 *
	 * @param array $mat Matrix.
	 * @return array
	 */
	private static function gf2_square( array $mat ) {
		$out = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$out[ $n ] = self::gf2_times( $mat, $mat[ $n ] );
		}
		return $out;
	}

	/**
	 * CRC32 of A.B from crc32(A), crc32(B) and strlen(B) (port of zlib's crc32_combine).
	 * Lets a CRC over a file be carried across requests in a JSON cursor.
	 *
	 * @param int $crc1 CRC32 of the first part.
	 * @param int $crc2 CRC32 of the second part.
	 * @param int $len2 Length of the second part.
	 * @return int
	 */
	public static function crc32_combine( $crc1, $crc2, $len2 ) {
		$crc1 = $crc1 & 0xFFFFFFFF;
		$crc2 = $crc2 & 0xFFFFFFFF;
		if ( $len2 <= 0 ) {
			return $crc1;
		}
		$odd    = array( 0xedb88320 );
		$row    = 1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[ $n ] = $row;
			$row     <<= 1;
		}
		$even = self::gf2_square( $odd );
		$odd  = self::gf2_square( $even );
		do {
			$even = self::gf2_square( $odd );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $even, $crc1 );
			}
			$len2 >>= 1;
			if ( 0 === $len2 ) {
				break;
			}
			$odd = self::gf2_square( $even );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $odd, $crc1 );
			}
			$len2 >>= 1;
		} while ( 0 !== $len2 );
		return ( $crc1 ^ $crc2 ) & 0xFFFFFFFF;
	}

	/**
	 * Uncompressed size recorded in a gzip trailer (modulo 4 GiB), 0 if unknown.
	 *
	 * @param string $gz Gzip file.
	 * @return int
	 */
	public static function gz_isize( $gz ) {
		$size = @filesize( $gz );
		if ( ! $size || $size < 18 ) {
			return 0;
		}
		$fp = @fopen( $gz, 'rb' );
		if ( ! $fp ) {
			return 0;
		}
		fseek( $fp, $size - 4 );
		$raw = fread( $fp, 4 );
		fclose( $fp );
		if ( 4 !== strlen( (string) $raw ) ) {
			return 0;
		}
		$v = unpack( 'V', $raw );
		return (int) $v[1];
	}

	/**
	 * Most bytes a compressed archive of $size may legitimately expand to:
	 * GZ_MAX_RATIO times its size, but at least GZ_MIN_CAP. Used to bound the
	 * extraction of formats whose entries inflate (.zip, .daf).
	 *
	 * @param string $path Archive file.
	 * @return float
	 */
	public static function extract_budget( $path ) {
		return (float) max( self::GZ_MIN_CAP, self::GZ_MAX_RATIO * (float) @filesize( $path ) );
	}

	/**
	 * Most bytes gunzip may write: GZ_MAX_RATIO times the input (at least
	 * GZ_MIN_CAP) and never more than the free disk space minus GZ_DISK_MARGIN.
	 *
	 * @param string $gz      Gzip file.
	 * @param string $dest    Output file.
	 * @param int    $written Bytes already written to $dest.
	 * @return float
	 */
	public static function gunzip_cap( $gz, $dest, $written ) {
		$cap  = (float) max( self::GZ_MIN_CAP, self::GZ_MAX_RATIO * (float) @filesize( $gz ) );
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( dirname( $dest ) ) : false;
		if ( false !== $free && null !== $free ) {
			$cap = min( $cap, (float) $free + (float) $written - self::GZ_DISK_MARGIN );
		}
		return $cap;
	}

	/**
	 * Gunzip $gz into $dest until done or the deadline passes. Resumes at
	 * $cursor['out'] bytes of output; nothing is held in memory beyond one chunk.
	 * Stops with an error when the output would exceed $max_out (default
	 * gunzip_cap()): a decompression bomb or a disk too small for the dump.
	 *
	 * @param string     $gz       Gzip file.
	 * @param string     $dest     Output file.
	 * @param array      $cursor   array( out => bytes written ), updated in place.
	 * @param float      $deadline microtime deadline.
	 * @param float|null $max_out  Output limit in bytes.
	 * @return bool True when complete.
	 * @throws FSC_Exception On corrupt input, write errors or too much output.
	 */
	public static function gunzip_step( $gz, $dest, array &$cursor, $deadline, $max_out = null ) {
		if ( ! function_exists( 'gzopen' ) ) {
			throw new FSC_Exception( 'The PHP zlib extension is required to read this archive.' );
		}
		$done = isset( $cursor['out'] ) ? (int) $cursor['out'] : 0;
		if ( null === $max_out ) {
			$max_out = self::gunzip_cap( $gz, $dest, $done );
		}
		$in   = @gzopen( $gz, 'rb' );
		if ( ! $in ) {
			throw new FSC_Exception( 'Cannot open the compressed database dump.' );
		}
		if ( $done > 0 && 0 !== gzseek( $in, $done ) ) {
			gzclose( $in );
			throw new FSC_Exception( 'The compressed database dump is corrupt (cannot resume).' );
		}
		$out = @fopen( $dest, 'c+b' );
		if ( ! $out ) {
			gzclose( $in );
			throw new FSC_Exception( 'Cannot write the temporary SQL file.' );
		}
		ftruncate( $out, $done );
		fseek( $out, $done );
		$complete = false;
		while ( true ) {
			$buf = gzread( $in, self::GZ_CHUNK );
			if ( false === $buf ) {
				fclose( $out );
				gzclose( $in );
				throw new FSC_Exception( 'The compressed database dump is corrupt.' );
			}
			if ( '' === $buf ) {
				$complete = true;
				break;
			}
			if ( $done + strlen( $buf ) > $max_out ) {
				fclose( $out );
				gzclose( $in );
				@unlink( $dest );
				$cursor['out'] = 0;
				throw new FSC_Exception( sprintf( 'The compressed database dump expands to more than %d MB (over %d times its size, or more than the free disk space). It is refused as damaged or malicious.', (int) floor( $max_out / 1048576 ), self::GZ_MAX_RATIO ) );
			}
			if ( fwrite( $out, $buf ) !== strlen( $buf ) ) {
				fclose( $out );
				gzclose( $in );
				throw new FSC_Exception( 'Writing the temporary SQL file failed (disk full?).' );
			}
			$done         += strlen( $buf );
			$cursor['out'] = $done;
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}
		fclose( $out );
		gzclose( $in );
		$cursor['out'] = $done;
		return $complete;
	}

	/**
	 * Last bytes of a file.
	 *
	 * @param string $path File.
	 * @param int    $n    Bytes.
	 * @return string
	 */
	public static function tail( $path, $n ) {
		$size = (int) @filesize( $path );
		$fp   = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			return '';
		}
		fseek( $fp, max( 0, $size - $n ) );
		$s = (string) fread( $fp, $n );
		fclose( $fp );
		return $s;
	}

	/**
	 * Decode a JSON object.
	 *
	 * @param string $raw  JSON.
	 * @param string $what Name for the error message.
	 * @return array
	 * @throws FSC_Exception When invalid.
	 */
	public static function json( $raw, $what ) {
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			throw new FSC_Exception( sprintf( 'The archive metadata (%s) is not valid JSON.', $what ) );
		}
		return $data;
	}

	/**
	 * Nested array value by path.
	 *
	 * @param array  $data    Data.
	 * @param string $path    Dotted path.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( array $data, $path, $default = '' ) {
		foreach ( explode( '.', $path ) as $k ) {
			if ( ! is_array( $data ) || ! array_key_exists( $k, $data ) ) {
				return $default;
			}
			$data = $data[ $k ];
		}
		return $data;
	}

	/**
	 * First non-empty string among values.
	 *
	 * @param mixed ...$values Values.
	 * @return string
	 */
	public static function first( ...$values ) {
		foreach ( $values as $v ) {
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				return trim( $v );
			}
		}
		return '';
	}
}
