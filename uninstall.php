<?php
/**
 * Uninstall: remove temporary tables and files and the daily cleanup event.
 * Archives are kept; a storage directory (wp-content/fsc-storage and
 * FSC_STORAGE_DIR) is removed only when no archive is left in it.
 *
 * @package wp-free-site-cloner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'fsctmp_', 'fscold_' ) as $fsc_prefix ) {
	$fsc_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $fsc_prefix ) . '%' ) );
	foreach ( (array) $fsc_tables as $fsc_table ) {
		if ( 0 === strpos( $fsc_table, $fsc_prefix ) && preg_match( '/^[A-Za-z0-9_$]+$/', $fsc_table ) ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $fsc_table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}

wp_clear_scheduled_hook( 'fsc_daily_cleanup' );

// The default location and, when configured, FSC_STORAGE_DIR (absolute, no "." or ".." segments, no symlink).
$fsc_dirs = array( WP_CONTENT_DIR . '/fsc-storage' );
if ( defined( 'FSC_STORAGE_DIR' ) && is_string( FSC_STORAGE_DIR ) ) {
	$fsc_custom = rtrim( FSC_STORAGE_DIR, '/\\' );
	if ( '' !== $fsc_custom && preg_match( '#^(/|[A-Za-z]:[\\\\/])#', $fsc_custom ) && ! preg_match( '#(^|[\\\\/])\.\.?([\\\\/]|$)#', $fsc_custom ) && ! is_link( $fsc_custom ) ) {
		$fsc_dirs[] = $fsc_custom;
	}
}
$fsc_deny = array( '.', '..', '.htaccess', 'web.config', 'index.php' );

/**
 * Delete the regular files of a directory and the directory itself (no recursion, no symlinks followed).
 *
 * @param string $dir Directory.
 */
$fsc_clear = function ( $dir ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $fsc_file ) {
		if ( '.' !== $fsc_file && '..' !== $fsc_file && ( is_file( $dir . '/' . $fsc_file ) || is_link( $dir . '/' . $fsc_file ) ) ) {
			@unlink( $dir . '/' . $fsc_file );
		}
	}
	@rmdir( $dir );
};

foreach ( $fsc_dirs as $fsc_dir ) {
	if ( ! is_dir( $fsc_dir ) || is_link( $fsc_dir ) ) {
		continue;
	}
	// Switch flag (FSC_Recovery::FLAG), legacy layout (fsc-storage/tmp) and the private directories (fsc-storage/private-<hex>/tmp).
	@unlink( $fsc_dir . '/.fsc-switch-pending' );
	$fsc_clear( $fsc_dir . '/tmp' );
	foreach ( (array) scandir( $fsc_dir ) as $fsc_sub ) {
		$fsc_path = $fsc_dir . '/' . $fsc_sub;
		if ( ! preg_match( '/^private-[a-f0-9]{32}$/', (string) $fsc_sub ) || ! is_dir( $fsc_path ) || is_link( $fsc_path ) ) {
			continue;
		}
		$fsc_clear( $fsc_path . '/tmp' );
		foreach ( (array) scandir( $fsc_path ) as $fsc_file ) {
			// Unfinished uploads and exports are useless without the plugin; archives are kept.
			if ( '.part' === substr( (string) $fsc_file, -5 ) && is_file( $fsc_path . '/' . $fsc_file ) ) {
				@unlink( $fsc_path . '/' . $fsc_file );
			}
		}
		if ( ! array_diff( (array) scandir( $fsc_path ), $fsc_deny ) ) {
			$fsc_clear( $fsc_path );
		}
	}
	if ( ! array_diff( (array) scandir( $fsc_dir ), $fsc_deny ) ) {
		$fsc_clear( $fsc_dir );
	}
}
