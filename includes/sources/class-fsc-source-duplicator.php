<?php
/**
 * Duplicator archive: DupArchive (.daf, pure PHP reader) or ZipArchive
 * (.zip, needs ext-zip). No WordPress dependency. See internal/formats/duplicator.md.
 *
 * The archive is rooted at ABSPATH. Only wp-content/** is extracted; the
 * metadata comes from dup-installer/dup_descriptors_<hash>/archive.txt and
 * the SQL from .../db_dumps/<ts>-dump.sql.gz (gunzipped to a temp file in
 * chunks).
 *
 * @package wp-free-site-cloner
 */

/**
 * Duplicator source.
 */
class FSC_Source_Duplicator implements FSC_Source {

	const CONTENT  = 'wp-content/';
	const CHUNK    = 4194304;
	const MAX_JSON = 16777216;
	const EOF_MARK = 'DUPLICATOR_MYSQLDUMP_EOF';

	/** Zip entries up to this size are read in one call instead of through a stream. */
	const SMALL = 4194304;

	/** @var string */
	private $path;

	/** @var string zip|daf */
	private $kind;

	/** @var FSC_Daf_Reader|null */
	private $daf = null;

	/** @var ZipArchive|null */
	private $zip = null;

	/** @var array|null Open zip entry stream: array( index, fp, pos ). */
	private $zs = null;

	/** @var array|null */
	private $located = null;

	/**
	 * Constructor.
	 *
	 * @param string $path Archive path.
	 */
	public function __construct( $path ) {
		$this->path = $path;
		$this->kind = 'zip' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ? 'zip' : 'daf';
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->close_stream();
		if ( $this->zip ) {
			$this->zip->close();
		}
	}

	/**
	 * Classify an archive entry name.
	 *
	 * @param string $name Entry name.
	 * @return string|null meta|dump_gz|dump_sql or null.
	 */
	public static function classify( $name ) {
		if ( preg_match( '#^dup-installer/dup_descriptors_[^/]+/archive\.txt$#', $name ) || preg_match( '#^dup-installer/dup-archive__[^/]+\.txt$#', $name ) ) {
			return 'meta';
		}
		if ( preg_match( '#^dup-installer/dup_descriptors_[^/]+/db_dumps/[^/]+-dump\.sql\.gz$#', $name ) ) {
			return 'dump_gz';
		}
		if ( preg_match( '#^dup-installer/dup-database__[^/]+\.sql$#', $name ) ) {
			return 'dump_sql';
		}
		return null;
	}

	/**
	 * Map an archive entry name to a wp-content relative path, or null when
	 * the entry is outside wp-content or excluded.
	 *
	 * @param string $name Entry name (relative to ABSPATH).
	 * @return string|null
	 */
	public static function content_path( $name ) {
		$name = str_replace( '\\', '/', (string) $name );
		while ( 0 === strpos( $name, './' ) ) {
			$name = substr( $name, 2 );
		}
		if ( 0 !== strpos( $name, self::CONTENT ) ) {
			return null;
		}
		$rel = trim( substr( $name, strlen( self::CONTENT ) ), '/' );
		if ( '' === $rel || FSC_Source_Util::excluded( $rel ) ) {
			return null;
		}
		return $rel;
	}

	/**
	 * Whether ZipArchive is available.
	 *
	 * @return bool
	 */
	public static function zip_available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Detect by extension and signature (.daf header, or a zip holding
	 * Duplicator's archive.txt).
	 *
	 * @param string $path Archive path.
	 * @return bool
	 */
	public static function detect( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'daf' !== $ext && 'zip' !== $ext ) {
			return false;
		}
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			return false;
		}
		$sig = (string) fread( $fp, 6 );
		fclose( $fp );
		if ( 'daf' === $ext ) {
			return '<A><V>' === $sig;
		}
		if ( "PK\x03\x04" !== substr( $sig, 0, 4 ) ) {
			return false;
		}
		if ( ! self::zip_available() ) {
			// Claimed so that meta() can explain that ext-zip is missing.
			return true;
		}
		try {
			$src = new self( $path );
			return null !== $src->locate()['meta'];
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Open the zip.
	 *
	 * @return ZipArchive
	 * @throws FSC_Exception When ext-zip is missing or the zip is unreadable.
	 */
	private function zip() {
		if ( ! $this->zip ) {
			if ( ! self::zip_available() ) {
				throw new FSC_Exception( 'Importing Duplicator .zip archives needs the PHP zip extension (ZipArchive), which this server does not have. Ask your host to enable it, or build the backup in Duplicator with the DupArchive (.daf) format.' );
			}
			$z     = new ZipArchive();
			$flags = defined( 'ZipArchive::RDONLY' ) ? ZipArchive::RDONLY : 0;
			$r     = $z->open( $this->path, $flags );
			if ( true !== $r ) {
				throw new FSC_Exception( sprintf( 'Cannot open the .zip archive (ZipArchive error %s). The file may be incomplete: upload or copy it again.', is_int( $r ) ? $r : '?' ) );
			}
			$this->zip = $z;
		}
		return $this->zip;
	}

	/**
	 * DAF reader.
	 *
	 * @return FSC_Daf_Reader
	 * @throws FSC_Exception When unreadable or encrypted.
	 */
	private function daf() {
		if ( ! $this->daf ) {
			$this->daf = new FSC_Daf_Reader( $this->path );
			if ( $this->daf->header()['encrypted'] ) {
				throw new FSC_Exception( 'This Duplicator .daf archive is encrypted with a password, which is not supported. Build the backup again without a password.' );
			}
		}
		return $this->daf;
	}

	/**
	 * Fresh low-level cursor.
	 *
	 * @return array
	 */
	private function new_cursor() {
		return 'zip' === $this->kind ? array(
			'i'     => 0,
			'entry' => null,
		) : FSC_Daf_Reader::new_cursor();
	}

	/**
	 * Close the open zip entry stream.
	 */
	private function close_stream() {
		if ( $this->zs && is_resource( $this->zs['fp'] ) ) {
			fclose( $this->zs['fp'] );
		}
		$this->zs = null;
	}

	/**
	 * Next archive entry (any path).
	 *
	 * @param array $c Low-level cursor.
	 * @return array|null array( name, type, size, mtime ).
	 * @throws FSC_Exception On corrupt archives.
	 */
	private function entry_next( array &$c ) {
		if ( 'daf' === $this->kind ) {
			return $this->daf()->next_entry( $c );
		}
		$z          = $this->zip();
		$c['entry'] = null;
		if ( $c['i'] >= $z->numFiles ) {
			return null;
		}
		$i = (int) $c['i'];
		$s = $z->statIndex( $i );
		++$c['i'];
		if ( ! $s ) {
			throw new FSC_Exception( sprintf( 'The .zip archive is corrupt (entry %d).', $i ) );
		}
		$name = (string) $s['name'];
		$type = '/' === substr( $name, -1 ) ? 'dir' : 'file';
		$os   = 0;
		$attr = 0;
		if ( 'file' === $type && $z->getExternalAttributesIndex( $i, $os, $attr ) && ZipArchive::OPSYS_UNIX === $os && 0120000 === ( ( $attr >> 16 ) & 0170000 ) ) {
			$type = 'symlink';
		}
		if ( 'file' === $type ) {
			$c['entry'] = array(
				'index' => $i,
				'name'  => $name,
				'size'  => (int) $s['size'],
				'read'  => 0,
				'crc'   => 0,
				'enc'   => ! empty( $s['encryption_method'] ),
			);
		}
		return array(
			'name'  => $name,
			'type'  => $type,
			'size'  => 'file' === $type ? (int) $s['size'] : 0,
			'mtime' => (int) $s['mtime'],
		);
	}

	/**
	 * Read from the current archive entry.
	 *
	 * @param array $c   Low-level cursor.
	 * @param int   $max Max bytes.
	 * @return string
	 * @throws FSC_Exception On corrupt archives.
	 */
	private function entry_read( array &$c, $max ) {
		if ( 'daf' === $this->kind ) {
			return $this->daf()->read( $c, $max );
		}
		if ( empty( $c['entry'] ) ) {
			return '';
		}
		$e    = &$c['entry'];
		$left = (int) $e['size'] - (int) $e['read'];
		if ( $left <= 0 ) {
			return '';
		}
		if ( $e['enc'] ) {
			throw new FSC_Exception( 'This .zip archive contains encrypted files, which is not supported.' );
		}
		if ( 0 === (int) $e['read'] && $left <= min( (int) $max, self::SMALL ) ) {
			// Small entry in one go: getStream() reopens the whole archive per call on older libzip.
			$data = $this->zip()->getFromIndex( $e['index'] );
			if ( ! is_string( $data ) || strlen( $data ) !== $left ) {
				throw new FSC_Exception( sprintf( 'The .zip archive is corrupt or truncated (%s).', $e['name'] ) );
			}
			$e['read'] = $left;
			return $data;
		}
		if ( ! $this->zs || $this->zs['index'] !== $e['index'] || $this->zs['pos'] !== (int) $e['read'] ) {
			$this->close_stream();
			$z  = $this->zip();
			$fp = method_exists( $z, 'getStreamIndex' ) ? $z->getStreamIndex( $e['index'] ) : $z->getStream( $e['name'] );
			if ( ! $fp ) {
				throw new FSC_Exception( sprintf( 'Cannot read %s from the .zip archive.', $e['name'] ) );
			}
			$this->zs = array(
				'index' => $e['index'],
				'fp'    => $fp,
				'pos'   => 0,
			);
			// Streams cannot seek inside a compressed entry: skip what was already read.
			while ( $this->zs['pos'] < (int) $e['read'] ) {
				$skip = fread( $fp, (int) min( 1048576, (int) $e['read'] - $this->zs['pos'] ) );
				if ( false === $skip || '' === $skip ) {
					throw new FSC_Exception( sprintf( 'The .zip archive is corrupt (%s).', $e['name'] ) );
				}
				$this->zs['pos'] += strlen( $skip );
			}
		}
		$want = (int) min( $max, $left );
		$out  = '';
		while ( strlen( $out ) < $want ) {
			$buf = fread( $this->zs['fp'], $want - strlen( $out ) );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			$out .= $buf;
		}
		if ( '' === $out ) {
			$this->close_stream();
			throw new FSC_Exception( sprintf( 'The .zip archive is corrupt or truncated (%s).', $e['name'] ) );
		}
		// libzip only verifies an entry's CRC when getFromIndex() reads it in
		// one piece (the small path). The stream stops after exactly size
		// bytes, so carry a running CRC and compare it ourselves at the end.
		$this->zs['pos'] += strlen( $out );
		$chunk            = crc32( $out ) & 0xFFFFFFFF;
		$e['crc']         = 0 === (int) $e['read'] ? $chunk : FSC_Source_Util::crc32_combine( (int) $e['crc'], $chunk, strlen( $out ) );
		$e['read']       += strlen( $out );
		if ( (int) $e['read'] >= (int) $e['size'] ) {
			$stat = $this->zip()->statIndex( (int) $e['index'] );
			if ( is_array( $stat ) && isset( $stat['crc'] ) && ( (int) $e['crc'] & 0xFFFFFFFF ) !== ( (int) $stat['crc'] & 0xFFFFFFFF ) ) {
				$this->close_stream();
				throw new FSC_Exception( sprintf( 'The .zip archive is corrupt or truncated (%s).', $e['name'] ) );
			}
		}
		return $out;
	}

	/**
	 * Position for progress.
	 *
	 * @param array $c Low-level cursor.
	 * @return float 0..1
	 */
	private function entry_progress( array $c ) {
		if ( 'daf' === $this->kind ) {
			$size = $this->daf()->archive_size();
			return $size > 0 ? min( 1.0, FSC_Daf_Reader::position( $c ) / $size ) : 0.0;
		}
		$n = max( 1, $this->zip()->numFiles );
		return min( 1.0, (int) $c['i'] / $n );
	}

	/**
	 * Find the metadata and dump entries.
	 *
	 * @return array array( meta => cursor|null, dump => cursor|null, dump_gz => bool, dump_name => string ).
	 * @throws FSC_Exception On corrupt archives.
	 */
	public function locate() {
		if ( null !== $this->located ) {
			return $this->located;
		}
		$found = array(
			'meta'      => null,
			'dump'      => null,
			'dump_gz'   => false,
			'dump_name' => '',
		);
		if ( 'zip' === $this->kind ) {
			$z = $this->zip();
			for ( $i = 0; $i < $z->numFiles; $i++ ) {
				$name = (string) $z->getNameIndex( $i );
				$kind = self::classify( $name );
				if ( 'meta' === $kind && null === $found['meta'] ) {
					$found['meta'] = array(
						'i'     => $i,
						'entry' => null,
					);
				} elseif ( ( 'dump_gz' === $kind || 'dump_sql' === $kind ) && null === $found['dump'] ) {
					$found['dump']      = array(
						'i'     => $i,
						'entry' => null,
					);
					$found['dump_gz']   = 'dump_gz' === $kind;
					$found['dump_name'] = $name;
				}
			}
			$this->located = $found;
			return $found;
		}
		$starts = array();
		$extra  = $this->daf_extra_pos();
		if ( $extra > 0 ) {
			$starts[] = $extra;
		}
		$starts[] = null;
		foreach ( $starts as $start ) {
			$c = FSC_Daf_Reader::new_cursor();
			if ( null !== $start ) {
				$c['offset'] = $start;
			}
			try {
				while ( null === $found['meta'] || null === $found['dump'] ) {
					$e = $this->daf()->next_entry( $c );
					if ( null === $e ) {
						break;
					}
					$kind = 'file' === $e['type'] ? self::classify( $e['name'] ) : null;
					if ( 'meta' === $kind && null === $found['meta'] ) {
						$found['meta'] = array(
							'offset' => $e['offset'],
							'entry'  => null,
						);
					} elseif ( ( 'dump_gz' === $kind || 'dump_sql' === $kind ) && null === $found['dump'] ) {
						$found['dump']      = array(
							'offset' => $e['offset'],
							'entry'  => null,
						);
						$found['dump_gz']   = 'dump_gz' === $kind;
						$found['dump_name'] = $e['name'];
					}
				}
			} catch ( FSC_Exception $ex ) {
				if ( null === $start ) {
					throw $ex;
				}
			}
			if ( null !== $found['meta'] && null !== $found['dump'] ) {
				break;
			}
		}
		$this->located = $found;
		return $found;
	}

	/**
	 * extraPos from __dup__archive__index.json (offset of the installer
	 * records, which hold archive.txt and the dump), or 0.
	 *
	 * @return int
	 */
	private function daf_extra_pos() {
		try {
			$c = FSC_Daf_Reader::new_cursor();
			$e = $this->daf()->next_entry( $c );
			if ( ! $e || '__dup__archive__index.json' !== $e['name'] || $e['size'] > 65536 ) {
				return 0;
			}
			$raw = '';
			while ( '' !== ( $buf = $this->daf()->read( $c, 65536 ) ) ) {
				$raw .= $buf;
			}
			$j = json_decode( rtrim( $raw, "\0" ), true );
			$p = is_array( $j ) && isset( $j['extraPos'] ) ? (int) $j['extraPos'] : 0;
			return ( $p > 0 && $p < $this->daf()->archive_size() ) ? $p : 0;
		} catch ( FSC_Exception $ex ) {
			return 0;
		}
	}

	/**
	 * Whole content of a small entry.
	 *
	 * @param array $locator Cursor positioned before the entry.
	 * @return string
	 * @throws FSC_Exception When too large or unreadable.
	 */
	private function read_small( array $locator ) {
		$c = $locator;
		$e = $this->entry_next( $c );
		if ( ! $e || $e['size'] > self::MAX_JSON ) {
			throw new FSC_Exception( 'The Duplicator metadata file is missing or too large.' );
		}
		$raw = '';
		while ( '' !== ( $buf = $this->entry_read( $c, self::CHUNK ) ) ) {
			$raw .= $buf;
		}
		return $raw;
	}

	/**
	 * Metadata, see FSC_Source::meta().
	 *
	 * @return array
	 * @throws FSC_Exception When unreadable or unsupported.
	 */
	public function meta() {
		if ( 'zip' === $this->kind ) {
			$this->zip();
		} else {
			$this->daf();
		}
		$loc = $this->locate();
		if ( null === $loc['meta'] ) {
			throw new FSC_Exception( 'This is not a Duplicator archive (archive.txt is missing).' );
		}
		$m = FSC_Source_Util::json( $this->read_small( $loc['meta'] ), 'archive.txt' );
		if ( ! empty( $m['secure_on'] ) ) {
			throw new FSC_Exception( 'This Duplicator archive is password protected, which is not supported. Build the backup again without a password.' );
		}
		if ( null === $loc['dump'] ) {
			throw new FSC_Exception( 'This Duplicator archive contains no database dump.' );
		}
		$rv      = 'wpInfo.configs.realValues.';
		$sub     = isset( $m['subsites'][0] ) && is_array( $m['subsites'][0] ) ? $m['subsites'][0] : array();
		$g       = function ( $path ) use ( $m ) {
			return FSC_Source_Util::get( $m, $path );
		};
		$home    = FSC_Source_Util::first( $g( $rv . 'homeUrl' ), FSC_Source_Util::get( $sub, 'fullHomeUrl' ), $g( 'url_old' ) );
		$siteurl = FSC_Source_Util::first( $g( $rv . 'siteUrl' ), FSC_Source_Util::get( $sub, 'fullSiteUrl' ), $g( 'url_old' ), $home );
		$prefix  = is_string( $g( 'wp_tableprefix' ) ) ? $g( 'wp_tableprefix' ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			throw new FSC_Exception( 'The Duplicator archive does not state a valid table prefix.' );
		}
		$warn = array( 'Only wp-content and the database are imported. WordPress core files, wp-config.php and the Duplicator installer in the archive are ignored; this site keeps its WordPress version and configuration.' );
		if ( ! empty( $m['exportOnlyDB'] ) ) {
			$warn[] = 'This is a database-only Duplicator backup; files in wp-content are not changed.';
		}
		$ver = FSC_Source_Util::first( $g( 'version_dup' ) );
		return array(
			'format'      => 'duplicator',
			'format_name' => 'Duplicator (.' . $this->kind . ( '' !== $ver ? ', version ' . $ver : '' ) . ')',
			'home'        => '' !== $home ? $home : $siteurl,
			'siteurl'     => $siteurl,
			'abspath'     => FSC_Source_Util::first( $g( $rv . 'originalPaths.abs' ), $g( $rv . 'archivePaths.abs' ), $g( 'wpInfo.targetRoot' ) ),
			'content_dir' => FSC_Source_Util::first( $g( $rv . 'originalPaths.wpcontent' ), $g( 'wpInfo.configs.defines.WP_CONTENT_DIR.value' ) ),
			'uploads_url' => FSC_Source_Util::first( $g( $rv . 'uploadBaseUrl' ), FSC_Source_Util::get( $sub, 'fullUploadUrl' ) ),
			'prefix'      => $prefix,
			'sql_prefix'  => $prefix,
			'sql_entry'   => $loc['dump_name'],
			'multisite'   => ! empty( $m['mu_mode'] ) || ! empty( $m['wpInfo']['is_multisite'] ),
			'wp_version'  => FSC_Source_Util::first( $g( 'version_wp' ), $g( 'wpInfo.version' ) ),
			'file_count'  => null,
			'sql_sha256'  => '',
			'warnings'    => $warn,
		);
	}

	/**
	 * Extract the dump to $dest: copy the (gzipped) entry out, then gunzip it
	 * in chunks. Resumable in both stages.
	 *
	 * @param string $dest     Destination file.
	 * @param array  $cursor   Cursor.
	 * @param float  $deadline Deadline.
	 * @return bool
	 * @throws FSC_Exception On errors.
	 */
	public function extract_sql( $dest, array &$cursor, $deadline ) {
		$gz = $dest . '.gz';
		if ( empty( $cursor['stage'] ) ) {
			$loc = $this->locate();
			if ( null === $loc['dump'] ) {
				throw new FSC_Exception( 'This Duplicator archive contains no database dump.' );
			}
			$c = $loc['dump'];
			$e = $this->entry_next( $c );
			if ( ! $e || 'file' !== $e['type'] ) {
				throw new FSC_Exception( 'The database dump in the Duplicator archive is unreadable.' );
			}
			$cursor = array(
				'stage'    => 'copy',
				'src'      => $c,
				'gz'       => $loc['dump_gz'],
				'size'     => $e['size'],
				'copied'   => 0,
				'gunzip'   => array( 'out' => 0 ),
				'progress' => 0,
			);
		}
		while ( true ) {
			if ( 'copy' === $cursor['stage'] ) {
				$target = $cursor['gz'] ? $gz : $dest;
				$out    = @fopen( $target, 'c+b' );
				if ( ! $out ) {
					throw new FSC_Exception( 'Cannot write the temporary SQL file.' );
				}
				$cap = FSC_Source_Util::extract_budget( $this->path );
				ftruncate( $out, (int) $cursor['copied'] );
				fseek( $out, (int) $cursor['copied'] );
				$done = false;
				do {
					$buf = $this->entry_read( $cursor['src'], self::CHUNK );
					if ( '' === $buf ) {
						$done = true;
						break;
					}
					if ( fwrite( $out, $buf ) !== strlen( $buf ) ) {
						fclose( $out );
						throw new FSC_Exception( 'Writing the temporary SQL file failed (disk full?).' );
					}
					$cursor['copied'] += strlen( $buf );
					if ( $cursor['copied'] > $cap ) {
						fclose( $out );
						@unlink( $target );
						throw new FSC_Exception( sprintf( 'The database dump in the Duplicator archive expands to more than %d MB (far more than the archive size). It is refused as damaged or malicious.', (int) floor( $cap / 1048576 ) ) );
					}
					$free = function_exists( 'disk_free_space' ) ? @disk_free_space( dirname( $target ) ) : false;
					if ( false !== $free && null !== $free && $free < FSC_Source_Util::GZ_DISK_MARGIN ) {
						fclose( $out );
						throw new FSC_Exception( 'There is not enough free disk space to unpack the database dump. Free up space and start the import again.' );
					}
				} while ( microtime( true ) < $deadline );
				fclose( $out );
				$share              = $cursor['gz'] ? 0.5 : 1.0;
				$cursor['progress'] = $share * ( $cursor['size'] > 0 ? $cursor['copied'] / $cursor['size'] : 1 );
				if ( ! $done ) {
					return false;
				}
				$cursor['stage'] = $cursor['gz'] ? 'gunzip' : 'check';
				continue;
			}
			if ( 'gunzip' === $cursor['stage'] ) {
				$sub  = $cursor['gunzip'];
				$done = FSC_Source_Util::gunzip_step( $gz, $dest, $sub, $deadline );
				$isz  = FSC_Source_Util::gz_isize( $gz );
				$cursor['gunzip']   = $sub;
				$cursor['progress'] = 0.5 + 0.5 * ( $isz > 0 ? min( 1, $sub['out'] / $isz ) : 0 );
				if ( ! $done ) {
					return false;
				}
				@unlink( $gz );
				$cursor['stage'] = 'check';
				continue;
			}
			if ( $cursor['gz'] && false === strpos( FSC_Source_Util::tail( $dest, 512 ), self::EOF_MARK ) ) {
				throw new FSC_Exception( 'The database dump in the Duplicator archive is incomplete (end marker missing). Build the backup again.' );
			}
			$cursor['progress'] = 1;
			return true;
		}
	}

	/**
	 * Next wp-content entry.
	 *
	 * @param array $cursor Cursor.
	 * @return array|null
	 * @throws FSC_Exception On corrupt archives.
	 */
	public function files_next( array &$cursor ) {
		if ( ! isset( $cursor['c'] ) ) {
			$cursor = array( 'c' => $this->new_cursor() );
		}
		while ( true ) {
			$e = $this->entry_next( $cursor['c'] );
			if ( null === $e ) {
				$cursor['end'] = true;
				return null;
			}
			$rel = self::content_path( $e['name'] );
			if ( null === $rel ) {
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
	 * @throws FSC_Exception On corrupt archives.
	 */
	public function files_read( array &$cursor, $max ) {
		if ( ! isset( $cursor['c'] ) ) {
			return '';
		}
		return $this->entry_read( $cursor['c'], $max );
	}

	/**
	 * Progress of the file walk.
	 *
	 * @param array $cursor Cursor.
	 * @return float
	 */
	public function files_progress( array $cursor ) {
		if ( ! isset( $cursor['c'] ) ) {
			return 0.0;
		}
		if ( ! empty( $cursor['end'] ) ) {
			return 1.0;
		}
		try {
			return $this->entry_progress( $cursor['c'] );
		} catch ( FSC_Exception $e ) {
			return 0.0;
		}
	}

	/**
	 * Extraction byte budget, see FSC_Source::extract_budget(). Zip entries and
	 * .daf blocks inflate, so the bound is a multiple of the archive size.
	 *
	 * @return float
	 */
	public function extract_budget() {
		return FSC_Source_Util::extract_budget( $this->path );
	}
}
