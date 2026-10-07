<?php
/**
 * PHPUnit bootstrap: loads the pure classes without WordPress.
 *
 * @package wp-free-site-cloner
 */

error_reporting( E_ALL );
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/autoload.php';
require __DIR__ . '/unit/TestCase.php';

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub for the pure classes.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
