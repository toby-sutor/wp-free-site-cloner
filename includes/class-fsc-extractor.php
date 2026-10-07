<?php
/**
 * Safe, resumable extraction of the wp-content entries of any FSC_Source,
 * and the journaled switch that moves the extracted (staged) entries into
 * the live wp-content. No WordPress dependency.
 *
 * Rejected: absolute paths, drive letters, "..", NUL bytes, symlinks, hard
 * links and other special types, and anything whose parent directory
 * resolves outside the target directory. Existing symlinks are never written
 * through.
 *
 * @package wp-free-site-cloner
 */

/**
 * Extractor. Cursor: array( src => source cursor, cur => file in progress or null,
 * files, dirs, bytes, skipped, seen_files ). seen_files counts every file entry
 * the source returned (extracted or skipped), for progress against a file count.
 */
class FSC_Extractor {

	const CHUNK = 4194304;

	/** Longest file name (bytes) most filesystems accept. */
	const MAX_SEGMENT = 255;

	/** Longest absolute target path accepted (PATH_MAX is 4096 on Linux). */
	const MAX_PATH = 4000;

	/**
	 * Top-level wp-content folders whose children are switched one by one
	 * (plugins/<x>, themes/<x>, ...); uploads/<yyyy> goes one level deeper
	 * (uploads/<yyyy>/<mm>). Every other top-level entry is one unit.
	 */
	const CONTAINERS = array( 'plugins', 'themes', 'mu-plugins', 'languages', 'uploads' );

	/** @var string */
	private $target;

	/** @var FSC_Source */
	private $source;

	/** @var callable|null */
	private $skip;

	/** @var float|null Cached upper bound on the bytes the source may expand to. */
	private $budget = null;

	/** @var array Messages for the job log. */
	public $messages = array();

	/** @var callable|null Tests only: called at every point where a killed request could stop the switch. */
	public static $crash_hook = null;

	/**
	 * Call the test crash hook.
	 */
	private static function hook() {
		if ( null !== self::$crash_hook ) {
			call_user_func( self::$crash_hook );
		}
	}

	/**
	 * Constructor.
	 *
	 * @param string        $target Target directory (wp-content).
	 * @param FSC_Source    $source Source.
	 * @param callable|null $skip   fn( string $rel_path ): bool, true to skip an entry.
	 * @throws FSC_Exception When the target does not exist.
	 */
	public function __construct( $target, $source, $skip = null ) {
		$real = realpath( $target );
		if ( false === $real || ! is_dir( $real ) ) {
			throw new FSC_Exception( 'The extraction target directory does not exist.' );
		}
		$this->target = $real;
		$this->source = $source;
		$this->skip   = $skip;
	}

	/**
	 * Fresh cursor.
	 *
	 * @return array
	 */
	public static function new_cursor() {
		return array(
			'src'     => array(),
			'cur'     => null,
			'files'   => 0,
			'dirs'    => 0,
			'bytes'   => 0,
			'skipped' => 0,
			'seen_files' => 0,
		);
	}

	/**
	 * Normalise an archive path to a safe relative path, or null when unsafe.
	 *
	 * @param string $path Path from the archive.
	 * @return string|null
	 */
	public static function safe_relative( $path ) {
		$path = (string) $path;
		if ( '' === $path || false !== strpos( $path, "\0" ) ) {
			return null;
		}
		$path = str_replace( '\\', '/', $path );
		if ( '/' === $path[0] || preg_match( '#^[A-Za-z]:#', $path ) ) {
			return null;
		}
		$out = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg || strlen( $seg ) > self::MAX_SEGMENT ) {
				return null;
			}
			$out[] = $seg;
		}
		return empty( $out ) ? null : implode( '/', $out );
	}

	/**
	 * Whether $path is the target or inside it.
	 *
	 * @param string $path Real path.
	 * @return bool
	 */
	private function inside( $path ) {
		return $path === $this->target || 0 === strpos( $path, $this->target . DIRECTORY_SEPARATOR );
	}

	/**
	 * Create a directory (and parents) below the target, verifying each level.
	 *
	 * @param string $rel Relative directory ('' for the target itself).
	 * @return bool
	 */
	private function make_dir( $rel ) {
		if ( '' === $rel ) {
			return true;
		}
		$abs = $this->target;
		foreach ( explode( '/', $rel ) as $seg ) {
			$abs .= '/' . $seg;
			if ( ! is_dir( $abs ) ) {
				if ( file_exists( $abs ) || is_link( $abs ) ) {
					return false;
				}
				if ( ! @mkdir( $abs, 0755 ) && ! is_dir( $abs ) ) {
					return false;
				}
			}
			$real = realpath( $abs );
			if ( false === $real || ! $this->inside( $real ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Extract until done or the deadline passes.
	 *
	 * @param array $cursor   Cursor, updated in place.
	 * @param float $deadline microtime deadline.
	 * @return bool True when all entries are processed.
	 * @throws FSC_Exception On write errors or a corrupt archive.
	 */
	public function step( array &$cursor, $deadline ) {
		do {
			if ( ! empty( $cursor['cur'] ) ) {
				$this->continue_file( $cursor );
				continue;
			}
			$e = $this->source->files_next( $cursor['src'] );
			if ( null === $e ) {
				return true;
			}
			if ( 'file' === $e['type'] ) {
				$cursor['seen_files'] = ( isset( $cursor['seen_files'] ) ? (int) $cursor['seen_files'] : 0 ) + 1;
			}
			$rel = self::safe_relative( $e['path'] );
			if ( null === $rel ) {
				$this->reject( $cursor, 'Rejected unsafe or overlong path: ' . self::printable( $e['path'] ) );
				continue;
			}
			if ( strlen( $this->target ) + 1 + strlen( $rel ) > self::MAX_PATH ) {
				$this->reject( $cursor, 'Skipped path that is too long for this server: ' . self::printable( $rel ) );
				continue;
			}
			if ( 'file' !== $e['type'] && 'dir' !== $e['type'] ) {
				$this->reject( $cursor, sprintf( 'Rejected %s entry: %s', $e['type'], $rel ) );
				continue;
			}
			if ( $this->skip && call_user_func( $this->skip, $rel ) ) {
				++$cursor['skipped'];
				continue;
			}
			if ( 'dir' === $e['type'] ) {
				if ( ! $this->make_dir( $rel ) ) {
					$this->reject( $cursor, 'Rejected directory outside the target: ' . $rel );
				} else {
					++$cursor['dirs'];
				}
				continue;
			}
			$parent = false === strrpos( $rel, '/' ) ? '' : substr( $rel, 0, strrpos( $rel, '/' ) );
			$abs    = $this->target . '/' . $rel;
			if ( ! $this->make_dir( $parent ) ) {
				$this->reject( $cursor, 'Rejected file outside the target: ' . $rel );
				continue;
			}
			if ( is_link( $abs ) || is_dir( $abs ) ) {
				$this->reject( $cursor, 'Skipped file that would replace a link or directory: ' . $rel );
				continue;
			}
			$fp = @fopen( $abs, 'wb' );
			if ( ! $fp ) {
				throw new FSC_Exception( sprintf( 'Cannot write %s (permissions?).', $rel ) );
			}
			fclose( $fp );
			$cursor['cur'] = array(
				'path'    => $rel,
				'size'    => (int) $e['size'],
				'mtime'   => (int) $e['mtime'],
				'written' => 0,
			);
			$this->continue_file( $cursor );
		} while ( microtime( true ) < $deadline );
		return false;
	}

	/**
	 * Archive path made safe for a log line (control bytes escaped, shortened).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function printable( $path ) {
		$p = preg_replace_callback(
			'/[\x00-\x1f\x7f]/',
			function ( $m ) {
				return sprintf( '\\x%02x', ord( $m[0] ) );
			},
			(string) $path
		);
		return strlen( $p ) > 300 ? substr( $p, 0, 300 ) . '...' : $p;
	}

	/**
	 * Log a rejected entry.
	 *
	 * @param array  $cursor  Cursor.
	 * @param string $message Message.
	 */
	private function reject( array &$cursor, $message ) {
		++$cursor['skipped'];
		$this->messages[] = $message;
	}

	/**
	 * Copy the next chunk of the file in progress.
	 *
	 * @param array $cursor Cursor.
	 * @throws FSC_Exception On write errors.
	 */
	private function continue_file( array &$cursor ) {
		$cur = $cursor['cur'];
		$abs = $this->target . '/' . $cur['path'];
		$buf = $this->source->files_read( $cursor['src'], self::CHUNK );
		if ( '' !== $buf ) {
			$fp = @fopen( $abs, 'c+b' );
			if ( ! $fp ) {
				throw new FSC_Exception( sprintf( 'Cannot write %s (permissions?).', $cur['path'] ) );
			}
			fseek( $fp, $cur['written'] );
			$ok = fwrite( $fp, $buf ) === strlen( $buf );
			fclose( $fp );
			if ( ! $ok ) {
				throw new FSC_Exception( sprintf( 'Writing %s failed (disk full?).', $cur['path'] ) );
			}
			$cursor['cur']['written'] += strlen( $buf );
			$cursor['bytes']          += strlen( $buf );
			$this->check_budget( $cursor );
			if ( $cursor['cur']['written'] < $cur['size'] ) {
				return;
			}
		}
		clearstatcache( true, $abs );
		if ( is_file( $abs ) && filesize( $abs ) > $cursor['cur']['written'] ) {
			$fp = @fopen( $abs, 'c+b' );
			if ( $fp ) {
				ftruncate( $fp, $cursor['cur']['written'] );
				fclose( $fp );
			}
		}
		if ( $cur['mtime'] > 0 ) {
			@touch( $abs, $cur['mtime'] );
		}
		++$cursor['files'];
		$cursor['cur'] = null;
	}

	/**
	 * Stop a decompression bomb before it fills the disk: the extracted bytes
	 * must stay under the source's budget, and the staging volume must keep the
	 * free-space margin. Called once per written chunk, not per byte.
	 *
	 * @param array $cursor Cursor.
	 * @throws FSC_Exception When the budget or the free-space margin is exceeded.
	 */
	private function check_budget( array $cursor ) {
		if ( null === $this->budget ) {
			$this->budget = (float) $this->source->extract_budget();
		}
		if ( (float) $cursor['bytes'] > $this->budget ) {
			throw new FSC_Exception( sprintf( 'The archive expands to more than %d MB of files, far more than its size. It is refused as damaged or malicious.', (int) floor( $this->budget / 1048576 ) ) );
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $this->target ) : false;
		if ( false !== $free && null !== $free && $free < FSC_Source_Util::GZ_DISK_MARGIN ) {
			throw new FSC_Exception( 'There is not enough free disk space to finish unpacking the archive. Free up space and start the import again.' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Switch: staged entries -> live wp-content.                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Names in a directory (no dot entries), sorted.
	 *
	 * @param string $dir Directory.
	 * @return array
	 */
	private static function children( $dir ) {
		$out = array();
		if ( is_dir( $dir ) && ! is_link( $dir ) ) {
			foreach ( (array) @scandir( $dir ) as $f ) {
				if ( is_string( $f ) && '.' !== $f && '..' !== $f ) {
					$out[] = $f;
				}
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Whether a path exists (a dangling symlink counts).
	 *
	 * @param string $p Path.
	 * @return bool
	 */
	private static function present( $p ) {
		return file_exists( $p ) || is_link( $p );
	}

	/**
	 * Units (paths relative to wp-content) that replace their live
	 * counterpart as a whole: plugins/<x>, themes/<x>, mu-plugins/<x>,
	 * languages/<x>, uploads/<yyyy>/<mm> (or uploads/<x> when <x> is not a
	 * year folder), and every other top-level entry.
	 *
	 * @param string $stage Staging directory.
	 * @return array Sorted relative paths.
	 */
	public static function plan_units( $stage ) {
		$units = array();
		foreach ( self::children( $stage ) as $top ) {
			$p = $stage . '/' . $top;
			if ( ! in_array( $top, self::CONTAINERS, true ) || ! is_dir( $p ) || is_link( $p ) ) {
				$units[] = $top;
				continue;
			}
			foreach ( self::children( $p ) as $c ) {
				if ( 'uploads' === $top && preg_match( '/^\d{4}$/', $c ) && is_dir( $p . '/' . $c ) && ! is_link( $p . '/' . $c ) ) {
					foreach ( self::children( $p . '/' . $c ) as $m ) {
						$units[] = $top . '/' . $c . '/' . $m;
					}
				} else {
					$units[] = $top . '/' . $c;
				}
			}
		}
		sort( $units, SORT_STRING );
		return $units;
	}

	/**
	 * Why a unit must not be switched, or '' when it may.
	 *
	 * @param string $live      Live wp-content directory.
	 * @param string $rel       Unit path.
	 * @param array  $protected Real paths that must never be moved (this plugin, the storage directory).
	 * @return string
	 */
	public static function check_unit( $live, $rel, array $protected = array() ) {
		$root = realpath( $live );
		if ( false === $root ) {
			return 'wp-content is missing';
		}
		$abs = $root;
		foreach ( explode( '/', $rel ) as $seg ) {
			$abs .= '/' . $seg;
			if ( is_link( $abs ) ) {
				return 'the existing path on this site is a symbolic link';
			}
			if ( ! file_exists( $abs ) ) {
				break;
			}
		}
		$parent = $root;
		$parts  = explode( '/', $rel );
		array_pop( $parts );
		foreach ( $parts as $seg ) {
			if ( ! is_dir( $parent . '/' . $seg ) ) {
				if ( file_exists( $parent . '/' . $seg ) ) {
					return 'a file on this site is in the way';
				}
				break;
			}
			$parent .= '/' . $seg;
		}
		$real = realpath( $parent );
		if ( false === $real || ( $real !== $root && 0 !== strpos( $real, $root . '/' ) ) ) {
			return 'its folder on this site is outside wp-content';
		}
		$unit = $root . '/' . $rel;
		foreach ( $protected as $p ) {
			if ( is_string( $p ) && '' !== $p && ( $p === $unit || 0 === strpos( $p, $unit . '/' ) ) ) {
				return 'it holds this plugin or its storage';
			}
		}
		return '';
	}

	/**
	 * Whether a unit can be moved: write access to the parents and, for a
	 * directory changing parent, to the directory itself.
	 *
	 * @param string $from Source path.
	 * @param string $to   Target path (may not exist yet).
	 * @return bool
	 */
	public static function movable( $from, $to ) {
		if ( ! self::present( $from ) ) {
			return true;
		}
		if ( ! is_writable( dirname( $from ) ) || ( is_dir( $from ) && ! is_link( $from ) && ! is_writable( $from ) ) ) {
			return false;
		}
		$d = dirname( $to );
		while ( ! is_dir( $d ) && dirname( $d ) !== $d ) {
			$d = dirname( $d );
		}
		return is_writable( $d );
	}

	/**
	 * Create a directory and its parents.
	 *
	 * @param string $dir Directory.
	 * @throws FSC_Exception When it cannot be created.
	 */
	private static function mkdirs( $dir ) {
		if ( is_dir( $dir ) ) {
			return;
		}
		if ( ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			throw new FSC_Exception( sprintf( 'Cannot create the folder %s (permissions?).', $dir ) );
		}
	}

	/**
	 * Move $src to $dst. One rename() when $dst does not exist and both are on
	 * the same filesystem; otherwise entry by entry (copy, then delete the
	 * source), which merges into an existing $dst and resumes where it
	 * stopped. Symlinks are moved as links, never followed.
	 *
	 * @param string $src      Source.
	 * @param string $dst      Target.
	 * @param float  $deadline microtime deadline (checked between files when copying).
	 * @param bool   $copied   Set to true when the copy fallback was used.
	 * @param bool   $force    Always copy (tests).
	 * @return bool True when $src is gone.
	 * @throws FSC_Exception On errors.
	 */
	public static function move_tree( $src, $dst, $deadline, &$copied, $force = false ) {
		if ( ! self::present( $src ) ) {
			return true;
		}
		self::mkdirs( dirname( $dst ) );
		if ( ! $force && ! self::present( $dst ) && @rename( $src, $dst ) ) {
			self::hook();
			return true;
		}
		$copied = true;
		return self::copy_tree( $src, $dst, $deadline );
	}

	/**
	 * Copy-then-delete move of one tree (see move_tree()).
	 *
	 * @param string $src      Source.
	 * @param string $dst      Target.
	 * @param float  $deadline Deadline.
	 * @return bool
	 * @throws FSC_Exception On errors.
	 */
	private static function copy_tree( $src, $dst, $deadline ) {
		if ( is_link( $src ) || ! is_dir( $src ) ) {
			if ( is_dir( $dst ) && ! is_link( $dst ) ) {
				throw new FSC_Exception( sprintf( 'Cannot move %s: a folder is in the way.', $dst ) );
			}
			$tmp = $dst . '.fsc-part';
			@unlink( $tmp );
			$ok = is_link( $src ) ? @symlink( (string) readlink( $src ), $tmp ) : @copy( $src, $tmp );
			if ( ! $ok || ! @rename( $tmp, $dst ) ) {
				@unlink( $tmp );
				throw new FSC_Exception( sprintf( 'Cannot copy %s (disk full or permissions?).', $dst ) );
			}
			if ( ! is_link( $src ) ) {
				@touch( $dst, (int) @filemtime( $src ) );
			}
			self::hook();
			if ( ! @unlink( $src ) ) {
				throw new FSC_Exception( sprintf( 'Cannot remove %s after copying it.', $src ) );
			}
			self::hook();
			return true;
		}
		if ( self::present( $dst ) && ( is_link( $dst ) || ! is_dir( $dst ) ) ) {
			throw new FSC_Exception( sprintf( 'Cannot move %s: a file is in the way.', $dst ) );
		}
		self::mkdirs( $dst );
		foreach ( self::children( $src ) as $f ) {
			if ( ! self::copy_tree( $src . '/' . $f, $dst . '/' . $f, $deadline ) ) {
				return false;
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}
		if ( ! @rmdir( $src ) ) {
			throw new FSC_Exception( sprintf( 'Cannot remove the folder %s after copying it.', $src ) );
		}
		return true;
	}

	/**
	 * Delete a tree without following symlinks, until the deadline.
	 *
	 * @param string $path     File or directory.
	 * @param float  $deadline microtime deadline.
	 * @return bool True when it is gone.
	 */
	public static function purge_tree( $path, $deadline ) {
		if ( ! self::present( $path ) ) {
			return true;
		}
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			return @unlink( $path ) || ! self::present( $path );
		}
		foreach ( self::children( $path ) as $f ) {
			if ( ! self::purge_tree( $path . '/' . $f, $deadline ) || microtime( true ) >= $deadline ) {
				return false;
			}
		}
		return @rmdir( $path ) || ! self::present( $path );
	}

	/**
	 * Whether a tree holds any file or link.
	 *
	 * @param string $path Directory.
	 * @return bool
	 */
	public static function has_files( $path ) {
		if ( ! self::present( $path ) ) {
			return false;
		}
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			return true;
		}
		foreach ( self::children( $path ) as $f ) {
			if ( self::has_files( $path . '/' . $f ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Last complete journal record.
	 *
	 * @param string $journal Journal file.
	 * @return array array( index, state ) or array( -1, '' ) when empty.
	 */
	public static function journal_last( $journal ) {
		$raw = is_file( $journal ) ? (string) @file_get_contents( $journal ) : '';
		if ( preg_match_all( '/^(\d+) ([abcxyz])$/m', $raw, $m ) && ! empty( $m[0] ) ) {
			$k = count( $m[0] ) - 1;
			return array( (int) $m[1][ $k ], $m[2][ $k ] );
		}
		return array( -1, '' );
	}

	/**
	 * Append a journal record before the step it names starts.
	 *
	 * @param string $journal Journal file.
	 * @param int    $i       Unit index.
	 * @param string $state   State letter.
	 * @throws FSC_Exception When it cannot be written.
	 */
	private static function journal( $journal, $i, $state ) {
		$rec = $i . ' ' . $state . "\n";
		$fp  = @fopen( $journal, 'ab' );
		if ( ! $fp || fwrite( $fp, $rec ) !== strlen( $rec ) ) {
			if ( $fp ) {
				fclose( $fp );
			}
			throw new FSC_Exception( 'Cannot write the switch journal (disk full?).' );
		}
		fflush( $fp );
		fclose( $fp );
		self::hook();
	}

	/**
	 * Move the staged units into the live directory (forward) or undo it
	 * (rollback), journaled so a killed request resumes exactly where it
	 * stopped. Per unit, forward: a = move live -> old, b = move staged -> live,
	 * c = done; rollback: x = move live -> staged, y = move old -> live,
	 * z = done (units are undone in reverse order). A record is written
	 * before its step starts; every step is a resumable move_tree(), so
	 * repeating an interrupted step is safe.
	 *
	 * @param array $sw       array( stage, live, old, journal, units ).
	 * @param float $deadline microtime deadline.
	 * @param bool  $rollback Undo instead of switching.
	 * @param bool  $copied   Set when the copy fallback (other filesystem) was used.
	 * @param bool  $force    Always copy (tests).
	 * @return bool True when finished.
	 * @throws FSC_Exception On errors (the journal keeps the position).
	 */
	public static function switch_step( array $sw, $deadline, $rollback, &$copied, $force = false ) {
		$units = array_values( $sw['units'] );
		$n     = count( $units );
		$j     = $sw['journal'];
		list( $i, $s ) = self::journal_last( $j );
		// A torn append (power loss) can leave a record whose index is past the
		// plan. Refuse it rather than read it as "every unit is switched", which
		// would commit the database over unswitched files.
		if ( $i >= $n ) {
			throw new FSC_Exception( 'The switch journal is damaged (it names more units than the plan has).' );
		}
		$path  = function ( $root, $k ) use ( $units ) {
			return $root . '/' . $units[ $k ];
		};
		if ( $rollback ) {
			if ( -1 === $i ) {
				return true;
			}
			if ( 'a' === $s ) {
				$s = 'y';
				self::journal( $j, $i, $s );
			} elseif ( 'b' === $s || 'c' === $s ) {
				$s = 'x';
				self::journal( $j, $i, $s );
			}
			while ( true ) {
				if ( $i < 0 || $i >= $n ) {
					return true;
				}
				if ( 'x' === $s ) {
					if ( ! self::move_tree( $path( $sw['live'], $i ), $path( $sw['stage'], $i ), $deadline, $copied, $force ) ) {
						return false;
					}
					$s = 'y';
					self::journal( $j, $i, $s );
				}
				if ( 'y' === $s ) {
					if ( ! self::move_tree( $path( $sw['old'], $i ), $path( $sw['live'], $i ), $deadline, $copied, $force ) ) {
						return false;
					}
					$s = 'z';
					self::journal( $j, $i, $s );
				}
				--$i;
				if ( $i < 0 ) {
					return true;
				}
				$s = 'x';
				self::journal( $j, $i, $s );
				if ( microtime( true ) >= $deadline ) {
					return false;
				}
			}
		}
		if ( in_array( $s, array( 'x', 'y', 'z' ), true ) ) {
			throw new FSC_Exception( 'The switch was already being undone.' );
		}
		if ( -1 === $i ) {
			if ( 0 === $n ) {
				return true;
			}
			$i = 0;
			$s = 'a';
			self::journal( $j, $i, $s );
		}
		while ( $i < $n ) {
			if ( 'a' === $s ) {
				if ( ! self::move_tree( $path( $sw['live'], $i ), $path( $sw['old'], $i ), $deadline, $copied, $force ) ) {
					return false;
				}
				$s = 'b';
				self::journal( $j, $i, $s );
			}
			if ( 'b' === $s ) {
				if ( ! self::move_tree( $path( $sw['stage'], $i ), $path( $sw['live'], $i ), $deadline, $copied, $force ) ) {
					return false;
				}
				$s = 'c';
				self::journal( $j, $i, $s );
			}
			++$i;
			if ( $i >= $n ) {
				return true;
			}
			$s = 'a';
			self::journal( $j, $i, $s );
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Units switched so far according to the journal (for progress).
	 *
	 * @param string $journal Journal file.
	 * @return int
	 */
	public static function switched_count( $journal ) {
		list( $i, $s ) = self::journal_last( $journal );
		if ( -1 === $i ) {
			return 0;
		}
		return 'c' === $s ? $i + 1 : $i;
	}
}
