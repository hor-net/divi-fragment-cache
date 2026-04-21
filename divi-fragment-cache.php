<?php
/*
Plugin Name: Divi Fragment Cache
Description: Cache dei frammenti HTML generati dagli shortcode dei moduli Divi (et_pb_*).
Version: 0.1.0
Requires at least: 5.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: divi-fragment-cache
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DFC_VERSION', '0.1.0' );
define( 'DFC_PLUGIN_FILE', __FILE__ );
define( 'DFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DFC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once DFC_PLUGIN_DIR . 'includes/class-dfc-plugin.php';

register_activation_hook( __FILE__, [ 'DFC_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'DFC_Plugin', 'deactivate' ] );

DFC_Plugin::instance()->init();
