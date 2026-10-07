<?php
/**
 * Dev-only headless driver: runs a full export or import in-process by
 * looping the same step functions the AJAX endpoints use.
 *
 * Usage (plugin must be active):
 *   wp eval-file tests/e2e/drive.php export
 *   wp eval-file tests/e2e/drive.php import <archive name in fsc-storage | path to archive>
 *   wp eval-file tests/e2e/drive.php resume
 *   wp eval-file tests/e2e/drive.php status
 *   wp eval-file tests/e2e/drive.php cancel
 *
 * Arguments can also come from the environment: FSC_MODE, FSC_ARCHIVE.
 * Options (environment):
 *   FSC_BUDGET=<seconds>      per-step budget (default 5)
 *   FSC_STOP_AFTER=<n>        stop cleanly after n steps (exit 3), job stays resumable
 *   FSC_CRASH_AFTER_UNITS=<n> exit(9) after the n-th unit of a step ran but before its checkpoint was saved
 *   FSC_CRASH_PHASE=<phase>   only count units that start in this phase towards FSC_CRASH_AFTER_UNITS
 *   FSC_TRACE=1               print "FSC_STEP <json>" (job response without log) after every step
 *   FSC_UNITS_PER_STEP=<n>    end every step after n units (more, smaller steps; for traces)
 *   FSC_SPLIT_FINALIZE=1      import: exit 0 after the swap; run "resume" in a new process
 *   FSC_CRASH_SWITCH_AT=<n>   import: exit(9) at the n-th point of the file switch where a killed
 *                             request could stop (after a journal record, rename, copied file)
 *   FSC_FAIL_SWITCH_AT=<n>    import: throw an error at the n-th such point (tests the rollback)
 *   FSC_CRASH_AFTER_RENAME=1  import: exit(9) right after the RENAME TABLE, before the job records it
 *   FSC_LOSE_DB_AFTER_RENAME=1 import: right after the RENAME TABLE the server drops the connection and the
 *                             statement counts as failed (like "lost connection during query")
 *   FSC_CRASH_IN_CONS=<n>     import: exit(9) right after the n-th constraint ALTER of finalize
 *   FSC_QUIET=1               no log lines, only the final result line
 * The last output line is "FSC_RESULT <json>" (job status). Exit code 0 on success, 1 on error.
 *
 * @package wp-free-site-cloner
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this with: wp eval-file tests/e2e/drive.php <mode>\n" );
	exit( 1 );
}
if ( ! class_exists( 'FSC_Plugin' ) ) {
	fwrite( STDERR, "WP Free Site Cloner is not active.\n" );
	exit( 1 );
}

$fsc_args   = isset( $args ) && is_array( $args ) ? $args : array();
$fsc_mode   = isset( $fsc_args[0] ) ? $fsc_args[0] : (string) getenv( 'FSC_MODE' );
$fsc_arch   = isset( $fsc_args[1] ) ? $fsc_args[1] : (string) getenv( 'FSC_ARCHIVE' );
$fsc_budget = getenv( 'FSC_BUDGET' ) ? (float) getenv( 'FSC_BUDGET' ) : 5.0;
$fsc_stop   = (int) getenv( 'FSC_STOP_AFTER' );
$fsc_crash  = (int) getenv( 'FSC_CRASH_AFTER_UNITS' );
$fsc_split  = '1' === getenv( 'FSC_SPLIT_FINALIZE' );
$fsc_quiet  = '1' === getenv( 'FSC_QUIET' );
$fsc_cphase = (string) getenv( 'FSC_CRASH_PHASE' );
$fsc_trace  = '1' === getenv( 'FSC_TRACE' );
$fsc_ups    = (int) getenv( 'FSC_UNITS_PER_STEP' );
$fsc_sw_at  = (int) getenv( 'FSC_CRASH_SWITCH_AT' );
$fsc_sw_err = (int) getenv( 'FSC_FAIL_SWITCH_AT' );
if ( '1' === getenv( 'FSC_CRASH_AFTER_RENAME' ) ) {
	FSC_DB_Import::$rename_hook = function () {
		echo "Simulated crash right after the RENAME TABLE (FSC_CRASH_AFTER_RENAME).\n";
		exit( 9 );
	};
}
if ( '1' === getenv( 'FSC_LOSE_DB_AFTER_RENAME' ) ) {
	FSC_DB_Import::$rename_hook = function () {
		global $wpdb;
		echo "Simulated lost connection during the RENAME TABLE (FSC_LOSE_DB_AFTER_RENAME).\n";
		// The server ends this connection; the handle stays, dead, as after a network failure.
		@mysqli_query( $wpdb->dbh, 'KILL ' . (int) mysqli_thread_id( $wpdb->dbh ) );
		throw new FSC_Exception( 'Database error [2013] Lost connection to server during query. Statement: RENAME TABLE ...' );
	};
}
if ( (int) getenv( 'FSC_CRASH_IN_CONS' ) > 0 ) {
	$fsc_cons_n               = 0;
	$fsc_cons_at              = (int) getenv( 'FSC_CRASH_IN_CONS' );
	FSC_DB_Import::$cons_hook = function () use ( &$fsc_cons_n, $fsc_cons_at ) {
		if ( ++$fsc_cons_n === $fsc_cons_at ) {
			echo "Simulated crash after constraint ALTER $fsc_cons_n (FSC_CRASH_IN_CONS).\n";
			exit( 9 );
		}
	};
}
if ( $fsc_sw_at > 0 || $fsc_sw_err > 0 ) {
	$fsc_sw_n                  = 0;
	FSC_Extractor::$crash_hook = function () use ( &$fsc_sw_n, $fsc_sw_at, $fsc_sw_err ) {
		++$fsc_sw_n;
		if ( $fsc_sw_n === $fsc_sw_at ) {
			echo "Simulated crash at switch point $fsc_sw_n (FSC_CRASH_SWITCH_AT).\n";
			exit( 9 );
		}
		if ( $fsc_sw_n === $fsc_sw_err ) {
			throw new FSC_Exception( "Simulated switch failure at point $fsc_sw_n (FSC_FAIL_SWITCH_AT)." );
		}
	};
}
$fsc_plugin = FSC_Plugin::instance();
$fsc_st     = $fsc_plugin->storage();
$fsc_seq    = 0;

$fsc_print = function ( array $resp ) use ( &$fsc_seq, $fsc_quiet ) {
	foreach ( $resp['log'] as $line ) {
		if ( $line['seq'] > $fsc_seq ) {
			$fsc_seq = $line['seq'];
			if ( ! $fsc_quiet ) {
				echo gmdate( 'H:i:s', $line['time'] ) . ' [' . $line['seq'] . '] ' . $line['msg'] . "\n";
			}
		}
	}
};

$fsc_finish = function ( $job ) use ( $fsc_plugin, $fsc_print, &$fsc_seq ) {
	$resp = $fsc_plugin->job_response( $job, $fsc_seq );
	$fsc_print( $resp );
	unset( $resp['log'] );
	echo 'FSC_RESULT ' . wp_json_encode( $resp, JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 'error' === $resp['status'] ? 1 : 0 );
};

$fsc_trace_out = function ( array $resp ) use ( $fsc_trace ) {
	if ( $fsc_trace ) {
		unset( $resp['log'] );
		echo 'FSC_STEP ' . wp_json_encode( $resp, JSON_UNESCAPED_SLASHES ) . "\n";
	}
};

$fsc_loop = function ( $job ) use ( $fsc_plugin, $fsc_st, $fsc_budget, $fsc_stop, $fsc_crash, $fsc_cphase, $fsc_split, $fsc_print, $fsc_trace_out, $fsc_finish, $fsc_ups, &$fsc_seq ) {
	$steps = 0;
	while ( 'running' === $job->data['status'] ) {
		if ( $fsc_stop && $steps >= $fsc_stop ) {
			echo "Stopped after $steps steps (FSC_STOP_AFTER). Continue with: wp eval-file tests/e2e/drive.php resume\n";
			$resp = $fsc_plugin->job_response( $job, $fsc_seq );
			unset( $resp['log'] );
			echo 'FSC_RESULT ' . wp_json_encode( $resp, JSON_UNESCAPED_SLASHES ) . "\n";
			exit( 3 );
		}
		++$steps;
		if ( 'import' === $job->data['type'] && 'finalize' === $job->data['phase'] ) {
			if ( $fsc_split ) {
				echo "Swap done (FSC_SPLIT_FINALIZE). Continue with: wp eval-file tests/e2e/drive.php resume\n";
				exit( 0 );
			}
			// In-process continuation: forget cached options of the pre-swap database.
			wp_cache_flush();
			if ( isset( $GLOBALS['wp_rewrite'] ) ) {
				$GLOBALS['wp_rewrite']->init();
			}
		}
		if ( $fsc_crash || $fsc_ups > 0 ) {
			if ( ! $job->lock( 30 ) ) {
				fwrite( STDERR, "Lock busy.\n" );
				exit( 1 );
			}
			$units  = 0;
			$cunits = 0;
			$job->run(
				function ( $job, $deadline, &$state ) use ( $fsc_st, &$units, &$cunits, $fsc_crash, $fsc_cphase, $fsc_ups ) {
					$phase = $job->data['phase'];
					$done  = 'export' === $job->data['type']
						? FSC_Exporter::unit( $job, $fsc_st, $deadline )
						: FSC_Importer::unit( $job, $fsc_st, $deadline, $state );
					++$units;
					if ( $fsc_ups > 0 && $units >= $fsc_ups ) {
						$state['yield'] = true;
					}
					if ( $fsc_crash && ( '' === $fsc_cphase || $fsc_cphase === $phase ) && ++$cunits >= $fsc_crash ) {
						// The unit's work is done but its checkpoint is never saved, like a request killed mid-step.
						echo "Simulated crash in phase $phase after unit $units (FSC_CRASH_AFTER_UNITS).\n";
						exit( 9 );
					}
					return $done;
				},
				$fsc_budget
			);
			$job->unlock();
			$fsc_print( $fsc_plugin->job_response( $job, $fsc_seq ) );
			$fsc_trace_out( $fsc_plugin->job_response( $job, $fsc_seq ) );
			continue;
		}
		try {
			$resp = $fsc_plugin->run_step( $job, $fsc_budget );
		} catch ( FSC_Exception $e ) {
			if ( 423 === $e->getCode() ) {
				sleep( 1 );
				continue;
			}
			fwrite( STDERR, 'Step failed: ' . $e->getMessage() . "\n" );
			exit( 1 );
		}
		$fsc_print( $resp );
		$fsc_trace_out( $resp );
		$fresh = FSC_Job::load( $job->path() );
		if ( ! $fresh ) {
			fwrite( STDERR, "Job file vanished.\n" );
			exit( 1 );
		}
		$job = $fresh;
	}
	$fsc_finish( $job );
};

try {
	switch ( $fsc_mode ) {
		case 'export':
			$fsc_plugin->clear_previous( true );
			$fsc_job = FSC_Exporter::start( $fsc_st );
			$fsc_loop( $fsc_job );
			break;

		case 'import':
			if ( '' === $fsc_arch ) {
				fwrite( STDERR, "Usage: drive.php import <archive>\n" );
				exit( 1 );
			}
			$fsc_name = $fsc_arch;
			if ( is_file( $fsc_arch ) && null === $fsc_st->resolve( basename( $fsc_arch ) ) ) {
				$fsc_st->ensure();
				$fsc_name = basename( $fsc_arch );
				if ( ! FSC_Storage::is_valid_name( $fsc_name ) || ! copy( $fsc_arch, $fsc_st->dir() . '/' . $fsc_name ) ) {
					fwrite( STDERR, "Cannot copy the archive into storage.\n" );
					exit( 1 );
				}
				echo "Copied $fsc_arch into storage as $fsc_name\n";
			} elseif ( is_file( $fsc_arch ) ) {
				$fsc_name = basename( $fsc_arch );
			}
			$fsc_info = FSC_Importer::inspect( $fsc_st, $fsc_name );
			echo 'Source: ' . $fsc_info['meta']['format'] . ' from ' . $fsc_info['meta']['home'] . ' (prefix ' . $fsc_info['meta']['prefix'] . ")\n";
			$fsc_plugin->clear_previous( true );
			$fsc_res = FSC_Importer::start( $fsc_st, $fsc_name );
			if ( ! FSC_Importer::check_token( FSC_Job::load( $fsc_st->job_path() ), $fsc_res['token'] ) ) {
				fwrite( STDERR, "Token check failed.\n" );
				exit( 1 );
			}
			$fsc_loop( $fsc_res['job'] );
			break;

		case 'resume':
			$fsc_job = $fsc_plugin->current_job();
			if ( ! $fsc_job ) {
				fwrite( STDERR, "No job to resume.\n" );
				exit( 1 );
			}
			$fsc_loop( $fsc_job );
			break;

		case 'status':
			$fsc_job = $fsc_plugin->current_job();
			if ( ! $fsc_job ) {
				echo "FSC_RESULT null\n";
				exit( 0 );
			}
			$fsc_finish( $fsc_job );
			break;

		case 'cancel':
			echo 'FSC_RESULT ' . wp_json_encode( array( 'cancelled' => $fsc_plugin->cancel() ) ) . "\n";
			exit( 0 );

		default:
			fwrite( STDERR, "Usage: wp eval-file tests/e2e/drive.php export|import <archive>|resume|status|cancel\n" );
			exit( 1 );
	}
} catch ( Exception $e ) {
	fwrite( STDERR, 'Error: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
