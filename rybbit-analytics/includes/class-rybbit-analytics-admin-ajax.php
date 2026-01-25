<?php
/**
 * Admin AJAX handlers for Rybbit Analytics.
 */
class Rybbit_Analytics_Admin_Ajax {
    public function __construct() {
        add_action('wp_ajax_rybbit_preview_identify_payload', array($this, 'preview_identify_payload'));
        add_action('wp_ajax_rybbit_check_latest_version', array($this, 'check_latest_version'));
    }

    /**
     * Return an example identify payload for the current user using the provided settings.
     *
     * Expects POST:
     * - identify_mode: disabled|pseudonymized|full
     * - userid_strategy: wp_scoped|wp_user_id|user_login|email|user_meta
     * - userid_meta_key: string (only when userid_strategy=user_meta)
     */
    public function preview_identify_payload() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Forbidden'), 403);
        }

        // Provide a friendly JSON error on nonce failure.
        if (!check_ajax_referer('rybbit_admin_settings', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Invalid nonce. Please reload the page.'), 403);
        }

        $identify_mode = isset($_POST['identify_mode']) ? sanitize_text_field(wp_unslash($_POST['identify_mode'])) : 'disabled';
        $userid_strategy = isset($_POST['userid_strategy']) ? sanitize_text_field(wp_unslash($_POST['userid_strategy'])) : 'wp_scoped';
        $userid_meta_key = isset($_POST['userid_meta_key']) ? sanitize_key(wp_unslash($_POST['userid_meta_key'])) : '';

        $allowed_modes = array('disabled', 'pseudonymized', 'full');
        if (!in_array($identify_mode, $allowed_modes, true)) {
            $identify_mode = 'disabled';
        }

        $allowed_strategies = array('wp_scoped', 'wp_user_id', 'user_login', 'email', 'user_meta');
        if (!in_array($userid_strategy, $allowed_strategies, true)) {
            $userid_strategy = 'wp_scoped';
        }

        // Temporarily override options so we can preview without saving.
        add_filter('pre_option_rybbit_identify_userid_strategy', function () use ($userid_strategy) {
            return $userid_strategy;
        });
        add_filter('pre_option_rybbit_identify_userid_meta_key', function () use ($userid_meta_key) {
            return $userid_meta_key;
        });

        $payload = function_exists('rybbit_analytics_get_identify_payload')
            ? rybbit_analytics_get_identify_payload($identify_mode)
            : null;

        // Provide a consistent response shape.
        wp_send_json_success(array(
            'payload' => $payload,
        ));
    }

    /**
     * Force-refresh and return the latest plugin version info.
     */
    public function check_latest_version() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Forbidden'), 403);
        }

        if (!check_ajax_referer('rybbit_admin_settings', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Invalid nonce. Please reload the page.'), 403);
        }

        if (!class_exists('Rybbit_Analytics_Updates')) {
            wp_send_json_error(array('message' => 'Update checker is unavailable.'), 500);
        }

        $installed = Rybbit_Analytics_Updates::get_installed_version();
        $latest = Rybbit_Analytics_Updates::get_latest_version(true);

        $update_available = null;
        if (is_string($installed) && $installed !== '' && is_string($latest) && $latest !== '') {
            $update_available = version_compare($installed, $latest, '<');
        }

        wp_send_json_success(array(
            'installed' => $installed,
            'latest' => $latest,
            'updateAvailable' => $update_available,
        ));
    }
}
