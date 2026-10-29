<?php
/**
 * Central place for option names, defaults and value helpers.
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option names, defaults, normalization and the script attribute builder.
 *
 * Admin, frontend, debug preview, activation and uninstall all use this class.
 * This keeps defaults in one place.
 */
final class Integrate_Rybbit_Options {

	/**
	 * Settings group used by the settings page.
	 */
	const SETTINGS_GROUP = 'integrate_rybbit_settings';

	/**
	 * Option that stores the schema version of the stored options.
	 */
	const DB_VERSION_OPTION = 'integrate_rybbit_db_version';

	/**
	 * Current schema version. Increase it when a migration is added.
	 */
	const DB_VERSION = 2;

	/**
	 * Default script URL of Rybbit Cloud.
	 */
	const DEFAULT_SCRIPT_URL = 'https://app.rybbit.io/api/script.js';

	/**
	 * Maximum length of the data-tag attribute (Rybbit limit).
	 */
	const TAG_MAX_LENGTH = 256;

	/**
	 * Allowed values for the identify mode.
	 */
	const IDENTIFY_MODES = array( 'disabled', 'pseudonymized', 'full' );

	/**
	 * Allowed values for the userId strategy.
	 */
	const USERID_STRATEGIES = array( 'wp_scoped', 'hmac', 'wp_user_id', 'user_login', 'email', 'user_meta' );

	/**
	 * Options from older plugin versions. Uninstall removes them too.
	 */
	const LEGACY_OPTIONS = array( 'rybbit_do_not_track_admins' );

	/**
	 * Documented defaults of the JSON based session replay options.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function replay_json_defaults() {
		return array(
			'rybbit_replay_mask_input_options' => array(
				'password' => true,
				'email'    => true,
			),
			'rybbit_replay_sampling'           => array(
				'mousemove'        => false,
				'mouseInteraction' => array(
					'MouseUp'     => false,
					'MouseDown'   => false,
					'Click'       => true,
					'ContextMenu' => false,
					'DblClick'    => true,
					'Focus'       => true,
					'Blur'        => true,
					'TouchStart'  => false,
					'TouchEnd'    => false,
				),
				'scroll'           => 500,
				'input'            => 'last',
				'media'            => 800,
			),
			'rybbit_replay_slim_dom_options'   => array(
				'script'               => false,
				'comment'              => true,
				'headFavicon'          => true,
				'headWhitespace'       => true,
				'headMetaDescKeywords' => true,
				'headMetaSocial'       => true,
				'headMetaRobots'       => true,
				'headMetaHttpEquiv'    => true,
				'headMetaAuthorship'   => true,
				'headMetaVerification' => true,
			),
		);
	}

	/**
	 * Default value of every plugin option, keyed by option name.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		$json = self::replay_json_defaults();

		return array(
			'rybbit_site_id'                    => '',
			'rybbit_script_url'                 => self::DEFAULT_SCRIPT_URL,
			'rybbit_script_loading'             => 'defer',
			'rybbit_track_wp_admin'             => '0',
			'rybbit_excluded_roles'             => array( 'administrator' ),
			'rybbit_skip_patterns'              => '',
			'rybbit_mask_patterns'              => '',
			'rybbit_debounce'                   => '500',
			'rybbit_tag'                        => '',
			'rybbit_identify_mode'              => 'disabled',
			'rybbit_identify_userid_strategy'   => 'wp_scoped',
			'rybbit_identify_userid_meta_key'   => '',
			'rybbit_replay_mask_text_selectors' => '',
			'rybbit_replay_block_class'         => 'rr-block',
			'rybbit_replay_block_selector'      => '',
			'rybbit_replay_ignore_class'        => 'rr-ignore',
			'rybbit_replay_ignore_selector'     => '',
			'rybbit_replay_mask_text_class'     => 'rr-mask',
			'rybbit_replay_mask_all_inputs'     => '1',
			'rybbit_replay_mask_input_options'  => self::encode_json( $json['rybbit_replay_mask_input_options'] ),
			'rybbit_replay_collect_fonts'       => '1',
			'rybbit_replay_sampling'            => self::encode_json( $json['rybbit_replay_sampling'] ),
			'rybbit_replay_slim_dom_options'    => self::encode_json( $json['rybbit_replay_slim_dom_options'] ),
			'rybbit_delete_data_on_uninstall'   => '0',
		);
	}

	/**
	 * Default value of one option.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	public static function default_value( $name ) {
		$defaults = self::defaults();
		return array_key_exists( $name, $defaults ) ? $defaults[ $name ] : false;
	}

	/**
	 * Read an option and fall back to the plugin default.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	public static function get( $name ) {
		return get_option( $name, self::default_value( $name ) );
	}

	/**
	 * All option names the plugin can store. Uninstall uses this list.
	 *
	 * @return string[]
	 */
	public static function all_option_names() {
		return array_merge(
			array_keys( self::defaults() ),
			self::LEGACY_OPTIONS,
			array( self::DB_VERSION_OPTION )
		);
	}

	/**
	 * Run migrations when the stored schema version is older than the code.
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		if ( $installed < 2 ) {
			self::upgrade_to_2();
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Version 2 replaces the "do not track admins" checkbox with the role list.
	 *
	 * Old logic: if the role list exists, use it. If not, exclude admins
	 * unless the old checkbox is "0". The migration keeps that result.
	 */
	private static function upgrade_to_2() {
		$missing = new stdClass();
		$roles   = get_option( 'rybbit_excluded_roles', $missing );
		$legacy  = get_option( 'rybbit_do_not_track_admins', $missing );

		if ( $roles === $missing ) {
			$track_admins = $legacy !== $missing && '1' !== (string) $legacy;
			add_option( 'rybbit_excluded_roles', $track_admins ? array() : array( 'administrator' ) );
		} elseif ( ! is_array( $roles ) ) {
			update_option( 'rybbit_excluded_roles', self::normalize_roles( $roles ) );
		}

		delete_option( 'rybbit_do_not_track_admins' );
	}

	/**
	 * Encode data as compact JSON without escaped slashes or unicode.
	 *
	 * @param mixed $data   Data to encode.
	 * @param bool  $pretty Pretty print the output.
	 * @return string
	 */
	public static function encode_json( $data, $pretty = false ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		$json = wp_json_encode( $data, $flags );
		return false === $json ? '' : $json;
	}

	/**
	 * Normalize a checkbox value to "1" or "0".
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_checkbox( $value ) {
		return ( '1' === $value || 1 === $value || true === $value || 'on' === $value ) ? '1' : '0';
	}

	/**
	 * Debounce in milliseconds. Invalid values fall back to the default.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_debounce( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || ! preg_match( '/^\d+$/', $value ) ) {
			return (string) self::default_value( 'rybbit_debounce' );
		}
		return (string) absint( $value );
	}

	/**
	 * Free text tag. Rybbit allows at most 256 characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_tag( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::TAG_MAX_LENGTH );
		}
		return substr( $value, 0, self::TAG_MAX_LENGTH );
	}

	/**
	 * Single CSS class name. An empty result falls back to the default.
	 *
	 * @param mixed  $value         Raw value.
	 * @param string $default_class Fallback class.
	 * @return string
	 */
	public static function sanitize_css_class( $value, $default_class ) {
		$value = sanitize_html_class( trim( (string) $value ) );
		return '' === $value ? (string) $default_class : $value;
	}

	/**
	 * Single CSS selector. Removes tags and line breaks but keeps
	 * combinators such as ">" and "~".
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_css_selector( $value ) {
		return trim( wp_strip_all_tags( (string) $value, true ) );
	}

	/**
	 * Newline separated list. Trims lines and removes empty lines.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_lines( $value ) {
		return implode( "\n", self::lines_to_list( $value, false ) );
	}

	/**
	 * Convert a newline separated list to an array of strings.
	 *
	 * Older versions could store a JSON array. That format is still accepted.
	 *
	 * @param mixed $value     Raw value.
	 * @param bool  $allow_json Accept a JSON array string.
	 * @return string[]
	 */
	public static function lines_to_list( $value, $allow_json = true ) {
		if ( is_array( $value ) ) {
			$items = $value;
		} else {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return array();
			}

			$decoded = $allow_json ? json_decode( $value, true ) : null;
			if ( is_array( $decoded ) ) {
				$items = $decoded;
			} else {
				$items = preg_split( '/\r\n|\r|\n/', $value );
			}
		}

		$out = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			// Keep "<" and ">" because regex patterns can use them. The value is escaped on output.
			$item = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', wp_check_invalid_utf8( (string) $item ) ) );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalize a stored JSON object option.
	 *
	 * @param mixed $value         Raw value.
	 * @param bool  $allow_boolean Accept "true" and "false" (slimDOM option).
	 * @return string|null Canonical JSON, "true", "false" or "" for empty. Null if invalid.
	 */
	public static function normalize_json_object( $value, $allow_boolean = false ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$lower = strtolower( $value );
		if ( $allow_boolean && ( 'true' === $lower || 'false' === $lower ) ) {
			return $lower;
		}

		// Decode as objects so "{}" stays an object and key order is kept.
		$decoded = json_decode( $value );
		if ( ! is_object( $decoded ) ) {
			return null;
		}

		return self::encode_json( $decoded );
	}

	/**
	 * Normalize a role list to existing role slugs.
	 *
	 * @param mixed $value Array, JSON array string or newline separated list.
	 * @return string[]
	 */
	public static function normalize_roles( $value ) {
		$roles = array_map( 'sanitize_key', self::lines_to_list( $value ) );
		$roles = array_filter( $roles );

		$existing = array_keys( wp_roles()->roles );

		return array_values( array_intersect( array_unique( $roles ), $existing ) );
	}

	/**
	 * Roles that never receive the tracking script.
	 *
	 * @return string[]
	 */
	public static function get_excluded_roles() {
		$value = self::get( 'rybbit_excluded_roles' );
		$roles = array_filter( array_map( 'sanitize_key', self::lines_to_list( $value ) ) );
		return array_values( array_unique( $roles ) );
	}

	/**
	 * Check if a user has one of the excluded roles.
	 *
	 * @param WP_User|null $user User to check. Defaults to the current user.
	 * @return bool
	 */
	public static function is_user_excluded( $user = null ) {
		if ( null === $user ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			$user = wp_get_current_user();
		}

		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		$excluded = self::get_excluded_roles();
		if ( empty( $excluded ) ) {
			return false;
		}

		return ! empty( array_intersect( $excluded, (array) $user->roles ) );
	}

	/**
	 * Site ID and script URL are both set.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) self::get( 'rybbit_site_id' ) )
			&& '' !== trim( (string) self::get( 'rybbit_script_url' ) );
	}

	/**
	 * Script loading strategy: "defer" or "async".
	 *
	 * @return string
	 */
	public static function get_script_strategy() {
		return 'async' === self::get( 'rybbit_script_loading' ) ? 'async' : 'defer';
	}

	/**
	 * Script URL with the site ID as query parameter.
	 *
	 * Rybbit reads "?siteId=" first. Optimizer plugins often remove
	 * data attributes, but they keep the URL.
	 *
	 * @return string Empty string if the plugin is not configured.
	 */
	public static function get_script_src() {
		if ( ! self::is_configured() ) {
			return '';
		}

		$url     = trim( (string) self::get( 'rybbit_script_url' ) );
		$site_id = trim( (string) self::get( 'rybbit_site_id' ) );

		return add_query_arg( 'siteId', rawurlencode( $site_id ), $url );
	}

	/**
	 * Data attributes for the tracking script tag.
	 *
	 * Attributes with the documented default value are left out. This keeps
	 * the tag short and lets Rybbit change defaults later.
	 *
	 * @return array<string, string>
	 */
	public static function build_script_attributes() {
		$attributes = array(
			'data-site-id' => trim( (string) self::get( 'rybbit_site_id' ) ),
		);

		$skip = self::lines_to_list( self::get( 'rybbit_skip_patterns' ) );
		if ( ! empty( $skip ) ) {
			$attributes['data-skip-patterns'] = self::encode_json( $skip );
		}

		$mask = self::lines_to_list( self::get( 'rybbit_mask_patterns' ) );
		if ( ! empty( $mask ) ) {
			$attributes['data-mask-patterns'] = self::encode_json( $mask );
		}

		$debounce = self::sanitize_debounce( self::get( 'rybbit_debounce' ) );
		if ( self::default_value( 'rybbit_debounce' ) !== $debounce ) {
			$attributes['data-debounce'] = $debounce;
		}

		$tag = self::sanitize_tag( self::get( 'rybbit_tag' ) );
		if ( '' !== $tag ) {
			$attributes['data-tag'] = $tag;
		}

		$selectors = self::lines_to_list( self::get( 'rybbit_replay_mask_text_selectors' ) );
		if ( ! empty( $selectors ) ) {
			$attributes['data-replay-mask-text-selectors'] = self::encode_json( $selectors );
		}

		$classes = array(
			'rybbit_replay_block_class'     => 'data-replay-block-class',
			'rybbit_replay_ignore_class'    => 'data-replay-ignore-class',
			'rybbit_replay_mask_text_class' => 'data-replay-mask-text-class',
		);
		foreach ( $classes as $option => $attribute ) {
			$default = (string) self::default_value( $option );
			$value   = self::sanitize_css_class( self::get( $option ), $default );
			if ( $default !== $value ) {
				$attributes[ $attribute ] = $value;
			}
		}

		$selector_options = array(
			'rybbit_replay_block_selector'  => 'data-replay-block-selector',
			'rybbit_replay_ignore_selector' => 'data-replay-ignore-selector',
		);
		foreach ( $selector_options as $option => $attribute ) {
			$value = self::sanitize_css_selector( self::get( $option ) );
			if ( '' !== $value ) {
				$attributes[ $attribute ] = $value;
			}
		}

		$booleans = array(
			'rybbit_replay_mask_all_inputs' => 'data-replay-mask-all-inputs',
			'rybbit_replay_collect_fonts'   => 'data-replay-collect-fonts',
		);
		foreach ( $booleans as $option => $attribute ) {
			$value = self::sanitize_checkbox( self::get( $option ) );
			if ( self::default_value( $option ) !== $value ) {
				$attributes[ $attribute ] = '1' === $value ? 'true' : 'false';
			}
		}

		$json_options = array(
			'rybbit_replay_mask_input_options' => 'data-replay-mask-input-options',
			'rybbit_replay_sampling'           => 'data-replay-sampling',
			'rybbit_replay_slim_dom_options'   => 'data-replay-slim-dom-options',
		);
		foreach ( $json_options as $option => $attribute ) {
			$allow_boolean = 'rybbit_replay_slim_dom_options' === $option;
			$value         = self::normalize_json_object( self::get( $option ), $allow_boolean );
			if ( null !== $value && '' !== $value && self::default_value( $option ) !== $value ) {
				$attributes[ $attribute ] = $value;
			}
		}

		/**
		 * Filter the data attributes of the Rybbit tracking script tag.
		 *
		 * Use this to add attributes the settings page does not cover,
		 * for example "data-namespace".
		 *
		 * @param array<string, string> $attributes Attribute name => value.
		 */
		$attributes = apply_filters( 'integrate_rybbit_script_attributes', $attributes );

		return is_array( $attributes ) ? $attributes : array();
	}
}
