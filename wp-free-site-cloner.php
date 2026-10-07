<?php
/**
 * Plugin Name:       WP Free Site Cloner
 * Plugin URI:        https://github.com/toby-sutor/wp-free-site-cloner
 * Description:       Export wp-content and the database into one .tar archive and restore it on another WordPress site, with URLs and paths rewritten. Also imports All-in-One WP Migration (.wpress) and Duplicator (.zip) archives. Free, no upsells.
 * Version:           0.9.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WP Free Site Cloner contributors
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       wp-free-site-cloner
 * Domain Path:       /languages
 *
 * @package wp-free-site-cloner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FSC_VERSION', '0.9.4' );
define( 'FSC_PLUGIN_FILE', __FILE__ );
define( 'FSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once FSC_PLUGIN_DIR . 'includes/autoload.php';

FSC_Plugin::instance()->init();
