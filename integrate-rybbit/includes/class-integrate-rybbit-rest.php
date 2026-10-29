<?php
/**
 * REST route that gives analytics data to the dashboard scripts.
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the route "integrate-rybbit/v1/stats".
 *
 * The browser never talks to Rybbit. It asks this route. The server adds the
 * API key, so the key never leaves the server.
 */
final class Integrate_Rybbit_Rest {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'integrate-rybbit/v1';

	/**
	 * Route of the statistics.
	 */
	const ROUTE = '/stats';

	/**
	 * Allowed values of the "view" parameter.
	 */
	const VIEWS = array( 'summary', 'full', 'live' );

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'range' => array(
						'type'              => 'string',
						'default'           => Integrate_Rybbit_Options::get_dashboard_range(),
						'enum'              => Integrate_Rybbit_Options::DASHBOARD_RANGES,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'view'  => array(
						'type'              => 'string',
						'default'           => 'full',
						'enum'              => self::VIEWS,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Only users with the dashboard capability may read the data.
	 *
	 * For cookie logins, WordPress checks the REST nonce before this method runs.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( Integrate_Rybbit_Options::get_dashboard_capability() );
	}

	/**
	 * Return the statistics.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_stats( WP_REST_Request $request ) {
		$data = Integrate_Rybbit_Api::get_dashboard( (string) $request['range'], (string) $request['view'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['generated'] = time();

		return rest_ensure_response( $data );
	}
}
