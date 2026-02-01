<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Main plugin class for Integrate Rybbit
 */
class Integrate_Rybbit {
    public function __construct() {
        // Initialize plugin
        add_action('init', array($this, 'init'));
    }
    public function init() {
        // Plugin initialization code
    }
}
