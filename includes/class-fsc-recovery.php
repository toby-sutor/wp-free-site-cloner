<?php
/**
 * Automatic recovery of an abandoned import switch. No WordPress dependency:
 * it runs from the .maintenance file this plugin writes (before WordPress
 * loads any plugin, see includes/fsc-recover.php) and from the plugin itself
 * (early on plugin load, wp_loaded, admin_init, the daily cron).
 *
 * A switch is abandoned when its job has not been saved for
 * stale_seconds() and no request holds the job lock. Then:
 * - before the commit point (RENAME TABLE not done): the journaled file moves
 *   are undone (pure file operations, the live database was never touched),
 *   .maintenance is removed and the job fails with "the site was not
 *   changed"; temp tables and the staging folder are removed later by the
 *   plugin (finish_switch()).
 * - after the commit point: the site is consistent (new files, new
 *   database); .maintenance is removed and finalize runs later from the
 *   plugin (flag file in the storage base).
 * Whether the RENAME happened is read from the job ("swapped"), or, when the
 * request died between "swapping" and "swapped", from the database: the
 * RENAME is atomic, so a remaining fsctmp_options table means it did not run.
 * commit_state() is the one place that decides this, also for the plugin
 * (failed steps, cancel, replace, clean-up).
 *
 * @package wp-free-site-cloner
 */

/**
 * Recovery of an abandoned switch.
 */
class FSC_Recovery {

	/** Seconds without progress after which a switch counts as abandoned (FSC_SWITCH_STALE_SECONDS overrides). */
	const STALE_DEFAULT = 300;

	/** Lower and upper bound for FSC_SWITCH_STALE_SECONDS. */
	const STALE_MIN = 10;
	const STALE_MAX = 3600;

	/** Failed attempts after which maintenance mode is ended anyway (the plugin keeps retrying). */
	const MAX_ATTEMPTS = 3;

	/** Seconds between two failed attempts. */
	const RETRY_AFTER = 30;

	/** Temporary table prefix of the import (FSC_DB_Import::TEMP). */
	const TEMP_PREFIX = 'fsctmp_';

	/** Flag file in the storage base while a switch may need recovery or finishing. */
	const FLAG = '.fsc-switch-pending';

	/** Results of recover(). */
	const NONE    = 'none';    // No switch to recover.
	const FRESH   = 'fresh';   // The switch is not abandoned (yet).
	const BUSY    = 'busy';    // A request holds the job lock.
	const PENDING = 'pending'; // Rollback started, continues with the next call.
	const RETRY   = 'retry';   // An attempt failed; tried again later.
	const DONE    = 'done';    // Site consistent, maintenance mode ended.
	const FAILED  = 'failed';  // Gave up for now: maintenance mode ended, site may be inconsistent.

	/** Answers of commit_state(). */
	const COMMITTED     = 'committed';     // The RENAME TABLE ran: the new database is live, files are never moved back.
	const NOT_COMMITTED = 'not_committed'; // It did not run: the switch can be rolled back.
	const UNKNOWN       = 'unknown';       // Cannot tell right now: nothing may be moved or removed.

	/**
	 * Seconds without progress after which a switch counts as abandoned.
	 *
	 * @return int
	 */
	public static function stale_seconds() {
		$s = defined( 'FSC_SWITCH_STALE_SECONDS' ) && is_numeric( FSC_SWITCH_STALE_SECONDS ) ? (int) FSC_SWITCH_STALE_SECONDS : self::STALE_DEFAULT;
		return max( self::STALE_MIN, min( self::STALE_MAX, $s ) );
	}

	/**
	 * Path of the flag file.
	 *
	 * @param FSC_Storage $st Storage.
	 * @return string
	 */
	public static function flag_path( FSC_Storage $st ) {
		return $st->base_dir() . '/' . self::FLAG;
	}

	/**
	 * Set or clear the flag file (best effort).
	 *
	 * @param FSC_Storage $st Storage.
	 * @param bool        $on Set (true) or clear.
	 */
	public static function flag( FSC_Storage $st, $on ) {
		if ( null !== $st->error() || ! is_dir( $st->base_dir() ) ) {
			return;
		}
		$f = self::flag_path( $st );
		if ( $on ) {
			if ( ! is_file( $f ) ) {
				@file_put_contents( $f, '' );
			}
		} elseif ( is_file( $f ) || is_link( $f ) ) {
			@unlink( $f );
		}
	}

	/**
	 * Entry point of the .maintenance file (see includes/fsc-recover.php).
	 *
	 * @param string $maintenance Path of the .maintenance file.
	 * @return int|null Value for WordPress' $upgrading: time() keeps the
	 *                  maintenance page, 0 lets the request load the site,
	 *                  null when storage is unusable (WordPress' own
	 *                  10-minute rule then applies).
	 */
	public static function from_maintenance( $maintenance ) {
		$st = FSC_Storage::default_storage();
		if ( null !== $st->error() ) {
			return null;
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}
		$r = self::recover(
			$st,
			WP_CONTENT_DIR,
			array(
				'stale'       => self::stale_seconds(),
				'deadline'    => microtime( true ) + 10,
				'maintenance' => $maintenance,
				'db'          => array( __CLASS__, 'db_temp_exists' ),
			)
		);
		if ( self::NONE === $r ) {
			// Our .maintenance without a switch behind it (job gone or finished).
			FSC_Importer::maintenance_off( $maintenance );
		}
		return in_array( $r, array( self::NONE, self::DONE, self::FAILED ), true ) ? 0 : time();
	}

	/**
	 * Recover an abandoned switch (idempotent; holds the job lock while working).
	 *
	 * @param FSC_Storage $st   Storage.
	 * @param string      $live Live wp-content directory.
	 * @param array       $o    stale (seconds), deadline (microtime), maintenance (file),
	 *                          db (callable: true = temp tables still exist, false = gone,
	 *                          null = cannot tell), now (timestamp, tests).
	 * @return string One of the result constants.
	 */
	public static function recover( FSC_Storage $st, $live, array $o ) {
		$job = FSC_Job::load( $st->job_path() );
		if ( ! self::candidate( $job ) ) {
			return self::NONE;
		}
		$now = isset( $o['now'] ) ? (int) $o['now'] : time();
		if ( empty( $job->data['recovery'] ) && 'running' === $job->data['status'] && $now - (int) $job->data['updated'] < (int) $o['stale'] ) {
			return self::FRESH;
		}
		if ( ! $job->lock( 0 ) ) {
			return self::BUSY;
		}
		try {
			$fresh = FSC_Job::load( $job->path() );
			if ( ! self::candidate( $fresh ) || $fresh->data['id'] !== $job->data['id'] ) {
				return self::NONE;
			}
			$job->data = $fresh->data;
			if ( empty( $job->data['recovery'] ) && 'running' === $job->data['status'] && $now - (int) $job->data['updated'] < (int) $o['stale'] ) {
				return self::FRESH;
			}
			if ( ! empty( $job->data['recover_attempts'] ) && $now - (int) $job->data['recover_last'] < self::RETRY_AFTER ) {
				return self::RETRY;
			}
			return self::work( $job, $st, $live, $o, $now );
		} finally {
			$job->unlock();
		}
	}

	/**
	 * Whether a job may hold a switch that needs recovery: an import past
	 * the switch plan that has not been cleaned up, or one that switched the
	 * database but was not finalized.
	 *
	 * @param FSC_Job|null $job Job.
	 * @return bool
	 */
	private static function candidate( $job ) {
		if ( ! $job || 'import' !== $job->data['type'] || ! in_array( $job->data['status'], array( 'running', 'error' ), true ) ) {
			return false;
		}
		$d = $job->data;
		if ( ! empty( $d['swapped'] ) ) {
			return 'running' === $d['status'];
		}
		if ( ! empty( $d['cleaned'] ) || ! empty( $d['recovered'] ) ) {
			return false;
		}
		$sw = isset( $d['cursor']['swap'] ) ? $d['cursor']['swap'] : null;
		return is_array( $sw ) && isset( $sw['stage'] );
	}

	/**
	 * Recovery under the job lock.
	 *
	 * @param FSC_Job     $job  Job (locked, fresh).
	 * @param FSC_Storage $st   Storage.
	 * @param string      $live Live wp-content.
	 * @param array       $o    Options (see recover()).
	 * @param int         $now  Now.
	 * @return string
	 */
	private static function work( FSC_Job $job, FSC_Storage $st, $live, array $o, $now ) {
		$id   = $job->data['id'];
		$idle = self::duration( (int) $o['stale'] );
		$file = isset( $o['maintenance'] ) ? $o['maintenance'] : null;
		self::flag( $st, true );
		try {
			if ( empty( $job->data['recovery'] ) ) {
				$job->data['recovery'] = 'started';
				$job->save();
			}
			$state = self::commit_state( $job, isset( $o['db'] ) ? $o['db'] : null );
			if ( self::UNKNOWN === $state ) {
				throw new FSC_Exception( 'Cannot reach the database to check whether the table switch completed.' );
			}
			if ( self::COMMITTED === $state ) {
				if ( 'finish' === $job->data['recovery'] ) {
					// Decided before; the clean-up is the plugin's (finish_switch()).
					FSC_Importer::maintenance_off( $file );
					return self::DONE;
				}
				$job->data['phase']    = 'finalize';
				$job->data['recovery'] = 'finish';
				FSC_Importer::maintenance_off( $file );
				$job->log( sprintf( 'The import was not continued for %s after the database switch. The new site is live; the remaining clean-up runs automatically (next page load or WP-Cron).', $idle ) );
				$job->save();
				return self::DONE;
			}
			$paths = FSC_Importer::switch_paths( $st, $id, $live );
			$sw    = $job->get( 'swap' );
			$msg   = sprintf( 'The import was interrupted during the switch and not continued for %s.', $idle );
			if ( 'running' === $job->data['status'] && ( ! is_array( $sw ) || ! in_array( $sw['stage'], array( 'rollback', 'rolled_back' ), true ) ) ) {
				// From now on a resumed step continues this rollback instead of moving forward.
				$sw          = is_array( $sw ) ? $sw : array();
				$sw['stage'] = 'rollback';
				$sw['error'] = $msg;
				$job->set( 'swap', $sw );
				$job->log( $msg . ' Moving the previous files back automatically.' );
			}
			if ( 'rollback' !== $job->data['recovery'] ) {
				$job->data['recovery'] = 'rollback';
				$job->save();
			}
			if ( null !== $paths ) {
				$jl = FSC_Extractor::journal_last( $paths['journal'] );
				if ( -1 !== $jl[0] && ! ( 0 === $jl[0] && 'z' === $jl[1] ) ) {
					$copied = false;
					if ( ! FSC_Extractor::switch_step( $paths, isset( $o['deadline'] ) ? $o['deadline'] : INF, true, $copied ) ) {
						$job->save();
						return self::PENDING;
					}
				}
			}
			FSC_Importer::maintenance_off( $file );
			$job->data['recovered'] = 'rollback';
			unset( $job->data['recover_attempts'], $job->data['recover_last'] );
			if ( 'running' === $job->data['status'] ) {
				$sw          = $job->get( 'swap' );
				$sw          = is_array( $sw ) ? $sw : array();
				$sw['stage'] = 'rolled_back';
				$job->set( 'swap', $sw );
				$job->fail( ( isset( $sw['error'] ) ? $sw['error'] : $msg ) . ' The previous files were moved back automatically; the site was not changed.' );
			} else {
				$job->log( 'Moved the previous files back into wp-content automatically.' );
			}
			$job->save();
			return self::DONE;
		} catch ( Throwable $e ) {
			$job->data['recover_attempts'] = (int) ( isset( $job->data['recover_attempts'] ) ? $job->data['recover_attempts'] : 0 ) + 1;
			$job->data['recover_last']     = $now;
			$why                           = FSC_Job::public_message( $e );
			$job->log( sprintf( 'Automatic recovery of the switch failed (attempt %d): %s', $job->data['recover_attempts'], $why ) );
			$out = self::RETRY;
			if ( $job->data['recover_attempts'] >= self::MAX_ATTEMPTS ) {
				FSC_Importer::maintenance_off( $file );
				if ( self::MAX_ATTEMPTS === $job->data['recover_attempts'] ) {
					$job->log( 'WARNING: Maintenance mode was ended although the switch could not be recovered; the site may be inconsistent. Recovery is retried by WP-Cron and on admin page loads; cancelling the import moves the previous files back where possible (files that cannot be moved stay in the storage folder tmp/old-' . $id . ').' );
				}
				$out = self::FAILED;
			}
			try {
				$job->save();
			} catch ( Throwable $e2 ) {
				// Disk full: the next attempt starts from the journal again.
				unset( $e2 );
			}
			return $out;
		}
	}

	/**
	 * "5 minutes" / "40 seconds".
	 *
	 * @param int $s Seconds.
	 * @return string
	 */
	private static function duration( $s ) {
		return $s >= 120 ? sprintf( '%d minutes', (int) round( $s / 60 ) ) : sprintf( '%d seconds', $s );
	}

	/**
	 * Whether the RENAME TABLE (commit point) of an import has run. Every
	 * path that may move files back or delete the staged files asks here.
	 * - "swapped" is set: committed.
	 * - "swapping" is not set (it is saved before the RENAME starts), or a
	 *   rollback was decided earlier: not committed.
	 * - "swapping" without "swapped": the database decides.
	 *   Temp tables gone: committed. The job is put on the finalize phase; a
	 *   job that failed meanwhile runs again, only its clean-up is left.
	 *   Temp tables still there: not committed. "swapping" is cleared first,
	 *   so the answer stays the same after the temp tables were dropped.
	 *   No answer, or the answer cannot be saved: unknown. The caller moves
	 *   and removes nothing and leaves the job to a later request.
	 *
	 * @param FSC_Job       $job Job (the caller holds its lock).
	 * @param callable|null $db  Returns true while the temp tables exist, false when they
	 *                           are gone, null when it cannot tell (see temp_state()).
	 * @return string COMMITTED, NOT_COMMITTED or UNKNOWN.
	 */
	public static function commit_state( FSC_Job $job, $db ) {
		$d = $job->data;
		if ( ! empty( $d['swapped'] ) ) {
			return self::COMMITTED;
		}
		if ( empty( $d['swapping'] ) || ! empty( $d['cleaned'] ) || ! empty( $d['recovered'] ) ) {
			return self::NOT_COMMITTED;
		}
		try {
			$exists = is_callable( $db ) ? call_user_func( $db ) : null;
		} catch ( Throwable $e ) {
			$exists = null;
		}
		if ( null === $exists ) {
			return self::UNKNOWN;
		}
		if ( $exists ) {
			$job->data['swapping'] = false;
			try {
				$job->save();
			} catch ( Throwable $e ) {
				// Not recorded: once the temp tables are dropped, they would read as a completed switch.
				$job->data['swapping'] = true;
				return self::UNKNOWN;
			}
			return self::NOT_COMMITTED;
		}
		$job->data['swapped'] = true;
		$job->data['phase']   = 'finalize';
		if ( 'error' === $d['status'] ) {
			$job->data['status'] = 'running';
			$job->data['error']  = null;
			unset( $job->data['finished_at'], $job->data['crash_count'], $job->data['crash_sig'] );
		}
		$job->log( 'The table swap had completed although the request that ran it did not record it; the imported database is live.' );
		try {
			$job->save();
		} catch ( Throwable $e ) {
			// The database gives the next request the same answer.
			unset( $e );
		}
		return self::COMMITTED;
	}

	/**
	 * Whether the temp tables of an import still exist (for commit_state()).
	 * A RENAME TABLE of temp tables that still waits in the server after its
	 * client lost the connection or was killed can complete later: that is
	 * "cannot tell" until the statement has ended.
	 *
	 * @param callable $rows Runs a statement: function ( $sql ) that returns the rows
	 *                       with numeric keys, or null when the statement failed.
	 * @return bool|null True: fsctmp_options exists; false: it is gone; null: cannot tell.
	 */
	public static function temp_state( $rows ) {
		$db   = call_user_func( $rows, 'SELECT DATABASE()' );
		$db   = isset( $db[0][0] ) ? (string) $db[0][0] : '';
		$list = call_user_func( $rows, 'SHOW FULL PROCESSLIST' );
		// Columns: Id, User, Host, db, Command, Time, State, Info. A list that cannot be read is skipped.
		foreach ( is_array( $list ) ? $list : array() as $r ) {
			$info = isset( $r[7] ) ? ltrim( (string) $r[7] ) : '';
			if ( 0 === stripos( $info, 'RENAME TABLE' ) && false !== strpos( $info, '`' . self::TEMP_PREFIX ) && ( '' === $db || ! isset( $r[3] ) || (string) $r[3] === $db ) ) {
				return null;
			}
		}
		$found = call_user_func( $rows, "SHOW TABLES LIKE '" . str_replace( '_', '\\_', self::TEMP_PREFIX ) . "options'" );
		return is_array( $found ) ? count( $found ) > 0 : null;
	}

	/**
	 * temp_state() read with mysqli and the DB_* constants (used from
	 * .maintenance, before WordPress has a database connection).
	 *
	 * @return bool|null Null when the database cannot be reached.
	 */
	public static function db_temp_exists() {
		if ( ! function_exists( 'mysqli_init' ) || ! defined( 'DB_HOST' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) ) {
			return null;
		}
		list( $host, $port, $socket ) = self::parse_db_host( (string) DB_HOST );
		mysqli_report( MYSQLI_REPORT_OFF );
		$m = mysqli_init();
		if ( ! $m ) {
			return null;
		}
		$flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? (int) MYSQL_CLIENT_FLAGS : 0;
		try {
			if ( ! @mysqli_real_connect( $m, $host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket, $flags ) ) {
				return null;
			}
			$out = self::temp_state(
				function ( $sql ) use ( $m ) {
					$r = @mysqli_query( $m, $sql );
					if ( ! $r instanceof mysqli_result ) {
						return null;
					}
					$rows = array();
					while ( is_array( $row = $r->fetch_row() ) ) {
						$rows[] = $row;
					}
					$r->free();
					return $rows;
				}
			);
			@mysqli_close( $m );
			return $out;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Split DB_HOST like wpdb does: "host", "host:port", "host:/socket",
	 * ":/socket", "[ipv6]:port".
	 *
	 * @param string $h DB_HOST.
	 * @return array array( host|null, port|null, socket|null ).
	 */
	public static function parse_db_host( $h ) {
		$socket = null;
		$port   = null;
		$p      = strpos( $h, ':/' );
		if ( false !== $p ) {
			$socket = substr( $h, $p + 1 );
			$h      = substr( $h, 0, $p );
		}
		if ( preg_match( '/^\[([^\]]+)\](?::(\d+))?$/', $h, $m ) ) {
			// mysqlnd wants IPv6 addresses in brackets.
			$h    = '[' . $m[1] . ']';
			$port = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : null;
		} elseif ( 1 === substr_count( $h, ':' ) ) {
			list( $h, $pt ) = explode( ':', $h );
			if ( '' !== $pt && ctype_digit( $pt ) ) {
				$port = (int) $pt;
			}
		}
		return array( '' === $h ? null : $h, $port, $socket );
	}
}
