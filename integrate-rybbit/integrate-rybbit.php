<?php
/*
Plugin Name: Integrate Rybbit
Plugin URI: https://github.com/maki-it/rybbit-wordpress-plugin
Description: Add and manage the Rybbit tracking script.
Version: 0.0.0
Tested up to: 6.9
Requires at least: 5.8
Requires PHP: 7.4
Author: Maki IT
Author URI: https://maki-it.de
License: GPLv3 or later
License URI: https://github.com/maki-it/rybbit-wordpress-plugin/blob/main/LICENSE
Text Domain: integrate-rybbit
Domain Path: /languages
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Plugin basename for hooks like plugin_action_links_{basename}
if (!defined('INTEGRATE_RYBBIT_PLUGIN_BASENAME')) {
    define('INTEGRATE_RYBBIT_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

// Require main class files
require_once plugin_dir_path(__FILE__) . 'includes/class-integrate-rybbit.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
require_once plugin_dir_path(__FILE__) . 'admin/class-integrate-rybbit-admin.php';
require_once plugin_dir_path(__FILE__) . 'public/class-integrate-rybbit-public.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-integrate-rybbit-admin-ajax.php';

// Initialize main plugin class
new Integrate_Rybbit();

// Initialize context-specific logic
if (is_admin()) {
    new Integrate_Rybbit_Admin();
    new Integrate_Rybbit_Admin_Ajax();

    // Also load tracking hooks in wp-admin (script injection is still governed by excluded roles).
    new Integrate_Rybbit_Public();
} else {
    new Integrate_Rybbit_Public();
}

// Set defaults on activation (only if options don't exist yet).
register_activation_hook(__FILE__, function () {
    if (get_option('rybbit_do_not_track_admins', null) === null) {
        add_option('rybbit_do_not_track_admins', '1');
    }

    // Default: do not track administrators.
    if (get_option('rybbit_excluded_roles', null) === null) {
        add_option('rybbit_excluded_roles', array('administrator'));
    }

    if (get_option('rybbit_identify_mode', null) === null) {
        add_option('rybbit_identify_mode', 'disabled');
    }

    if (get_option('rybbit_delete_data_on_uninstall', null) === null) {
        add_option('rybbit_delete_data_on_uninstall', '1');
    }
});