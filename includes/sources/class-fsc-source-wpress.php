<?php
/**
 * All-in-One WP Migration archive (.wpress). No WordPress dependency.
 *
 * Layout (see internal/formats/wpress.md): a 4377-byte header per entry
 * (name 255, size 14, mtime 12, path 4088, crc32 8; ASCII, NUL-padded),
 * followed by the raw entry data. The archive ends with a header whose name
 * is empty: all NUL (v1) or carrying its own offset and a CRC (v2).
 *
 * @package wp-free-site-cloner
 */

/**
 * .wpress source.
 */
class FSC_Source_Wpress implements FSC_Source {

	const HEADER      = 4377;
	const CHUNK       = 4194304;
	const PLACEHOLDER = 'SERVMASK_PREFIX_';
	const SQL         = 'database.sql';
	const PACKAGE     = 'package.json';
	const MAX_JSON    = 16777216;

	/** Root-level entries that are AIOWPM metadata, never wp-content files. */
	const CONFIG = array( 'package.json', 'multisite.json', 'blogs.json', 'database.sql' );

	/** @var string */
	private $path;

	/** @var resource|null */
	private $fp = null;

	/** @var int */
	private $size = 0;

	/**
	 * Constructor.
	 *
	 * @param string $path Archive path.
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		if ( $this->fp ) {
			fclose( $this->fp );
		}
	}

	/**
	 * Open the archive.
	 *
	 * @return resource
	 * @throws FSC_Exception When unreadable.
	 */
	private function fp() {
		if ( ! $this->fp ) {
			$fp = @fopen( $this->path, 'rb' );
			if ( ! $fp ) {
				throw new FSC_Exception( 'Cannot open the .wpress archive.' );
			}
			clearstatcache( true, $this->path );
			$this->fp   = $fp;
			$this->size = (int) filesize( $this->path );
		}
		return $this->fp;
	}

	/**
	 * Read exactly $len bytes at $offset.
	 *
	 * @param int $offset Offset.
	 * @param int $len    Length.
	 * @return string
	 * @throws FSC_Exception When the archive ends early.
	 */
	private function read_at( $offset, $len ) {
		$fp = $this->fp();
		if ( 0 !== fseek( $fp, $offset ) ) {
			throw new FSC_Exception( 'The .wpress archive is truncated.' );
		}
		$out = '';
		while ( strlen( $out ) < $len ) {
			$buf = fread( $fp, min( self::CHUNK, $len - strlen( $out ) ) );
			if ( false === $buf || '' === $buf ) {
				throw new FSC_Exception( 'The .wpress archive is truncated (upload incomplete?).' );
			}
			$out .= $buf;
		}
		return $out;
	}

	/**
	 * Detect by extension and a valid first header.
	 *
	 * @param string $path Archive path.
	 * @return bool
	 */
	public static function detect( $path ) {
		if ( 'wpress' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return false;
		}
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			return false;
		}
		$block = fread( $fp, self::HEADER );
		fclose( $fp );
		if ( ! is_string( $block ) || strlen( $block ) !== self::HEADER ) {
			return false;
		}
		try {
			$h = self::parse_header( $block );
		} catch ( FSC_Exception $e ) {
			return false;
		}
		return 'file' === $h['type'];
	}

	/**
	 * One NUL-padded field: the content before the padding, or null when
	 * something other than NUL follows the first NUL.
	 *
	 * @param string $block Header.
	 * @param int    $start Offset.
	 * @param int    $len   Length.
	 * @return string|null
	 */
	private static function field( $block, $start, $len ) {
		$raw = substr( $block, $start, $len );
		$nul = strpos( $raw, "\0" );
		if ( false === $nul ) {
			return $raw;
		}
		return strspn( $raw, "\0", $nul ) === strlen( $raw ) - $nul ? substr( $raw, 0, $nul ) : null;
	}

	/**
	 * Parse one 4377-byte header.
	 *
	 * @param string $block Header bytes.
	 * @return array File: type=file, name, dir, rel, size, mtime, crc ('' or 8 hex).
	 *               End: type=eof, variant (1|2), offset (int|null), crc.
	 * @throws FSC_Exception When the block is not a valid header.
	 */
	public static function parse_header( $block ) {
		if ( strlen( $block ) !== self::HEADER ) {
			throw new FSC_Exception( 'The .wpress archive is truncated.' );
		}
		$name  = self::field( $block, 0, 255 );
		$size  = self::field( $block, 255, 14 );
		$mtime = self::field( $block, 269, 12 );
		$dir   = self::field( $block, 281, 4088 );
		$crc   = self::field( $block, 4369, 8 );
		if ( "\0" === $block[0] ) {
			if ( strspn( $block, "\0" ) === self::HEADER ) {
				return array(
					'type'    => 'eof',
					'variant' => 1,
					'offset'  => null,
					'crc'     => '',
				);
			}
			if ( null !== $size && preg_match( '/^\d{1,14}$/', $size ) && null !== $crc && preg_match( '/^[0-9a-fA-F]{8}$/', $crc ) ) {
				return array(
					'type'    => 'eof',
					'variant' => 2,
					'offset'  => (int) $size,
					'crc'     => strtolower( $crc ),
				);
			}
			throw new FSC_Exception( 'The .wpress archive has an invalid end block.' );
		}
		if ( null === $name || '' === $name || null === $size || ! preg_match( '/^\d{1,14}$/', $size )
			|| null === $mtime || ! preg_match( '/^\d{0,12}$/', $mtime ) || null === $dir ) {
			throw new FSC_Exception( 'The .wpress archive has an invalid entry header (not a .wpress file or corrupt).' );
		}
		if ( null === $crc || ( '' !== $crc && ! preg_match( '/^[0-9a-fA-F]{8}$/', $crc ) ) ) {
			// Pre-CRC archives used the whole 4096 bytes for the path.
			$dir = self::field( $block, 281, 4096 );
			$crc = '';
			if ( null === $dir ) {
				throw new FSC_Exception( 'The .wpress archive has an invalid entry header.' );
			}
		}
		$dir = str_replace( '\\', '/', $dir );
		$rel = ( '' === $dir || '.' === $dir ) ? $name : rtrim( $dir, '/' ) . '/' . $name;
		return array(
			'type'  => 'file',
			'name'  => $name,
			'dir'   => $dir,
			'rel'   => $rel,
			'root'  => '' === $dir || '.' === $dir,
			'size'  => (int) $size,
			'mtime' => (int) $mtime,
			'crc'   => strtolower( $crc ),
		);
	}

	/**
	 * Header at $offset, with its data offset, validated against the file size.
	 *
	 * @param int $offset Offset.
	 * @return array
	 * @throws FSC_Exception When truncated or corrupt.
	 */
	private function header_at( $offset ) {
		$this->fp();
		if ( $offset + self::HEADER > $this->size ) {
			throw new FSC_Exception( 'The .wpress archive is truncated (upload incomplete?).' );
		}
		$h = self::parse_header( $this->read_at( $offset, self::HEADER ) );
		if ( 'file' === $h['type'] ) {
			$h['data'] = $offset + self::HEADER;
			if ( $h['data'] + $h['size'] > $this->size ) {
				throw new FSC_Exception( 'The .wpress archive is truncated (upload incomplete?).' );
			}
		}
		return $h;
	}

	/**
	 * Read a small root-level JSON entry among the leading root entries.
	 *
	 * @return array array( package => array|null, multisite => bool ).
	 * @throws FSC_Exception On corrupt archives.
	 */
	private function leading_config() {
		$offset    = 0;
		$package   = null;
		$multisite = false;
		for ( $i = 0; $i < 32; $i++ ) {
			$h = $this->header_at( $offset );
			if ( 'eof' === $h['type'] || ! $h['root'] ) {
				break;
			}
			if ( self::PACKAGE === $h['name'] ) {
				if ( $h['size'] > self::MAX_JSON ) {
					throw new FSC_Exception( 'package.json in the .wpress archive is too large.' );
				}
				$raw = $this->read_at( $h['data'], $h['size'] );
				if ( '' !== $h['crc'] && sprintf( '%08x', crc32( $raw ) ) !== $h['crc'] ) {
					throw new FSC_Exception( 'package.json in the .wpress archive is corrupt (CRC mismatch).' );
				}
				$package = FSC_Source_Util::json( $raw, self::PACKAGE );
			} elseif ( 'multisite.json' === $h['name'] ) {
				$multisite = true;
			}
			$offset = $h['data'] + $h['size'];
		}
		return array(
			'package'   => $package,
			'multisite' => $multisite,
		);
	}

	/**
	 * Metadata, see FSC_Source::meta().
	 *
	 * @return array
	 * @throws FSC_Exception When unreadable or unsupported.
	 */
	public function meta() {
		$this->fp();
		if ( $this->size < 2 * self::HEADER ) {
			throw new FSC_Exception( 'The .wpress archive is too small to be valid.' );
		}
		try {
			$end = self::parse_header( $this->read_at( $this->size - self::HEADER, self::HEADER ) );
		} catch ( FSC_Exception $e ) {
			$end = array( 'type' => 'invalid' );
		}
		if ( 'eof' !== $end['type'] || ( 2 === $end['variant'] && $end['offset'] !== $this->size - self::HEADER ) ) {
			throw new FSC_Exception( 'The .wpress archive is incomplete (end marker missing). Upload or copy it again.' );
		}
		$cfg = $this->leading_config();
		$p   = $cfg['package'];
		if ( null === $p ) {
			throw new FSC_Exception( 'This .wpress archive has no package.json.' );
		}
		if ( ! empty( $p['Encrypted'] ) || '' !== FSC_Source_Util::first( FSC_Source_Util::get( $p, 'EncryptedSignature' ) ) ) {
			throw new FSC_Exception( 'This .wpress archive is encrypted with a password, which is not supported. Export it again from All-in-One WP Migration without a password.' );
		}
		if ( ! empty( $p['Compression'] ) && ( ! is_array( $p['Compression'] ) || ! empty( $p['Compression']['Enabled'] ) ) ) {
			throw new FSC_Exception( 'This .wpress archive is compressed, which is not supported. Export it again from All-in-One WP Migration without compression.' );
		}
		$prefix = FSC_Source_Util::get( $p, 'Database.Prefix' );
		// An array or number here would cast to "Array"/digits and pass the pattern below.
		if ( ! is_string( $prefix ) || ( '' !== $prefix && ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) ) {
			$prefix = '';
		}
		$home    = FSC_Source_Util::first( FSC_Source_Util::get( $p, 'InternalHomeURL' ), FSC_Source_Util::get( $p, 'HomeURL' ) );
		$siteurl = FSC_Source_Util::first( FSC_Source_Util::get( $p, 'InternalSiteURL' ), FSC_Source_Util::get( $p, 'SiteURL' ) );
		$ver     = FSC_Source_Util::first( FSC_Source_Util::get( $p, 'Plugin.Version' ) );
		$warn    = array();
		if ( '' === $prefix ) {
			$warn[] = 'The archive does not state its table prefix; prefixed option and user meta keys are mapped to this site\'s prefix.';
		}
		return array(
			'format'          => 'wpress',
			'format_name'     => 'All-in-One WP Migration (.wpress' . ( '' !== $ver ? ', version ' . $ver : '' ) . ')',
			'home'            => '' !== $home ? $home : $siteurl,
			'siteurl'         => '' !== $siteurl ? $siteurl : $home,
			'abspath'         => FSC_Source_Util::first( FSC_Source_Util::get( $p, 'WordPress.Absolute' ) ),
			'content_dir'     => FSC_Source_Util::first( FSC_Source_Util::get( $p, 'WordPress.Content' ) ),
			'uploads_url'     => FSC_Source_Util::first( FSC_Source_Util::get( $p, 'WordPress.UploadsURL' ) ),
			'prefix'          => $prefix,
			'sql_prefix'      => self::PLACEHOLDER,
			'key_placeholder' => self::PLACEHOLDER,
			'sql_entry'       => self::SQL,
			'multisite'       => $cfg['multisite'],
			'wp_version'      => FSC_Source_Util::first( FSC_Source_Util::get( $p, 'WordPress.Version' ) ),
			'file_count'      => null,
			'sql_sha256'      => '',
			'warnings'        => $warn,
			// AIOWPM empties active_plugins, template and stylesheet in its dump.
			'active_plugins'  => array_values( array_filter( (array) FSC_Source_Util::get( $p, 'Plugins', array() ), 'is_string' ) ),
			'theme'           => array(
				'template'   => FSC_Source_Util::first( FSC_Source_Util::get( $p, 'Template' ) ),
				'stylesheet' => FSC_Source_Util::first( FSC_Source_Util::get( $p, 'Stylesheet' ) ),
			),
		);
	}

	/**
	 * Copy database.sql out of the archive (resumable, CRC checked).
	 *
	 * @param string $dest     Destination file.
	 * @param array  $cursor   Cursor.
	 * @param float  $deadline Deadline.
	 * @return bool
	 * @throws FSC_Exception On errors.
	 */
	public function extract_sql( $dest, array &$cursor, $deadline ) {
		if ( empty( $cursor['entry'] ) ) {
			$offset = isset( $cursor['scan'] ) ? (int) $cursor['scan'] : 0;
			while ( true ) {
				$h = $this->header_at( $offset );
				if ( 'eof' === $h['type'] ) {
					throw new FSC_Exception( 'The .wpress archive contains no database.sql.' );
				}
				if ( $h['root'] && self::SQL === $h['name'] ) {
					$cursor['entry'] = self::entry_cursor( $h );
					break;
				}
				$offset         = $h['data'] + $h['size'];
				$cursor['scan'] = $offset;
				if ( microtime( true ) >= $deadline ) {
					$cursor['progress'] = 0;
					return false;
				}
			}
		}
		$out = @fopen( $dest, 'c+b' );
		if ( ! $out ) {
			throw new FSC_Exception( 'Cannot write the temporary SQL file.' );
		}
		ftruncate( $out, (int) $cursor['entry']['read'] );
		fseek( $out, (int) $cursor['entry']['read'] );
		$done = false;
		do {
			$buf = $this->read_entry( $cursor['entry'], self::CHUNK );
			if ( '' === $buf ) {
				$done = true;
				break;
			}
			if ( fwrite( $out, $buf ) !== strlen( $buf ) ) {
				fclose( $out );
				throw new FSC_Exception( 'Writing the temporary SQL file failed (disk full?).' );
			}
		} while ( microtime( true ) < $deadline );
		fclose( $out );
		$e                  = $cursor['entry'];
		$cursor['progress'] = $e['size'] > 0 ? $e['read'] / $e['size'] : 1;
		return $done;
	}

	/**
	 * Cursor for reading one entry.
	 *
	 * @param array $h Parsed header with data offset.
	 * @return array
	 */
	private static function entry_cursor( array $h ) {
		return array(
			'rel'   => $h['rel'],
			'data'  => $h['data'],
			'size'  => $h['size'],
			'read'  => 0,
			'crc'   => 0,
			'check' => $h['crc'],
		);
	}

	/**
	 * Read the next piece of an entry; the running CRC lives in the cursor.
	 *
	 * @param array $e   Entry cursor.
	 * @param int   $max Max bytes.
	 * @return string
	 * @throws FSC_Exception On a CRC mismatch.
	 */
	private function read_entry( array &$e, $max ) {
		$left = (int) $e['size'] - (int) $e['read'];
		if ( $left <= 0 ) {
			return '';
		}
		$n   = (int) min( $max, $left, self::CHUNK );
		$buf = $this->read_at( (int) $e['data'] + (int) $e['read'], $n );
		$c   = crc32( $buf ) & 0xFFFFFFFF;
		$e['crc']   = 0 === (int) $e['read'] ? $c : FSC_Source_Util::crc32_combine( (int) $e['crc'], $c, $n );
		$e['read'] += $n;
		if ( $e['read'] >= $e['size'] && '' !== $e['check'] && sprintf( '%08x', $e['crc'] ) !== $e['check'] ) {
			throw new FSC_Exception( sprintf( 'The .wpress archive is corrupt: CRC mismatch in %s.', $e['rel'] ) );
		}
		return $buf;
	}

	/**
	 * Next wp-content entry.
	 *
	 * @param array $cursor Cursor.
	 * @return array|null
	 * @throws FSC_Exception On corrupt archives.
	 */
	public function files_next( array &$cursor ) {
		if ( ! isset( $cursor['offset'] ) ) {
			$cursor = array(
				'offset' => 0,
				'entry'  => null,
			);
		}
		if ( ! empty( $cursor['entry'] ) ) {
			$cursor['offset'] = $cursor['entry']['data'] + $cursor['entry']['size'];
			$cursor['entry']  = null;
		}
		if ( ! empty( $cursor['eof'] ) ) {
			return null;
		}
		while ( true ) {
			$h = $this->header_at( (int) $cursor['offset'] );
			if ( 'eof' === $h['type'] ) {
				$cursor['eof'] = true;
				return null;
			}
			if ( ( $h['root'] && in_array( $h['name'], self::CONFIG, true ) ) || FSC_Source_Util::excluded( $h['rel'] ) ) {
				$cursor['offset'] = $h['data'] + $h['size'];
				continue;
			}
			$cursor['entry'] = self::entry_cursor( $h );
			return array(
				'path'  => $h['rel'],
				'type'  => 'file',
				'size'  => $h['size'],
				'mtime' => $h['mtime'],
			);
		}
	}

	/**
	 * Read from the current entry.
	 *
	 * @param array $cursor Cursor.
	 * @param int   $max    Max bytes.
	 * @return string
	 * @throws FSC_Exception On a CRC mismatch.
	 */
	public function files_read( array &$cursor, $max ) {
		if ( empty( $cursor['entry'] ) ) {
			return '';
		}
		return $this->read_entry( $cursor['entry'], $max );
	}

	/**
	 * Progress by archive offset.
	 *
	 * @param array $cursor Cursor.
	 * @return float
	 */
	public function files_progress( array $cursor ) {
		$this->fp();
		if ( $this->size <= 0 || ! isset( $cursor['offset'] ) ) {
			return 0.0;
		}
		if ( ! empty( $cursor['eof'] ) ) {
			return 1.0;
		}
		$pos = (int) $cursor['offset'];
		if ( ! empty( $cursor['entry'] ) ) {
			$pos = (int) $cursor['entry']['data'] + (int) $cursor['entry']['read'];
		}
		return min( 1.0, $pos / $this->size );
	}

	/**
	 * Extraction byte budget, see FSC_Source::extract_budget(). The .wpress
	 * format stores files uncompressed, so the archive size is an ample bound.
	 *
	 * @return float
	 */
	public function extract_budget() {
		return FSC_Source_Util::extract_budget( $this->path );
	}
}
