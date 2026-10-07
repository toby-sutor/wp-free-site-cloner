<?php
/**
 * Native archive (.tar with fsc-manifest.json, fsc-database.sql, wp-content/...).
 * No WordPress dependency.
 *
 * @package wp-free-site-cloner
 */

/**
 * Native tar source.
 */
class FSC_Source_Native implements FSC_Source {

	const MANIFEST = 'fsc-manifest.json';
	const SQL      = 'fsc-database.sql';
	const CONTENT  = 'wp-content/';
	const CHUNK    = 4194304;

	/** @var string */
	private $path;

	/** @var FSC_Tar_Reader|null */
	private $reader = null;

	/** @var array|null */
	private $manifest = null;

	/**
	 * Constructor.
	 *
	 * @param string $path Archive path.
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Lazily opened reader.
	 *
	 * @return FSC_Tar_Reader
	 */
	private function reader() {
		if ( ! $this->reader ) {
			$this->reader = new FSC_Tar_Reader( $this->path );
		}
		return $this->reader;
	}

	/**
	 * Detect by extension and a manifest as first entry.
	 *
	 * @param string $path Archive path.
	 * @return bool
	 */
	public static function detect( $path ) {
		if ( 'tar' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return false;
		}
		try {
			$r      = new FSC_Tar_Reader( $path );
			$cursor = FSC_Tar_Reader::new_cursor();
			$e      = $r->next_entry( $cursor );
			$r->close();
			return $e && self::MANIFEST === $e['name'];
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Raw manifest.
	 *
	 * @return array
	 * @throws FSC_Exception When missing or invalid.
	 */
	public function manifest() {
		if ( null !== $this->manifest ) {
			return $this->manifest;
		}
		$cursor = FSC_Tar_Reader::new_cursor();
		$e      = $this->reader()->next_entry( $cursor );
		if ( ! $e || self::MANIFEST !== $e['name'] || $e['size'] > 16777216 ) {
			throw new FSC_Exception( 'This .tar file is not a WP Free Site Cloner archive (manifest missing).' );
		}
		$data = json_decode( $this->reader()->read( $cursor, $e['size'] ), true );
		if ( ! is_array( $data ) || empty( $data['format'] ) || 'fsc' !== $data['format'] ) {
			throw new FSC_Exception( 'The archive manifest is invalid.' );
		}
		if ( isset( $data['format_version'] ) && (int) $data['format_version'] > 1 ) {
			throw new FSC_Exception( 'The archive was made by a newer version of the plugin. Please update the plugin.' );
		}
		$this->manifest = $data;
		return $data;
	}

	/**
	 * A finished archive has exactly the size recorded in its manifest
	 * (archive_size, 0.9.1+), is a whole number of 512-byte blocks and ends
	 * with two zero blocks; anything else is an incomplete upload or copy.
	 *
	 * @param string     $path     Archive.
	 * @param array|null $manifest Parsed manifest, when known.
	 * @throws FSC_Exception When incomplete.
	 */
	public static function check_complete( $path, $manifest = null ) {
		FSC_Integrity::check_size( $path, $manifest );
	}

	/**
	 * Cheap completeness check of a freshly uploaded or copied file.
	 *
	 * @param string $path Archive.
	 * @return array array( ok => bool, message => string, expected => int|null, actual => int ).
	 */
	public static function quick_check( $path ) {
		clearstatcache( true, $path );
		$out = array(
			'ok'       => true,
			'message'  => '',
			'expected' => null,
			'actual'   => (int) @filesize( $path ),
		);
		try {
			if ( ! self::detect( $path ) ) {
				throw new FSC_Exception( __( 'This .tar file is not a WP Free Site Cloner archive, or its beginning is damaged.', 'wp-free-site-cloner' ) );
			}
			$src             = new self( $path );
			$m               = $src->manifest();
			$out['expected'] = FSC_Integrity::expected_size( $m );
			self::check_complete( $path, $m );
		} catch ( Throwable $e ) {
			$out['ok']      = false;
			$out['message'] = FSC_Job::public_message( $e );
		}
		return $out;
	}

	/**
	 * Archive path.
	 *
	 * @return string
	 */
	public function path() {
		return $this->path;
	}

	/**
	 * Metadata, see FSC_Source::meta().
	 *
	 * @return array
	 */
	public function meta() {
		$m = $this->manifest();
		self::check_complete( $this->path, $m );
		// Manifest values are typed strictly: an array or object never becomes "Array" or 1.
		$g   = function ( $key ) use ( $m ) {
			return isset( $m[ $key ] ) && ( is_string( $m[ $key ] ) || is_int( $m[ $key ] ) || is_float( $m[ $key ] ) ) ? (string) $m[ $key ] : '';
		};
		$int = function ( $key ) use ( $m ) {
			return isset( $m[ $key ] ) && ( is_int( $m[ $key ] ) || ( is_string( $m[ $key ] ) && ctype_digit( $m[ $key ] ) ) ) ? (int) $m[ $key ] : 0;
		};
		$ms     = isset( $m['multisite'] ) ? $m['multisite'] : false;
		$prefix = $g( 'table_prefix' );
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			throw new FSC_Exception( 'The archive does not state a valid table prefix.' );
		}
		return array(
			'format'      => 'native',
			'format_name' => 'WP Free Site Cloner (.tar)',
			'home'        => $g( 'home' ),
			'siteurl'     => $g( 'siteurl' ),
			'abspath'     => $g( 'abspath' ),
			'content_dir' => $g( 'content_dir' ),
			'uploads_url' => $g( 'uploads_baseurl' ),
			'prefix'      => $prefix,
			'sql_prefix'  => '{{FSC_PREFIX}}',
			'sql_entry'   => self::SQL,
			// Unknown types count as multisite (refused) rather than as a single site.
			'multisite'   => ! ( false === $ms || 0 === $ms || '0' === $ms || '' === $ms || null === $ms ),
			'wp_version'  => $g( 'wp_version' ),
			'file_count'  => $int( 'file_count' ),
			'sql_sha256'  => $g( 'sql_sha256' ),
			'files_bytes' => $int( 'files_bytes' ),
			'sql_size'    => $int( 'sql_size' ),
			'table_count' => isset( $m['tables'] ) && is_array( $m['tables'] ) ? count( $m['tables'] ) : 0,
			'archive_size' => FSC_Integrity::expected_size( $m ),
		);
	}

	/**
	 * Copy fsc-database.sql out of the archive.
	 *
	 * @param string $dest     Destination file.
	 * @param array  $cursor   Cursor.
	 * @param float  $deadline Deadline.
	 * @return bool
	 * @throws FSC_Exception On errors.
	 */
	public function extract_sql( $dest, array &$cursor, $deadline ) {
		$r = $this->reader();
		if ( empty( $cursor['tar'] ) ) {
			$tar = FSC_Tar_Reader::new_cursor();
			while ( true ) {
				$e = $r->next_entry( $tar );
				if ( ! $e || 0 === strpos( $e['name'], self::CONTENT ) ) {
					throw new FSC_Exception( 'The archive contains no database dump.' );
				}
				if ( self::SQL === $e['name'] ) {
					break;
				}
			}
			$cursor['tar'] = $tar;
		}
		$tar = $cursor['tar'];
		$out = @fopen( $dest, 'c+b' );
		if ( ! $out ) {
			throw new FSC_Exception( 'Cannot write the temporary SQL file.' );
		}
		ftruncate( $out, (int) $tar['entry']['read'] );
		fseek( $out, (int) $tar['entry']['read'] );
		$done = false;
		do {
			$buf = $r->read( $tar, self::CHUNK );
			if ( '' === $buf ) {
				$done = true;
				break;
			}
			if ( fwrite( $out, $buf ) !== strlen( $buf ) ) {
				fclose( $out );
				throw new FSC_Exception( 'Writing the temporary SQL file failed (disk full?).' );
			}
			$cursor['tar'] = $tar;
		} while ( microtime( true ) < $deadline );
		fclose( $out );
		$cursor['tar']      = $tar;
		$cursor['progress'] = $tar['entry']['size'] > 0 ? $tar['entry']['read'] / $tar['entry']['size'] : 1;
		return $done;
	}

	/**
	 * Next wp-content entry.
	 *
	 * @param array $cursor Cursor.
	 * @return array|null
	 */
	public function files_next( array &$cursor ) {
		if ( ! isset( $cursor['offset'] ) ) {
			$cursor = FSC_Tar_Reader::new_cursor();
		}
		while ( true ) {
			$e = $this->reader()->next_entry( $cursor );
			if ( ! $e ) {
				return null;
			}
			$name = $e['name'];
			while ( 0 === strpos( $name, './' ) ) {
				$name = substr( $name, 2 );
			}
			if ( 0 !== strpos( $name, self::CONTENT ) ) {
				continue;
			}
			$rel = rtrim( substr( $name, strlen( self::CONTENT ) ), '/' );
			if ( '' === $rel || FSC_Source_Util::excluded( $rel ) ) {
				continue;
			}
			return array(
				'path'  => $rel,
				'type'  => $e['type'],
				'size'  => $e['size'],
				'mtime' => $e['mtime'],
			);
		}
	}

	/**
	 * Read from the current entry.
	 *
	 * @param array $cursor Cursor.
	 * @param int   $max    Max bytes.
	 * @return string
	 */
	public function files_read( array &$cursor, $max ) {
		return $this->reader()->read( $cursor, $max );
	}

	/**
	 * Progress by archive offset.
	 *
	 * @param array $cursor Cursor.
	 * @return float
	 */
	public function files_progress( array $cursor ) {
		$size = $this->reader()->archive_size();
		if ( $size <= 0 || ! isset( $cursor['offset'] ) ) {
			return 0.0;
		}
		$pos = (int) $cursor['offset'];
		if ( ! empty( $cursor['entry'] ) ) {
			$pos = (int) $cursor['entry']['data'] + (int) $cursor['entry']['read'];
		}
		return min( 1.0, $pos / $size );
	}

	/**
	 * Extraction byte budget, see FSC_Source::extract_budget(). A native .tar
	 * stores files uncompressed, so the files either sum to the recorded
	 * files_bytes or occupy the archive minus its SQL dump; a manifest that
	 * under-reports files_bytes cannot raise the real bound below that.
	 *
	 * @return float
	 * @throws FSC_Exception When the manifest is unreadable.
	 */
	public function extract_budget() {
		$m     = $this->meta();
		$files = (float) ( isset( $m['files_bytes'] ) ? (int) $m['files_bytes'] : 0 );
		$rest  = (float) ( (int) $m['archive_size'] - (int) $m['sql_size'] );
		return (float) max( $files, $rest, FSC_Source_Util::GZ_MIN_CAP );
	}
}
