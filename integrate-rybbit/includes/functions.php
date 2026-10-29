<?php
/**
 * General plugin functions.
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'integrate_rybbit_get_identify_payload' ) ) {
	/**
	 * Build the identify payload for the current logged-in user.
	 *
	 * Modes:
	 *  - disabled: returns null.
	 *  - pseudonymized: sends the userId, non-sensitive traits and a hashed email.
	 *  - full: sends the userId and cleartext traits (email, name, username).
	 *
	 * @param string $mode Identify mode.
	 * @return array{userId: string, traits: array<string, mixed>}|null Null if disabled or not logged in.
	 */
	function integrate_rybbit_get_identify_payload( $mode = 'pseudonymized' ) {
		$mode = is_string( $mode ) ? $mode : 'disabled';
		if ( ! in_array( $mode, array( 'pseudonymized', 'full' ), true ) ) {
			return null;
		}

		if ( ! is_user_logged_in() ) {
			return null;
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return null;
		}

		$user_id = integrate_rybbit_get_user_id( $user, $mode );

		$traits = array(
			'roles' => array_values( (array) $user->roles ),
		);

		if ( 'pseudonymized' === $mode ) {
			if ( ! empty( $user->user_login ) ) {
				$traits['username'] = (string) $user->user_login;
			} elseif ( ! empty( $user->display_name ) ) {
				$traits['username'] = (string) $user->display_name;
			}

			if ( ! empty( $user->display_name ) ) {
				$traits['name'] = (string) $user->display_name;
			}

			// Hash only. No cleartext email in this mode.
			if ( ! empty( $user->user_email ) ) {
				$traits['email_hash'] = hash( 'sha256', strtolower( trim( (string) $user->user_email ) ) );
			}
		} else {
			// Cleartext traits (PII). The site owner enabled this explicitly.
			if ( ! empty( $user->user_login ) ) {
				$traits['username'] = (string) $user->user_login;
			}
			if ( ! empty( $user->display_name ) ) {
				$traits['name'] = (string) $user->display_name;
			}
			if ( ! empty( $user->user_email ) ) {
				$traits['email'] = (string) $user->user_email;
			}
		}

		/**
		 * Filter the traits sent with identify().
		 *
		 * @param array<string, mixed> $traits Traits.
		 * @param WP_User              $user   Current user.
		 * @param string               $mode   Identify mode.
		 */
		$traits = apply_filters( 'integrate_rybbit_identify_traits', $traits, $user, $mode );
		$traits = integrate_rybbit_limit_traits( is_array( $traits ) ? $traits : array() );

		return array(
			'userId' => $user_id,
			'traits' => $traits,
		);
	}
}

if ( ! function_exists( 'integrate_rybbit_get_user_id' ) ) {
	/**
	 * Build the userId for a user based on the configured strategy.
	 *
	 * @param WP_User $user User.
	 * @param string  $mode Identify mode.
	 * @return string
	 */
	function integrate_rybbit_get_user_id( WP_User $user, $mode ) {
		$strategy = (string) Integrate_Rybbit_Options::get( 'rybbit_identify_userid_strategy' );
		$blog_id  = (int) get_current_blog_id();

		// Default: stable and scoped to the site. This helps on multisite.
		$user_id = 'wp:' . $blog_id . ':' . (int) $user->ID;

		switch ( $strategy ) {
			case 'hmac':
				// Keyed hash. Without the site salts nobody can map it back to the WordPress user ID.
				$user_id = 'hmac:' . hash_hmac( 'sha256', $blog_id . ':' . (int) $user->ID, wp_salt( 'auth' ) );
				break;

			case 'wp_user_id':
				$user_id = (string) (int) $user->ID;
				break;

			case 'user_login':
				if ( ! empty( $user->user_login ) ) {
					$user_id = (string) $user->user_login;
				}
				break;

			case 'email':
				if ( ! empty( $user->user_email ) ) {
					$email = strtolower( trim( (string) $user->user_email ) );
					// In pseudonymized mode, never use the cleartext email as userId.
					$user_id = 'pseudonymized' === $mode ? 'sha256:' . hash( 'sha256', $email ) : $email;
				}
				break;

			case 'user_meta':
				$meta_key = sanitize_key( (string) Integrate_Rybbit_Options::get( 'rybbit_identify_userid_meta_key' ) );
				if ( '' !== $meta_key ) {
					$meta_val = get_user_meta( (int) $user->ID, $meta_key, true );
					if ( is_scalar( $meta_val ) && '' !== trim( (string) $meta_val ) ) {
						$user_id = trim( (string) $meta_val );
					}
				}
				break;
		}

		/**
		 * Filter the userId sent with identify().
		 *
		 * @param string  $user_id  User ID for Rybbit.
		 * @param WP_User $user     Current user.
		 * @param string  $strategy Configured strategy.
		 */
		$user_id = (string) apply_filters( 'integrate_rybbit_identify_user_id', $user_id, $user, $strategy );

		// Rybbit accepts at most 255 characters.
		return function_exists( 'mb_substr' ) ? mb_substr( $user_id, 0, 255 ) : substr( $user_id, 0, 255 );
	}
}

if ( ! function_exists( 'integrate_rybbit_limit_traits' ) ) {
	/**
	 * Keep the traits below the Rybbit size limit of 2 KB.
	 *
	 * Optional traits are removed first until the JSON fits.
	 *
	 * @param array<string, mixed> $traits Traits.
	 * @return array<string, mixed>
	 */
	function integrate_rybbit_limit_traits( array $traits ) {
		$max_bytes = 2048;

		foreach ( array( 'roles', 'name', 'username' ) as $key ) {
			if ( strlen( (string) wp_json_encode( $traits ) ) <= $max_bytes ) {
				return $traits;
			}
			unset( $traits[ $key ] );
		}

		return strlen( (string) wp_json_encode( $traits ) ) <= $max_bytes ? $traits : array();
	}
}

if ( ! function_exists( 'integrate_rybbit_load_textdomain' ) ) {
	/**
	 * Load the translations that ship with the plugin.
	 *
	 * WordPress only looks in the global languages directory by default.
	 * This loads the bundled .mo file for the current locale from the plugin.
	 * Without a bundled file for the locale, WordPress uses its own language packs.
	 *
	 * @return void
	 */
	function integrate_rybbit_load_textdomain() {
		$locale = determine_locale();
		$mofile = INTEGRATE_RYBBIT_DIR . 'languages/integrate-rybbit-' . $locale . '.mo';

		if ( is_readable( $mofile ) ) {
			load_textdomain( 'integrate-rybbit', $mofile, $locale );
		}
	}
}
