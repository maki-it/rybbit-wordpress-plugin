<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Admin AJAX handlers for Integrate Rybbit.
 */
class Integrate_Rybbit_Admin_Ajax {
    public function __construct() {
        add_action('wp_ajax_rybbit_preview_identify_payload', array($this, 'preview_identify_payload'));
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

        $payload = function_exists('integrate_rybbit_get_identify_payload')
            ? integrate_rybbit_get_identify_payload($identify_mode)
            : null;

        // Provide a consistent response shape.
        wp_send_json_success(array(
            'payload' => $payload,
        ));
    }
}
