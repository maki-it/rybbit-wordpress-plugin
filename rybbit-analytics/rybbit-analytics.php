<?php
/*
Plugin Name: Rybbit Analytics
Description: Analytics plugin for WordPress.
Version: 1.0.0
Author: Kim Oliver Drechsel <kontakt@maki-it.de>
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Require main class files
require_once plugin_dir_path(__FILE__) . 'includes/class-rybbit-analytics.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
require_once plugin_dir_path(__FILE__) . 'admin/class-rybbit-analytics-admin.php';
require_once plugin_dir_path(__FILE__) . 'public/class-rybbit-analytics-public.php';

// Initialize main plugin class
new Rybbit_Analytics();

// Initialize context-specific logic
if (is_admin()) {
    new Rybbit_Analytics_Admin();
} else {
    new Rybbit_Analytics_Public();
}
