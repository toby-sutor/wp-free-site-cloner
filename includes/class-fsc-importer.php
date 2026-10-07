<?php
/**
 * Import orchestration.
 *
 * Phases: verify -> sql -> db_prepare -> db -> replace -> files -> swap -> finalize -> done.
 * "verify" only reads the archive (SHA-256 per segment for native archives
 * from 0.9.1 on). Until "swap" the live site is untouched: the database goes
 * into fsctmp_ tables and the files into a private staging directory
 * (<storage>/tmp/stage-<job>).
 *
 * "swap" (the only window in which the live site changes, under
 * ABSPATH/.maintenance):
 *   1. prepare: fix the temp tables (URLs, prefixes, plugins/theme, compat
 *      net); plan the units (plugins/<x>, themes/<x>, uploads/<yyyy>/<mm>, ...).
 *   2. move: per unit, the live entry goes to <storage>/tmp/old-<job> and the
 *      staged one takes its place (rename(); copy + delete across filesystems),
 *      journaled in <job>.swapj before each step.
 *   3. RENAME TABLE (atomic) right after the last move: the commit point.
 * A failure before the RENAME moves every unit back (reverse order, also
 * journaled), removes .maintenance and fails the job: site unchanged. A
 * killed request resumes forward from the journal; a cancel/replace of such
 * a job rolls back. After the RENAME there is no rollback; "finalize" removes
 * .maintenance (again), flushes caches, gives renamed constraints their
 * original names (FSC_DB_Import::fix_constraints()) and deletes the
 * old/staging folders.
 * Once the RENAME was sent but not recorded (killed request, lost
 * connection), the database says whether it ran; while it cannot tell,
 * nothing is moved back or removed (FSC_Recovery::commit_state()).
 * A switch nobody continues is recovered automatically (FSC_Recovery): from
 * the .maintenance file once it is stale, and from the plugin.
 *
 * @package wp-free-site-cloner
 */

/**
 * Importer (requires WordPress).
 */
class FSC_Importer {

	/** Token lifetime in seconds without activity (extended by every step). */
	const TOKEN_TTL = 7200;

	/** Seconds the token keeps working after the job is done or failed (final status poll). */
	const TOKEN_GRACE = 120;

	/** Marker line in the .maintenance file this plugin writes. */
	const MAINTENANCE_MARK = 'WP Free Site Cloner maintenance';

	/** Phases in order, with the progress range each one covers. */
	const PHASES = array(
		'verify'     => array( 0, 10 ),
		'sql'        => array( 10, 16 ),
		'db_prepare' => array( 16, 17 ),
		'db'         => array( 17, 50 ),
		'replace'    => array( 50, 62 ),
		'files'      => array( 62, 96 ),
		'swap'       => array( 96, 98 ),
		'finalize'   => array( 98, 100 ),
	);

	/**
	 * Human-readable phase labels.
	 *
	 * @return array
	 */
	public static function phase_labels() {
		return array(
			'verify'     => __( 'Verifying archive', 'wp-free-site-cloner' ),
			'sql'        => __( 'Reading database from archive', 'wp-free-site-cloner' ),
			'db_prepare' => __( 'Preparing database import', 'wp-free-site-cloner' ),
			'db'         => __( 'Importing database', 'wp-free-site-cloner' ),
			'replace'    => __( 'Updating URLs and paths', 'wp-free-site-cloner' ),
			'files'      => __( 'Extracting files to a staging folder', 'wp-free-site-cloner' ),
			'swap'       => __( 'Switching to the new site', 'wp-free-site-cloner' ),
			'finalize'   => __( 'Cleaning up', 'wp-free-site-cloner' ),
			'done'       => __( 'Import complete', 'wp-free-site-cloner' ),
		);
	}

	/**
	 * User-facing steps in order.
	 *
	 * @return array key => label.
	 */
	public static function step_labels() {
		return array(
			'verify'   => __( 'Verify archive', 'wp-free-site-cloner' ),
			'database' => __( 'Load database into temporary tables', 'wp-free-site-cloner' ),
			'replace'  => __( 'Replace URLs and paths', 'wp-free-site-cloner' ),
			'files'    => __( 'Extract files to staging', 'wp-free-site-cloner' ),
			'swap'     => __( 'Switch to the new site', 'wp-free-site-cloner' ),
			'finalize' => __( 'Clean up', 'wp-free-site-cloner' ),
		);
	}

	/**
	 * Step key of an internal phase.
	 *
	 * @param string $phase Phase.
	 * @return string
	 */
	public static function step_of( $phase ) {
		$map = array(
			'verify'     => 'verify',
			'sql'        => 'database',
			'db_prepare' => 'database',
			'db'         => 'database',
			'replace'    => 'replace',
			'files'      => 'files',
			'swap'       => 'swap',
			'finalize'   => 'finalize',
		);
		return isset( $map[ $phase ] ) ? $map[ $phase ] : 'finalize';
	}

	/**
	 * Bytes done/total of the SQL extraction cursor of any source.
	 *
	 * @param array $c Cursor.
	 * @return array array( done, total ).
	 */
	private static function sql_bytes( $c ) {
		if ( ! is_array( $c ) ) {
			return array( 0, 0 );
		}
		if ( isset( $c['tar']['entry']['read'], $c['tar']['entry']['size'] ) ) {
			return array( (int) $c['tar']['entry']['read'], (int) $c['tar']['entry']['size'] );
		}
		if ( isset( $c['entry']['read'], $c['entry']['size'] ) ) {
			return array( (int) $c['entry']['read'], (int) $c['entry']['size'] );
		}
		if ( isset( $c['stage'], $c['copied'], $c['size'] ) && 'copy' === $c['stage'] ) {
			return array( (int) $c['copied'], (int) $c['size'] );
		}
		if ( isset( $c['gunzip']['out'] ) ) {
			return array( (int) $c['gunzip']['out'], 0 );
		}
		return array( 0, 0 );
	}

	/**
	 * Table name as the admin knows it (archive prefix instead of the SQL placeholder or the temp prefix).
	 *
	 * @param string $name  Name.
	 * @param string $from  Prefix to replace.
	 * @param string $to    Replacement.
	 * @return string
	 */
	private static function display_table( $name, $from, $to ) {
		return ( '' !== (string) $from && 0 === strpos( $name, $from ) ) ? $to . substr( $name, strlen( $from ) ) : $name;
	}

	/**
	 * Current step detail and counters.
	 *
	 * @param FSC_Job $job Job.
	 * @return array array( steps, step_detail, step_progress ).
	 */
	public static function progress_info( FSC_Job $job ) {
		$d        = $job->data;
		$phase    = $d['phase'];
		$meta     = isset( $d['meta'] ) ? $d['meta'] : array();
		$detail   = '';
		$progress = null;
		$b        = array( 'FSC_Exporter', 'bytes' );
		switch ( $phase ) {
			case 'verify':
				$v = $job->get( 'verify' );
				if ( is_array( $v ) && isset( $v['covered'] ) ) {
					$part     = isset( $v['hash']['off'] ) ? (int) $v['hash']['off'] : 0;
					$done     = min( (int) $v['covered'], (int) $v['next'] * (int) $v['segment_bytes'] + $part );
					$progress = array(
						'done'  => $done,
						'total' => (int) $v['covered'],
						'unit'  => 'bytes',
					);
					/* translators: 1: bytes checked, 2: total */
					$detail = sprintf( __( 'Verifying archive: %1$s of %2$s', 'wp-free-site-cloner' ), call_user_func( $b, $done ), call_user_func( $b, $v['covered'] ) );
				} else {
					$detail = __( 'Looking for the checksum list', 'wp-free-site-cloner' );
				}
				break;
			case 'sql':
				list( $done, $total ) = self::sql_bytes( $job->get( 'sql' ) );
				$progress             = array(
					'done'  => $done,
					'total' => $total,
					'unit'  => 'bytes',
				);
				$detail = $total > 0
					/* translators: 1: bytes done, 2: total */
					? sprintf( __( 'Reading the database dump from the archive: %1$s of %2$s', 'wp-free-site-cloner' ), call_user_func( $b, $done ), call_user_func( $b, $total ) )
					/* translators: %s: bytes done */
					: sprintf( __( 'Reading the database dump from the archive: %s', 'wp-free-site-cloner' ), call_user_func( $b, $done ) );
				break;
			case 'db_prepare':
				$detail = __( 'Preparing the temporary tables', 'wp-free-site-cloner' );
				break;
			case 'db':
				$db = $job->get( 'db' );
				if ( is_array( $db ) ) {
					$progress = array(
						'done'  => (int) $db['offset'],
						'total' => (int) $db['size'],
						'unit'  => 'bytes',
					);
					$sizes    = sprintf(
						/* translators: 1: bytes done, 2: total */
						__( '%1$s of %2$s', 'wp-free-site-cloner' ),
						call_user_func( $b, $db['offset'] ),
						call_user_func( $b, $db['size'] )
					);
					if ( ! empty( $db['cur_table'] ) ) {
						$table = self::display_table( $db['cur_table'], isset( $meta['sql_prefix'] ) ? $meta['sql_prefix'] : '', ! empty( $meta['prefix'] ) ? $meta['prefix'] : '' );
						if ( ! empty( $meta['table_count'] ) ) {
							/* translators: 1: table, 2: table number, 3: table count, 4: "x MB of y MB" */
							$detail = sprintf( __( 'Importing table %1$s (%2$s of %3$s tables): %4$s', 'wp-free-site-cloner' ), $table, number_format_i18n( max( 1, (int) $db['tables_created'] ) ), number_format_i18n( $meta['table_count'] ), $sizes );
						} else {
							/* translators: 1: table, 2: "x MB of y MB" */
							$detail = sprintf( __( 'Importing table %1$s: %2$s', 'wp-free-site-cloner' ), $table, $sizes );
						}
					} else {
						/* translators: %s: "x MB of y MB" */
						$detail = sprintf( __( 'Importing the database: %s', 'wp-free-site-cloner' ), $sizes );
					}
				}
				break;
			case 'replace':
				$sr = $job->get( 'sr' );
				if ( is_array( $sr ) ) {
					$n        = count( $sr['tables'] );
					$progress = array(
						'done'  => min( $n, (int) $sr['ti'] ),
						'total' => $n,
						'unit'  => 'tables',
					);
					if ( $sr['ti'] < $n ) {
						$table = self::display_table( $sr['tables'][ $sr['ti'] ], FSC_DB_Import::TEMP, isset( $d['target']['prefix'] ) ? $d['target']['prefix'] : '' );
						$rows  = is_array( $sr['table'] ) ? (int) $sr['table']['offset'] : 0;
						/* translators: 1: table, 2: table number, 3: table count, 4: rows checked */
						$detail = sprintf( __( 'Replacing URLs in %1$s (table %2$s of %3$s): %4$s rows checked', 'wp-free-site-cloner' ), $table, number_format_i18n( $sr['ti'] + 1 ), number_format_i18n( $n ), number_format_i18n( $rows ) );
					} else {
						$detail = __( 'Replacing URLs: final checks', 'wp-free-site-cloner' );
					}
				}
				break;
			case 'files':
				$c = $job->get( 'files' );
				if ( is_array( $c ) ) {
					$seen     = isset( $c['seen_files'] ) ? (int) $c['seen_files'] : (int) $c['files'];
					$total    = 'native' === ( isset( $meta['format'] ) ? $meta['format'] : '' ) ? (int) $meta['file_count'] : 0;
					$progress = array(
						'done'  => $seen,
						'total' => $total,
						'unit'  => 'files',
					);
					if ( $total > 0 && ! empty( $meta['files_bytes'] ) ) {
						/* translators: 1: files done, 2: files total, 3: bytes done, 4: bytes total */
						$detail = sprintf( __( 'Extracting files: %1$s of %2$s (%3$s of %4$s)', 'wp-free-site-cloner' ), number_format_i18n( $seen ), number_format_i18n( $total ), call_user_func( $b, $c['bytes'] ), call_user_func( $b, $meta['files_bytes'] ) );
					} else {
						/* translators: 1: files done, 2: bytes done */
						$detail = sprintf( __( 'Extracting files: %1$s (%2$s)', 'wp-free-site-cloner' ), number_format_i18n( $seen ), call_user_func( $b, $c['bytes'] ) );
					}
				}
				break;
			case 'swap':
				$sw = $job->get( 'swap' );
				if ( is_array( $sw ) && isset( $sw['stage'] ) && 'rollback' === $sw['stage'] ) {
					$detail = __( 'The switch failed: moving the previous files back', 'wp-free-site-cloner' );
				} elseif ( is_array( $sw ) && ! empty( $sw['units'] ) ) {
					/* translators: 1: folders moved, 2: total */
					$detail = sprintf( __( 'Moving files into place: %1$s of %2$s folders, then switching the database', 'wp-free-site-cloner' ), number_format_i18n( isset( $sw['moved'] ) ? (int) $sw['moved'] : 0 ), number_format_i18n( (int) $sw['units'] ) );
				} else {
					$detail = __( 'Switching to the imported database and files', 'wp-free-site-cloner' );
				}
				break;
			case 'finalize':
				$fin = $job->get( 'fin' );
				if ( 'purge' === $fin ) {
					$detail = __( 'Deleting the replaced files', 'wp-free-site-cloner' );
				} elseif ( 'cons' === $fin ) {
					$detail = __( 'Restoring database constraint names', 'wp-free-site-cloner' );
				} else {
					$detail = __( 'Flushing caches and rewrite rules', 'wp-free-site-cloner' );
				}
				break;
		}
		if ( 'done' === $d['status'] ) {
			$progress = null;
			$detail   = __( 'Import complete', 'wp-free-site-cloner' );
		}
		return array(
			'steps'         => FSC_Job::build_steps( self::step_labels(), self::step_of( $phase ), $d['status'], isset( $d['skipped_steps'] ) ? (array) $d['skipped_steps'] : array() ),
			'step_detail'   => $detail,
			'step_progress' => $progress,
		);
	}

	/**
	 * Expected (manifest) and actual size of an archive in storage; never throws.
	 *
	 * @param FSC_Storage $st   Storage.
	 * @param string      $name Archive name.
	 * @return array array( archive_size_expected => int|null, archive_size_actual => int ) or empty when missing.
	 */
	public static function size_info( FSC_Storage $st, $name ) {
		$path = $st->resolve( $name );
		if ( null === $path ) {
			return array();
		}
		$expected = null;
		try {
			if ( FSC_Source_Native::detect( $path ) ) {
				$src      = new FSC_Source_Native( $path );
				$expected = FSC_Integrity::expected_size( $src->manifest() );
			}
		} catch ( Throwable $e ) {
			// Never throws: an unexpected error is only written to the PHP error log.
			if ( ! ( $e instanceof FSC_Exception ) ) {
				FSC_Job::public_message( $e );
			}
			$expected = null;
		}
		clearstatcache( true, $path );
		return array(
			'archive_size_expected' => $expected,
			'archive_size_actual'   => (int) filesize( $path ),
		);
	}

	/**
	 * Source classes, tried in order.
	 *
	 * @return array
	 */
	public static function source_classes() {
		$out = array();
		foreach ( array( 'FSC_Source_Native', 'FSC_Source_Wpress', 'FSC_Source_Duplicator' ) as $c ) {
			if ( class_exists( $c ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * Detect the format of an archive.
	 *
	 * @param string $path Archive path.
	 * @return string Class name.
	 * @throws FSC_Exception When no source can read it.
	 */
	public static function detect( $path ) {
		foreach ( self::source_classes() as $c ) {
			if ( call_user_func( array( $c, 'detect' ), $path ) ) {
				return $c;
			}
		}
		throw new FSC_Exception( __( 'Unsupported archive. Supported: WP Free Site Cloner .tar, All-in-One WP Migration .wpress, Duplicator .daf and .zip.', 'wp-free-site-cloner' ) );
	}

	/**
	 * Values of this (destination) site.
	 *
	 * @return array
	 */
	public static function target() {
		$uploads = wp_upload_dir( null, false );
		return array(
			'home'        => (string) get_option( 'home' ),
			'siteurl'     => (string) get_option( 'siteurl' ),
			'abspath'     => ABSPATH,
			'content_dir' => WP_CONTENT_DIR,
			'uploads_url' => isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '',
			'prefix'      => FSC_DB::wpdb()->prefix,
		);
	}

	/**
	 * Inspect an archive in storage for the confirmation screen.
	 *
	 * @param FSC_Storage $st   Storage.
	 * @param string      $name Archive name.
	 * @return array array( name, size, format, meta, target ).
	 * @throws FSC_Exception When the archive is missing or unsupported.
	 */
	public static function inspect( FSC_Storage $st, $name ) {
		if ( is_multisite() ) {
			throw new FSC_Exception( __( 'Multisite networks are not supported.', 'wp-free-site-cloner' ) );
		}
		$path = $st->resolve( $name );
		if ( null === $path ) {
			throw new FSC_Exception( __( 'Archive not found in the storage directory.', 'wp-free-site-cloner' ) );
		}
		$class  = self::detect( $path );
		$source = new $class( $path );
		$meta   = $source->meta();
		if ( ! empty( $meta['multisite'] ) ) {
			throw new FSC_Exception( __( 'This archive is from a multisite network, which is not supported.', 'wp-free-site-cloner' ) );
		}
		if ( '' === (string) $meta['home'] && '' === (string) $meta['siteurl'] ) {
			throw new FSC_Exception( __( 'The archive does not say which site it came from (no URL in its metadata).', 'wp-free-site-cloner' ) );
		}
		global $wp_version;
		$warnings = array();
		if ( ! empty( $meta['warnings'] ) && is_array( $meta['warnings'] ) ) {
			$warnings = array_values( array_map( 'strval', $meta['warnings'] ) );
		}
		unset( $meta['warnings'] );
		if ( '' !== (string) $meta['wp_version'] && version_compare( $meta['wp_version'], '6.8-alpha', '>=' ) && version_compare( $wp_version, '6.8-alpha', '<' ) ) {
			$warnings[] = sprintf(
				/* translators: 1: source WordPress version, 2: this site's WordPress version */
				__( 'Update WordPress before importing: the archive comes from WordPress %1$s, whose password hashes WordPress %2$s cannot check. Nobody could log in after the import.', 'wp-free-site-cloner' ),
				$meta['wp_version'],
				$wp_version
			);
		} elseif ( '' !== (string) $meta['wp_version'] && version_compare( $meta['wp_version'], $wp_version, '>' ) ) {
			$warnings[] = sprintf(
				/* translators: 1: source WordPress version, 2: this site's WordPress version */
				__( 'The archive comes from WordPress %1$s, this site runs %2$s. Update WordPress first, or themes and plugins from the archive may not work.', 'wp-free-site-cloner' ),
				$meta['wp_version'],
				$wp_version
			);
		}
		clearstatcache( true, $path );
		return array(
			'name'                  => $name,
			'size'                  => (int) filesize( $path ),
			'class'                 => $class,
			'meta'                  => $meta,
			'target'                => self::target(),
			'warnings'              => $warnings,
			'archive_size_expected' => isset( $meta['archive_size'] ) ? $meta['archive_size'] : null,
			'archive_size_actual'   => (int) filesize( $path ),
		);
	}

	/**
	 * Replacement pairs from source meta to this site.
	 *
	 * @param array $meta   Source meta.
	 * @param array $target Target values.
	 * @return array
	 */
	public static function pairs( array $meta, array $target ) {
		$urls  = array();
		$paths = array();
		if ( '' !== $meta['uploads_url'] && '' !== $target['uploads_url'] ) {
			$urls[] = array( $meta['uploads_url'], $target['uploads_url'] );
		}
		if ( '' !== $meta['siteurl'] ) {
			$urls[] = array( $meta['siteurl'], $target['siteurl'] );
		}
		if ( '' !== $meta['home'] ) {
			$urls[] = array( $meta['home'], $target['home'] );
		}
		if ( '' !== $meta['content_dir'] ) {
			$paths[] = array( $meta['content_dir'], $target['content_dir'] );
		}
		if ( '' !== $meta['abspath'] ) {
			$paths[] = array( $meta['abspath'], $target['abspath'] );
		}
		/**
		 * Filter the search-replace pairs used on import.
		 *
		 * @param array $pairs  old => new.
		 * @param array $meta   Source metadata.
		 * @param array $target Destination values.
		 */
		return (array) apply_filters( 'fsc_import_replace_pairs', FSC_Search_Replace::build_pairs( $urls, $paths ), $meta, $target );
	}

	/**
	 * Create the import job and return the plain secret token.
	 *
	 * @param FSC_Storage $st   Storage.
	 * @param string      $name Archive name.
	 * @return array array( job => FSC_Job, token => string ).
	 * @throws FSC_Exception On invalid archives.
	 */
	public static function start( FSC_Storage $st, $name ) {
		$info  = self::inspect( $st, $name );
		$st->ensure();
		self::check_space( $st, $info['meta'], $st->resolve( $name ), true );
		$token = bin2hex( random_bytes( 32 ) );
		$job   = FSC_Job::create(
			$st->job_path(),
			'import',
			array(
				'phase'           => 'verify',
				'archive'         => $name,
				'source'          => $info['class'],
				'meta'            => $info['meta'],
				'target'          => $info['target'],
				'pairs'           => self::pairs( $info['meta'], $info['target'] ),
				'plugin_basename' => plugin_basename( FSC_PLUGIN_FILE ),
				'token_hash'      => hash( 'sha256', $token ),
				'expires'         => time() + self::TOKEN_TTL,
				'swapped'         => false,
			)
		);
		$job->set( 'sql', array() );
		$job->set( 'verify', null );
		$job->log( sprintf( 'Import started: %s (%s, from %s).', $name, $info['meta']['format'], $info['meta']['home'] ) );
		$job->save();
		return array(
			'job'   => $job,
			'token' => $token,
		);
	}

	/**
	 * Whether $token has the shape of an issued token (64 lowercase hex).
	 *
	 * @param mixed $token Token.
	 * @return bool
	 */
	public static function valid_token_format( $token ) {
		return is_string( $token ) && 64 === strlen( $token ) && (bool) preg_match( '/^[a-f0-9]{64}$/', $token );
	}

	/**
	 * Whether $token opens $job.
	 *
	 * @param FSC_Job|null $job   Job.
	 * @param string       $token Plain token.
	 * @return bool
	 */
	public static function check_token( $job, $token ) {
		if ( ! $job || 'import' !== $job->data['type'] || empty( $job->data['token_hash'] ) || ! self::valid_token_format( $token ) ) {
			return false;
		}
		$now = time();
		if ( $now > (int) $job->data['expires'] ) {
			return false;
		}
		// Revoked TOKEN_GRACE seconds after the job finished (done or error).
		if ( 'running' !== $job->data['status'] ) {
			$end = isset( $job->data['finished_at'] ) ? (int) $job->data['finished_at'] : (int) $job->data['updated'];
			if ( $now > $end + self::TOKEN_GRACE ) {
				return false;
			}
		}
		return hash_equals( (string) $job->data['token_hash'], hash( 'sha256', $token ) );
	}

	/**
	 * Skip rules for extraction (paths relative to wp-content).
	 *
	 * @param string $basename plugin_basename() of this plugin.
	 * @return callable
	 */
	public static function skip_callback( $basename ) {
		$dir = dirname( $basename );
		return function ( $rel ) use ( $dir ) {
			$lower = strtolower( $rel );
			// Top-level only: wp-config.php inside plugins is often a template, and the
			// real one lives in ABSPATH, which extraction never reaches.
			if ( in_array( $lower, array( 'wp-config.php', 'object-cache.php', 'advanced-cache.php', 'db.php' ), true ) ) {
				return true;
			}
			if ( 'fsc-storage' === $lower || 0 === strpos( $lower, 'fsc-storage/' ) ) {
				return true;
			}
			if ( '.' !== $dir && '' !== $dir ) {
				// Case-insensitive: on a case-insensitive filesystem Plugins/WP-Free-Site-Cloner is this plugin too.
				$own = strtolower( 'plugins/' . $dir );
				if ( $lower === $own || 0 === strpos( $lower, $own . '/' ) ) {
					return true;
				}
			}
			return false;
		};
	}

	/**
	 * Source instance for the job.
	 *
	 * @param FSC_Job     $job Job.
	 * @param FSC_Storage $st  Storage.
	 * @return FSC_Source
	 * @throws FSC_Exception When the archive is gone.
	 */
	private static function source( FSC_Job $job, FSC_Storage $st ) {
		$path  = $st->resolve( $job->data['archive'] );
		$class = $job->data['source'];
		if ( null === $path || ! in_array( $class, self::source_classes(), true ) ) {
			throw new FSC_Exception( __( 'The archive disappeared from the storage directory.', 'wp-free-site-cloner' ) );
		}
		return new $class( $path );
	}

	/**
	 * Set phase progress.
	 *
	 * @param FSC_Job $job      Job.
	 * @param float   $fraction Fraction of the current phase.
	 */
	private static function progress( FSC_Job $job, $fraction ) {
		$phase = $job->data['phase'];
		if ( isset( self::PHASES[ $phase ] ) ) {
			$r                     = self::PHASES[ $phase ];
			$job->data['progress'] = (int) floor( $r[0] + ( $r[1] - $r[0] ) * max( 0, min( 1, $fraction ) ) );
		}
	}

	/**
	 * One unit of import work (called by FSC_Job::run()).
	 *
	 * @param FSC_Job     $job      Job.
	 * @param FSC_Storage $st       Storage.
	 * @param float       $deadline Deadline.
	 * @param array       $state    Run state (resumed_after_crash, yield).
	 * @return bool True when the import is complete.
	 * @throws FSC_Exception On errors.
	 */
	public static function unit( FSC_Job $job, FSC_Storage $st, $deadline, array &$state ) {
		$id = $job->data['id'];
		// Sliding token lifetime: TOKEN_TTL after the last step.
		$job->data['expires'] = time() + self::TOKEN_TTL;
		switch ( $job->data['phase'] ) {
			case 'verify':
				self::verify_unit( $job, $st, $deadline );
				return false;

			case 'sql':
				$cursor = $job->get( 'sql' );
				$src    = self::source( $job, $st );
				$done   = $src->extract_sql( $st->tmp_file( $id, 'sql' ), $cursor, $deadline );
				$job->set( 'sql', $cursor );
				self::progress( $job, isset( $cursor['progress'] ) ? $cursor['progress'] : 0 );
				if ( $done ) {
					$sha = (string) $job->data['meta']['sql_sha256'];
					if ( '' !== $sha && ! hash_equals( strtolower( $sha ), hash_file( 'sha256', $st->tmp_file( $id, 'sql' ) ) ) ) {
						throw new FSC_Exception( __( 'The database dump in the archive is corrupt (checksum mismatch).', 'wp-free-site-cloner' ) );
					}
					$job->log( sprintf( 'Database dump extracted (%s).', size_format( filesize( $st->tmp_file( $id, 'sql' ) ) ) ) );
					$job->data['phase'] = 'db_prepare';
				}
				return false;

			case 'db_prepare':
				FSC_DB_Import::prepare( $job, $st );
				$job->data['phase'] = 'db';
				return false;

			case 'db':
				$done = FSC_DB_Import::import_step( $job, $st, $deadline, ! empty( $state['resumed_after_crash'] ) );
				self::progress( $job, FSC_DB_Import::import_progress( $job->get( 'db' ) ) );
				if ( $done ) {
					$db = $job->get( 'db' );
					$job->log( sprintf( 'Database imported into temporary tables: %d statements executed, %d skipped.', $db['executed'], $db['skipped'] ) );
					FSC_DB_Import::replace_init( $job );
					$job->data['phase'] = 'replace';
				}
				return false;

			case 'replace':
				$done = FSC_DB_Import::replace_step( $job, $st );
				self::progress( $job, FSC_DB_Import::replace_progress( $job->get( 'sr' ) ) );
				if ( $done ) {
					$job->log( sprintf( 'Updated URLs and paths in %d rows.', $job->get( 'sr' )['changed'] ) );
					self::check_space( $st, $job->data['meta'], $st->resolve( $job->data['archive'] ), false );
					$stage = self::stage_dir( $st, $id );
					FSC_Extractor::purge_tree( $stage, INF );
					if ( ! @mkdir( $stage, 0700, true ) && ! is_dir( $stage ) ) {
						throw new FSC_Exception( 'Cannot create the staging folder in the storage directory.' );
					}
					$job->data['phase'] = 'files';
					$job->set( 'files', FSC_Extractor::new_cursor() );
					$job->log( 'Extracting files into a staging folder; the live site is not changed until the switch.' );
				}
				return false;

			case 'files':
				$cursor = $job->get( 'files' );
				$src    = self::source( $job, $st );
				$x      = new FSC_Extractor( self::stage_dir( $st, $id ), $src, self::skip_callback( $job->data['plugin_basename'] ) );
				$done   = $x->step( $cursor, $deadline );
				foreach ( $x->messages as $m ) {
					$job->log( $m );
				}
				$job->set( 'files', $cursor );
				self::progress( $job, $done ? 1 : $src->files_progress( $cursor['src'] ) );
				if ( $done ) {
					$job->log( sprintf( 'Extracted %d files (%s), skipped %d entries.', $cursor['files'], size_format( $cursor['bytes'] ), $cursor['skipped'] ) );
					$job->data['phase'] = 'swap';
				}
				return false;

			case 'swap':
				if ( self::swap_unit( $job, $st, $deadline, $state ) ) {
					$job->data['phase'] = 'finalize';
					self::progress( $job, 0 );
					// The next request must load the imported site.
					$state['yield'] = true;
				}
				return false;

			case 'finalize':
				$fin = $job->get( 'fin' );
				if ( 'purge' !== $fin && 'cons' !== $fin ) {
					// Also covers a switch request that died right after the RENAME.
					self::maintenance_off();
					FSC_DB_Import::drop_prefixed( FSC_DB_Import::OLD );
					$st->clean_tmp( $id );
					wp_cache_flush();
					flush_rewrite_rules( true );
					wp_cache_flush();
					$job->set( 'fin', 'cons' );
					return false;
				}
				if ( 'cons' === $fin ) {
					// Constraint names are free now that the replaced tables are gone.
					if ( FSC_DB_Import::fix_constraints( $job, $deadline ) ) {
						$job->set( 'fin', 'purge' );
					}
					return false;
				}
				if ( ! FSC_Extractor::purge_tree( self::old_dir( $st, $id ), $deadline ) || ! FSC_Extractor::purge_tree( self::stage_dir( $st, $id ), $deadline ) ) {
					return false;
				}
				FSC_Recovery::flag( $st, false );
				$job->data['phase']  = 'done';
				$job->data['result'] = array( 'login_url' => wp_login_url() );
				$job->log( 'Import complete. Log in with the user accounts of the imported site.' );
				return true;
		}
		throw new FSC_Exception( 'Unknown import phase.' );
	}

	/**
	 * One unit of the verify phase: find the trailer, then hash segments
	 * until the deadline (a segment may continue in the next unit). The
	 * segment hashes live in tmp/<job>.segs (64 hex + newline each), not in
	 * the job cursor.
	 *
	 * @param FSC_Job     $job      Job.
	 * @param FSC_Storage $st       Storage.
	 * @param float       $deadline Deadline.
	 * @throws FSC_Exception On a damaged or truncated archive.
	 */
	private static function verify_unit( FSC_Job $job, FSC_Storage $st, $deadline ) {
		$v    = $job->get( 'verify' );
		$path = $st->resolve( $job->data['archive'] );
		if ( null === $path ) {
			throw new FSC_Exception( __( 'The archive disappeared from the storage directory.', 'wp-free-site-cloner' ) );
		}
		$segs = $st->tmp_file( $job->data['id'], 'segs' );
		$next = function ( $note = null ) use ( $job, $segs ) {
			if ( null !== $note ) {
				$skipped                    = isset( $job->data['skipped_steps'] ) ? (array) $job->data['skipped_steps'] : array();
				$skipped['verify']          = $note;
				$job->data['skipped_steps'] = $skipped;
			}
			@unlink( $segs );
			$job->set( 'verify', array( 'done' => true ) );
			$job->data['phase'] = 'sql';
			self::progress( $job, 0 );
		};
		if ( ! is_array( $v ) ) {
			if ( 'FSC_Source_Native' !== $job->data['source'] ) {
				$name = ! empty( $job->data['meta']['format_name'] ) ? $job->data['meta']['format_name'] : $job->data['meta']['format'];
				/* translators: %s: archive format */
				$note = sprintf( __( 'Skipped: %s archives have no whole-archive checksum. The per-file CRC32 checksums they carry are checked while the database and files are extracted.', 'wp-free-site-cloner' ), $name );
				$job->log( $note );
				$next( $note );
				return;
			}
			$src      = new FSC_Source_Native( $path );
			$manifest = $src->manifest();
			FSC_Source_Native::check_complete( $path, $manifest );
			$damaged = __( 'The archive is damaged: its checksum list is missing or unreadable. Download it again, or copy it by FTP/SFTP.', 'wp-free-site-cloner' );
			try {
				$t = FSC_Integrity::read_trailer( $path );
			} catch ( FSC_Exception $e ) {
				throw new FSC_Exception( $damaged );
			}
			if ( null === $t ) {
				if ( null !== FSC_Integrity::expected_size( $manifest ) ) {
					throw new FSC_Exception( $damaged );
				}
				$note = __( 'This archive was made by an older version and has no checksum; it cannot be verified.', 'wp-free-site-cloner' );
				$job->log( 'WARNING: ' . $note );
				$next( $note );
				return;
			}
			if ( false === @file_put_contents( $segs, implode( "\n", $t['segments'] ) . "\n" ) ) {
				throw new FSC_Exception( 'Cannot write a temporary file (disk full?).' );
			}
			$job->set(
				'verify',
				array(
					'covered'       => $t['covered'],
					'segment_bytes' => $t['segment_bytes'],
					'count'         => count( $t['segments'] ),
					'next'          => 0,
					'hash'          => null,
				)
			);
			$job->log( sprintf( 'Verifying %s in %d SHA-256 segments.', size_format( $t['covered'] ), count( $t['segments'] ) ) );
			self::progress( $job, 0 );
			return;
		}
		if ( ! empty( $v['done'] ) ) {
			$next();
			return;
		}
		$n = isset( $v['count'] ) ? (int) $v['count'] : 0;
		do {
			if ( $v['next'] >= $n ) {
				break;
			}
			$k     = (int) $v['next'];
			$state = isset( $v['hash'] ) ? $v['hash'] : null;
			$got   = FSC_Integrity::hash_segment_step( $path, $k, $v['segment_bytes'], $v['covered'], $state, $deadline );
			$v['hash'] = $state;
			if ( null === $got ) {
				break;
			}
			$fp   = @fopen( $segs, 'rb' );
			$want = '';
			if ( $fp ) {
				fseek( $fp, $k * 65 );
				$want = (string) fread( $fp, 64 );
				fclose( $fp );
			}
			if ( 64 !== strlen( $want ) ) {
				throw new FSC_Exception( 'The checksum list of the import disappeared from the storage directory.' );
			}
			if ( ! hash_equals( $want, $got ) ) {
				throw new FSC_Exception( FSC_Integrity::damaged_message( $k, $v['segment_bytes'] ) );
			}
			$v['next'] = $k + 1;
		} while ( microtime( true ) < $deadline );
		$job->set( 'verify', $v );
		self::progress( $job, $v['next'] / max( 1, $n ) );
		if ( $v['next'] >= $n ) {
			$job->log( sprintf( 'Archive verified: all %d checksums match.', $n ) );
			$next();
		}
	}

	/**
	 * Clean up after a failed or cancelled import (before or after the switch).
	 * Nothing is touched while the database cannot tell whether the switch
	 * was committed, and nothing when it turns out that it was: finalize
	 * then finishes the import (see FSC_Recovery::commit_state()).
	 *
	 * @param FSC_Job     $job Job (the caller holds its lock).
	 * @param FSC_Storage $st  Storage.
	 * @return bool False when nothing was cleaned up: the job must be kept.
	 */
	public static function cleanup( FSC_Job $job, FSC_Storage $st ) {
		$id      = $job->data['id'];
		$swapped = ! empty( $job->data['swapped'] );
		$state   = FSC_DB_Import::commit_state( $job );
		if ( FSC_Recovery::UNKNOWN === $state || ( FSC_Recovery::COMMITTED === $state && ! $swapped ) ) {
			return false;
		}
		$old = self::old_dir( $st, $id );
		if ( ! $swapped ) {
			// Undo a switch that was interrupted and then cancelled or replaced.
			$sw = self::switch_paths( $st, $id );
			$jl = null === $sw ? array( -1, '' ) : FSC_Extractor::journal_last( $sw['journal'] );
			if ( -1 !== $jl[0] && ! ( 0 === $jl[0] && 'z' === $jl[1] ) ) {
				try {
					$copied = false;
					FSC_Extractor::switch_step( $sw, INF, true, $copied );
					$job->log( 'Moved the previous files back into wp-content.' );
				} catch ( Throwable $e ) {
					$job->data['restore_failed'] = true;
					$job->log( 'WARNING: Could not move all previous files back (' . FSC_Job::public_message( $e ) . '). They are kept in the storage folder tmp/old-' . $id . '.' );
				}
			}
			FSC_DB_Import::drop_prefixed( FSC_DB_Import::TEMP );
		} else {
			// A swapped import cancelled before finalize: its clean-up of the database.
			try {
				FSC_DB_Import::drop_prefixed( FSC_DB_Import::OLD );
				if ( 'purge' !== $job->get( 'fin' ) ) {
					FSC_DB_Import::fix_constraints( $job, INF );
				}
			} catch ( Throwable $e ) {
				$job->log( 'WARNING: Database clean-up after the switch failed: ' . FSC_Job::public_message( $e ) );
			}
		}
		self::maintenance_off();
		FSC_Extractor::purge_tree( self::stage_dir( $st, $id ), INF );
		if ( $swapped || ! FSC_Extractor::has_files( $old ) ) {
			FSC_Extractor::purge_tree( $old, INF );
		} else {
			@file_put_contents( $old . '/RESTORE-FAILED.txt', "Files of the site that could not be moved back after a failed import.\n" );
		}
		$st->clean_tmp( $id );
		FSC_Recovery::flag( $st, false );
		return true;
	}

	/**
	 * Staging directory of a job.
	 *
	 * @param FSC_Storage $st Storage.
	 * @param string      $id Job id.
	 * @return string
	 */
	public static function stage_dir( FSC_Storage $st, $id ) {
		return $st->tmp_dir() . '/stage-' . preg_replace( '/[^a-f0-9]/', '', (string) $id );
	}

	/**
	 * Directory that keeps the replaced live entries until finalize.
	 *
	 * @param FSC_Storage $st Storage.
	 * @param string      $id Job id.
	 * @return string
	 */
	public static function old_dir( FSC_Storage $st, $id ) {
		return $st->tmp_dir() . '/old-' . preg_replace( '/[^a-f0-9]/', '', (string) $id );
	}

	/**
	 * Paths and units of the switch, or null before it was planned.
	 *
	 * @param FSC_Storage $st   Storage.
	 * @param string      $id   Job id.
	 * @param string|null $live Live wp-content (default WP_CONTENT_DIR).
	 * @return array|null
	 */
	public static function switch_paths( FSC_Storage $st, $id, $live = null ) {
		$units = json_decode( (string) @file_get_contents( $st->tmp_file( $id, 'moves' ) ), true );
		if ( ! is_array( $units ) ) {
			return null;
		}
		return array(
			'stage'   => self::stage_dir( $st, $id ),
			'live'    => null === $live ? WP_CONTENT_DIR : $live,
			'old'     => self::old_dir( $st, $id ),
			'journal' => $st->tmp_file( $id, 'swapj' ),
			'units'   => array_values( array_filter( $units, 'is_string' ) ),
		);
	}

	/**
	 * One unit of the swap phase (see the file comment).
	 *
	 * @param FSC_Job     $job      Job.
	 * @param FSC_Storage $st       Storage.
	 * @param float       $deadline Deadline.
	 * @param array       $state    Unit state (see FSC_Job::run()).
	 * @return bool True when the site is switched.
	 * @throws FSC_Exception On errors (after the files were moved back).
	 */
	private static function swap_unit( FSC_Job $job, FSC_Storage $st, $deadline, array &$state ) {
		$id    = $job->data['id'];
		$sw    = $job->get( 'swap' );
		$stage = self::stage_dir( $st, $id );
		if ( ! is_array( $sw ) ) {
			// 1. Prepare: temp tables and the move plan. Nothing live changes.
			FSC_DB_Import::swap_prepare( $job, is_dir( $stage ) ? $stage : '' );
			$protected = array();
			foreach ( array( dirname( FSC_PLUGIN_FILE ), $st->tmp_dir() ) as $p ) {
				$r = realpath( $p );
				if ( false !== $r ) {
					$protected[] = $r;
				}
			}
			$units = array();
			foreach ( FSC_Extractor::plan_units( $stage ) as $rel ) {
				$why = FSC_Extractor::check_unit( WP_CONTENT_DIR, $rel, $protected );
				if ( '' !== $why ) {
					$job->log( sprintf( 'Skipped %s: %s.', $rel, $why ) );
					continue;
				}
				if ( ! FSC_Extractor::movable( WP_CONTENT_DIR . '/' . $rel, self::old_dir( $st, $id ) . '/' . $rel ) || ! FSC_Extractor::movable( $stage . '/' . $rel, WP_CONTENT_DIR . '/' . $rel ) ) {
					throw new FSC_Exception( sprintf( 'Cannot replace %s: the web server may not write there. Nothing on the site was changed.', 'wp-content/' . $rel ) );
				}
				$units[] = $rel;
			}
			if ( false === @file_put_contents( $st->tmp_file( $id, 'moves' ), json_encode( $units ) ) ) {
				throw new FSC_Exception( 'Cannot write the switch plan (disk full?).' );
			}
			@unlink( $st->tmp_file( $id, 'swapj' ) );
			$old = self::old_dir( $st, $id );
			if ( ! is_dir( $old ) && ! @mkdir( $old, 0700, true ) && ! is_dir( $old ) ) {
				throw new FSC_Exception( 'Cannot create a folder in the storage directory (permissions?). Nothing on the site was changed.' );
			}
			$st_dev   = @stat( $st->tmp_dir() );
			$live_dev = @stat( WP_CONTENT_DIR );
			$copy     = is_array( $st_dev ) && is_array( $live_dev ) && $st_dev['dev'] !== $live_dev['dev'];
			if ( $copy ) {
				$job->log( 'The storage folder is on a different filesystem than wp-content: files are copied instead of moved, so the switch (and its maintenance mode) takes longer.' );
			}
			$job->set(
				'swap',
				array(
					'stage' => 'move',
					'units' => count( $units ),
					'moved' => 0,
				)
			);
			$job->log( sprintf( 'Switching %d folders and files and the database (maintenance mode on meanwhile).', count( $units ) ) );
			// Lets the plugin find an abandoned switch with one stat call per request (FSC_Plugin::recover_on_load()).
			FSC_Recovery::flag( $st, true );
			return false;
		}
		$paths = self::switch_paths( $st, $id );
		if ( null === $paths ) {
			throw new FSC_Exception( 'The switch plan is missing; the import cannot continue.' );
		}
		$copied = false;
		if ( 'rollback' === $sw['stage'] ) {
			self::maintenance_on( $job );
			if ( ! FSC_Extractor::switch_step( $paths, $deadline, true, $copied ) ) {
				return false;
			}
			self::maintenance_off();
			$job->set( 'swap', array( 'stage' => 'rolled_back' ) + $sw );
			throw new FSC_Exception( $sw['error'] . ' The previous files were moved back; the site was not changed.' );
		}
		if ( 'move' !== $sw['stage'] ) {
			throw new FSC_Exception( isset( $sw['error'] ) ? $sw['error'] : 'The switch was interrupted.' );
		}
		// 2. Move, 3. RENAME TABLE right after the last move.
		// A resumed switch is alive again: a recovery that has not decided to roll back yet stands down.
		unset( $job->data['recovery'], $job->data['recover_attempts'], $job->data['recover_last'] );
		self::maintenance_on( $job );
		try {
			$done        = FSC_Extractor::switch_step( $paths, $deadline, false, $copied );
			$sw['moved'] = FSC_Extractor::switched_count( $paths['journal'] );
			$job->set( 'swap', $sw );
			if ( ! $done ) {
				return false;
			}
			if ( ! FSC_DB_Import::swap_rename( $job ) ) {
				return self::swap_undecided( $job, $state, '' );
			}
		} catch ( Throwable $e ) {
			if ( ! empty( $job->data['swapped'] ) ) {
				self::maintenance_off();
				throw $e;
			}
			$why = FSC_Job::public_message( $e );
			// Once the RENAME was sent, it may have run although this request saw an error (lost connection).
			$commit = FSC_DB_Import::commit_state( $job );
			if ( FSC_Recovery::UNKNOWN === $commit ) {
				return self::swap_undecided( $job, $state, $why );
			}
			if ( FSC_Recovery::NOT_COMMITTED === $commit ) {
				unset( $job->data['commit_unknown'] );
				$sw['stage'] = 'rollback';
				$sw['error'] = $why;
				$job->set( 'swap', $sw );
				$job->log( 'ERROR during the switch: ' . $sw['error'] . ' Moving the previous files back.' );
				return false;
			}
			$job->log( 'The error that request had reported: ' . $why );
		}
		unset( $job->data['commit_unknown'] );
		self::maintenance_off();
		$st->clean_tmp( $id );
		$job->log( sprintf( 'Switched %d folders and files.', $sw['moved'] ) );
		return true;
	}

	/**
	 * The database cannot tell yet whether the RENAME TABLE ran: everything
	 * stays as it is and the request ends; the next step asks again.
	 *
	 * @param FSC_Job $job   Job.
	 * @param array   $state Unit state (see FSC_Job::run()).
	 * @param string  $why   Error of the switch request, or ''.
	 * @return bool False: the switch is not done.
	 */
	private static function swap_undecided( FSC_Job $job, array &$state, $why ) {
		if ( empty( $job->data['commit_unknown'] ) ) {
			$job->data['commit_unknown'] = true;
			$job->log( 'WARNING: ' . ( '' === $why ? '' : $why . ' ' ) . 'The database cannot tell yet whether the table swap completed. Nothing is moved back; every further step asks again.' );
		}
		// The browser sends the next step at once.
		usleep( 500000 );
		$state['yield'] = true;
		return false;
	}

	/**
	 * Put the site into maintenance mode for the switch (idempotent). Every
	 * call also touches the file: its age is the heartbeat the file itself
	 * checks (see maintenance_code()).
	 *
	 * @param FSC_Job $job Job.
	 */
	public static function maintenance_on( FSC_Job $job ) {
		$f = ABSPATH . '.maintenance';
		clearstatcache( true, $f );
		if ( file_exists( $f ) ) {
			if ( self::is_ours( $f ) ) {
				@touch( $f );
			} elseif ( empty( $job->data['maintenance_foreign'] ) ) {
				$job->data['maintenance_foreign'] = true;
				$job->log( 'A .maintenance file from something else exists; it is left alone.' );
			}
			return;
		}
		// Written to a temp file and renamed: a request must never include a half-written file.
		$tmp = $f . '.fsc-' . bin2hex( random_bytes( 4 ) );
		if ( false === @file_put_contents( $tmp, self::maintenance_code( self::recover_expr() ) ) || ! @rename( $tmp, $f ) ) {
			@unlink( $tmp );
			if ( empty( $job->data['maintenance_failed'] ) ) {
				$job->data['maintenance_failed'] = true;
				$job->log( 'Could not create .maintenance in the WordPress folder (not writable); the switch runs without maintenance mode.' );
			}
		}
	}

	/**
	 * PHP of the .maintenance file. WordPress includes it on every request
	 * before loading plugins and shows its maintenance page while
	 * $upgrading is less than 10 minutes old.
	 * - WP-CLI and this import's own step requests pass ($upgrading = 0).
	 * - While the file was touched within FSC_SWITCH_STALE_SECONDS (default
	 *   300): maintenance (one filemtime() call, nothing else).
	 * - Once stale: the plugin's recovery script decides (rolls the switch
	 *   back or ends maintenance after the commit point). Without the script
	 *   WordPress' own rule applies: maintenance ends 10 minutes after the
	 *   last touch.
	 * The file may be readable over the web: it holds no absolute path, no
	 * storage name and no token, only the plugin path relative to
	 * WP_CONTENT_DIR or the WordPress folder.
	 *
	 * @param string $recover PHP expression with the path of includes/fsc-recover.php, or ''.
	 * @return string
	 */
	public static function maintenance_code( $recover ) {
		$stale = '( defined( \'FSC_SWITCH_STALE_SECONDS\' ) && is_numeric( FSC_SWITCH_STALE_SECONDS ) ? max( ' . FSC_Recovery::STALE_MIN . ', min( ' . FSC_Recovery::STALE_MAX . ', (int) FSC_SWITCH_STALE_SECONDS ) ) : ' . FSC_Recovery::STALE_DEFAULT . ' )';
		return '<?php' . "\n"
			. '// ' . self::MAINTENANCE_MARK . ' (import switch). Removed automatically when the switch ends; safe to delete by hand afterwards.' . "\n"
			. '// When the switch makes no progress for a while, the plugin moves the previous files back (or finishes the switch) and the site loads again.' . "\n"
			. '$upgrading = time();' . "\n"
			. 'if ( ( defined( \'WP_CLI\' ) && WP_CLI ) || ( isset( $_POST[\'action\'], $_SERVER[\'SCRIPT_FILENAME\'] ) && \'fsc_import_step\' === $_POST[\'action\'] && \'admin-ajax.php\' === basename( $_SERVER[\'SCRIPT_FILENAME\'] ) ) ) {' . "\n"
			. "\t" . '$upgrading = 0;' . "\n"
			. '} elseif ( time() - (int) @filemtime( __FILE__ ) >= ' . $stale . ' ) {' . "\n"
			. "\t" . '$upgrading     = (int) @filemtime( __FILE__ );' . "\n"
			. "\t" . '$fsc_recover_f = ' . ( '' === $recover ? "''" : $recover ) . ';' . "\n"
			. "\t" . 'if ( \'\' !== $fsc_recover_f && is_file( $fsc_recover_f ) ) {' . "\n"
			. "\t\t" . '$fsc_recover_v = include $fsc_recover_f;' . "\n"
			. "\t\t" . 'if ( is_int( $fsc_recover_v ) ) {' . "\n"
			. "\t\t\t" . '$upgrading = $fsc_recover_v;' . "\n"
			. "\t\t" . '}' . "\n"
			. "\t" . '}' . "\n"
			. '}' . "\n";
	}

	/**
	 * PHP expression for the path of includes/fsc-recover.php, relative to
	 * WP_CONTENT_DIR or to the WordPress folder (__DIR__ of .maintenance),
	 * or '' when the plugin lives elsewhere (no automatic recovery then).
	 *
	 * @return string
	 */
	public static function recover_expr() {
		if ( ! defined( 'FSC_PLUGIN_FILE' ) ) {
			return '';
		}
		$dir  = dirname( FSC_PLUGIN_FILE );
		$real = realpath( $dir );
		if ( false === $real ) {
			return '';
		}
		$cands = array( $dir );
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$cands[] = rtrim( WP_PLUGIN_DIR, '/\\' ) . '/' . basename( $dir );
		}
		$roots = array();
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots['WP_CONTENT_DIR'] = rtrim( WP_CONTENT_DIR, '/\\' );
		}
		$roots['__DIR__'] = rtrim( ABSPATH, '/\\' );
		foreach ( $roots as $const => $root ) {
			foreach ( $cands as $c ) {
				if ( '' !== $root && 0 === strpos( $c, $root . '/' ) && realpath( $c ) === $real ) {
					$rel = substr( $c, strlen( $root ) ) . '/includes/fsc-recover.php';
					if ( ! preg_match( '#(^|/)\.\.?(/|$)#', $rel ) && realpath( $root . $rel ) === $real . '/includes/fsc-recover.php' ) {
						$expr = $const . ' . ' . var_export( $rel, true );
						return '__DIR__' === $const ? $expr : '( defined( \'' . $const . '\' ) ? ' . $expr . ' : \'\' )';
					}
				}
			}
		}
		return '';
	}

	/**
	 * Remove the .maintenance file if this plugin wrote it.
	 *
	 * @param string|null $f File (default ABSPATH/.maintenance).
	 */
	public static function maintenance_off( $f = null ) {
		$f = null === $f ? ABSPATH . '.maintenance' : $f;
		if ( is_file( $f ) && self::is_ours( $f ) ) {
			@unlink( $f );
		}
	}

	/**
	 * Whether a .maintenance file was written by this plugin.
	 *
	 * @param string $f File.
	 * @return bool
	 */
	private static function is_ours( $f ) {
		return false !== strpos( (string) @file_get_contents( $f, false, null, 0, 300 ), self::MAINTENANCE_MARK );
	}

	/**
	 * Refuse to start (or to extract) when the staging folder's filesystem
	 * has less free space than the archive's files need.
	 *
	 * @param FSC_Storage $st       Storage.
	 * @param array       $meta     Source meta.
	 * @param string|null $archive  Archive path.
	 * @param bool        $with_sql Also count the database dump (at start).
	 * @throws FSC_Exception When there is not enough space.
	 */
	public static function check_space( FSC_Storage $st, array $meta, $archive, $with_sql ) {
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( is_dir( $st->tmp_dir() ) ? $st->tmp_dir() : $st->dir() ) : false;
		if ( false === $free || null === $free ) {
			return;
		}
		$need = self::space_needed( $meta, null !== $archive && is_file( $archive ) ? (int) filesize( $archive ) : 0, $with_sql );
		if ( $free < $need ) {
			throw new FSC_Exception(
				sprintf(
					/* translators: 1: bytes needed, 2: bytes free */
					__( 'Not enough free disk space for the import: about %1$s are needed to unpack the archive next to the site, %2$s are free. Free up space (for example delete old archives) and start the import again. Nothing on the site was changed.', 'wp-free-site-cloner' ),
					size_format( $need ),
					size_format( $free )
				)
			);
		}
	}

	/**
	 * Bytes the staging folder (and, at start, the database dump) need,
	 * plus a 5% / 64 MB margin. Native archives record their file bytes;
	 * for foreign archives the archive size is the estimate.
	 *
	 * @param array $meta         Source meta.
	 * @param int   $archive_size Archive size.
	 * @param bool  $with_sql     Count the database dump too.
	 * @return float
	 */
	public static function space_needed( array $meta, $archive_size, $with_sql ) {
		$files = isset( $meta['files_bytes'] ) ? (int) $meta['files_bytes'] : 0;
		if ( $files <= 0 ) {
			$files = (int) $archive_size;
		}
		$sql = $with_sql && isset( $meta['sql_size'] ) ? (int) $meta['sql_size'] : 0;
		return (float) ( $files + $sql ) * 1.05 + 67108864;
	}

	/**
	 * Delete staging/old folders of other jobs older than $max_age (for the
	 * daily cleanup). An old-<job> folder marked RESTORE-FAILED is kept.
	 *
	 * @param FSC_Storage $st      Storage.
	 * @param string|null $keep_id Job id to keep.
	 * @param int         $max_age Seconds.
	 * @return array Removed folder names.
	 */
	public static function purge_orphans( FSC_Storage $st, $keep_id, $max_age ) {
		$out = array();
		$tmp = $st->tmp_dir();
		foreach ( (array) @scandir( $tmp ) as $f ) {
			if ( ! is_string( $f ) || ! preg_match( '/^(stage|old)-([a-f0-9]{16})$/', $f, $m ) || $m[2] === $keep_id ) {
				continue;
			}
			$p = $tmp . '/' . $f;
			if ( is_link( $p ) || ! is_dir( $p ) || time() - (int) @filemtime( $p ) < $max_age || is_file( $p . '/RESTORE-FAILED.txt' ) ) {
				continue;
			}
			if ( FSC_Extractor::purge_tree( $p, microtime( true ) + 20 ) ) {
				$out[] = $f;
			}
		}
		return $out;
	}
}
