<?php
/**
 * Recovery entry point. The .maintenance file this plugin writes during the
 * import switch includes it once the switch has made no progress for
 * FSC_SWITCH_STALE_SECONDS (default 300). It runs inside WordPress'
 * wp_is_maintenance_mode(), before any plugin is loaded: plain PHP, no
 * WordPress functions, no output.
 *
 * Returns the value for WordPress' $upgrading: time() keeps the maintenance
 * page, 0 lets this request load the (recovered) site, null when it cannot
 * decide (the .maintenance file then falls back to WordPress' own rule:
 * maintenance ends 10 minutes after the file was last touched).
 *
 * @package wp-free-site-cloner
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CONTENT_DIR' ) ) {
	return null;
}

// Never print a warning (it could hold a path) into the maintenance page or the site.
set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
	function () {
		return true;
	}
);
try {
	require_once __DIR__ . '/autoload.php';
	$fsc_recover_result = FSC_Recovery::from_maintenance( ABSPATH . '.maintenance' );
} catch ( Throwable $fsc_recover_error ) {
	$fsc_recover_result = null;
	error_log( 'WP Free Site Cloner: automatic switch recovery failed: ' . get_class( $fsc_recover_error ) . ': ' . ( class_exists( 'FSC_Job', false ) ? FSC_Job::redact_paths( $fsc_recover_error->getMessage() ) : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
restore_error_handler();
return $fsc_recover_result;
