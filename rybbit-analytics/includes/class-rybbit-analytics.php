<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Main plugin class for Rybbit Analytics
 */
class Rybbit_Analytics {
    public function __construct() {
        // Initialize plugin
        add_action('init', array($this, 'init'));
    }
    public function init() {
        // Plugin initialization code
    }
}
