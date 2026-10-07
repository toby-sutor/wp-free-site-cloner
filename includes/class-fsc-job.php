<?php
/**
 * Job state stored as a JSON file, a lock against concurrent steps and a
 * time-budgeted step loop. No WordPress dependency.
 *
 * @package wp-free-site-cloner
 */

if ( ! class_exists( 'FSC_Exception' ) ) {
	/**
	 * Error with a message that is safe to show to the admin.
	 */
	class FSC_Exception extends Exception {
	}
}

/**
 * A resumable job. $data is saved atomically (temp file + rename) after every
 * unit of work, so a killed request loses at most one unit.
 */
class FSC_Job {

	/** Hard upper bound of the per-request time budget in seconds. */
	const MAX_BUDGET = 20;

	/** Log file size in bytes before it is rotated. */
	const LOG_MAX_BYTES = 1048576;

	/** A job interrupted this many times in a row at the same checkpoint fails (crash loop). */
	const MAX_CRASHES = 3;

	/** @var string */
	private $path;

	/** @var array */
	public $data = array();

	/** @var resource|null */
	private $lock_fp = null;

	/**
	 * Constructor.
	 *
	 * @param string $path Job file path.
	 * @param array  $data Job data.
	 */
	private function __construct( $path, array $data ) {
		$this->path = $path;
		$this->data = $data;
	}

	/**
	 * Create and save a new job.
	 *
	 * @param string $path Job file path.
	 * @param string $type Job type (export or import).
	 * @param array  $data Initial data.
	 * @return FSC_Job
	 */
	public static function create( $path, $type, array $data = array() ) {
		$now = time();
		$job = new self(
			$path,
			array_merge(
				array(
					'id'       => bin2hex( random_bytes( 8 ) ),
					'type'     => $type,
					'status'   => 'running',
					'phase'    => '',
					'progress' => 0,
					'error'    => null,
					'created'  => $now,
					'updated'  => $now,
					'last_progress_at' => $now,
					'in_step'  => false,
					'cursor'   => array(),
					'log_seq'  => 0,
				),
				$data
			)
		);
		@unlink( $job->log_path() );
		$job->save();
		return $job;
	}

	/**
	 * Load a job.
	 *
	 * @param string $path Job file path.
	 * @return FSC_Job|null Null when missing or unreadable.
	 */
	public static function load( $path ) {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$raw  = @file_get_contents( $path );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return null;
		}
		return new self( $path, $data );
	}

	/**
	 * Job file path.
	 *
	 * @return string
	 */
	public function path() {
		return $this->path;
	}

	/**
	 * Log file path (JSON lines next to the job file).
	 *
	 * @return string
	 */
	public function log_path() {
		return $this->path . '.log';
	}

	/**
	 * Atomically write the job file.
	 *
	 * @throws FSC_Exception When the file cannot be written.
	 */
	public function save() {
		$this->data['updated'] = time();
		$json                  = json_encode( $this->data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		$tmp                   = $this->path . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false === $json || false === @file_put_contents( $tmp, $json ) ) {
			@unlink( $tmp );
			throw new FSC_Exception( 'Cannot write the job file (disk full or not writable?).' );
		}
		if ( ! @rename( $tmp, $this->path ) ) {
			@unlink( $tmp );
			throw new FSC_Exception( 'Cannot replace the job file.' );
		}
	}

	/**
	 * Remove the job file, its log and lock file.
	 */
	public function delete() {
		$this->unlock();
		@unlink( $this->path );
		@unlink( $this->log_path() );
		@unlink( $this->path . '.lock' );
	}

	/**
	 * Take the exclusive step lock.
	 *
	 * @param int $wait_seconds How long to wait for a running step.
	 * @return bool
	 */
	public function lock( $wait_seconds = 0 ) {
		if ( $this->lock_fp ) {
			return true;
		}
		$fp = @fopen( $this->path . '.lock', 'c' );
		if ( ! $fp ) {
			return false;
		}
		$until = microtime( true ) + $wait_seconds;
		do {
			if ( flock( $fp, LOCK_EX | LOCK_NB ) ) {
				$this->lock_fp = $fp;
				return true;
			}
			if ( microtime( true ) >= $until ) {
				break;
			}
			usleep( 200000 );
		} while ( true );
		fclose( $fp );
		return false;
	}

	/**
	 * Release the lock.
	 */
	public function unlock() {
		if ( $this->lock_fp ) {
			flock( $this->lock_fp, LOCK_UN );
			fclose( $this->lock_fp );
			$this->lock_fp = null;
		}
	}

	/**
	 * Append a log line (kept in a separate append-only file so the job file stays small).
	 *
	 * @param string $message Message.
	 */
	public function log( $message ) {
		$this->data['log_seq'] = (int) $this->data['log_seq'] + 1;
		$line                  = json_encode(
			array(
				'seq'  => $this->data['log_seq'],
				'time' => time(),
				'msg'  => self::redact_paths( (string) $message ),
			),
			JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
		) . "\n";
		$file = $this->log_path();
		if ( is_file( $file ) && filesize( $file ) > self::LOG_MAX_BYTES ) {
			@rename( $file, $file . '.1' );
		}
		@file_put_contents( $file, $line, FILE_APPEND );
	}

	/**
	 * Log entries with a sequence number above $after_seq.
	 *
	 * @param int $after_seq Last sequence number the client has seen.
	 * @param int $limit     Maximum entries returned (newest kept).
	 * @return array List of array(seq, time, msg).
	 */
	public function log_since( $after_seq, $limit = 200 ) {
		$out  = array();
		$file = $this->log_path();
		if ( ! is_file( $file ) ) {
			return $out;
		}
		$fp = @fopen( $file, 'rb' );
		if ( ! $fp ) {
			return $out;
		}
		while ( ( $line = fgets( $fp ) ) !== false ) {
			$row = json_decode( $line, true );
			if ( is_array( $row ) && isset( $row['seq'] ) && $row['seq'] > $after_seq ) {
				$out[] = $row;
				if ( count( $out ) > $limit ) {
					array_shift( $out );
				}
			}
		}
		fclose( $fp );
		return $out;
	}

	/**
	 * Per-request time budget: min(20s, max_execution_time * 0.5), optionally lowered by the client.
	 *
	 * @param float|null $requested          Budget asked for by the client.
	 * @param int|null   $max_execution_time Defaults to the ini value; 0 means unlimited.
	 * @return float Seconds.
	 */
	public static function budget( $requested = null, $max_execution_time = null ) {
		if ( null === $max_execution_time ) {
			$max_execution_time = (int) ini_get( 'max_execution_time' );
		}
		$max = self::MAX_BUDGET;
		if ( $max_execution_time > 0 ) {
			$max = min( $max, $max_execution_time * 0.5 );
		}
		$max = max( 1.0, (float) $max );
		if ( null !== $requested && is_numeric( $requested ) && $requested > 0 ) {
			return max( 1.0, min( $max, (float) $requested ) );
		}
		return $max;
	}

	/**
	 * Run $unit repeatedly until it reports completion, the budget is used up
	 * or an error occurs. The job is saved after each unit. The caller holds the lock.
	 *
	 * The unit callback receives ($job, $deadline, $state) and returns true when the
	 * whole job is finished. $state['resumed_after_crash'] is true on the first unit
	 * of a request that follows a request which died mid-step. A unit can set
	 * $state['yield'] to end the request early (the next unit needs a fresh request).
	 *
	 * @param callable $unit   Unit of work.
	 * @param float    $budget Seconds.
	 * @return bool True when finished.
	 */
	public function run( $unit, $budget ) {
		$start    = microtime( true );
		$deadline = $start + $budget;
		$state    = array( 'resumed_after_crash' => ! empty( $this->data['in_step'] ) );
		$sig      = $this->progress_signature();
		if ( $state['resumed_after_crash'] ) {
			$this->log( 'Resuming after an interrupted request.' );
			// Crash loop: the same checkpoint keeps killing the request (time or memory limit).
			$same                       = isset( $this->data['crash_sig'] ) && $this->data['crash_sig'] === $sig;
			$this->data['crash_count']  = $same ? (int) $this->data['crash_count'] + 1 : 1;
			$this->data['crash_sig']    = $sig;
			if ( $this->data['crash_count'] >= self::MAX_CRASHES ) {
				$this->fail( sprintf( 'The server ended the request %d times in a row at the same point (probably a PHP time or memory limit). Check the PHP error log; raising memory_limit or max_execution_time may help.', $this->data['crash_count'] ) );
				$this->data['in_step'] = false;
				$this->save();
				return false;
			}
		} else {
			unset( $this->data['crash_count'], $this->data['crash_sig'] );
		}
		$this->data['in_step'] = true;
		$this->save();
		$done = false;
		try {
			do {
				$done = (bool) call_user_func_array( $unit, array( $this, $deadline, &$state ) );
				$state['resumed_after_crash'] = false;
				if ( $done ) {
					$this->data['status']      = 'done';
					$this->data['progress']    = 100;
					$this->data['finished_at'] = time();
				}
				$now = $this->progress_signature();
				if ( $now !== $sig ) {
					$this->data['last_progress_at'] = time();
					$sig                            = $now;
				}
				$this->save();
			} while ( ! $done && empty( $state['yield'] ) && microtime( true ) < $deadline && 'running' === $this->data['status'] );
		} catch ( Exception $e ) {
			$this->fail( self::public_message( $e ) );
		} catch ( Error $e ) {
			$this->fail( self::public_message( $e ) );
		}
		$this->data['in_step'] = false;
		$this->save();
		return $done;
	}

	/**
	 * Message for the admin: the text of an FSC_Exception, or a generic one
	 * with a correlation id for anything else (details go to the PHP error log
	 * only: they may hold paths, SQL or other internals).
	 *
	 * @param Exception|Error $e Error.
	 * @return string
	 */
	public static function public_message( $e ) {
		if ( $e instanceof FSC_Exception ) {
			return $e->getMessage();
		}
		$ref = bin2hex( random_bytes( 4 ) );
		error_log( sprintf( 'WP Free Site Cloner [ref %s]: %s: %s in %s:%d', $ref, get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return sprintf( 'Internal error (reference %s). The details were written to the PHP error log.', $ref );
	}

	/**
	 * Replace this site's absolute paths in a message by relative forms
	 * (WP_CONTENT_DIR -> "wp-content", ABSPATH -> "", FSC_STORAGE_DIR ->
	 * "[storage]") and hide the random private storage directory name.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function redact_paths( $message ) {
		$map = array();
		if ( defined( 'FSC_STORAGE_DIR' ) && is_string( FSC_STORAGE_DIR ) && strlen( FSC_STORAGE_DIR ) > 1 ) {
			$map[ rtrim( FSC_STORAGE_DIR, '/\\' ) ] = '[storage]';
		}
		if ( defined( 'WP_CONTENT_DIR' ) && strlen( WP_CONTENT_DIR ) > 1 ) {
			$map[ rtrim( WP_CONTENT_DIR, '/\\' ) ] = 'wp-content';
		}
		if ( defined( 'ABSPATH' ) && strlen( ABSPATH ) > 1 ) {
			$map[ rtrim( ABSPATH, '/\\' ) . '/' ] = '';
		}
		$message = '' === $message || empty( $map ) ? $message : strtr( $message, $map );
		// The random storage directory name is the only protection on servers that ignore .htaccess.
		return (string) preg_replace( '/private-[0-9a-f]{32}/', 'private-***', $message );
	}

	/**
	 * Fingerprint of everything that moves when work is done (phase, percent, cursor).
	 *
	 * @return string
	 */
	public function progress_signature() {
		return md5( (string) json_encode( array( $this->data['phase'], $this->data['progress'], $this->data['status'], $this->data['cursor'] ), JSON_PARTIAL_OUTPUT_ON_ERROR ) );
	}

	/**
	 * Ordered step list with states for the UI.
	 *
	 * @param array  $labels  key => label, in order.
	 * @param string $current Key of the current step.
	 * @param string $status  Job status (running, done, error).
	 * @param array  $skipped key => note for skipped steps.
	 * @return array List of array( key, label, state[, note] ).
	 */
	public static function build_steps( array $labels, $current, $status, array $skipped = array() ) {
		$keys = array_keys( $labels );
		$cur  = array_search( $current, $keys, true );
		if ( false === $cur ) {
			$cur = count( $keys ) - 1;
		}
		$out = array();
		foreach ( $keys as $i => $key ) {
			if ( 'done' === $status || $i < $cur ) {
				$state = isset( $skipped[ $key ] ) ? 'skipped' : 'done';
			} elseif ( $i === $cur ) {
				$state = 'error' === $status ? 'failed' : ( isset( $skipped[ $key ] ) ? 'skipped' : 'active' );
			} else {
				$state = 'pending';
			}
			$step = array(
				'key'   => $key,
				'label' => $labels[ $key ],
				'state' => $state,
			);
			if ( 'skipped' === $state ) {
				$step['note'] = (string) $skipped[ $key ];
			}
			$out[] = $step;
		}
		return $out;
	}

	/**
	 * Mark the job as failed.
	 *
	 * @param string $message Error.
	 */
	public function fail( $message ) {
		$message                   = self::redact_paths( (string) $message );
		$this->data['status']      = 'error';
		$this->data['error']       = $message;
		$this->data['finished_at'] = time();
		$this->log( 'ERROR: ' . $message );
	}

	/**
	 * Get a cursor value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data['cursor'] ) ? $this->data['cursor'][ $key ] : $default;
	}

	/**
	 * Set a cursor value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function set( $key, $value ) {
		$this->data['cursor'][ $key ] = $value;
	}
}
