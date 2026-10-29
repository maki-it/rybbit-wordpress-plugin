<?php
/**
 * Admin AJAX handlers.
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview of the identify payload on the settings page.
 */
final class Integrate_Rybbit_Admin_Ajax {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_rybbit_preview_identify_payload', array( $this, 'preview_identify_payload' ) );
	}

	/**
	 * Return an example identify payload for the current user with unsaved settings.
	 *
	 * Expects POST fields:
	 * - identify_mode: disabled|pseudonymized|full
	 * - userid_strategy: one of Integrate_Rybbit_Options::USERID_STRATEGIES
	 * - userid_meta_key: string, used only with the user_meta strategy
	 */
	public function preview_identify_payload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'integrate-rybbit' ) ), 403 );
		}

		if ( ! check_ajax_referer( 'rybbit_admin_settings', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token. Please reload the page.', 'integrate-rybbit' ) ), 403 );
		}

		$identify_mode   = isset( $_POST['identify_mode'] ) ? sanitize_key( wp_unslash( $_POST['identify_mode'] ) ) : 'disabled';
		$userid_strategy = isset( $_POST['userid_strategy'] ) ? sanitize_key( wp_unslash( $_POST['userid_strategy'] ) ) : 'wp_scoped';
		$userid_meta_key = isset( $_POST['userid_meta_key'] ) ? sanitize_key( wp_unslash( $_POST['userid_meta_key'] ) ) : '';

		if ( ! in_array( $identify_mode, Integrate_Rybbit_Options::IDENTIFY_MODES, true ) ) {
			$identify_mode = 'disabled';
		}

		if ( ! in_array( $userid_strategy, Integrate_Rybbit_Options::USERID_STRATEGIES, true ) ) {
			$userid_strategy = 'wp_scoped';
		}

		// Override the options for this request only. Nothing is saved.
		add_filter(
			'pre_option_rybbit_identify_userid_strategy',
			static function () use ( $userid_strategy ) {
				return $userid_strategy;
			}
		);
		add_filter(
			'pre_option_rybbit_identify_userid_meta_key',
			static function () use ( $userid_meta_key ) {
				return $userid_meta_key;
			}
		);

		wp_send_json_success(
			array(
				'payload' => integrate_rybbit_get_identify_payload( $identify_mode ),
			)
		);
	}
}
