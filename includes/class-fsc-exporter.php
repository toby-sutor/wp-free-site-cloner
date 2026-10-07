<?php
/**
 * Export orchestration: DB dump, file scan, tar assembly.
 *
 * Phases: db -> scan -> archive (manifest + SQL) -> files -> checksum -> finish.
 * The checksum phase first patches the manifest's archive_size with the final
 * size (known arithmetically once all files are written), then hashes the
 * archive in segments; finish appends the fsc-checksum.json trailer and the
 * end blocks and renames the .part file.
 *
 * @package wp-free-site-cloner
 */

/**
 * Exporter (requires WordPress).
 */
class FSC_Exporter {

	/** Bytes copied per unit of work. */
	const UNIT_BYTES = 4194304;

	/** Entries per unit of work. */
	const UNIT_ENTRIES = 200;

	/**
	 * Human-readable phase labels.
	 *
	 * @return array
	 */
	public static function phase_labels() {
		return array(
			'db'      => __( 'Exporting database', 'wp-free-site-cloner' ),
			'scan'    => __( 'Scanning files', 'wp-free-site-cloner' ),
			'archive' => __( 'Adding database to archive', 'wp-free-site-cloner' ),
			'files'    => __( 'Adding files to archive', 'wp-free-site-cloner' ),
			'checksum' => __( 'Computing checksums', 'wp-free-site-cloner' ),
			'finish'   => __( 'Finishing archive', 'wp-free-site-cloner' ),
			'done'    => __( 'Export complete', 'wp-free-site-cloner' ),
		);
	}

	/**
	 * User-facing steps in order.
	 *
	 * @return array key => label.
	 */
	public static function step_labels() {
		return array(
			'database' => __( 'Export database', 'wp-free-site-cloner' ),
			'files'    => __( 'Add files', 'wp-free-site-cloner' ),
			'checksum' => __( 'Compute checksums', 'wp-free-site-cloner' ),
			'finish'   => __( 'Finish archive', 'wp-free-site-cloner' ),
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
			'db'       => 'database',
			'scan'     => 'files',
			'archive'  => 'files',
			'files'    => 'files',
			'checksum' => 'checksum',
			'finish'   => 'finish',
		);
		return isset( $map[ $phase ] ) ? $map[ $phase ] : 'finish';
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
		$detail   = '';
		$progress = null;
		switch ( $phase ) {
			case 'db':
				$db = $job->get( 'db' );
				if ( is_array( $db ) ) {
					$progress = array(
						'done'  => (int) $db['rows_done'],
						'total' => (int) $db['rows_total'],
						'unit'  => 'rows',
					);
					if ( $db['ti'] < count( $db['tables'] ) ) {
						$t    = $db['tables'][ $db['ti'] ];
						$done = is_array( $db['table'] ) ? (int) $db['table']['offset'] : 0;
						if ( $t['rows'] > 0 && $done <= $t['rows'] ) {
							/* translators: 1: table, 2: rows done, 3: estimated rows */
							$detail = sprintf( __( 'Exporting table %1$s: %2$s of %3$s rows', 'wp-free-site-cloner' ), $t['name'], number_format_i18n( $done ), number_format_i18n( $t['rows'] ) );
						} else {
							/* translators: 1: table, 2: rows done */
							$detail = sprintf( __( 'Exporting table %1$s: %2$s rows', 'wp-free-site-cloner' ), $t['name'], number_format_i18n( $done ) );
						}
					}
				}
				break;
			case 'scan':
				$c = $job->get( 'scan' );
				if ( is_array( $c ) ) {
					$progress = array(
						'done'  => (int) $c['files'],
						'total' => 0,
						'unit'  => 'files',
					);
					/* translators: 1: files found, 2: size */
					$detail = sprintf( __( 'Scanning files: %1$s found (%2$s)', 'wp-free-site-cloner' ), number_format_i18n( $c['files'] ), self::bytes( $c['bytes'] ) );
				}
				break;
			case 'archive':
				$entry    = $job->get( 'entry' );
				$db       = $job->get( 'db' );
				$total    = is_array( $entry ) ? (int) $entry['size'] : ( is_array( $db ) ? (int) $db['size'] : 0 );
				$done     = is_array( $entry ) ? (int) $entry['done'] : 0;
				$progress = array(
					'done'  => $done,
					'total' => $total,
					'unit'  => 'bytes',
				);
				/* translators: 1: bytes done, 2: total */
				$detail = sprintf( __( 'Adding the database dump to the archive: %1$s of %2$s', 'wp-free-site-cloner' ), self::bytes( $done ), self::bytes( $total ) );
				break;
			case 'files':
				$scan     = $job->get( 'scan' );
				$progress = array(
					'done'  => (int) $job->get( 'files_done', 0 ),
					'total' => (int) $scan['files'],
					'unit'  => 'files',
				);
				/* translators: 1: files done, 2: files total, 3: bytes done, 4: bytes total */
				$detail = sprintf( __( 'Adding files: %1$s of %2$s (%3$s of %4$s)', 'wp-free-site-cloner' ), number_format_i18n( $progress['done'] ), number_format_i18n( $progress['total'] ), self::bytes( $job->get( 'bytes_done', 0 ) ), self::bytes( $scan['bytes'] ) );
				break;
			case 'checksum':
				$c     = $job->get( 'checksum' );
				$total = is_array( $c ) ? (int) $c['covered'] : (int) $job->get( 'tar_size', 0 );
				$done  = is_array( $c ) ? min( $total, (int) $c['next'] * (int) $c['segment_bytes'] ) : 0;
				$progress = array(
					'done'  => $done,
					'total' => $total,
					'unit'  => 'bytes',
				);
				/* translators: 1: bytes done, 2: total */
				$detail = sprintf( __( 'Computing checksums: %1$s of %2$s', 'wp-free-site-cloner' ), self::bytes( $done ), self::bytes( $total ) );
				break;
			case 'finish':
				$detail = __( 'Writing the checksum list and closing the archive', 'wp-free-site-cloner' );
				break;
		}
		if ( 'done' === $d['status'] && ! empty( $d['result']['archive'] ) ) {
			$progress = null;
			/* translators: 1: archive name, 2: size */
			$detail = sprintf( __( 'Archive ready: %1$s (%2$s)', 'wp-free-site-cloner' ), $d['result']['archive'], self::bytes( $d['result']['size'] ) );
		}
		return array(
			'steps'         => FSC_Job::build_steps( self::step_labels(), self::step_of( $phase ), $d['status'], isset( $d['skipped_steps'] ) ? (array) $d['skipped_steps'] : array() ),
			'step_detail'   => $detail,
			'step_progress' => $progress,
		);
	}

	/**
	 * Human size with one decimal from 1 GB up.
	 *
	 * @param int|float $b Bytes.
	 * @return string
	 */
	public static function bytes( $b ) {
		$b = (float) $b;
		if ( $b < 1 ) {
			/* translators: zero bytes */
			return __( '0 B', 'wp-free-site-cloner' );
		}
		return (string) size_format( $b, $b >= 1073741824 ? 1 : 0 );
	}

	/**
	 * Directories and files excluded from the export, relative to wp-content.
	 *
	 * @return array
	 */
	public static function excludes() {
		$ex = FSC_File_Scanner::default_excludes();
		// This plugin: the destination has its own copy (a dev checkout would add vendor/ and tests).
		$dir = dirname( plugin_basename( FSC_PLUGIN_FILE ) );
		if ( '.' !== $dir && '' !== $dir ) {
			$ex[] = 'plugins/' . $dir;
		}
		$content = wp_normalize_path( WP_CONTENT_DIR ) . '/';
		$own     = rtrim( wp_normalize_path( FSC_PLUGIN_DIR ), '/' );
		if ( 0 === strpos( $own, $content ) ) {
			$ex[] = substr( $own, strlen( $content ) );
		}
		$ex = array_values( array_unique( $ex ) );
		/**
		 * Filter the paths (relative to wp-content) excluded from exports.
		 *
		 * @param array $ex Relative paths.
		 */
		return (array) apply_filters( 'fsc_export_excludes', $ex );
	}

	/**
	 * Create an export job.
	 *
	 * @param FSC_Storage $st Storage.
	 * @return FSC_Job
	 * @throws FSC_Exception On failed preflight checks.
	 */
	public static function start( FSC_Storage $st ) {
		if ( is_multisite() ) {
			throw new FSC_Exception( __( 'Multisite networks are not supported.', 'wp-free-site-cloner' ) );
		}
		$st->ensure();
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$name = FSC_Storage::new_archive_name( $host );
		$job  = FSC_Job::create(
			$st->job_path(),
			'export',
			array(
				'phase'   => 'db',
				'archive' => $name,
			)
		);
		FSC_DB::session();
		FSC_DB_Export::init( $job, $st->tmp_file( $job->data['id'], 'sql' ) );
		$status = FSC_DB_Export::table_status( wp_list_pluck( $job->get( 'db' )['tables'], 'name' ) );
		$est    = 0;
		foreach ( $status as $s ) {
			$est += $s['bytes'];
		}
		$free = $st->free_space();
		if ( null !== $free && $free < $est * 2 ) {
			$job->delete();
			$st->clean_tmp( $job->data['id'] );
			/* translators: 1: bytes needed, 2: bytes free */
			throw new FSC_Exception( sprintf( __( 'Not enough disk space: about %1$s needed for the database alone, %2$s free.', 'wp-free-site-cloner' ), size_format( $est * 2 ), size_format( $free ) ) );
		}
		$job->log( sprintf( 'Export started: %s', $name ) );
		$job->save();
		return $job;
	}

	/**
	 * One unit of export work (called by FSC_Job::run()).
	 *
	 * @param FSC_Job     $job      Job.
	 * @param FSC_Storage $st       Storage.
	 * @param float       $deadline Deadline.
	 * @return bool True when the export is complete.
	 * @throws FSC_Exception On errors.
	 */
	public static function unit( FSC_Job $job, FSC_Storage $st, $deadline ) {
		$id   = $job->data['id'];
		$sql  = $st->tmp_file( $id, 'sql' );
		$list = $st->tmp_file( $id, 'list' );
		$part = $st->dir() . '/' . $job->data['archive'] . '.part';

		switch ( $job->data['phase'] ) {
			case 'db':
				FSC_DB::session();
				if ( FSC_DB_Export::step( $job, $sql ) ) {
					$db = $job->get( 'db' );
					$job->log( sprintf( 'Database exported: %d rows, %s.', $db['rows_done'], size_format( $db['size'] ) ) );
					$job->data['phase'] = 'scan';
					$job->set( 'scan', FSC_File_Scanner::new_cursor() );
				}
				$job->data['progress'] = (int) floor( 25 * FSC_DB_Export::progress( $job->get( 'db' ) ) );
				return false;

			case 'scan':
				$cursor  = $job->get( 'scan' );
				$scanner = new FSC_File_Scanner( WP_CONTENT_DIR, $list, self::excludes() );
				$done    = $scanner->step( $cursor, $deadline );
				foreach ( array_slice( $scanner->messages, 0, 50 ) as $m ) {
					$job->log( $m );
				}
				$job->set( 'scan', $cursor );
				if ( $done ) {
					$job->log( sprintf( 'Found %d files (%s) in %d directories.', $cursor['files'], size_format( $cursor['bytes'] ), $cursor['dirs'] ) );
					$need = $cursor['bytes'] + (int) filesize( $sql ) + 1048576;
					$free = $st->free_space();
					if ( null !== $free && $free < $need ) {
						/* translators: 1: bytes needed, 2: bytes free */
						throw new FSC_Exception( sprintf( __( 'Not enough disk space for the archive: %1$s needed, %2$s free.', 'wp-free-site-cloner' ), size_format( $need ), size_format( $free ) ) );
					}
					$job->data['phase'] = 'archive';
					$job->set( 'tar_size', 0 );
					$job->set( 'entry', null );
				}
				$job->data['progress'] = 27;
				return false;

			case 'archive':
				$w     = new FSC_Tar_Writer( $part, $job->get( 'tar_size' ) );
				$entry = $job->get( 'entry' );
				if ( null === $entry ) {
					$json = wp_json_encode( self::manifest( $job, $sql ), JSON_UNESCAPED_SLASHES );
					$pos  = FSC_Integrity::size_offset_in( (string) $json );
					if ( null === $pos || 0 !== $w->size() || strlen( FSC_Tar::entry_headers( FSC_Source_Native::MANIFEST, strlen( $json ), 0 ) ) !== FSC_Tar::BLOCK ) {
						throw new FSC_Exception( 'Cannot write the archive manifest.' );
					}
					$w->add_string( FSC_Source_Native::MANIFEST, $json );
					$job->set( 'size_offset', FSC_Tar::BLOCK + $pos );
					$entry = $w->begin_file( $sql, FSC_Source_Native::SQL );
				}
				$done = $w->write_chunk( $entry, self::UNIT_BYTES );
				$job->set( 'tar_size', $w->size() );
				$job->set( 'entry', $done ? null : $entry );
				$w->close();
				if ( $done ) {
					$job->data['phase'] = 'files';
					$job->set( 'list_offset', 0 );
					$job->set( 'files_done', 0 );
					$job->set( 'bytes_done', 0 );
				}
				$job->data['progress'] = 28 + (int) floor( 7 * $entry['done'] / max( 1, $entry['size'] ) );
				return false;

			case 'files':
				self::files_unit( $job, $part, $list, $deadline );
				$scan                  = $job->get( 'scan' );
				$job->data['progress'] = 35 + (int) floor( 60 * min( 1, $job->get( 'bytes_done' ) / max( 1, $scan['bytes'] ) ) );
				if ( 'checksum' === $job->data['phase'] ) {
					$job->log( sprintf( 'Added %d files (%s).', $job->get( 'files_done' ), size_format( $job->get( 'bytes_done' ) ) ) );
					$job->set( 'checksum', null );
				}
				return false;

			case 'checksum':
				$c = $job->get( 'checksum' );
				if ( null === $c ) {
					// Final size is fixed now: patch it first so the hashes cover the final bytes.
					$covered = (int) $job->get( 'tar_size' );
					$final   = FSC_Integrity::final_size( $covered, FSC_Integrity::SEGMENT );
					if ( null !== $job->get( 'size_offset' ) ) {
						FSC_Integrity::patch_size( $part, (int) $job->get( 'size_offset' ), $final );
					}
					$c = array(
						'covered'       => $covered,
						'final'         => $final,
						'segment_bytes' => FSC_Integrity::SEGMENT,
						'next'          => 0,
						'segments'      => array(),
					);
					$job->set( 'checksum', $c );
					$job->log( sprintf( 'Archive size will be %s bytes; computing %d SHA-256 checksums.', number_format( $final ), FSC_Integrity::segment_count( $covered, $c['segment_bytes'] ) ) );
					$job->data['progress'] = 95;
					return false;
				}
				$n = FSC_Integrity::segment_count( $c['covered'], $c['segment_bytes'] );
				if ( $c['next'] < $n ) {
					$c['segments'][ $c['next'] ] = FSC_Integrity::hash_segment( $part, $c['next'], $c['segment_bytes'], $c['covered'] );
					++$c['next'];
					$job->set( 'checksum', $c );
				}
				if ( $c['next'] >= $n ) {
					$job->log( sprintf( 'Checksums computed: %d segments.', $n ) );
					$job->data['phase'] = 'finish';
				}
				$job->data['progress'] = 95 + (int) floor( 4 * $c['next'] / max( 1, $n ) );
				return false;

			case 'finish':
				$final = $st->dir() . '/' . $job->data['archive'];
				if ( ! is_file( $part ) && is_file( $final ) ) {
					// Renamed before an interruption.
					$size = (int) filesize( $final );
				} else {
					$c = $job->get( 'checksum' );
					// A job started by 0.9.0 reaches finish without checksums: end blocks only.
					$w = new FSC_Tar_Writer( $part, is_array( $c ) ? $c['covered'] : $job->get( 'tar_size' ) );
					if ( is_array( $c ) ) {
						$w->add_string( FSC_Integrity::TRAILER, FSC_Integrity::trailer_json( $c['covered'], $c['segments'], $c['segment_bytes'] ) );
					}
					$w->finish();
					$size = $w->size();
					$w->close();
					if ( is_array( $c ) && $size !== (int) $c['final'] ) {
						throw new FSC_Exception( sprintf( 'Internal error: the archive has %d bytes, the manifest says %d.', $size, $c['final'] ) );
					}
					if ( ! @rename( $part, $final ) ) {
						throw new FSC_Exception( __( 'Cannot rename the finished archive.', 'wp-free-site-cloner' ) );
					}
				}
				$st->clean_tmp( $id );
				$job->data['phase']  = 'done';
				$job->data['result'] = array(
					'archive' => $job->data['archive'],
					'size'    => $size,
				);
				$job->log( sprintf( 'Export complete: %s (%s).', $job->data['archive'], size_format( $size ) ) );
				return true;
		}
		throw new FSC_Exception( 'Unknown export phase.' );
	}

	/**
	 * Add list entries to the archive until the unit limits are hit.
	 *
	 * @param FSC_Job $job      Job.
	 * @param string  $part     Archive path.
	 * @param string  $list     List file.
	 * @param float   $deadline Deadline.
	 * @throws FSC_Exception On write errors.
	 */
	private static function files_unit( FSC_Job $job, $part, $list, $deadline ) {
		$w      = new FSC_Tar_Writer( $part, $job->get( 'tar_size' ) );
		$entry  = $job->get( 'entry' );
		$budget = self::UNIT_BYTES;
		$count  = 0;
		if ( null !== $entry ) {
			$before = $entry['done'];
			$done   = $w->write_chunk( $entry, $budget );
			$budget -= $entry['done'] - $before;
			$job->set( 'bytes_done', $job->get( 'bytes_done' ) + $entry['done'] - $before );
			if ( $done ) {
				$job->set( 'files_done', $job->get( 'files_done' ) + 1 );
				$entry = null;
			}
		}
		if ( null === $entry ) {
			$fp = fopen( $list, 'rb' );
			if ( ! $fp ) {
				throw new FSC_Exception( 'Cannot read the file list.' );
			}
			fseek( $fp, $job->get( 'list_offset' ) );
			while ( $budget > 0 && $count < self::UNIT_ENTRIES && microtime( true ) < $deadline ) {
				$line = fgets( $fp );
				if ( false === $line ) {
					$job->data['phase'] = 'checksum';
					break;
				}
				$job->set( 'list_offset', $job->get( 'list_offset' ) + strlen( $line ) );
				$row = FSC_File_Scanner::parse_line( $line );
				if ( ! $row || '' === $row['path'] ) {
					continue;
				}
				++$count;
				$name = 'wp-content/' . $row['path'];
				if ( 'D' === $row['type'] ) {
					$w->add_dir( $name, (int) @filemtime( WP_CONTENT_DIR . '/' . $row['path'] ) );
					continue;
				}
				try {
					$entry = $w->begin_file( WP_CONTENT_DIR . '/' . $row['path'], $name );
				} catch ( FSC_Exception $e ) {
					$job->log( sprintf( 'Skipped file that disappeared or became unreadable: %s', $row['path'] ) );
					continue;
				}
				$done    = $w->write_chunk( $entry, $budget );
				$budget -= $entry['done'];
				$job->set( 'bytes_done', $job->get( 'bytes_done' ) + $entry['done'] );
				if ( ! empty( $entry['short'] ) ) {
					$job->log( sprintf( 'File shrank while it was archived: %s', $row['path'] ) );
				}
				if ( ! $done ) {
					break;
				}
				$job->set( 'files_done', $job->get( 'files_done' ) + 1 );
				$entry = null;
			}
			fclose( $fp );
		}
		$job->set( 'entry', $entry );
		$job->set( 'tar_size', $w->size() );
		$w->close();
	}

	/**
	 * Archive manifest.
	 *
	 * @param FSC_Job $job Job.
	 * @param string  $sql SQL file.
	 * @return array
	 */
	private static function manifest( FSC_Job $job, $sql ) {
		global $wp_version;
		$wpdb    = FSC_DB::wpdb();
		$uploads = wp_upload_dir( null, false );
		$scan    = $job->get( 'scan' );
		$tables  = array();
		foreach ( $job->get( 'db' )['tables'] as $t ) {
			$tables[] = FSC_DB_Export::placeholder_name( $t['name'], $wpdb->prefix );
		}
		// Key order matters: archive_size must stay the third key (its digits sit at bytes 563..582).
		return array(
			'format'          => 'fsc',
			'format_version'  => 1,
			'archive_size'    => FSC_Integrity::SIZE_PLACEHOLDER,
			'plugin_version'  => FSC_VERSION,
			'created'         => gmdate( 'c' ),
			'wp_version'      => $wp_version,
			'php_version'     => PHP_VERSION,
			'mysql_version'   => $wpdb->db_version(),
			'home'            => get_option( 'home' ),
			'siteurl'         => get_option( 'siteurl' ),
			'abspath'         => ABSPATH,
			'content_dir'     => WP_CONTENT_DIR,
			'content_url'     => content_url(),
			'uploads_baseurl' => isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '',
			'table_prefix'    => $wpdb->prefix,
			'charset'         => $wpdb->charset,
			'collate'         => $wpdb->collate,
			'multisite'       => false,
			'tables'          => $tables,
			'file_count'      => (int) $scan['files'],
			'files_bytes'     => (int) $scan['bytes'],
			'sql_size'        => (int) filesize( $sql ),
			'sql_sha256'      => hash_file( 'sha256', $sql ),
		);
	}

	/**
	 * Remove the partial archive and temp files of an unfinished export.
	 *
	 * @param FSC_Job     $job Job.
	 * @param FSC_Storage $st  Storage.
	 */
	public static function cleanup( FSC_Job $job, FSC_Storage $st ) {
		if ( ! empty( $job->data['archive'] ) && FSC_Storage::is_valid_name( $job->data['archive'] ) ) {
			@unlink( $st->dir() . '/' . $job->data['archive'] . '.part' );
		}
		$st->clean_tmp( $job->data['id'] );
	}
}
