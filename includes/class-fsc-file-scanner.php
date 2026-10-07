<?php
/**
 * Resumable breadth-first walk of a directory tree into a list file.
 * No WordPress dependency.
 *
 * List file format, one entry per line:
 *   D\t<rawurlencoded relative path>
 *   F\t<size>\t<rawurlencoded relative path>
 * The list file doubles as the queue of directories still to read, so the
 * cursor stays small: array( size => list file size, read => offset of the
 * next line to examine, files, dirs, bytes ).
 *
 * @package wp-free-site-cloner
 */

/**
 * Directory scanner.
 */
class FSC_File_Scanner {

	/** @var string */
	private $root;

	/** @var string */
	private $list;

	/** @var array Relative paths to exclude (exact path; a directory excludes its subtree). */
	private $excludes;

	/** @var array Name-prefix excludes: array( parent dir, name prefix ) from entries ending in "*". */
	private $prefixes = array();

	/** @var int Symlink notices logged so far. */
	private $link_notes = 0;

	/** @var array Messages for the job log. */
	public $messages = array();

	/**
	 * Built-in excludes, relative to wp-content.
	 *
	 * @return array
	 */
	public static function default_excludes() {
		return array(
			// This plugin's storage, WordPress temp dirs, logs.
			'fsc-storage',
			'upgrade',
			'upgrade-temp-backup',
			'wflogs',
			'debug.log',
			// Backup plugins.
			'ai1wm-backups',
			'plugins/all-in-one-wp-migration/storage',
			'updraft',
			'backups-dup-lite',
			'backups-dup-pro',
			'duplicator-backups',
			'wpvividbackups',
			'backuply',
			'uploads/backwpup',
			'uploads/backwpup-*',
			'uploads/backup-guard',
			'uploads/wp-staging/backups',
			'uploads/backupbuddy_backups',
			// Regenerable caches.
			'cache',
			'et-cache',
			'litespeed',
			'wphb-cache',
		);
	}

	/**
	 * Constructor.
	 *
	 * @param string $root     Directory to walk.
	 * @param string $list     List file path.
	 * @param array  $excludes Relative paths to skip (a directory excludes its subtree).
	 */
	public function __construct( $root, $list, array $excludes = array() ) {
		$this->root     = rtrim( $root, '/\\' );
		$this->list     = $list;
		$this->excludes = array();
		foreach ( $excludes as $x ) {
			$x = trim( str_replace( '\\', '/', (string) $x ), '/' );
			if ( '' === $x ) {
				continue;
			}
			if ( '*' === substr( $x, -1 ) ) {
				$slash            = strrpos( $x, '/' );
				$this->prefixes[] = false === $slash ? array( '', substr( $x, 0, -1 ) ) : array( substr( $x, 0, $slash ), substr( $x, $slash + 1, -1 ) );
				continue;
			}
			$this->excludes[ $x ] = true;
		}
	}

	/**
	 * Whether a relative path (or one of its parents) is excluded.
	 *
	 * @param string $rel Relative path.
	 * @return bool
	 */
	public function is_excluded( $rel ) {
		$parts = explode( '/', $rel );
		$path  = '';
		foreach ( $parts as $i => $name ) {
			$parent = $path;
			$path   = '' === $path ? $name : $path . '/' . $name;
			if ( isset( $this->excludes[ $path ] ) ) {
				return true;
			}
			foreach ( $this->prefixes as $p ) {
				if ( $p[0] === $parent && '' !== $p[1] && 0 === strpos( $name, $p[1] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Fresh cursor.
	 *
	 * @return array
	 */
	public static function new_cursor() {
		return array(
			'size'  => 0,
			'read'  => 0,
			'files' => 0,
			'dirs'  => 0,
			'bytes' => 0,
		);
	}

	/**
	 * Parse one list line.
	 *
	 * @param string $line Line.
	 * @return array|null array( type => D|F, path, size ) or null.
	 */
	public static function parse_line( $line ) {
		$parts = explode( "\t", rtrim( $line, "\r\n" ) );
		if ( 'D' === $parts[0] && 2 === count( $parts ) ) {
			return array(
				'type' => 'D',
				'path' => rawurldecode( $parts[1] ),
				'size' => 0,
			);
		}
		if ( 'F' === $parts[0] && 3 === count( $parts ) ) {
			return array(
				'type' => 'F',
				'path' => rawurldecode( $parts[2] ),
				'size' => (int) $parts[1],
			);
		}
		return null;
	}

	/**
	 * Scan until done or $deadline (microtime) passes.
	 *
	 * @param array $cursor   Cursor, updated in place.
	 * @param float $deadline Deadline.
	 * @return bool True when the whole tree is listed.
	 * @throws FSC_Exception When the list file cannot be written.
	 */
	public function step( array &$cursor, $deadline ) {
		$fp = @fopen( $this->list, 'c+b' );
		if ( ! $fp ) {
			throw new FSC_Exception( 'Cannot write the file list.' );
		}
		ftruncate( $fp, (int) $cursor['size'] );
		if ( 0 === (int) $cursor['size'] ) {
			$this->write( $fp, "D\t\n" );
			$cursor['size'] = ftell( $fp );
		}
		$done = false;
		do {
			fseek( $fp, (int) $cursor['read'] );
			$dir = null;
			while ( ( $line = fgets( $fp ) ) !== false ) {
				$cursor['read'] += strlen( $line );
				$row             = self::parse_line( $line );
				if ( $row && 'D' === $row['type'] ) {
					$dir = $row['path'];
					break;
				}
			}
			if ( null === $dir ) {
				$done = true;
				break;
			}
			fseek( $fp, 0, SEEK_END );
			$this->list_dir( $fp, $dir, $cursor );
			$cursor['size'] = ftell( $fp );
		} while ( microtime( true ) < $deadline );
		fclose( $fp );
		return $done;
	}

	/**
	 * Write to the list file.
	 *
	 * @param resource $fp   Handle.
	 * @param string   $data Data.
	 * @throws FSC_Exception On a short write.
	 */
	private function write( $fp, $data ) {
		if ( fwrite( $fp, $data ) !== strlen( $data ) ) {
			throw new FSC_Exception( 'Writing the file list failed (disk full?).' );
		}
	}

	/**
	 * Append the children of one directory to the list.
	 *
	 * @param resource $fp     List handle positioned at the end.
	 * @param string   $rel    Directory relative to the root ('' for the root).
	 * @param array    $cursor Cursor (counters updated).
	 */
	private function list_dir( $fp, $rel, array &$cursor ) {
		$abs   = '' === $rel ? $this->root : $this->root . '/' . $rel;
		$names = @scandir( $abs );
		if ( false === $names ) {
			$this->messages[] = sprintf( 'Skipped unreadable directory: %s', $rel );
			return;
		}
		sort( $names, SORT_STRING );
		$out = '';
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = '' === $rel ? $name : $rel . '/' . $name;
			if ( $this->is_excluded( $child ) ) {
				continue;
			}
			$path = $abs . '/' . $name;
			if ( is_link( $path ) && ! $this->follow_link( $path, $child ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				if ( is_link( $path ) && $this->is_loop( $path, $abs ) ) {
					$this->messages[] = sprintf( 'Skipped symlinked directory that loops back: %s', $child );
					continue;
				}
				$out .= "D\t" . rawurlencode( $child ) . "\n";
				++$cursor['dirs'];
			} elseif ( is_file( $path ) ) {
				if ( ! is_readable( $path ) ) {
					$this->messages[] = sprintf( 'Skipped unreadable file: %s', $child );
					continue;
				}
				$size  = (int) @filesize( $path );
				$out  .= "F\t" . $size . "\t" . rawurlencode( $child ) . "\n";
				++$cursor['files'];
				$cursor['bytes'] += $size;
			}
			if ( strlen( $out ) > 65536 ) {
				$this->write( $fp, $out );
				$out = '';
			}
		}
		$this->write( $fp, $out );
	}

	/**
	 * Whether to follow a symlink: not when broken, and not when it points at
	 * an excluded path inside the root (e.g. into fsc-storage). Links that
	 * leave the root are followed and logged.
	 *
	 * @param string $path  Link path.
	 * @param string $child Relative link path.
	 * @return bool
	 */
	private function follow_link( $path, $child ) {
		$target = realpath( $path );
		if ( false === $target ) {
			$this->messages[] = sprintf( 'Skipped broken symlink: %s', $child );
			return false;
		}
		$root = realpath( $this->root );
		if ( false !== $root && ( $target === $root || 0 === strpos( $target, $root . '/' ) ) ) {
			$rel = ltrim( substr( $target, strlen( $root ) ), '/' );
			if ( '' !== $rel && $this->is_excluded( $rel ) ) {
				$this->messages[] = sprintf( 'Skipped symlink to an excluded path: %s', $child );
				return false;
			}
			return true;
		}
		if ( $this->link_notes < 20 ) {
			$this->messages[] = sprintf( 'Following symlink that points outside wp-content: %s -> %s', $child, $target );
		}
		++$this->link_notes;
		return true;
	}

	/**
	 * Whether a symlinked directory points at itself or one of its ancestors.
	 *
	 * @param string $link   Symlink path.
	 * @param string $parent Parent directory.
	 * @return bool
	 */
	private function is_loop( $link, $parent ) {
		$target = realpath( $link );
		$base   = realpath( $parent );
		if ( false === $target || false === $base ) {
			return true;
		}
		return $target === $base || 0 === strpos( $base . '/', rtrim( $target, '/' ) . '/' );
	}
}
