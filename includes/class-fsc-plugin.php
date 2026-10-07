<?php
/**
 * Admin page registration, assets and AJAX routing. The AJAX contract is
 * documented in internal/API.md.
 *
 * @package wp-free-site-cloner
 */

/**
 * Plugin bootstrap and AJAX endpoints.
 */
class FSC_Plugin {

	const NONCE = 'fsc_admin';
	const PAGE  = 'wp-free-site-cloner';

	/** A running job untouched for this many seconds may be replaced. */
	const STALE_AFTER = 600;

	/** Daily cleanup cron hook. */
	const CRON = 'fsc_daily_cleanup';

	/** Unfinished uploads/exports and job temp files older than this are deleted by the cron. */
	const STALE_FILES_AGE = 86400;

	/** With automatic deletion off, archives older than this many days trigger the admin notice. */
	const NOTICE_DAYS = 7;

	/** @var FSC_Plugin|null */
	private static $instance = null;

	/** @var string|null */
	private $hook = null;

	/**
	 * Singleton.
	 *
	 * @return FSC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'wp_auth_check_load', array( $this, 'disable_auth_check_on_our_page' ), 10, 2 );
		$admin = array(
			'fsc_export_start'   => 'ajax_export_start',
			'fsc_export_step'    => 'ajax_export_step',
			'fsc_status'         => 'ajax_status',
			'fsc_archives'       => 'ajax_archives',
			'fsc_archive_delete' => 'ajax_archive_delete',
			'fsc_archives_delete_all' => 'ajax_archives_delete_all',
			'fsc_download'       => 'ajax_download',
			'fsc_upload_chunk'   => 'ajax_upload_chunk',
			'fsc_import_inspect' => 'ajax_import_inspect',
			'fsc_import_start'   => 'ajax_import_start',
			'fsc_cancel'         => 'ajax_cancel',
		);
		foreach ( $admin as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
		add_action( 'wp_ajax_fsc_import_step', array( $this, 'ajax_import_step' ) );
		add_action( 'wp_ajax_nopriv_fsc_import_step', array( $this, 'ajax_import_step' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FSC_PLUGIN_FILE ), array( $this, 'action_links' ) );
		add_action( self::CRON, array( $this, 'cron_cleanup' ) );
		add_action( 'admin_init', array( $this, 'schedule_cron' ) );
		add_action( 'admin_init', array( $this, 'maintain_switch' ) );
		add_filter( 'fsc_export_excludes', array( $this, 'export_excludes' ) );
		register_activation_hook( FSC_PLUGIN_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( FSC_PLUGIN_FILE, array( __CLASS__, 'deactivate' ) );
		$this->recover_on_load();
	}

	/**
	 * WP-CLI (drive.php resumes deliberately) or an import step request
	 * (it continues the switch itself): no automatic recovery there.
	 *
	 * @return bool
	 */
	private static function skip_auto_recovery() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['action'] ) && 'fsc_import_step' === $_POST['action'];
	}

	/**
	 * On plugin load, while the switch flag exists (one stat call per
	 * request otherwise): roll back an abandoned switch before the rest of
	 * the site runs on a half-switched wp-content. Normally the .maintenance
	 * file did this already; this covers sites where it could not be
	 * written. The remaining clean-up runs on wp_loaded.
	 */
	public function recover_on_load() {
		if ( self::skip_auto_recovery() ) {
			return;
		}
		try {
			$st = $this->storage();
			if ( null !== $st->error() || ! is_file( FSC_Recovery::flag_path( $st ) ) ) {
				return;
			}
			$this->recover_switch( $st, 5.0 );
			add_action( 'wp_loaded', array( $this, 'finish_on_load' ) );
		} catch ( Throwable $e ) {
			self::log_unexpected( $e, 'recover' );
		}
	}

	/**
	 * wp_loaded while the switch flag exists: finish a recovered switch.
	 */
	public function finish_on_load() {
		try {
			$this->finish_switch( $this->storage(), 3.0 );
		} catch ( Throwable $e ) {
			self::log_unexpected( $e, 'recover' );
		}
	}

	/**
	 * admin_init (not AJAX): recover and finish an abandoned switch even
	 * without the flag file.
	 */
	public function maintain_switch() {
		if ( self::skip_auto_recovery() || wp_doing_ajax() ) {
			return;
		}
		try {
			$st = $this->storage();
			if ( null !== $st->error() ) {
				return;
			}
			$this->recover_switch( $st, 5.0 );
			$this->finish_switch( $st, 5.0 );
		} catch ( Throwable $e ) {
			self::log_unexpected( $e, 'recover' );
		}
	}

	/**
	 * Recover an abandoned switch (FSC_Recovery::recover() with this site's paths and database).
	 *
	 * @param FSC_Storage $st     Storage.
	 * @param float       $budget Seconds.
	 * @return string FSC_Recovery result.
	 */
	private function recover_switch( FSC_Storage $st, $budget ) {
		return FSC_Recovery::recover(
			$st,
			WP_CONTENT_DIR,
			array(
				'stale'       => FSC_Recovery::stale_seconds(),
				'deadline'    => microtime( true ) + $budget,
				'maintenance' => ABSPATH . '.maintenance',
				'db'          => array( 'FSC_DB_Import', 'temp_exists' ),
			)
		);
	}

	/**
	 * Finish what an abandoned switch left: finalize after the commit point
	 * (budgeted, continues on the next call), or remove the temp tables and
	 * the staging folder after a rollback. Clears the flag when nothing is left.
	 *
	 * @param FSC_Storage $st     Storage.
	 * @param float       $budget Seconds.
	 */
	private function finish_switch( FSC_Storage $st, $budget ) {
		$job = FSC_Job::load( $st->job_path() );
		if ( ! $job || 'import' !== $job->data['type'] || 'done' === $job->data['status'] || ( 'error' === $job->data['status'] && ( ! empty( $job->data['cleaned'] ) || ! empty( $job->data['swapped'] ) ) ) ) {
			FSC_Recovery::flag( $st, false );
			return;
		}
		$d = $job->data;
		if ( 'running' === $d['status'] && ( empty( $d['swapped'] ) || ( empty( $d['recovery'] ) && time() - (int) $d['updated'] < FSC_Recovery::stale_seconds() ) ) ) {
			// Still switching (or recovery pending), or the browser may still finalize it.
			return;
		}
		if ( 'error' === $d['status'] && ! empty( $d['recovery'] ) && empty( $d['recovered'] ) ) {
			// FSC_Recovery is still moving files back.
			return;
		}
		if ( ! $job->lock( 0 ) ) {
			return;
		}
		try {
			$fresh = FSC_Job::load( $job->path() );
			if ( ! $fresh || $fresh->data['id'] !== $job->data['id'] ) {
				return;
			}
			$job->data = $fresh->data;
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}
			if ( 'running' === $job->data['status'] && ! empty( $job->data['swapped'] ) ) {
				$job->run(
					function ( $job, $deadline, &$state ) use ( $st ) {
						return FSC_Importer::unit( $job, $st, $deadline, $state );
					},
					$budget
				);
			} elseif ( 'error' === $job->data['status'] && empty( $job->data['swapped'] ) && empty( $job->data['cleaned'] ) ) {
				// The staged files are only deleted once it is certain that the database was not switched.
				if ( FSC_Recovery::NOT_COMMITTED !== FSC_DB_Import::commit_state( $job ) ) {
					return;
				}
				// The staging folder may hold many files: delete it in budgeted calls first.
				if ( ! FSC_Extractor::purge_tree( FSC_Importer::stage_dir( $st, $job->data['id'] ), microtime( true ) + $budget ) ) {
					return;
				}
				if ( ! $this->cleanup_job( $job ) ) {
					return;
				}
				$job->log( empty( $job->data['restore_failed'] ) ? 'Temporary tables removed. The site was not changed.' : 'Temporary tables removed. Some files could not be moved back; see the warning above.' );
				$job->data['cleaned'] = true;
				$job->save();
			}
		} finally {
			$job->unlock();
		}
		$st->harden();
	}

	/**
	 * Activation: schedule the daily cleanup.
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Deactivation: remove the daily cleanup.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Re-schedule the daily cleanup when it is missing: an import replaces the
	 * options table, and with it the cron schedule. Not during AJAX or cron
	 * requests (an import step must not write options while tables are swapped).
	 */
	public function schedule_cron() {
		if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::activate();
	}

	/**
	 * Days after which archives are deleted automatically; 0 = never (default).
	 * FSC_ARCHIVE_RETENTION_DAYS in wp-config.php, then the
	 * fsc_archive_retention_days filter.
	 *
	 * @return array array( days => int, source => default|constant|filter ).
	 */
	public static function retention() {
		$days   = 0;
		$source = 'default';
		if ( defined( 'FSC_ARCHIVE_RETENTION_DAYS' ) && is_numeric( FSC_ARCHIVE_RETENTION_DAYS ) ) {
			$days   = (int) FSC_ARCHIVE_RETENTION_DAYS;
			$source = 'constant';
		}
		/**
		 * Filter the number of days after which archives are deleted by the
		 * daily cleanup. 0 turns automatic deletion off (the default).
		 *
		 * @param int $days Days.
		 */
		$filtered = apply_filters( 'fsc_archive_retention_days', $days );
		if ( is_numeric( $filtered ) && (int) $filtered !== $days ) {
			$days   = (int) $filtered;
			$source = 'filter';
		}
		return array(
			'days'   => max( 0, $days ),
			'source' => $source,
		);
	}

	/**
	 * Archive name and job id of the current job while it may still need its
	 * files (running, or failed but resumable).
	 *
	 * @return array array( names => archive names to keep, job => job id or null ).
	 */
	private function in_use() {
		$job = $this->current_job();
		$out = array(
			'names' => array(),
			'job'   => null,
		);
		if ( $job && in_array( $job->data['status'], array( 'running', 'error' ), true ) ) {
			$out['job'] = (string) $job->data['id'];
			if ( ! empty( $job->data['archive'] ) && is_string( $job->data['archive'] ) ) {
				$out['names'][] = $job->data['archive'];
			}
		}
		return $out;
	}

	/**
	 * Daily cleanup: archives past the retention period (when enabled),
	 * unfinished uploads/exports and job temp files older than 24 hours that
	 * do not belong to the current job; then re-apply the storage modes.
	 */
	public function cron_cleanup() {
		try {
			$st = $this->storage();
			if ( null !== $st->error() ) {
				return;
			}
			// An abandoned import switch: roll back or finish it (also without the flag file).
			$this->recover_switch( $st, 10.0 );
			$this->finish_switch( $st, 10.0 );
			$use  = $this->in_use();
			$ret  = self::retention();
			$gone = $st->purge_archives( $ret['days'], $use['names'] );
			$st->purge_stale( self::STALE_FILES_AGE, $use['job'], $use['names'] );
			// Staging/old folders of abandoned imports (directories, which purge_stale() never touches).
			FSC_Importer::purge_orphans( $st, null === $use['job'] ? '' : $use['job'], self::STALE_FILES_AGE );
			$st->harden();
			if ( $gone ) {
				error_log( sprintf( 'WP Free Site Cloner: deleted %d archive(s) older than %d days.', count( $gone ), $ret['days'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		} catch ( Throwable $e ) {
			self::log_unexpected( $e, self::CRON );
		}
	}

	/**
	 * Keep a configured storage directory inside wp-content out of exports.
	 *
	 * @param array $ex Paths relative to wp-content.
	 * @return array
	 */
	public function export_excludes( $ex ) {
		$ex      = (array) $ex;
		$content = rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ) . '/';
		$base    = wp_normalize_path( $this->storage()->base_dir() );
		$real    = realpath( $this->storage()->base_dir() );
		foreach ( array( $base, false === $real ? '' : wp_normalize_path( $real ) ) as $b ) {
			if ( '' !== $b && 0 === strpos( $b . '/', $content ) && strlen( $b ) > strlen( $content ) ) {
				$ex[] = substr( $b, strlen( $content ) );
			}
		}
		return array_values( array_unique( $ex ) );
	}

	/**
	 * Whether $path is $root or below it.
	 *
	 * @param string $path Path.
	 * @param string $root Root.
	 * @return bool
	 */
	private static function is_below( $path, $root ) {
		$root = rtrim( wp_normalize_path( (string) $root ), '/' );
		$path = rtrim( wp_normalize_path( (string) $path ), '/' );
		return '' !== $root && '' !== $path && ( $path === $root || 0 === strpos( $path . '/', $root . '/' ) );
	}

	/**
	 * Whether a directory lies inside the web root (ABSPATH, wp-content or the
	 * server's document root), with symlinks resolved where possible.
	 *
	 * @param string $dir Directory (may not exist yet).
	 * @return bool
	 */
	public static function is_public_path( $dir ) {
		$real = realpath( $dir );
		if ( false === $real ) {
			$parent = realpath( dirname( $dir ) );
			$real   = false === $parent ? $dir : $parent . '/' . basename( $dir );
		}
		$roots = array( ABSPATH, WP_CONTENT_DIR );
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$roots[] = wp_unslash( $_SERVER['DOCUMENT_ROOT'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		foreach ( $roots as $root ) {
			$rr = realpath( $root );
			foreach ( array( $dir, $real ) as $p ) {
				if ( self::is_below( $p, $root ) || ( false !== $rr && self::is_below( $p, $rr ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Storage facts and warnings for the admin page.
	 *
	 * @param FSC_Storage $st Storage (after ensure()).
	 * @return array
	 */
	public function storage_info( FSC_Storage $st ) {
		$info             = $st->info();
		$info['dir']      = null === $info['error'] ? $st->dir() : $st->base_dir();
		$info['public']   = self::is_public_path( $st->base_dir() );
		$info['warnings'] = array();
		$info['notes']    = array();
		if ( null !== $info['error'] ) {
			return $info;
		}
		if ( $info['custom'] && $info['public'] ) {
			$info['warnings'][] = __( 'The storage directory set by FSC_STORAGE_DIR is inside the web root. Choose a directory outside it, or archives may be downloadable on servers that ignore .htaccess.', 'wp-free-site-cloner' );
		} elseif ( ! $info['custom'] ) {
			$info['notes'][] = __( 'Archives are stored inside the web root, protected by a random folder name and deny rules. To keep them outside the web root, define FSC_STORAGE_DIR in wp-config.php.', 'wp-free-site-cloner' );
		}
		if ( $info['wp_modes'] && $info['open_modes'] ) {
			/* translators: 1: directory mode, 2: file mode (octal). */
			$info['warnings'][] = sprintf( __( 'Storage permissions follow FS_CHMOD_DIR/FS_CHMOD_FILE (%1$s/%2$s), so other accounts on this server may be able to read the archives.', 'wp-free-site-cloner' ), $info['dir_mode'], $info['file_mode'] );
		}
		if ( $info['mode_reverted'] ) {
			$info['warnings'][] = __( 'The storage permissions could not be restricted on this server. Delete archives as soon as you no longer need them.', 'wp-free-site-cloner' );
		}
		if ( ! $info['wp_modes'] && function_exists( 'posix_geteuid' ) ) {
			$owner = @fileowner( WP_CONTENT_DIR );
			if ( false !== $owner && (int) $owner !== (int) posix_geteuid() ) {
				$info['notes'][] = __( 'PHP runs as a different system user than the owner of wp-content, so an FTP/SFTP account may not be able to read the archives (they are readable by PHP only). Download them here, or define FS_CHMOD_DIR and FS_CHMOD_FILE in wp-config.php.', 'wp-free-site-cloner' );
			}
		}
		if ( $info['custom'] ) {
			$legacy = new FSC_Storage( WP_CONTENT_DIR . '/fsc-storage' );
			$count  = count( $legacy->list_archives() ) + self::legacy_archive_count( WP_CONTENT_DIR . '/fsc-storage' );
			if ( $count > 0 ) {
				/* translators: 1: number of archives, 2: directory. */
				$info['warnings'][] = sprintf( _n( '%1$d archive is still in the old storage location %2$s. Move it to FSC_STORAGE_DIR or delete it.', '%1$d archives are still in the old storage location %2$s. Move them to FSC_STORAGE_DIR or delete them.', $count, 'wp-free-site-cloner' ), $count, $legacy->dir() );
			}
		}
		return $info;
	}

	/**
	 * Count loose archive files in a legacy storage directory. GLOB_BRACE is not
	 * defined on every PHP build (notably musl/Alpine PHP 8), where referencing
	 * it raises an Error that @ does not suppress; fall back to one glob per
	 * extension there so the archive listing keeps working.
	 *
	 * @param string $dir Directory.
	 * @return int
	 */
	private static function legacy_archive_count( $dir ) {
		$dir = rtrim( $dir, '/' );
		if ( defined( 'GLOB_BRACE' ) ) {
			return count( (array) @glob( $dir . '/*.{tar,wpress,zip,daf}', GLOB_BRACE ) );
		}
		$count = 0;
		foreach ( array( 'tar', 'wpress', 'zip', 'daf' ) as $ext ) {
			$count += count( (array) @glob( $dir . '/*.' . $ext ) );
		}
		return $count;
	}

	/**
	 * Retention setting and old archives for the admin page.
	 *
	 * @param FSC_Storage $st Storage.
	 * @return array
	 */
	public function retention_info( FSC_Storage $st ) {
		$ret                = self::retention();
		$ret['notice_days'] = $ret['days'] > 0 ? $ret['days'] : self::NOTICE_DAYS;
		$old                = $st->old_archives( $ret['notice_days'] );
		$ret['old_count']   = count( $old );
		$ret['old_bytes']   = 0;
		foreach ( $old as $a ) {
			$ret['old_bytes'] += (int) $a['size'];
		}
		return $ret;
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-free-site-cloner', false, dirname( plugin_basename( FSC_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Storage instance.
	 *
	 * @return FSC_Storage
	 */
	public function storage() {
		return FSC_Storage::default_storage();
	}

	/**
	 * Admin page URL.
	 *
	 * @return string
	 */
	public function page_url() {
		return admin_url( 'tools.php?page=' . self::PAGE );
	}

	/**
	 * "Open" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Open', 'wp-free-site-cloner' ) . '</a>' );
		return $links;
	}

	/**
	 * Tools > Site Cloner.
	 */
	public function admin_menu() {
		$this->hook = add_management_page(
			__( 'WP Free Site Cloner', 'wp-free-site-cloner' ),
			__( 'Site Cloner', 'wp-free-site-cloner' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Suppress core's wp-auth-check session-expiry popup on our own admin
	 * page only. An export/import can run well past the heartbeat's usual
	 * session-expiry window, and an import's DB swap replaces the users
	 * table mid-job, invalidating the admin session outright - either way
	 * the modal would cover our progress or done screen and read as the
	 * tool having broken. Our own polling loop (and the done screen's
	 * login link) already tell the user what happened and what to do; the
	 * generic "Your session has expired" popup does not apply here.
	 * Screen-scoped via the core `wp_auth_check_load` filter (see that
	 * function's docblock in wp-includes/functions.php) rather than
	 * `remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' )`,
	 * which would disable it admin-wide instead of just on this page.
	 *
	 * @param bool           $show   Whether core would load the auth check.
	 * @param WP_Screen|null $screen Current screen.
	 * @return bool
	 */
	public function disable_auth_check_on_our_page( $show, $screen ) {
		if ( null !== $this->hook && $screen && $screen->id === $this->hook ) {
			return false;
		}
		return $show;
	}

	/**
	 * Largest safe upload chunk in bytes.
	 *
	 * @return int
	 */
	public static function chunk_size() {
		$max = (int) wp_max_upload_size();
		if ( $max <= 0 ) {
			$max = 2 * 1048576;
		}
		return (int) max( 65536, min( 8 * 1048576, floor( $max * 0.8 ) ) );
	}

	/**
	 * Data handed to the admin script as window.FSC_Admin.
	 *
	 * @return array
	 */
	public function script_data() {
		return array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE ),
			'chunkSize'  => self::chunk_size(),
			'maxBudget'  => FSC_Job::budget(),
			'loginUrl'   => wp_login_url(),
			'pageUrl'    => $this->page_url(),
			'multisite'  => is_multisite(),
			'version'    => FSC_VERSION,
			'phaseLabels' => array(
				'export' => FSC_Exporter::phase_labels(),
				'import' => FSC_Importer::phase_labels(),
			),
			'stepLabels'  => array(
				'export' => FSC_Exporter::step_labels(),
				'import' => FSC_Importer::step_labels(),
			),
		);
	}

	/**
	 * Enqueue admin assets on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( null === $this->hook || $hook !== $this->hook ) {
			return;
		}
		$ver = FSC_VERSION;
		if ( is_file( FSC_PLUGIN_DIR . 'assets/admin.css' ) ) {
			wp_enqueue_style( 'fsc-admin', FSC_PLUGIN_URL . 'assets/admin.css', array(), $ver );
		}
		if ( is_file( FSC_PLUGIN_DIR . 'assets/admin.js' ) ) {
			wp_enqueue_script( 'fsc-admin', FSC_PLUGIN_URL . 'assets/admin.js', array(), $ver, true );
			wp_localize_script( 'fsc-admin', 'FSC_Admin', $this->script_data() );
		}
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-free-site-cloner' ) );
		}
		$view = FSC_PLUGIN_DIR . 'admin/view-page.php';
		if ( is_file( $view ) ) {
			include $view;
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'WP Free Site Cloner', 'wp-free-site-cloner' ) . '</h1><div id="fsc-app"></div></div>';
	}

	/**
	 * One progress panel (step list + heartbeat + stall notice + live
	 * warnings), reused identically for export and import. Ids follow
	 * fsc-{prefix}-....
	 *
	 * @param string $prefix "export" or "import".
	 */
	public static function render_progress_panel( $prefix ) {
		$p = esc_attr( $prefix ); // Escaped once here; echoed as-is below.
		?>
		<div class="fsc-progress-row">
			<progress id="fsc-<?php echo $p; ?>-progress" class="fsc-progress" max="100" value="0"
				role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
				aria-label="<?php echo esc_attr__( 'Overall progress', 'wp-free-site-cloner' ); ?>"></progress>
			<span id="fsc-<?php echo $p; ?>-percent" class="fsc-percent">0%</span>
		</div>
		<p id="fsc-<?php echo $p; ?>-phase" class="fsc-phase-label" aria-live="polite"></p>
		<p id="fsc-<?php echo $p; ?>-status" class="fsc-status-line" aria-live="polite"></p>

		<div id="fsc-<?php echo $p; ?>-steps-wrap" class="fsc-steps-wrap" hidden>
			<ol id="fsc-<?php echo $p; ?>-steps" class="fsc-steps"
				aria-label="<?php echo esc_attr__( 'Steps', 'wp-free-site-cloner' ); ?>"></ol>

			<p id="fsc-<?php echo $p; ?>-step-detail" class="fsc-step-detail" aria-live="polite" hidden></p>
			<div class="fsc-progress-row fsc-step-progress-row">
				<progress id="fsc-<?php echo $p; ?>-step-progress" class="fsc-progress fsc-progress-small" max="100"
					role="progressbar" aria-label="<?php echo esc_attr__( 'Current step progress', 'wp-free-site-cloner' ); ?>" hidden></progress>
				<span id="fsc-<?php echo $p; ?>-step-percent" class="fsc-percent"></span>
			</div>
			<p id="fsc-<?php echo $p; ?>-step-counter" class="fsc-step-counter"></p>

			<p class="fsc-elapsed-row">
				<span id="fsc-<?php echo $p; ?>-elapsed-total"></span>
				<span id="fsc-<?php echo $p; ?>-elapsed-step"></span>
			</p>
			<p id="fsc-<?php echo $p; ?>-heartbeat" class="fsc-heartbeat" aria-live="polite"></p>

			<div id="fsc-<?php echo $p; ?>-stall" class="notice notice-warning inline fsc-msg-box" role="status" hidden>
				<p id="fsc-<?php echo $p; ?>-stall-msg"></p>
			</div>

			<div id="fsc-<?php echo $p; ?>-live-warnings" class="notice notice-warning inline" hidden>
				<p><strong><?php echo esc_html__( 'Warnings:', 'wp-free-site-cloner' ); ?></strong></p>
				<ul id="fsc-<?php echo $p; ?>-live-warnings-list"></ul>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Engine API shared by the AJAX handlers and tests/e2e/drive.php.     */
	/* ------------------------------------------------------------------ */

	/**
	 * The current job, if any.
	 *
	 * @return FSC_Job|null
	 */
	public function current_job() {
		return FSC_Job::load( $this->storage()->job_path() );
	}

	/**
	 * Whether a job is still running and was touched recently.
	 *
	 * @param FSC_Job|null $job Job.
	 * @return bool
	 */
	public static function is_active( $job ) {
		return $job && 'running' === $job->data['status'] && ( time() - (int) $job->data['updated'] ) < self::STALE_AFTER;
	}

	/**
	 * Whether a job is an import past the database switch that is still being
	 * finished: it can neither be cancelled nor replaced.
	 *
	 * @param FSC_Job $job Job.
	 * @return bool
	 */
	private static function is_finishing( FSC_Job $job ) {
		return 'import' === $job->data['type'] && ! empty( $job->data['swapped'] ) && self::is_active( $job );
	}

	/**
	 * Replace a job's data by what is in the job file now. For requests that
	 * waited for the step lock: the step that held it may have changed,
	 * finished or replaced the job.
	 *
	 * @param FSC_Job $job Job (locked).
	 * @return bool False when the job is gone or another job took its place.
	 */
	private static function reload( FSC_Job $job ) {
		$fresh = FSC_Job::load( $job->path() );
		if ( ! $fresh || $fresh->data['id'] !== $job->data['id'] ) {
			return false;
		}
		$job->data = $fresh->data;
		return true;
	}

	/**
	 * Make room for a new job: refuse while another is active (unless forced),
	 * otherwise clean up the previous one.
	 *
	 * @param bool $force Replace an active job.
	 * @throws FSC_Exception When a job is active or a step is running.
	 */
	public function clear_previous( $force ) {
		$old = $this->current_job();
		if ( ! $old ) {
			return;
		}
		if ( self::is_active( $old ) && ! $force ) {
			throw new FSC_Exception( __( 'Another export or import is running. Cancel it first or wait for it to finish.', 'wp-free-site-cloner' ), 409 );
		}
		if ( ! $old->lock( 25 ) ) {
			throw new FSC_Exception( __( 'Another step is running right now. Try again in a few seconds.', 'wp-free-site-cloner' ), 423 );
		}
		// The step this request waited for may have changed, finished or replaced the job.
		$same = self::reload( $old );
		if ( ! $same || ( self::is_active( $old ) && ! $force ) ) {
			$old->unlock();
			if ( ! $same && ! $this->current_job() ) {
				return;
			}
			throw new FSC_Exception( __( 'Another export or import is running. Cancel it first or wait for it to finish.', 'wp-free-site-cloner' ), 409 );
		}
		if ( 'running' === $old->data['status'] || 'error' === $old->data['status'] ) {
			// A stale swapped import (token lost, finalize never ran) may go: its database is already live.
			if ( self::is_finishing( $old ) || ! $this->cleanup_job( $old ) ) {
				$old->unlock();
				// Also when the clean-up found that the database switch had completed.
				if ( self::is_finishing( $old ) ) {
					throw new FSC_Exception( __( 'An import is finishing and cannot be replaced.', 'wp-free-site-cloner' ), 409 );
				}
				throw new FSC_Exception( __( 'The previous import cannot be replaced right now: the database did not confirm whether its table switch completed. Nothing was changed. Try again in a few seconds.', 'wp-free-site-cloner' ), 423 );
			}
		}
		$old->delete();
	}

	/**
	 * Remove leftovers of an unfinished job.
	 *
	 * @param FSC_Job $job Job.
	 * @return bool False when an import was left untouched and must be kept
	 *              (see FSC_Importer::cleanup()).
	 */
	private function cleanup_job( FSC_Job $job ) {
		if ( 'export' === $job->data['type'] ) {
			FSC_Exporter::cleanup( $job, $this->storage() );
			return true;
		}
		return FSC_Importer::cleanup( $job, $this->storage() );
	}

	/**
	 * Run one budgeted step of the current job. The caller has authorised it.
	 *
	 * @param FSC_Job    $job       Job.
	 * @param float|null $requested Budget asked for by the client.
	 * @return array Response data (see job_response()).
	 * @throws FSC_Exception When another step holds the lock (code 423).
	 */
	public function run_step( FSC_Job $job, $requested = null ) {
		if ( 'running' !== $job->data['status'] ) {
			return $this->job_response( $job );
		}
		if ( ! $job->lock( 0 ) ) {
			throw new FSC_Exception( __( 'Another step is still running. Retry shortly.', 'wp-free-site-cloner' ), 423 );
		}
		$fresh = FSC_Job::load( $job->path() );
		if ( ! $fresh || $fresh->data['id'] !== $job->data['id'] ) {
			$job->unlock();
			throw new FSC_Exception( __( 'The job no longer exists.', 'wp-free-site-cloner' ), 404 );
		}
		$job->data = $fresh->data;
		if ( 'running' !== $job->data['status'] ) {
			$job->unlock();
			return $this->job_response( $job );
		}
		$budget = FSC_Job::budget( $requested );
		$st     = $this->storage();
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		// One unit may overrun the budget (a large statement or file piece):
		// leave it room when max_execution_time is small.
		$met = (int) ini_get( 'max_execution_time' );
		if ( $met > 0 && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) max( $met, ceil( $budget ) + 30 ) );
		}
		if ( 'export' === $job->data['type'] ) {
			$unit = function ( $job, $deadline, &$state ) use ( $st ) {
				return FSC_Exporter::unit( $job, $st, $deadline );
			};
		} else {
			$unit = function ( $job, $deadline, &$state ) use ( $st ) {
				return FSC_Importer::unit( $job, $st, $deadline, $state );
			};
		}
		try {
			$job->run( $unit, $budget );
		} catch ( FSC_Exception $e ) {
			// The job file itself could not be saved (disk full): free what we can.
			if ( 'running' === $job->data['status'] || 'error' === $job->data['status'] ) {
				if ( ! ( 'import' === $job->data['type'] && ! empty( $job->data['swapped'] ) ) ) {
					$this->cleanup_job( $job );
				}
			}
			$job->unlock();
			throw $e;
		} catch ( Throwable $e ) {
			// Unknown state: keep the job resumable, only release the lock.
			$job->unlock();
			throw $e;
		}
		if ( 'error' === $job->data['status'] && empty( $job->data['cleaned'] ) ) {
			if ( $this->cleanup_job( $job ) ) {
				if ( 'import' === $job->data['type'] && empty( $job->data['swapped'] ) ) {
					if ( ! empty( $job->data['restore_failed'] ) ) {
						// FSC_Importer::cleanup() logged a WARNING naming the folder that still holds the old files.
						$job->log( 'Temporary tables removed. Some files could not be moved back; see the warning above.' );
					} else {
						$job->log( 'Temporary tables removed. The site was not changed.' );
					}
				}
				$job->data['cleaned'] = true;
				$job->save();
			} elseif ( 'error' === $job->data['status'] ) {
				// Kept as it is: the automatic recovery or the next cancel asks the database again.
				$job->log( 'WARNING: The database did not confirm whether the table swap completed. Nothing was moved back or removed; this is checked again automatically.' );
				$job->save();
			}
		}
		$job->unlock();
		$st->harden();
		$out           = $this->job_response( $job );
		$out['budget'] = $budget;
		return $out;
	}

	/**
	 * Public view of a job (never includes the token hash).
	 *
	 * @param FSC_Job $job       Job.
	 * @param int     $log_since Last log sequence number the client has seen.
	 * @return array
	 */
	public function job_response( FSC_Job $job, $log_since = null ) {
		$d      = $job->data;
		$labels = 'export' === $d['type'] ? FSC_Exporter::phase_labels() : FSC_Importer::phase_labels();
		$phase  = 'done' === $d['status'] ? 'done' : $d['phase'];
		if ( null === $log_since ) {
			$log_since = isset( $_POST['log_since'] ) ? (int) $_POST['log_since'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$out = array(
			'job_id'      => $d['id'],
			'type'        => $d['type'],
			'status'      => $d['status'],
			'phase'       => $phase,
			'phase_label' => isset( $labels[ $phase ] ) ? $labels[ $phase ] : $phase,
			'progress'    => (int) $d['progress'],
			'done'        => 'done' === $d['status'],
			'error'       => $d['error'],
			'archive'     => isset( $d['archive'] ) ? $d['archive'] : null,
			'result'      => isset( $d['result'] ) ? $d['result'] : null,
			'log'         => $job->log_since( (int) $log_since ),
			'log_seq'     => (int) $d['log_seq'],
			'updated'     => (int) $d['updated'],
		);
		try {
			$info = 'export' === $d['type'] ? FSC_Exporter::progress_info( $job ) : FSC_Importer::progress_info( $job );
		} catch ( Throwable $e ) {
			// Progress text must never break a step response.
			$labels = 'export' === $d['type'] ? FSC_Exporter::step_labels() : FSC_Importer::step_labels();
			$info   = array(
				'steps'         => FSC_Job::build_steps( $labels, '', $d['status'] ),
				'step_detail'   => '',
				'step_progress' => null,
			);
		}
		$out['steps']            = $info['steps'];
		$out['step_detail']      = (string) $info['step_detail'];
		$out['step_progress']    = $info['step_progress'];
		$out['last_progress_at'] = isset( $d['last_progress_at'] ) ? (int) $d['last_progress_at'] : (int) $d['updated'];
		$out['server_time']      = time();
		if ( 'import' === $d['type'] ) {
			$out['source'] = array(
				'format'  => $d['meta']['format'],
				'home'    => $d['meta']['home'],
				'siteurl' => $d['meta']['siteurl'],
			);
			$out['swapped'] = ! empty( $d['swapped'] );
		}
		return $out;
	}

	/**
	 * Cancel the current job.
	 *
	 * @return bool True when a job was cancelled.
	 * @throws FSC_Exception When it cannot be cancelled.
	 */
	public function cancel() {
		$job = $this->current_job();
		if ( ! $job ) {
			return false;
		}
		$active = __( 'The imported database is already active; the import can only be finished, not cancelled.', 'wp-free-site-cloner' );
		if ( self::is_finishing( $job ) ) {
			throw new FSC_Exception( $active, 409 );
		}
		if ( ! $job->lock( 25 ) ) {
			throw new FSC_Exception( __( 'A step is still running. Try again in a few seconds.', 'wp-free-site-cloner' ), 423 );
		}
		// The step this request waited for may have switched the site, or ended or replaced the job.
		if ( ! self::reload( $job ) ) {
			$job->unlock();
			return false;
		}
		if ( self::is_finishing( $job ) ) {
			$job->unlock();
			throw new FSC_Exception( $active, 409 );
		}
		$swapped = 'import' === $job->data['type'] && ! empty( $job->data['swapped'] );
		if ( 'done' !== $job->data['status'] && ! $swapped && ! $this->cleanup_job( $job ) ) {
			$job->unlock();
			// The clean-up found that the database switch had completed.
			if ( self::is_finishing( $job ) ) {
				throw new FSC_Exception( $active, 409 );
			}
			throw new FSC_Exception( __( 'The import cannot be cancelled right now: the database did not confirm whether its table switch completed. Nothing was changed. Try again in a few seconds.', 'wp-free-site-cloner' ), 423 );
		}
		$job->delete();
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX plumbing.                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Capability + nonce check for admin actions.
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __( 'You are not allowed to do this.', 'wp-free-site-cloner' ),
				),
				403
			);
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'bad_nonce',
					'message' => __( 'Your session expired. Reload the page.', 'wp-free-site-cloner' ),
				),
				403
			);
		}
		nocache_headers();
	}

	/**
	 * Write an unexpected error to the PHP error log under a short random
	 * reference that is shown to the user instead of the details.
	 *
	 * @param Throwable $e      Error.
	 * @param string    $action Where it happened.
	 * @return string Reference (8 hex).
	 */
	private static function log_unexpected( Throwable $e, $action = '' ) {
		try {
			$ref = bin2hex( random_bytes( 4 ) );
		} catch ( Throwable $ignored ) {
			$ref = substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		if ( '' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification
			$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( 'WP Free Site Cloner: unexpected error, reference %s, action %s: %s: %s in %s:%d', $ref, $action, get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) . "\n" . $e->getTraceAsString() );
		return $ref;
	}

	/**
	 * Generic message for an unexpected error.
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	private static function unexpected_message( $ref ) {
		/* translators: %s: short error reference. */
		return sprintf( __( 'An unexpected error occurred (reference %s). The details were written to the PHP error log.', 'wp-free-site-cloner' ), $ref );
	}

	/**
	 * Send an error. FSC_Exception messages are shown as they are; anything
	 * else is logged and answered with a generic message and a reference.
	 *
	 * @param Throwable $e      Error.
	 * @param string    $code   Error code.
	 * @param array     $extra  Extra data.
	 */
	private function fail( Throwable $e, $code = 'error', array $extra = array() ) {
		if ( ! $e instanceof FSC_Exception ) {
			$ref = self::log_unexpected( $e );
			wp_send_json_error(
				array(
					'code'    => 'error',
					'message' => self::unexpected_message( $ref ),
					'ref'     => $ref,
				),
				400
			);
		}
		$map = array(
			404 => 'not_found',
			409 => 'conflict',
			423 => 'busy',
		);
		$http = 400;
		if ( isset( $map[ $e->getCode() ] ) ) {
			$code = $map[ $e->getCode() ];
			$http = $e->getCode();
		}
		wp_send_json_error(
			array_merge(
				array(
					'code'    => $code,
					// No absolute paths or the private storage name in responses (the storage block shows them to admins).
					'message' => FSC_Job::redact_paths( $e->getMessage() ),
				),
				$extra
			),
			$http
		);
	}

	/**
	 * POST/GET string parameter.
	 *
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @return string
	 */
	private static function param( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$v = isset( $_REQUEST[ $key ] ) ? wp_unslash( $_REQUEST[ $key ] ) : $default;
		return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : $default;
	}

	/**
	 * Optional budget parameter.
	 *
	 * @return float|null
	 */
	private static function budget_param() {
		$b = self::param( 'budget' );
		return is_numeric( $b ) ? (float) $b : null;
	}

	/**
	 * POST fsc_export_start.
	 */
	public function ajax_export_start() {
		$this->guard();
		try {
			$this->clear_previous( '1' === self::param( 'force' ) );
			$job = FSC_Exporter::start( $this->storage() );
			wp_send_json_success( $this->job_response( $job, 0 ) );
		} catch ( Throwable $e ) {
			$this->fail( $e, is_multisite() ? 'multisite' : 'error' );
		}
	}

	/**
	 * POST fsc_export_step.
	 */
	public function ajax_export_step() {
		$this->guard();
		try {
			$job = $this->current_job();
			if ( ! $job || 'export' !== $job->data['type'] || ( '' !== self::param( 'job_id' ) && self::param( 'job_id' ) !== $job->data['id'] ) ) {
				throw new FSC_Exception( __( 'No export is running.', 'wp-free-site-cloner' ), 404 );
			}
			wp_send_json_success( $this->run_step( $job, self::budget_param() ) );
		} catch ( Throwable $e ) {
			$this->fail( $e );
		}
	}

	/**
	 * POST fsc_status.
	 */
	public function ajax_status() {
		$this->guard();
		try {
			$job = $this->current_job();
			wp_send_json_success(
				array(
					'job'    => $job ? $this->job_response( $job ) : null,
					'active' => self::is_active( $job ),
				)
			);
		} catch ( Throwable $e ) {
			$this->fail( $e );
		}
	}

	/**
	 * POST fsc_archives.
	 */
	public function ajax_archives() {
		$this->guard();
		try {
			$this->send_archives();
		} catch ( Throwable $e ) {
			$this->fail( $e );
		}
	}

	/**
	 * Archive list response.
	 */
	private function send_archives() {
		$st  = $this->storage();
		$out = array();
		try {
			$st->ensure();
			$st->adopt();
		} catch ( FSC_Exception $e ) {
			// Listing still works; the error shows up on export or upload.
			unset( $e );
		}
		foreach ( $st->list_archives() as $a ) {
			$a['size_human']       = size_format( $a['size'] );
			$a['bytes']            = (int) $a['size'];
			$a['sha256_available'] = 'tar' === $a['format'] && FSC_Integrity::has_checksums( $st->dir() . '/' . $a['name'] );
			$a['date']       = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $a['mtime'] );
			$a['download']   = add_query_arg(
				array(
					'action' => 'fsc_download',
					'name'   => rawurlencode( $a['name'] ),
					'nonce'  => wp_create_nonce( self::NONCE ),
				),
				admin_url( 'admin-ajax.php' )
			);
			$out[]           = $a;
		}
		wp_send_json_success(
			array(
				'archives'    => $out,
				'storage_dir' => null === $st->error() ? $st->dir() : '',
				'free_space'  => $st->free_space(),
				'storage'     => $this->storage_info( $st ),
				'retention'   => $this->retention_info( $st ),
			)
		);
	}

	/**
	 * POST fsc_archive_delete.
	 */
	public function ajax_archive_delete() {
		$this->guard();
		try {
			$name = self::param( 'name' );
			$job  = $this->current_job();
			if ( self::is_active( $job ) && isset( $job->data['archive'] ) && $job->data['archive'] === $name ) {
				throw new FSC_Exception( __( 'This archive is in use by a running job.', 'wp-free-site-cloner' ), 409 );
			}
			if ( ! $this->storage()->delete( $name ) ) {
				throw new FSC_Exception( __( 'Archive not found.', 'wp-free-site-cloner' ), 404 );
			}
			wp_send_json_success( array( 'deleted' => $name ) );
		} catch ( Throwable $e ) {
			$this->fail( $e );
		}
	}

	/**
	 * POST fsc_archives_delete_all (confirm=1): delete every archive except
	 * the one used by the current job.
	 */
	public function ajax_archives_delete_all() {
		$this->guard();
		try {
			if ( '1' !== self::param( 'confirm' ) ) {
				throw new FSC_Exception( __( 'Please confirm that all archives should be deleted.', 'wp-free-site-cloner' ) );
			}
			$use = $this->in_use();
			$res = $this->storage()->delete_all( $use['names'] );
			wp_send_json_success(
				array(
					'deleted' => count( $res['deleted'] ),
					'kept'    => $res['kept'],
				)
			);
		} catch ( Throwable $e ) {
			$this->fail( $e );
		}
	}

	/**
	 * GET fsc_download: stream an archive (supports a single byte range).
	 */
	public function ajax_download() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-free-site-cloner' ), '', array( 'response' => 403 ) );
		}
		try {
			$this->stream_download();
		} catch ( Throwable $e ) {
			$ref = self::log_unexpected( $e, 'fsc_download' );
			if ( ! headers_sent() ) {
				status_header( 500 );
				wp_die( esc_html( self::unexpected_message( $ref ) ), '', array( 'response' => 500 ) );
			}
			exit;
		}
	}

	/**
	 * Stream the requested archive (supports a single byte range).
	 */
	private function stream_download() {
		$name = self::param( 'name' );
		$path = $this->storage()->resolve( $name );
		if ( null === $path ) {
			status_header( 404 );
			wp_die( esc_html__( 'Archive not found.', 'wp-free-site-cloner' ), '', array( 'response' => 404 ) );
		}
		clearstatcache( true, $path );
		$size  = (int) filesize( $path );
		$mtime = (int) filemtime( $path );
		$etag  = '"' . dechex( $size ) . '-' . dechex( $mtime ) . '"';
		$lastm = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';
		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( '' !== $range && isset( $_SERVER['HTTP_IF_RANGE'] ) ) {
			// If-Range: serve the range only while the file is unchanged, otherwise the whole file.
			$if_range = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_RANGE'] ) ) );
			if ( $if_range !== $etag && $if_range !== $lastm ) {
				$range = '';
			}
		}
		// Compression would change the byte count behind Content-Length (the cause of short downloads).
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}
		if ( $size > 0 && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
			if ( '' === $m[1] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				$end   = '' === $m[2] ? $size - 1 : min( $size - 1, (int) $m[2] );
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		} else {
			status_header( 200 );
		}
		for ( $i = ob_get_level(); $i > 0; $i-- ) {
			if ( ! @ob_end_clean() ) {
				break;
			}
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		nocache_headers();
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private, no-transform' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . ( $size > 0 ? $end - $start + 1 : 0 ) );
		header( 'Accept-Ranges: bytes' );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . $lastm );
		header( 'X-Accel-Buffering: no' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}
		$fp = fopen( $path, 'rb' );
		if ( $fp && $size > 0 ) {
			fseek( $fp, $start );
			$left = $end - $start + 1;
			while ( $left > 0 && ! feof( $fp ) && ! connection_aborted() ) {
				$buf = fread( $fp, (int) min( 1048576, $left ) );
				if ( false === $buf || '' === $buf ) {
					break;
				}
				echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$left -= strlen( $buf );
				flush();
			}
		}
		if ( $fp ) {
			fclose( $fp );
		}
		exit;
	}

	/**
	 * POST fsc_upload_chunk (multipart, file field "chunk").
	 */
	public function ajax_upload_chunk() {
		$this->guard();
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$file = isset( $_FILES['chunk'] ) ? $_FILES['chunk'] : null;
			if ( ! $file || ! isset( $file['tmp_name'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				$err = $file && isset( $file['error'] ) ? (int) $file['error'] : -1;
				/* translators: %d: PHP upload error code. */
				throw new FSC_Exception( sprintf( __( 'The chunk upload failed (PHP upload error %d). Try a smaller chunk size.', 'wp-free-site-cloner' ), $err ) );
			}
			$st  = $this->storage();
			$res = $st->upload_chunk( self::param( 'name' ), (int) self::param( 'offset', '0' ), $file['tmp_name'], (int) self::param( 'total', '0' ) );
			if ( $res['complete'] && 'tar' === strtolower( pathinfo( $res['name'], PATHINFO_EXTENSION ) ) ) {
				// Truncated or foreign .tar: say so now, not after the user clicked Import.
				$res['check'] = FSC_Source_Native::quick_check( $st->dir() . '/' . $res['name'] );
			}
			wp_send_json_success( $res );
		} catch ( Throwable $e ) {
			if ( $e instanceof FSC_Exception && 409 === $e->getCode() && preg_match( '/expected (\d+)/', $e->getMessage(), $m ) ) {
				wp_send_json_error(
					array(
						'code'     => 'offset_mismatch',
						'message'  => $e->getMessage(),
						'expected' => (int) $m[1],
					),
					409
				);
			}
			$this->fail( $e );
		}
	}

	/**
	 * POST fsc_import_inspect.
	 */
	public function ajax_import_inspect() {
		$this->guard();
		try {
			$info = FSC_Importer::inspect( $this->storage(), self::param( 'name' ) );
			unset( $info['class'] );
			wp_send_json_success( $info );
		} catch ( Throwable $e ) {
			$this->fail( $e, is_multisite() ? 'multisite' : 'error', $e instanceof FSC_Exception ? FSC_Importer::size_info( $this->storage(), self::param( 'name' ) ) : array() );
		}
	}

	/**
	 * POST fsc_import_start.
	 */
	public function ajax_import_start() {
		$this->guard();
		try {
			if ( '1' !== self::param( 'confirm' ) ) {
				throw new FSC_Exception( __( 'Please confirm that the current site will be replaced.', 'wp-free-site-cloner' ) );
			}
			$this->clear_previous( '1' === self::param( 'force' ) );
			$res  = FSC_Importer::start( $this->storage(), self::param( 'name' ) );
			$data = $this->job_response( $res['job'], 0 );
			$data['token'] = $res['token'];
			wp_send_json_success( $data );
		} catch ( Throwable $e ) {
			$this->fail( $e, is_multisite() ? 'multisite' : 'error' );
		}
	}

	/**
	 * POST fsc_import_step (logged in or not; authenticated by the secret token).
	 */
	public function ajax_import_step() {
		nocache_headers();
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';
			// Unauthenticated endpoint: reject malformed tokens before touching the job file, and
			// answer every failure the same way so the response is no oracle.
			$job = FSC_Importer::valid_token_format( $token ) ? $this->current_job() : null;
			if ( ! FSC_Importer::check_token( $job, $token ) ) {
				wp_send_json_error(
					array(
						'code'    => 'bad_token',
						'message' => __( 'Invalid or expired import token.', 'wp-free-site-cloner' ),
					),
					403
				);
			}
			wp_send_json_success( $this->run_step( $job, self::budget_param() ) );
		} catch ( Throwable $e ) {
			$this->fail( $e );
		}
	}

	/**
	 * POST fsc_cancel.
	 */
	public function ajax_cancel() {
		$this->guard();
		try {
			wp_send_json_success( array( 'cancelled' => $this->cancel() ) );
		} catch ( Throwable $e ) {
			$this->fail( $e );
		}
	}
}
