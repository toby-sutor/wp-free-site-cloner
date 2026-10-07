<?php
/**
 * Serialization-safe search and replace. No WordPress dependency.
 *
 * Serialized strings are first validated with unserialize( allowed_classes => false )
 * (no object is ever instantiated), then rewritten by a walker that works on the
 * raw serialized text: string values are replaced (recursively, so serialized data
 * inside serialized strings is handled) and their lengths recomputed. Keys, class
 * names, numbers and references are copied verbatim.
 *
 * @package wp-free-site-cloner
 */

/**
 * Search-replace engine.
 */
class FSC_Search_Replace {

	/** Nesting limit for serialized-in-serialized strings. */
	const MAX_DEPTH = 16;

	/**
	 * Structural nesting limit for arrays and objects inside one serialized
	 * value. walk() recurses once per array/object level, so without a bound a
	 * deeply nested but valid value (a:1:{i:0;a:1:{i:0;...}}) overflows the C
	 * stack and segfaults the import. PHP's own unserialize_max_depth (default
	 * 4096) normally rejects such data before it reaches walk(), but a site may
	 * raise or disable it. 512 is far below the ~35000 that overflows a default
	 * 8 MB stack on PHP 7.4/8.3 and far above anything real WordPress data
	 * nests to; past it walk() keeps the value unchanged, like unparseable data.
	 */
	const MAX_STRUCT_DEPTH = 512;

	/** @var array old => new, longest old first */
	private $pairs = array();

	/**
	 * Constructor.
	 *
	 * @param array $pairs old => new.
	 */
	public function __construct( array $pairs ) {
		foreach ( $pairs as $old => $new ) {
			$old = (string) $old;
			$new = (string) $new;
			if ( '' !== $old && $old !== $new ) {
				$this->pairs[ $old ] = $new;
			}
		}
		uksort(
			$this->pairs,
			function ( $a, $b ) {
				$d = strlen( (string) $b ) - strlen( (string) $a );
				return 0 !== $d ? $d : strcmp( (string) $a, (string) $b );
			}
		);
	}

	/**
	 * Replacement pairs, longest search string first.
	 *
	 * @return array
	 */
	public function pairs() {
		return $this->pairs;
	}

	/**
	 * Search strings (for LIKE pre-filtering).
	 *
	 * @return array
	 */
	public function olds() {
		return array_map( 'strval', array_keys( $this->pairs ) );
	}

	/**
	 * Minimal set of search strings for a LIKE pre-filter: a string that contains
	 * another search string is dropped, since any row matching it also matches the shorter one.
	 *
	 * @return array
	 */
	public function like_terms() {
		$olds = $this->olds();
		usort(
			$olds,
			function ( $a, $b ) {
				return strlen( $a ) - strlen( $b );
			}
		);
		$keep = array();
		foreach ( $olds as $o ) {
			$covered = false;
			foreach ( $keep as $k ) {
				if ( false !== strpos( $o, $k ) ) {
					$covered = true;
					break;
				}
			}
			if ( ! $covered ) {
				$keep[] = $o;
			}
		}
		return $keep;
	}

	/**
	 * JSON-escaped form of a string without the surrounding quotes (escapes / and \).
	 *
	 * @param string $s String.
	 * @return string
	 */
	private static function json_inner( $s ) {
		$j = json_encode( $s, JSON_UNESCAPED_UNICODE );
		return is_string( $j ) ? substr( $j, 1, -1 ) : $s;
	}

	/**
	 * Add $old => $new plus its JSON-escaped and url-encoded variants.
	 *
	 * @param array  $pairs Pairs, updated.
	 * @param string $old   Old.
	 * @param string $new   New.
	 */
	private static function add_variants( array &$pairs, $old, $new ) {
		if ( '' === $old || $old === $new ) {
			return;
		}
		if ( ! isset( $pairs[ $old ] ) ) {
			$pairs[ $old ] = $new;
		}
		$variants = array(
			array( self::json_inner( $old ), self::json_inner( $new ) ),
			array( urlencode( $old ), urlencode( $new ) ),
			array( rawurlencode( $old ), rawurlencode( $new ) ),
		);
		foreach ( $variants as $v ) {
			if ( $v[0] !== $old && ! isset( $pairs[ $v[0] ] ) ) {
				$pairs[ $v[0] ] = $v[1];
			}
		}
	}

	/**
	 * Pairs for one URL: http://, https:// and protocol-relative //host forms of
	 * the old URL, each with JSON-escaped and url-encoded variants.
	 *
	 * @param string $old Old URL.
	 * @param string $new New URL.
	 * @return array
	 */
	public static function url_pairs( $old, $new ) {
		$old   = rtrim( trim( (string) $old ), '/' );
		$new   = rtrim( trim( (string) $new ), '/' );
		$pairs = array();
		if ( '' === $old || '' === $new || $old === $new ) {
			return $pairs;
		}
		$old_rel = preg_replace( '#^[a-z][a-z0-9+.-]*:(?=//)#i', '', $old );
		$new_rel = preg_replace( '#^[a-z][a-z0-9+.-]*:(?=//)#i', '', $new );
		if ( 0 === strpos( $old_rel, '//' ) && strlen( $old_rel ) > 2 ) {
			self::add_variants( $pairs, 'http:' . $old_rel, $new );
			self::add_variants( $pairs, 'https:' . $old_rel, $new );
			if ( 0 === strpos( $new_rel, '//' ) ) {
				self::add_variants( $pairs, $old_rel, $new_rel );
			}
		} else {
			self::add_variants( $pairs, $old, $new );
		}
		return $pairs;
	}

	/**
	 * Pairs for a filesystem path (trailing separators stripped; very short paths ignored).
	 *
	 * @param string $old Old path.
	 * @param string $new New path.
	 * @return array
	 */
	public static function path_pairs( $old, $new ) {
		$old   = rtrim( (string) $old, '/\\' );
		$new   = rtrim( (string) $new, '/\\' );
		$pairs = array();
		if ( strlen( $old ) < 4 || '' === $new || $old === $new ) {
			return $pairs;
		}
		$pairs[ $old ] = $new;
		$json          = self::json_inner( $old );
		if ( $json !== $old ) {
			$pairs[ $json ] = self::json_inner( $new );
		}
		return $pairs;
	}

	/**
	 * Build pairs from URL and path mappings. Earlier entries win on duplicate keys.
	 *
	 * @param array $urls  List of array( old, new ).
	 * @param array $paths List of array( old, new ).
	 * @return array old => new
	 */
	public static function build_pairs( array $urls, array $paths = array() ) {
		$pairs = array();
		foreach ( $urls as $u ) {
			$pairs += self::url_pairs( $u[0], $u[1] );
		}
		foreach ( $paths as $p ) {
			$pairs += self::path_pairs( $p[0], $p[1] );
		}
		return $pairs;
	}

	/**
	 * Whether $s contains any search string.
	 *
	 * @param string $s Haystack.
	 * @return bool
	 */
	public function matches( $s ) {
		foreach ( $this->pairs as $old => $new ) {
			if ( false !== strpos( $s, (string) $old ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace in a value. Non-strings are returned unchanged.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public function replace( $value ) {
		if ( ! is_string( $value ) || empty( $this->pairs ) || ! $this->matches( $value ) ) {
			return $value;
		}
		return $this->replace_string( $value, 0 );
	}

	/**
	 * Cheap test whether a string may be serialized data.
	 *
	 * @param string $s String.
	 * @return bool
	 */
	public static function looks_serialized( $s ) {
		if ( 'N;' === $s ) {
			return true;
		}
		if ( strlen( $s ) < 4 || ':' !== $s[1] ) {
			return false;
		}
		$last = substr( $s, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}
		return false !== strpos( 'aOCsbidE', $s[0] );
	}

	/**
	 * Whether $s is valid serialized data (validated without instantiating classes).
	 *
	 * @param string $s String.
	 * @return bool
	 */
	public static function is_valid_serialized( $s ) {
		if ( 'b:0;' === $s ) {
			return true;
		}
		return false !== @unserialize( $s, array( 'allowed_classes' => false ) );
	}

	/**
	 * Replace inside one string value.
	 *
	 * @param string $s     String containing at least one search string.
	 * @param int    $depth Serialized nesting depth.
	 * @return string
	 */
	private function replace_string( $s, $depth ) {
		if ( $depth < self::MAX_DEPTH && self::looks_serialized( $s ) ) {
			if ( self::is_valid_serialized( $s ) ) {
				try {
					$pos = 0;
					$out = $this->walk( $s, $pos, $depth );
					if ( $pos < strlen( $s ) ) {
						$out .= strtr( substr( $s, $pos ), $this->pairs );
					}
					return $out;
				} catch ( Exception $e ) {
					return $s;
				}
			}
			$plain = strtr( $s, $this->pairs );
			$fixed = self::fix_lengths( $plain );
			return self::is_valid_serialized( $fixed ) ? $fixed : $plain;
		}
		return strtr( $s, $this->pairs );
	}

	/**
	 * Parse "<letter>:<int>:" at $pos and return the int; $pos moves past the second colon.
	 *
	 * @param string $s   Data.
	 * @param int    $pos Position, updated.
	 * @return int
	 * @throws Exception On malformed data.
	 */
	private static function read_len( $s, &$pos ) {
		$colon = strpos( $s, ':', $pos + 2 );
		if ( false === $colon ) {
			throw new Exception( 'bad length' );
		}
		$num = substr( $s, $pos + 2, $colon - $pos - 2 );
		if ( '' === $num || ! ctype_digit( ltrim( $num, '+' ) ) ) {
			throw new Exception( 'bad length' );
		}
		$pos = $colon + 1;
		return (int) $num;
	}

	/**
	 * Read a length-prefixed quoted string body at $pos (pointing at the opening quote).
	 *
	 * @param string $s   Data.
	 * @param int    $pos Position, moves past the closing quote.
	 * @param int    $len Length.
	 * @return string
	 * @throws Exception On malformed data.
	 */
	private static function read_quoted( $s, &$pos, $len ) {
		if ( '"' !== substr( $s, $pos, 1 ) || '"' !== substr( $s, $pos + 1 + $len, 1 ) ) {
			throw new Exception( 'bad string' );
		}
		$str = (string) substr( $s, $pos + 1, $len );
		$pos = $pos + 2 + $len;
		return $str;
	}

	/**
	 * Expect a literal at $pos.
	 *
	 * @param string $s   Data.
	 * @param int    $pos Position, updated.
	 * @param string $lit Literal.
	 * @throws Exception On mismatch.
	 */
	private static function expect( $s, &$pos, $lit ) {
		if ( substr( $s, $pos, strlen( $lit ) ) !== $lit ) {
			throw new Exception( 'expected ' . $lit );
		}
		$pos += strlen( $lit );
	}

	/**
	 * Copy a scalar token ending in ";" verbatim.
	 *
	 * @param string $s   Data.
	 * @param int    $pos Position, updated.
	 * @return string
	 * @throws Exception On malformed data.
	 */
	private static function copy_scalar( $s, &$pos ) {
		$semi = strpos( $s, ';', $pos );
		if ( false === $semi ) {
			throw new Exception( 'bad scalar' );
		}
		$out = substr( $s, $pos, $semi + 1 - $pos );
		$pos = $semi + 1;
		return $out;
	}

	/**
	 * Copy an array key or property name (i: or s:) verbatim.
	 *
	 * @param string $s   Data.
	 * @param int    $pos Position, updated.
	 * @return string
	 * @throws Exception On malformed data.
	 */
	private static function copy_key( $s, &$pos ) {
		$start = $pos;
		$t     = substr( $s, $pos, 1 );
		if ( 'i' === $t ) {
			return self::copy_scalar( $s, $pos );
		}
		if ( 's' !== $t ) {
			throw new Exception( 'bad key' );
		}
		$len = self::read_len( $s, $pos );
		self::read_quoted( $s, $pos, $len );
		self::expect( $s, $pos, ';' );
		return substr( $s, $start, $pos - $start );
	}

	/**
	 * Rewrite one serialized value starting at $pos.
	 *
	 * @param string $s      Serialized data.
	 * @param int    $pos    Position, updated.
	 * @param int    $depth  Nesting depth of serialized strings.
	 * @param int    $sdepth Structural nesting depth of arrays/objects.
	 * @return string
	 * @throws Exception On anything unexpected (caller keeps the original).
	 */
	private function walk( $s, &$pos, $depth, $sdepth = 0 ) {
		if ( $sdepth > self::MAX_STRUCT_DEPTH ) {
			// Too deeply nested to walk without risking a C stack overflow; keep it as is.
			throw new Exception( 'structure too deep' );
		}
		$t = substr( $s, $pos, 1 );
		switch ( $t ) {
			case 'N':
				self::expect( $s, $pos, 'N;' );
				return 'N;';
			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				return self::copy_scalar( $s, $pos );
			case 's':
				$len = self::read_len( $s, $pos );
				$str = self::read_quoted( $s, $pos, $len );
				self::expect( $s, $pos, ';' );
				if ( $this->matches( $str ) ) {
					$str = $this->replace_string( $str, $depth + 1 );
				}
				return 's:' . strlen( $str ) . ':"' . $str . '";';
			case 'E':
				$start = $pos;
				$len   = self::read_len( $s, $pos );
				self::read_quoted( $s, $pos, $len );
				self::expect( $s, $pos, ';' );
				return substr( $s, $start, $pos - $start );
			case 'a':
				$count = self::read_len( $s, $pos );
				self::expect( $s, $pos, '{' );
				$out = 'a:' . $count . ':{';
				for ( $k = 0; $k < $count; $k++ ) {
					$out .= self::copy_key( $s, $pos );
					$out .= $this->walk( $s, $pos, $depth, $sdepth + 1 );
				}
				self::expect( $s, $pos, '}' );
				return $out . '}';
			case 'O':
				$start = $pos;
				$len   = self::read_len( $s, $pos );
				self::read_quoted( $s, $pos, $len );
				self::expect( $s, $pos, ':' );
				$head  = substr( $s, $start, $pos - $start );
				$cpos  = $pos - 2;
				$count = self::read_len( $s, $cpos );
				$pos   = $cpos;
				self::expect( $s, $pos, '{' );
				$out = $head . $count . ':{';
				for ( $k = 0; $k < $count; $k++ ) {
					$out .= self::copy_key( $s, $pos );
					$out .= $this->walk( $s, $pos, $depth, $sdepth + 1 );
				}
				self::expect( $s, $pos, '}' );
				return $out . '}';
			case 'C':
				$start = $pos;
				$len   = self::read_len( $s, $pos );
				self::read_quoted( $s, $pos, $len );
				self::expect( $s, $pos, ':' );
				$head  = substr( $s, $start, $pos - $start );
				$cpos  = $pos - 2;
				$plen  = self::read_len( $s, $cpos );
				$pos   = $cpos;
				self::expect( $s, $pos, '{' );
				$payload = (string) substr( $s, $pos, $plen );
				$pos    += $plen;
				self::expect( $s, $pos, '}' );
				if ( $depth + 1 < self::MAX_DEPTH && $this->matches( $payload ) && self::looks_serialized( $payload ) && self::is_valid_serialized( $payload ) ) {
					$ppos = 0;
					$new  = $this->walk( $payload, $ppos, $depth + 1, $sdepth + 1 );
					if ( strlen( $payload ) === $ppos ) {
						$payload = $new;
					}
				}
				return $head . strlen( $payload ) . ':{' . $payload . '}';
		}
		throw new Exception( 'unsupported token ' . $t );
	}

	/**
	 * Best-effort repair of s:N:"..." lengths in broken serialized data.
	 * A declared length that already ends at '";' is trusted.
	 *
	 * @param string $s Data.
	 * @return string
	 */
	public static function fix_lengths( $s ) {
		$out = '';
		$pos = 0;
		$len = strlen( $s );
		while ( $pos < $len && preg_match( '/s:(\d+):"/', $s, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$start  = $m[0][1];
			$dstart = $start + strlen( $m[0][0] );
			$n      = (int) $m[1][0];
			$out   .= substr( $s, $pos, $start - $pos );
			if ( '";' === substr( $s, $dstart + $n, 2 ) ) {
				$str = substr( $s, $dstart, $n );
			} else {
				$end = strpos( $s, '";', $dstart );
				if ( false === $end ) {
					$out .= substr( $s, $start );
					$pos  = $len;
					break;
				}
				$str = substr( $s, $dstart, $end - $dstart );
			}
			$str  = (string) $str;
			$out .= 's:' . strlen( $str ) . ':"' . $str . '";';
			$pos  = $dstart + strlen( $str ) + 2;
		}
		if ( $pos < $len ) {
			$out .= substr( $s, $pos );
		}
		return $out;
	}
}
