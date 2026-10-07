<?php
/**
 * Protected storage directory for archives, job files and temporary files.
 * No WordPress dependency (the directory is passed in).
 *
 * @package wp-free-site-cloner
 */

/**
 * Storage directory helper. Only plain file names that resolve inside the
 * directory are ever accepted.
 *
 * Layout: <base>/ holds only the web server deny files. Everything else lives
 * in <base>/private-<32 hex>/ (archives, .part files) and its tmp/ subdir (job
 * file, log, temporary SQL and lists). The random name is the protection on
 * servers that ignore .htaccess (nginx); it is found by scanning <base>, never
 * stored in the database, because an import replaces the options table while
 * the job is running.
 *
 * <base> is wp-content/fsc-storage, or the FSC_STORAGE_DIR constant when it is
 * defined (a location outside the web root). Directories are kept at 0700 and
 * files at 0600 (FS_CHMOD_DIR/FS_CHMOD_FILE when defined), see harden().
 */
class FSC_Storage {

	/** Accepted archive file name. */
	const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}\.(tar|wpress|zip|daf)$/';

	/** Private directory name. */
	const PRIVATE_PATTERN = '/^private-[a-f0-9]{32}$/';

	/** Dot-separated name parts a web server may execute or interpret. */
	const RISKY_PART = '/^(php[0-9]?|phtml|pht|phar|phps|inc|cgi|pl|py|rb|sh|asp|aspx|jsp|shtml|htaccess|htpasswd|ini|user)$/i';

	/** @var string Base directory (wp-content/fsc-storage). */
	private $base;

	/** Default directory mode. */
	const DIR_MODE = 0700;

	/** Default file mode. */
	const FILE_MODE = 0600;

	/** Job temporary files in tmp/: <16 hex job id>.<suffix>, and job file write leftovers. */
	const TMP_PATTERN = '/^([a-f0-9]{16})\.[a-z0-9.]+$|^job\.json\.[a-f0-9]+\.tmp$/';

	/** @var string|null Private directory, once found or created. */
	private $private = null;

	/** @var int Directory mode. */
	private $dir_mode = self::DIR_MODE;

	/** @var int File mode. */
	private $file_mode = self::FILE_MODE;

	/** @var bool Modes come from FS_CHMOD_DIR/FS_CHMOD_FILE. */
	private $wp_modes = false;

	/** @var bool A chmod was undone because PHP could no longer use the path. */
	private $mode_reverted = false;

	/** @var bool Base comes from FSC_STORAGE_DIR. */
	private $custom = false;

	/** @var string|null Configuration error; storage refuses to work while set. */
	private $error = null;

	/**
	 * Constructor.
	 *
	 * @param string $dir Base storage directory.
	 */
	public function __construct( $dir ) {
		$this->base = rtrim( (string) $dir, '/\\' );
	}

	/**
	 * Configured location: FSC_STORAGE_DIR when defined, otherwise
	 * wp-content/fsc-storage. FS_CHMOD_DIR/FS_CHMOD_FILE override the modes.
	 *
	 * @return FSC_Storage
	 */
	public static function default_storage() {
		if ( defined( 'FSC_STORAGE_DIR' ) ) {
			$dir         = is_string( FSC_STORAGE_DIR ) ? FSC_STORAGE_DIR : '';
			$st          = new self( $dir );
			$st->custom  = true;
			$st->error   = self::check_base( $dir );
		} else {
			$st = new self( WP_CONTENT_DIR . '/fsc-storage' );
		}
		if ( defined( 'FS_CHMOD_DIR' ) || defined( 'FS_CHMOD_FILE' ) ) {
			$st->set_modes(
				defined( 'FS_CHMOD_DIR' ) ? (int) FS_CHMOD_DIR : self::DIR_MODE,
				defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : self::FILE_MODE
			);
			$st->wp_modes = true;
		}
		return $st;
	}

	/**
	 * Why a configured storage base is unusable, or null when it is fine.
	 * The directory itself may be missing (ensure() creates it), its parent not.
	 *
	 * @param string $dir Path.
	 * @return string|null
	 */
	public static function check_base( $dir ) {
		if ( ! is_string( $dir ) || '' === trim( $dir ) ) {
			return 'FSC_STORAGE_DIR is empty.';
		}
		if ( ! preg_match( '#^(/|[A-Za-z]:[\\\\/]|\\\\\\\\)#', $dir ) ) {
			return sprintf( 'FSC_STORAGE_DIR must be an absolute path (%s).', $dir );
		}
		if ( preg_match( '#(^|[\\\\/])\.\.?([\\\\/]|$)#', $dir ) ) {
			return sprintf( 'FSC_STORAGE_DIR must not contain "." or ".." segments (%s).', $dir );
		}
		$dir = rtrim( $dir, '/\\' );
		if ( '' === $dir ) {
			return 'FSC_STORAGE_DIR must not be the file system root.';
		}
		if ( is_link( $dir ) ) {
			return sprintf( 'FSC_STORAGE_DIR %s is a symbolic link, which is not allowed.', $dir );
		}
		if ( file_exists( $dir ) && ! is_dir( $dir ) ) {
			return sprintf( 'FSC_STORAGE_DIR %s is not a directory.', $dir );
		}
		if ( ! is_dir( $dir ) && ! is_dir( dirname( $dir ) ) ) {
			return sprintf( 'FSC_STORAGE_DIR %s does not exist and its parent directory is missing.', $dir );
		}
		return null;
	}

	/**
	 * Set directory and file modes (best effort, applied by harden()).
	 *
	 * @param int $dir_mode  Directory mode.
	 * @param int $file_mode File mode.
	 */
	public function set_modes( $dir_mode, $file_mode ) {
		$this->dir_mode  = (int) $dir_mode & 0777;
		$this->file_mode = (int) $file_mode & 0777;
	}

	/**
	 * Facts about the storage location for the admin page.
	 *
	 * @return array
	 */
	public function info() {
		return array(
			'base'          => $this->base,
			'custom'        => $this->custom,
			'error'         => $this->error,
			'dir_mode'      => sprintf( '%04o', $this->dir_mode ),
			'file_mode'     => sprintf( '%04o', $this->file_mode ),
			'wp_modes'      => $this->wp_modes,
			'open_modes'    => 0 !== ( $this->dir_mode & 0077 ) || 0 !== ( $this->file_mode & 0077 ),
			'mode_reverted' => $this->mode_reverted,
		);
	}

	/**
	 * Configuration error, or null.
	 *
	 * @return string|null
	 */
	public function error() {
		return $this->error;
	}

	/**
	 * Base directory (only deny files and the private directory live here).
	 *
	 * @return string
	 */
	public function base_dir() {
		return $this->base;
	}

	/**
	 * Find the private directory without creating anything. When several
	 * exist (a creation race), the lexicographically first one wins.
	 *
	 * @return string|null
	 */
	private function find_private() {
		if ( null !== $this->private ) {
			return $this->private;
		}
		if ( null !== $this->error || ! is_dir( $this->base ) ) {
			return null;
		}
		$found = array();
		foreach ( (array) @scandir( $this->base ) as $name ) {
			if ( is_string( $name ) && preg_match( self::PRIVATE_PATTERN, $name ) && is_dir( $this->base . '/' . $name ) && ! is_link( $this->base . '/' . $name ) ) {
				$found[] = $name;
			}
		}
		if ( empty( $found ) ) {
			return null;
		}
		sort( $found, SORT_STRING );
		$this->private = $this->base . '/' . $found[0];
		return $this->private;
	}

	/**
	 * Directory for archives. Before ensure() has created it, a path that does
	 * not exist is returned, so reads find nothing and writes fail.
	 *
	 * @return string
	 */
	public function dir() {
		$p = $this->find_private();
		return null === $p ? $this->base . '/private-missing' : $p;
	}

	/**
	 * Directory for job and temporary files.
	 *
	 * @return string
	 */
	public function tmp_dir() {
		return $this->dir() . '/tmp';
	}

	/**
	 * Path of the single job file.
	 *
	 * @return string
	 */
	public function job_path() {
		return $this->tmp_dir() . '/job.json';
	}

	/**
	 * Path of a temporary file for a job.
	 *
	 * @param string $job_id Job id (hex).
	 * @param string $suffix Suffix such as "sql" or "list".
	 * @return string
	 */
	public function tmp_file( $job_id, $suffix ) {
		return $this->tmp_dir() . '/' . preg_replace( '/[^a-f0-9]/', '', $job_id ) . '.' . preg_replace( '/[^a-z0-9.]/', '', $suffix );
	}

	/**
	 * Write the web server deny files into a directory.
	 *
	 * @param string $d Directory.
	 * @throws FSC_Exception When they cannot be written.
	 */
	private function protect( $d ) {
		$files = array(
			'.htaccess'  => "# Deny all web access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t\t<security>\n\t\t\t<authorization>\n\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n\t\t\t</authorization>\n\t\t</security>\n\t\t<directoryBrowse enabled=\"false\" />\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $name => $content ) {
			if ( is_file( $d . '/' . $name ) ) {
				continue;
			}
			if ( false === @file_put_contents( $d . '/' . $name, $content ) ) {
				throw new FSC_Exception( sprintf( 'The storage directory %s is not writable.', $d ) );
			}
			$this->chmod_file( $d . '/' . $name );
		}
	}

	/**
	 * Create a directory (not through a symlink).
	 *
	 * @param string $d Directory.
	 * @throws FSC_Exception When it cannot be created.
	 */
	private function make( $d ) {
		if ( is_link( $d ) ) {
			throw new FSC_Exception( sprintf( 'The storage directory %s is a symbolic link, which is not allowed.', $d ) );
		}
		if ( is_dir( $d ) ) {
			return;
		}
		// A configured base is created only when its parent exists; the default may create wp-content/fsc-storage.
		if ( ! @mkdir( $d, $this->dir_mode, ! $this->custom ) && ! is_dir( $d ) ) {
			throw new FSC_Exception( sprintf( 'Cannot create the storage directory %s.', $d ) );
		}
		$this->chmod_dir( $d );
	}

	/**
	 * Chmod a directory (best effort). Undone when PHP could no longer read or
	 * write it (ACLs, odd ownership), so a stricter mode never breaks storage.
	 *
	 * @param string $d Directory.
	 */
	private function chmod_dir( $d ) {
		if ( ! function_exists( 'chmod' ) || is_link( $d ) || ! is_dir( $d ) ) {
			return;
		}
		$old = @fileperms( $d );
		if ( false === $old || ( $old & 0777 ) === $this->dir_mode ) {
			return;
		}
		if ( ! @chmod( $d, $this->dir_mode ) ) {
			return;
		}
		clearstatcache( true, $d );
		if ( ! is_readable( $d ) || ! is_writable( $d ) ) {
			@chmod( $d, $old & 0777 );
			clearstatcache( true, $d );
			$this->mode_reverted = true;
		}
	}

	/**
	 * Chmod a regular file (best effort), undone when PHP could no longer read it.
	 *
	 * @param string $f File.
	 */
	private function chmod_file( $f ) {
		if ( ! function_exists( 'chmod' ) || is_link( $f ) || ! is_file( $f ) ) {
			return;
		}
		$old = @fileperms( $f );
		if ( false === $old || ( $old & 0777 ) === $this->file_mode ) {
			return;
		}
		if ( ! @chmod( $f, $this->file_mode ) ) {
			return;
		}
		clearstatcache( true, $f );
		if ( ! is_readable( $f ) ) {
			@chmod( $f, $old & 0777 );
			clearstatcache( true, $f );
			$this->mode_reverted = true;
		}
	}

	/**
	 * Apply the directory and file modes to the storage tree: base, private
	 * dir, tmp dir and the regular files directly inside them (not recursive,
	 * symlinks untouched). Cheap: these directories hold a handful of files.
	 */
	public function harden() {
		if ( null !== $this->error || ! is_dir( $this->base ) || is_link( $this->base ) ) {
			return;
		}
		$dirs = array( $this->base );
		if ( null !== $this->find_private() ) {
			$dirs[] = $this->private;
			if ( is_dir( $this->private . '/tmp' ) && ! is_link( $this->private . '/tmp' ) ) {
				$dirs[] = $this->private . '/tmp';
			}
		}
		foreach ( $dirs as $d ) {
			$this->chmod_dir( $d );
			foreach ( (array) @scandir( $d ) as $f ) {
				if ( is_string( $f ) && '.' !== $f && '..' !== $f ) {
					$this->chmod_file( $d . '/' . $f );
				}
			}
		}
	}

	/**
	 * Create the directories and the web server deny files.
	 *
	 * @throws FSC_Exception When the directory cannot be created or written.
	 */
	public function ensure() {
		if ( null !== $this->error ) {
			throw new FSC_Exception( $this->error );
		}
		$this->make( $this->base );
		$this->protect( $this->base );
		if ( null === $this->find_private() ) {
			$mine = $this->base . '/private-' . bin2hex( random_bytes( 16 ) );
			$this->make( $mine );
			// Another request may have created one at the same time: keep the first.
			$this->private = null;
			$winner        = $this->find_private();
			if ( $winner !== $mine ) {
				@rmdir( $mine );
			}
		}
		$dir = $this->dir();
		$this->protect( $dir );
		$legacy = $this->base . '/tmp';
		if ( ! is_dir( $dir . '/tmp' ) && is_dir( $legacy ) && ! is_link( $legacy ) ) {
			@rename( $legacy, $dir . '/tmp' );
		}
		$this->make( $dir . '/tmp' );
		$this->protect( $dir . '/tmp' );
		if ( is_dir( $legacy ) && ! is_link( $legacy ) ) {
			self::remove_legacy_tmp( $legacy );
		}
		$this->harden();
	}

	/**
	 * Delete a publicly reachable tmp dir left by an older version.
	 *
	 * @param string $d Directory.
	 */
	private static function remove_legacy_tmp( $d ) {
		foreach ( (array) @scandir( $d ) as $f ) {
			if ( is_string( $f ) && '.' !== $f && '..' !== $f && is_file( $d . '/' . $f ) && ! is_link( $d . '/' . $f ) ) {
				@unlink( $d . '/' . $f );
			}
		}
		@rmdir( $d );
	}

	/**
	 * Move archives that were put directly into the base directory (FTP
	 * uploads, archives of older versions) into the private directory.
	 *
	 * @return int Files moved.
	 */
	public function adopt() {
		if ( ! is_dir( $this->base ) ) {
			return 0;
		}
		$moved = 0;
		foreach ( (array) @scandir( $this->base ) as $name ) {
			if ( ! is_string( $name ) || ! self::is_valid_name( $name ) ) {
				continue;
			}
			$from = $this->base . '/' . $name;
			if ( ! is_file( $from ) || is_link( $from ) ) {
				continue;
			}
			if ( 0 === $moved ) {
				$this->ensure();
			}
			if ( @rename( $from, $this->dir() . '/' . $this->unique_name( $name ) ) ) {
				++$moved;
			}
		}
		return $moved;
	}

	/**
	 * Whether $name is an acceptable archive file name (no directories).
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function is_valid_name( $name ) {
		if ( ! is_string( $name ) || basename( $name ) !== $name || ! preg_match( self::NAME_PATTERN, $name ) || false !== strpos( $name, '..' ) ) {
			return false;
		}
		// "x.php.tar" can run as PHP on Apache setups that map handlers by any extension.
		foreach ( array_slice( explode( '.', $name ), 1, -1 ) as $part ) {
			if ( preg_match( self::RISKY_PART, $part ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * New archive name: <host>-<YmdHis>-<16 hex>.tar
	 *
	 * @param string   $host Site host.
	 * @param int|null $time Timestamp.
	 * @return string
	 */
	public static function new_archive_name( $host, $time = null ) {
		$host = strtolower( preg_replace( '/[^A-Za-z0-9.-]+/', '-', (string) $host ) );
		$host = trim( preg_replace( '/[.-]{2,}/', '-', $host ), '.-' );
		if ( '' === $host ) {
			$host = 'site';
		}
		$host = substr( $host, 0, 60 );
		return $host . '-' . gmdate( 'Ymd-His', null === $time ? time() : $time ) . '-' . bin2hex( random_bytes( 8 ) ) . '.tar';
	}

	/**
	 * Absolute path of an existing archive in storage, or null.
	 *
	 * @param string $name File name.
	 * @return string|null
	 */
	public function resolve( $name ) {
		if ( ! self::is_valid_name( $name ) ) {
			return null;
		}
		$path = $this->dir() . '/' . $name;
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return null;
		}
		$real_dir  = realpath( $this->dir() );
		$real_file = realpath( $path );
		if ( false === $real_dir || false === $real_file || dirname( $real_file ) !== $real_dir ) {
			return null;
		}
		return $real_file;
	}

	/**
	 * Archives in storage, newest first.
	 *
	 * @return array List of array(name, size, mtime, format).
	 */
	public function list_archives() {
		$out = array();
		if ( ! is_dir( $this->dir() ) ) {
			return $out;
		}
		foreach ( (array) scandir( $this->dir() ) as $name ) {
			if ( ! self::is_valid_name( $name ) || null === $this->resolve( $name ) ) {
				continue;
			}
			$path  = $this->dir() . '/' . $name;
			$out[] = array(
				'name'   => $name,
				'size'   => (int) filesize( $path ),
				'mtime'  => (int) filemtime( $path ),
				'format' => strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['mtime'] - $a['mtime'];
			}
		);
		return $out;
	}

	/**
	 * Delete an archive.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public function delete( $name ) {
		$path = $this->resolve( $name );
		return null !== $path && @unlink( $path );
	}

	/**
	 * Append one uploaded chunk to <name>.part and rename it when complete.
	 *
	 * @param string $name       Target file name.
	 * @param int    $offset     Byte offset of this chunk; must equal the current .part size.
	 * @param string $chunk_file Path of the uploaded chunk (temporary upload file).
	 * @param int    $total      Final file size.
	 * @return array array(received => bytes so far, complete => bool, name => final name).
	 * @throws FSC_Exception On invalid input or an offset mismatch (code 409, message includes the expected offset).
	 */
	public function upload_chunk( $name, $offset, $chunk_file, $total ) {
		if ( ! self::is_valid_name( $name ) ) {
			throw new FSC_Exception( 'Invalid file name. Allowed: letters, digits, dot, dash, underscore; extension .tar, .wpress, .zip or .daf.' );
		}
		$offset = (int) $offset;
		$total  = (int) $total;
		if ( $offset < 0 || $total <= 0 || $offset >= $total ) {
			throw new FSC_Exception( 'Invalid upload offset or size.' );
		}
		$this->ensure();
		$part = $this->dir() . '/' . $name . '.part';
		if ( is_link( $part ) ) {
			throw new FSC_Exception( 'Invalid upload target.' );
		}
		clearstatcache( true, $part );
		$current = is_file( $part ) ? (int) filesize( $part ) : 0;
		if ( 0 === $offset ) {
			$current = 0;
			$mode    = 'wb';
		} else {
			$mode = 'ab';
		}
		if ( $current !== $offset ) {
			throw new FSC_Exception( sprintf( 'Upload offset mismatch: expected %d.', $current ), 409 );
		}
		$free = $this->free_space();
		if ( 0 === $offset && null !== $free && $total > $free ) {
			throw new FSC_Exception( sprintf( 'Not enough disk space for this upload: %d MB needed, %d MB free.', (int) ceil( $total / 1048576 ), (int) floor( $free / 1048576 ) ) );
		}
		$in = @fopen( $chunk_file, 'rb' );
		if ( ! $in ) {
			throw new FSC_Exception( 'The uploaded chunk is missing.' );
		}
		$out = @fopen( $part, $mode );
		if ( ! $out ) {
			fclose( $in );
			throw new FSC_Exception( 'Cannot write to the storage directory.' );
		}
		$copied = stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		if ( 0 === $offset ) {
			$this->chmod_file( $part );
		}
		clearstatcache( true, $part );
		$received = (int) filesize( $part );
		if ( false === $copied || $received !== $offset + $copied ) {
			throw new FSC_Exception( 'Writing the uploaded chunk failed (disk full?).' );
		}
		if ( $received > $total ) {
			@unlink( $part );
			throw new FSC_Exception( 'Upload is larger than announced; aborted.' );
		}
		$final = $name;
		if ( $received === $total ) {
			$final = $this->unique_name( $name );
			if ( ! @rename( $part, $this->dir() . '/' . $final ) ) {
				throw new FSC_Exception( 'Cannot finalize the uploaded file.' );
			}
		}
		return array(
			'received' => $received,
			'complete' => $received === $total,
			'name'     => $final,
		);
	}

	/**
	 * $name, or name-1.ext, name-2.ext ... when taken.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function unique_name( $name ) {
		if ( ! file_exists( $this->dir() . '/' . $name ) ) {
			return $name;
		}
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$base = substr( $name, 0, -strlen( $ext ) - 1 );
		for ( $i = 1; $i < 1000; $i++ ) {
			$try = $base . '-' . $i . '.' . $ext;
			if ( ! file_exists( $this->dir() . '/' . $try ) ) {
				return $try;
			}
		}
		return $base . '-' . bin2hex( random_bytes( 4 ) ) . '.' . $ext;
	}

	/**
	 * Remove temporary files of a job (never archives).
	 *
	 * @param string $job_id Job id.
	 */
	public function clean_tmp( $job_id ) {
		$prefix = preg_replace( '/[^a-f0-9]/', '', $job_id );
		if ( '' === $prefix || ! is_dir( $this->tmp_dir() ) ) {
			return;
		}
		foreach ( (array) scandir( $this->tmp_dir() ) as $f ) {
			if ( 0 === strpos( $f, $prefix . '.' ) ) {
				@unlink( $this->tmp_dir() . '/' . $f );
			}
		}
	}

	/**
	 * Archives whose modification time is at least $days days old.
	 *
	 * @param int      $days Age in days (> 0).
	 * @param int|null $now  Timestamp.
	 * @return array Entries of list_archives().
	 */
	public function old_archives( $days, $now = null ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			return array();
		}
		$limit = ( null === $now ? time() : (int) $now ) - $days * 86400;
		$out   = array();
		foreach ( $this->list_archives() as $a ) {
			if ( $a['mtime'] <= $limit ) {
				$out[] = $a;
			}
		}
		return $out;
	}

	/**
	 * Delete archives older than $days days, except the names in $keep.
	 *
	 * @param int      $days Age in days; 0 or less deletes nothing.
	 * @param array    $keep Archive names to keep (in use by a job).
	 * @param int|null $now  Timestamp.
	 * @return array Deleted names.
	 */
	public function purge_archives( $days, array $keep = array(), $now = null ) {
		$deleted = array();
		foreach ( $this->old_archives( $days, $now ) as $a ) {
			if ( ! in_array( $a['name'], $keep, true ) && $this->delete( $a['name'] ) ) {
				$deleted[] = $a['name'];
			}
		}
		return $deleted;
	}

	/**
	 * Delete every archive except the names in $keep.
	 *
	 * @param array $keep Archive names to keep (in use by a job).
	 * @return array array( deleted => names, kept => names ).
	 */
	public function delete_all( array $keep = array() ) {
		$out = array(
			'deleted' => array(),
			'kept'    => array(),
		);
		foreach ( $this->list_archives() as $a ) {
			if ( in_array( $a['name'], $keep, true ) || ! $this->delete( $a['name'] ) ) {
				$out['kept'][] = $a['name'];
			} else {
				$out['deleted'][] = $a['name'];
			}
		}
		return $out;
	}

	/**
	 * Delete abandoned leftovers older than $max_age seconds: <archive>.part
	 * files (unfinished uploads and exports) in the private directory and job
	 * temporary files in tmp/. Never touches archives, the job file, its log
	 * or lock, directories or symlinks.
	 *
	 * @param int         $max_age    Seconds.
	 * @param string|null $keep_job   Job id whose temporary files stay.
	 * @param array       $keep_names Archive names whose .part file stays.
	 * @param int|null    $now        Timestamp.
	 * @return array Deleted file names.
	 */
	public function purge_stale( $max_age, $keep_job = null, array $keep_names = array(), $now = null ) {
		$limit   = ( null === $now ? time() : (int) $now ) - (int) $max_age;
		$deleted = array();
		if ( null === $this->find_private() ) {
			return $deleted;
		}
		$old = function ( $path ) use ( $limit ) {
			clearstatcache( true, $path );
			$m = @filemtime( $path );
			return is_file( $path ) && ! is_link( $path ) && false !== $m && $m <= $limit;
		};
		foreach ( (array) @scandir( $this->private ) as $f ) {
			if ( ! is_string( $f ) || '.part' !== substr( $f, -5 ) || ! self::is_valid_name( substr( $f, 0, -5 ) ) ) {
				continue;
			}
			if ( in_array( substr( $f, 0, -5 ), $keep_names, true ) ) {
				continue;
			}
			if ( $old( $this->private . '/' . $f ) && @unlink( $this->private . '/' . $f ) ) {
				$deleted[] = $f;
			}
		}
		$tmp  = $this->private . '/tmp';
		$keep = null === $keep_job ? '' : preg_replace( '/[^a-f0-9]/', '', (string) $keep_job );
		if ( is_dir( $tmp ) && ! is_link( $tmp ) ) {
			foreach ( (array) @scandir( $tmp ) as $f ) {
				if ( ! is_string( $f ) || ! preg_match( self::TMP_PATTERN, $f, $m ) ) {
					continue;
				}
				if ( '' !== $keep && isset( $m[1] ) && $m[1] === $keep ) {
					continue;
				}
				if ( $old( $tmp . '/' . $f ) && @unlink( $tmp . '/' . $f ) ) {
					$deleted[] = 'tmp/' . $f;
				}
			}
		}
		return $deleted;
	}

	/**
	 * Free disk space in bytes, or null when unknown.
	 *
	 * @return float|null
	 */
	public function free_space() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( is_dir( $this->dir() ) ? $this->dir() : ( is_dir( $this->base ) ? $this->base : dirname( $this->base ) ) );
		return false === $free ? null : (float) $free;
	}
}
