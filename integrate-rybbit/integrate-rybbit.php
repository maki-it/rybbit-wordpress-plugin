<?php
/**
 * Plugin Name:       Integrate Rybbit
 * Plugin URI:        https://wordpress.org/plugins/integrate-rybbit/
 * Description:       Add and manage the Rybbit Analytics tracking script.
 * Version:           0.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Maki IT
 * Author URI:        https://maki-it.de
 * License:           GPLv3 or later
 * License URI:       https://github.com/maki-it/rybbit-wordpress-plugin/blob/main/LICENSE
 * Text Domain:       integrate-rybbit
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The release workflow replaces this value with the tag version.
define( 'INTEGRATE_RYBBIT_VERSION', '0.0.0' );
define( 'INTEGRATE_RYBBIT_FILE', __FILE__ );
define( 'INTEGRATE_RYBBIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'INTEGRATE_RYBBIT_URL', plugin_dir_url( __FILE__ ) );
define( 'INTEGRATE_RYBBIT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once INTEGRATE_RYBBIT_DIR . 'includes/class-integrate-rybbit-options.php';
require_once INTEGRATE_RYBBIT_DIR . 'includes/functions.php';
require_once INTEGRATE_RYBBIT_DIR . 'includes/class-integrate-rybbit-api.php';
require_once INTEGRATE_RYBBIT_DIR . 'includes/class-integrate-rybbit-rest.php';
require_once INTEGRATE_RYBBIT_DIR . 'includes/class-integrate-rybbit-optout.php';
require_once INTEGRATE_RYBBIT_DIR . 'public/class-integrate-rybbit-public.php';

if ( is_admin() ) {
	require_once INTEGRATE_RYBBIT_DIR . 'admin/class-integrate-rybbit-admin.php';
	require_once INTEGRATE_RYBBIT_DIR . 'admin/class-integrate-rybbit-dashboard.php';
	require_once INTEGRATE_RYBBIT_DIR . 'includes/class-integrate-rybbit-admin-ajax.php';
}

register_activation_hook( __FILE__, array( 'Integrate_Rybbit_Options', 'maybe_upgrade' ) );

// Updates do not run the activation hook. Check the schema version on every load.
add_action( 'plugins_loaded', array( 'Integrate_Rybbit_Options', 'maybe_upgrade' ) );

Integrate_Rybbit_Api::register_hooks();

new Integrate_Rybbit_Public();
new Integrate_Rybbit_Rest();
new Integrate_Rybbit_Optout();

if ( is_admin() ) {
	new Integrate_Rybbit_Admin();
	new Integrate_Rybbit_Dashboard();
	new Integrate_Rybbit_Admin_Ajax();
}
