<?php
/*
Plugin Name: Rybbit Analytics
Description: Analytics plugin for WordPress.
Version: 1.0.0
Plugin URI: https://github.com/maki-it/rybbit-wordpress-plugin
Author: Maki IT
Author URI: https://maki-it.de
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Plugin basename for hooks like plugin_action_links_{basename}
if (!defined('RYBBIT_ANALYTICS_PLUGIN_BASENAME')) {
    define('RYBBIT_ANALYTICS_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

// Require main class files
require_once plugin_dir_path(__FILE__) . 'includes/class-rybbit-analytics.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
require_once plugin_dir_path(__FILE__) . 'admin/class-rybbit-analytics-admin.php';
require_once plugin_dir_path(__FILE__) . 'public/class-rybbit-analytics-public.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-rybbit-analytics-admin-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-rybbit-analytics-updates.php';

// Initialize main plugin class
new Rybbit_Analytics();

// Initialize context-specific logic
if (is_admin()) {
    new Rybbit_Analytics_Admin();
    new Rybbit_Analytics_Admin_Ajax();

    // Also load tracking hooks in wp-admin (script injection is still governed by excluded roles).
    new Rybbit_Analytics_Public();
} else {
    new Rybbit_Analytics_Public();
}

// Set defaults on activation (only if options don't exist yet).
register_activation_hook(__FILE__, function () {
    if (get_option('rybbit_do_not_track_admins', null) === null) {
        add_option('rybbit_do_not_track_admins', '1');
    }

    if (get_option('rybbit_identify_mode', null) === null) {
        add_option('rybbit_identify_mode', 'disabled');
    }

    if (get_option('rybbit_delete_data_on_uninstall', null) === null) {
        add_option('rybbit_delete_data_on_uninstall', '1');
    }
});