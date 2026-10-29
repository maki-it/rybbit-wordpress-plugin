<?php
/**
 * Client for the Rybbit Stats API.
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads analytics data from the Rybbit Stats API.
 *
 * The API is in beta. The parser is strict about types and tolerant about
 * missing fields. Responses are cached in transients to save API requests.
 * Rybbit Cloud limits the number of requests per day.
 *
 * Every method that fetches data returns a value or a WP_Error. The error data
 * holds an HTTP status for the REST response and sometimes "retry_after".
 */
final class Integrate_Rybbit_Api {

	/**
	 * Prefix of all transient names.
	 */
	const TRANSIENT_PREFIX = 'rybbit_api_';

	/**
	 * Transient that blocks requests after a rate limit response.
	 */
	const LOCK_TRANSIENT = 'rybbit_api_lock';

	/**
	 * Timeout of one API request in seconds.
	 */
	const TIMEOUT = 8;

	/**
	 * Longest cache time of the live visitor count in seconds.
	 */
	const LIVE_TTL = 60;

	/**
	 * Longest time that a rate limit blocks new requests in seconds.
	 */
	const MAX_LOCK_SECONDS = 3600;

	/**
	 * Number of rows of each top list.
	 */
	const TOP_LIMIT = 8;

	/**
	 * Top lists. Key is the name in the result, value is the Rybbit metric parameter.
	 */
	const METRICS = array(
		'pages'     => 'pathname',
		'referrers' => 'referrer',
		'countries' => 'country',
		'devices'   => 'device_type',
		'browsers'  => 'browser',
	);

	/**
	 * Clear the cache when the key, the URL or the site ID change.
	 *
	 * Without this, data of an old key or site could stay visible.
	 */
	public static function register_hooks() {
		foreach ( array( 'rybbit_api_key', 'rybbit_api_url', 'rybbit_site_id', 'rybbit_script_url' ) as $name ) {
			add_action( 'add_option_' . $name, array( __CLASS__, 'clear_cache' ) );
			add_action( 'update_option_' . $name, array( __CLASS__, 'clear_cache' ) );
			add_action( 'delete_option_' . $name, array( __CLASS__, 'clear_cache' ) );
		}
	}

	/**
	 * Make all cached responses invalid and remove a rate limit block.
	 *
	 * Old transients are not deleted. They expire by themselves.
	 */
	public static function clear_cache() {
		update_option( Integrate_Rybbit_Options::API_CACHE_VERSION_OPTION, wp_generate_password( 8, false ), false );
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Time zone name for API requests. Falls back to UTC.
	 *
	 * The API needs an IANA name. WordPress can also return an offset like "+02:00".
	 *
	 * @return string
	 */
	public static function get_timezone_name() {
		$name = wp_timezone_string();
		return in_array( $name, timezone_identifiers_list(), true ) ? $name : 'UTC';
	}

	/**
	 * Query parameters for the time range and the best bucket size.
	 *
	 * @param string $range One of Integrate_Rybbit_Options::DASHBOARD_RANGES.
	 * @return array{time: array<string, string|int>, bucket: string}
	 */
	public static function get_range_query( $range ) {
		$range    = Integrate_Rybbit_Options::sanitize_dashboard_range( $range );
		$timezone = self::get_timezone_name();

		if ( '24h' === $range ) {
			// The end is the next full hour (exclusive). So the cache key stays the same for one hour.
			$end = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			$end = $end->setTime( (int) $end->format( 'G' ) + 1, 0, 0 );

			return array(
				'time'   => array(
					'start_datetime' => $end->modify( '-24 hours' )->format( 'Y-m-d H:i:s' ),
					'end_datetime'   => $end->format( 'Y-m-d H:i:s' ),
					'time_zone'      => $timezone,
				),
				'bucket' => 'hour',
			);
		}

		$days  = (int) $range;
		$end   = new DateTimeImmutable( 'now', new DateTimeZone( $timezone ) );
		$start = $end->modify( '-' . ( $days - 1 ) . ' days' );

		return array(
			'time'   => array(
				'start_date' => $start->format( 'Y-m-d' ),
				'end_date'   => $end->format( 'Y-m-d' ),
				'time_zone'  => $timezone,
			),
			'bucket' => 'day',
		);
	}

	/**
	 * Check the connection. Asks if the site has any data.
	 *
	 * This request skips the cache and the rate limit block.
	 *
	 * @return array{has_data: bool}|WP_Error
	 */
	public static function test_connection() {
		$body = self::request( '/has-data', array(), 0, true );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return array( 'has_data' => ! empty( $body['hasData'] ) );
	}

	/**
	 * Data for the dashboard.
	 *
	 * The overview must work. A failed top list or live count only adds a warning.
	 *
	 * @param string $range Time range.
	 * @param string $view  "summary" (overview, chart, live), "full" (all) or "live" (live count only).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_dashboard( $range, $view = 'full' ) {
		$range = Integrate_Rybbit_Options::sanitize_dashboard_range( $range );

		if ( 'live' === $view ) {
			$live = self::get_live_count();
			return is_wp_error( $live ) ? $live : array( 'live' => $live );
		}

		$overview = self::get_overview( $range );
		if ( is_wp_error( $overview ) ) {
			return $overview;
		}

		$result = array(
			'range'    => $range,
			'overview' => $overview,
			'series'   => array(),
			'live'     => null,
			'warnings' => array(),
		);

		$series = self::get_time_series( $range );
		if ( is_wp_error( $series ) ) {
			$result['warnings'][] = 'series';
		} else {
			$result['series'] = $series;
		}

		$live = self::get_live_count();
		if ( is_wp_error( $live ) ) {
			$result['warnings'][] = 'live';
		} else {
			$result['live'] = $live;
		}

		if ( 'full' === $view ) {
			$result['top'] = array();
			foreach ( array_keys( self::METRICS ) as $name ) {
				$rows = self::get_metric( $range, $name );
				if ( is_wp_error( $rows ) ) {
					$result['warnings'][] = $name;
					$rows                 = array();
				}
				$result['top'][ $name ] = $rows;
			}
		}

		return $result;
	}

	/**
	 * Totals of the time range.
	 *
	 * @param string $range Time range.
	 * @return array<string, int|float>|WP_Error
	 */
	public static function get_overview( $range ) {
		$query = self::get_range_query( $range );
		$body  = self::request( '/overview', $query['time'], Integrate_Rybbit_Options::get_cache_seconds() );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$data = self::data_of( $body );
		return array(
			'sessions'          => (int) self::number( $data, 'sessions' ),
			'pageviews'         => (int) self::number( $data, 'pageviews' ),
			'users'             => (int) self::number( $data, 'users' ),
			'pages_per_session' => self::number( $data, 'pages_per_session' ),
			'bounce_rate'       => self::number( $data, 'bounce_rate' ),
			'session_duration'  => self::number( $data, 'session_duration' ),
		);
	}

	/**
	 * Visitors, sessions and pageviews per time bucket.
	 *
	 * @param string $range Time range.
	 * @return array<int, array<string, int|string>>|WP_Error
	 */
	public static function get_time_series( $range ) {
		$query = self::get_range_query( $range );
		$body  = self::request(
			'/overview/time-series',
			array_merge( $query['time'], array( 'bucket' => $query['bucket'] ) ),
			Integrate_Rybbit_Options::get_cache_seconds()
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$rows = array();
		foreach ( self::data_of( $body ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['time'] ) || ! is_scalar( $item['time'] ) ) {
				continue;
			}

			$time = substr( sanitize_text_field( (string) $item['time'] ), 0, 32 );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $time ) ) {
				continue;
			}

			$rows[] = array(
				'time'      => $time,
				'sessions'  => (int) self::number( $item, 'sessions' ),
				'pageviews' => (int) self::number( $item, 'pageviews' ),
				'users'     => (int) self::number( $item, 'users' ),
			);
		}

		return $rows;
	}

	/**
	 * One top list.
	 *
	 * @param string $range Time range.
	 * @param string $name  Key of self::METRICS.
	 * @return array<int, array<string, int|float|string>>|WP_Error
	 */
	public static function get_metric( $range, $name ) {
		if ( ! isset( self::METRICS[ $name ] ) ) {
			return new WP_Error( 'rybbit_invalid_metric', __( 'Unknown metric.', 'integrate-rybbit' ), array( 'status' => 400 ) );
		}

		$query = self::get_range_query( $range );
		$body  = self::request(
			'/metric',
			array_merge(
				$query['time'],
				array(
					'parameter' => self::METRICS[ $name ],
					'limit'     => self::TOP_LIMIT,
					'page'      => 1,
				)
			),
			Integrate_Rybbit_Options::get_cache_seconds()
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// The list is in "data.data". Older or newer versions may send it in "data".
		$data = self::data_of( $body );
		$list = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;

		$rows = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || ! array_key_exists( 'value', $item ) || ! is_scalar( $item['value'] ) ) {
				continue;
			}

			$rows[] = array(
				'value'      => mb_substr( wp_strip_all_tags( (string) $item['value'], true ), 0, 200 ),
				'count'      => (int) self::number( $item, 'count' ),
				'percentage' => self::number( $item, 'percentage' ),
			);

			if ( count( $rows ) >= self::TOP_LIMIT ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Visitors on the site in the last five minutes.
	 *
	 * @return int|WP_Error
	 */
	public static function get_live_count() {
		$ttl  = min( self::LIVE_TTL, Integrate_Rybbit_Options::get_cache_seconds() );
		$body = self::request( '/live-user-count', array( 'minutes' => 5 ), $ttl );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return (int) self::number( $body, 'count' );
	}

	/**
	 * The payload of a response. Rybbit wraps most results in "data".
	 *
	 * @param array<string, mixed> $body Decoded body.
	 * @return array<mixed>
	 */
	private static function data_of( array $body ) {
		return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
	}

	/**
	 * A number from an array. Missing or invalid values count as 0.
	 *
	 * @param array<mixed> $data Source.
	 * @param string       $key  Key.
	 * @return int|float
	 */
	private static function number( $data, $key ) {
		return isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? $data[ $key ] + 0 : 0;
	}

	/**
	 * Send a GET request to the API and return the decoded JSON body.
	 *
	 * @param string               $path      Path below "/api/sites/{site}".
	 * @param array<string, mixed> $query     Query parameters.
	 * @param int                  $ttl       Cache time in seconds. 0 turns the cache off.
	 * @param bool                 $bypass    Skip the cache and the rate limit block.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function request( $path, array $query, $ttl, $bypass = false ) {
		$api_key = Integrate_Rybbit_Options::get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'rybbit_no_api_key', __( 'No API key is set.', 'integrate-rybbit' ), array( 'status' => 400 ) );
		}

		$site_id = trim( (string) Integrate_Rybbit_Options::get( 'rybbit_site_id' ) );
		if ( '' === $site_id ) {
			return new WP_Error( 'rybbit_no_site_id', __( 'No Site ID is set.', 'integrate-rybbit' ), array( 'status' => 400 ) );
		}

		$base = Integrate_Rybbit_Options::get_api_base_url();
		if ( '' === $base ) {
			return new WP_Error( 'rybbit_bad_url', __( 'The API URL is not valid. It must use HTTPS.', 'integrate-rybbit' ), array( 'status' => 400 ) );
		}

		$use_cache = ! $bypass && $ttl > 0;
		$cache_key = self::cache_key( $base, $site_id, $path, $query );

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! $bypass ) {
			$blocked_until = (int) get_transient( self::LOCK_TRANSIENT );
			if ( $blocked_until > time() ) {
				return self::rate_limit_error( $blocked_until - time() );
			}
		}

		$url = $base . '/api/sites/' . rawurlencode( $site_id ) . $path;
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		/**
		 * Filter the HTTP arguments of a request to the Rybbit API.
		 *
		 * Use this for special setups, for example a longer timeout. Do not
		 * add the API key to other requests.
		 *
		 * @param array<string, mixed> $args HTTP arguments for wp_remote_get().
		 * @param string               $url  Request URL.
		 */
		$args = apply_filters(
			'integrate_rybbit_api_http_args',
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
			),
			$url
		);

		$response = wp_remote_get( $url, is_array( $args ) ? $args : array() );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'rybbit_unreachable',
				sprintf(
					/* translators: %s: Technical error message. */
					__( 'Could not reach the Rybbit server: %s', 'integrate-rybbit' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			return self::error_for_status( $status, $response, is_array( $body ) ? $body : array() );
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'rybbit_bad_response', __( 'The Rybbit server sent an invalid response.', 'integrate-rybbit' ), array( 'status' => 502 ) );
		}

		if ( $bypass ) {
			delete_transient( self::LOCK_TRANSIENT );
		}

		if ( $use_cache ) {
			set_transient( $cache_key, $body, $ttl );
		}

		return $body;
	}

	/**
	 * Name of the transient for one request.
	 *
	 * The name changes when the cache version, the URL, the site or the query change.
	 *
	 * @param string               $base    API base URL.
	 * @param string               $site_id Site ID.
	 * @param string               $path    Path.
	 * @param array<string, mixed> $query   Query parameters.
	 * @return string
	 */
	private static function cache_key( $base, $site_id, $path, array $query ) {
		$version = (string) get_option( Integrate_Rybbit_Options::API_CACHE_VERSION_OPTION, '1' );
		$hash    = md5( $version . '|' . $base . '|' . $site_id . '|' . $path . '|' . wp_json_encode( $query ) );

		return self::TRANSIENT_PREFIX . $hash;
	}

	/**
	 * Build the error for a response that is not "200 OK".
	 *
	 * @param int                  $status   HTTP status.
	 * @param array<string, mixed> $response HTTP response.
	 * @param array<string, mixed> $body     Decoded body. Can be empty.
	 * @return WP_Error
	 */
	private static function error_for_status( $status, $response, array $body ) {
		switch ( $status ) {
			case 401:
				return new WP_Error( 'rybbit_unauthorized', __( 'The API key was rejected. Check the key.', 'integrate-rybbit' ), array( 'status' => 502 ) );

			case 403:
				return new WP_Error(
					'rybbit_forbidden',
					__( 'The API key has no access to this site. Check the key permissions and your Rybbit plan.', 'integrate-rybbit' ),
					array( 'status' => 502 )
				);

			case 404:
				return new WP_Error( 'rybbit_not_found', __( 'Site not found. Check the Site ID.', 'integrate-rybbit' ), array( 'status' => 502 ) );

			case 429:
				$seconds = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				if ( $seconds < 1 && isset( $body['retryAfter'] ) && is_numeric( $body['retryAfter'] ) ) {
					$seconds = (int) $body['retryAfter'];
				}
				$seconds = min( self::MAX_LOCK_SECONDS, max( 1, $seconds ) );

				set_transient( self::LOCK_TRANSIENT, time() + $seconds, $seconds );
				return self::rate_limit_error( $seconds );
		}

		if ( 400 === $status && isset( $body['error'] ) && is_scalar( $body['error'] ) ) {
			return new WP_Error(
				'rybbit_bad_request',
				sprintf(
					/* translators: %s: Error message of the Rybbit API. */
					__( 'The Rybbit server rejected the request: %s', 'integrate-rybbit' ),
					mb_substr( sanitize_text_field( (string) $body['error'] ), 0, 200 )
				),
				array( 'status' => 502 )
			);
		}

		return new WP_Error(
			$status >= 500 ? 'rybbit_server_error' : 'rybbit_http_error',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The Rybbit server sent an unexpected response (HTTP %d).', 'integrate-rybbit' ),
				$status
			),
			array( 'status' => 502 )
		);
	}

	/**
	 * Error for a reached rate limit.
	 *
	 * @param int $seconds Seconds until a new request is allowed.
	 * @return WP_Error
	 */
	private static function rate_limit_error( $seconds ) {
		return new WP_Error(
			'rybbit_rate_limited',
			__( 'The Rybbit API rate limit is reached. Try again later.', 'integrate-rybbit' ),
			array(
				'status'      => 429,
				'retry_after' => (int) $seconds,
			)
		);
	}
}
