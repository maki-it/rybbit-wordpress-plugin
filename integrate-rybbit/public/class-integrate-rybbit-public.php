<?php
/**
 * Frontend output of the Rybbit tracking script.
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the tracking script and the identify helper.
 */
final class Integrate_Rybbit_Public {

	/**
	 * Handle of the Rybbit tracking script.
	 */
	const SCRIPT_HANDLE = 'rybbit-tracking-script';

	/**
	 * Handle of the helper that calls identify() and clearUserId().
	 */
	const HELPER_HANDLE = 'integrate-rybbit-helpers';

	/**
	 * Cookie that marks a logout. The next page view clears the Rybbit user ID.
	 */
	const CLEAR_COOKIE = 'rybbit_clear_user_id';

	/**
	 * True when the current request must clear the Rybbit user ID.
	 *
	 * @var bool
	 */
	private $clear_user = false;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'consume_logout_marker' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_filter( 'wp_script_attributes', array( $this, 'filter_script_attributes' ) );
		add_action( 'wp_logout', array( $this, 'set_logout_marker' ) );
		add_action( 'wp_login', array( $this, 'delete_logout_marker' ) );
	}

	/**
	 * Enqueue on frontend pages.
	 */
	public function enqueue_frontend() {
		$this->enqueue();
	}

	/**
	 * Enqueue in wp-admin only when the site owner enabled it.
	 */
	public function enqueue_admin() {
		if ( '1' !== Integrate_Rybbit_Options::sanitize_checkbox( Integrate_Rybbit_Options::get( 'rybbit_track_wp_admin' ) ) ) {
			return;
		}
		$this->enqueue();
	}

	/**
	 * Check if the tracking script loads for the current request.
	 *
	 * @return bool
	 */
	public static function should_track() {
		if ( ! Integrate_Rybbit_Options::is_configured() ) {
			return false;
		}

		$track = ! Integrate_Rybbit_Options::is_user_excluded();

		/**
		 * Filter if the Rybbit tracking script loads for the current request.
		 *
		 * @param bool $track True to load the tracking script.
		 */
		return (bool) apply_filters( 'integrate_rybbit_should_track', $track );
	}

	/**
	 * Register and enqueue the tracking script and, if needed, the helper.
	 */
	private function enqueue() {
		if ( ! self::should_track() ) {
			return;
		}

		// The version is null: the external script has its own cache rules and must not get "?ver=".
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			Integrate_Rybbit_Options::get_script_src(),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			array(
				'in_footer' => false,
				'strategy'  => Integrate_Rybbit_Options::get_script_strategy(),
			)
		);

		$identify = $this->get_identify_config();
		$events   = $this->get_events_config();
		if ( ! $this->clear_user && null === $identify && empty( $events ) ) {
			return;
		}

		wp_enqueue_script(
			self::HELPER_HANDLE,
			INTEGRATE_RYBBIT_URL . 'public/js/rybbit-helpers.js',
			array(),
			INTEGRATE_RYBBIT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'clearUser' => $this->clear_user,
			'identify'  => $identify,
			'events'    => $events,
		);

		wp_add_inline_script(
			self::HELPER_HANDLE,
			'window.integrateRybbitConfig = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);
	}

	/**
	 * Custom event rules for the helper script.
	 *
	 * @return array<int, array{selector: string, event: string, props: object}>
	 */
	private function get_events_config() {
		$events = array();

		foreach ( Integrate_Rybbit_Options::get_custom_events() as $rule ) {
			$events[] = array(
				'selector' => $rule['selector'],
				'event'    => $rule['event'],
				// An object keeps numeric keys and an empty list as a JSON object.
				'props'    => (object) $rule['props'],
			);
		}

		return $events;
	}

	/**
	 * Identify payload for the current user.
	 *
	 * @return array{userId: string, traits: array<string, mixed>}|null
	 */
	private function get_identify_config() {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$mode    = (string) Integrate_Rybbit_Options::get( 'rybbit_identify_mode' );
		$payload = integrate_rybbit_get_identify_payload( $mode );

		if ( ! is_array( $payload ) || empty( $payload['userId'] ) ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Add the data attributes to the tracking script tag.
	 *
	 * @param array<string, string|bool> $attributes Script tag attributes.
	 * @return array<string, string|bool>
	 */
	public function filter_script_attributes( $attributes ) {
		if ( ! is_array( $attributes ) || ! isset( $attributes['id'] ) || self::SCRIPT_HANDLE . '-js' !== $attributes['id'] ) {
			return $attributes;
		}

		return array_merge( $attributes, Integrate_Rybbit_Options::build_script_attributes() );
	}

	/**
	 * Read the logout marker on the first frontend request after logout.
	 *
	 * The page must not be cached because it contains a one-time instruction.
	 */
	public function consume_logout_marker() {
		if ( empty( $_COOKIE[ self::CLEAR_COOKIE ] ) || is_user_logged_in() ) {
			return;
		}

		if ( ! self::should_track() ) {
			return;
		}

		$this->clear_user = true;

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant of page cache plugins.
		}
		nocache_headers();

		$this->delete_logout_marker();
	}

	/**
	 * Set the logout marker cookie.
	 */
	public function set_logout_marker() {
		$this->send_marker_cookie( '1', time() + DAY_IN_SECONDS );
	}

	/**
	 * Delete the logout marker cookie.
	 */
	public function delete_logout_marker() {
		if ( ! isset( $_COOKIE[ self::CLEAR_COOKIE ] ) ) {
			return;
		}

		$this->send_marker_cookie( '', time() - YEAR_IN_SECONDS );
		unset( $_COOKIE[ self::CLEAR_COOKIE ] );
	}

	/**
	 * Send the marker cookie with safe flags.
	 *
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry timestamp.
	 */
	private function send_marker_cookie( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::CLEAR_COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
