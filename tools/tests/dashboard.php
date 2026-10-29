<?php
/**
 * Smoke test of the analytics dashboard backend.
 *
 * It runs inside the dev WordPress against the mock Rybbit API. Start it with
 * "make test-dashboard". The test changes the plugin settings of the dev site.
 *
 * @package Integrate_Rybbit
 */

// phpcs:disable

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run this file with "wp eval-file".' );
}

const MOCK = 'http://mock-rybbit:3000';

// WP-CLI runs this file inside a function, so the counters live in $GLOBALS.
$GLOBALS['rybbit_test'] = array(
	'failures' => 0,
	'total'    => 0,
);

function check( $label, $condition, $detail = '' ) {
	++$GLOBALS['rybbit_test']['total'];
	if ( $condition ) {
		WP_CLI::log( "  ok    $label" );
		return;
	}
	++$GLOBALS['rybbit_test']['failures'];
	WP_CLI::log( "  FAIL  $label" . ( '' !== $detail ? " ($detail)" : '' ) );
}

function mock_requests() {
	$res  = wp_remote_get( MOCK . '/__stats' );
	$body = is_wp_error( $res ) ? array() : json_decode( wp_remote_retrieve_body( $res ), true );
	return isset( $body['requests'] ) ? (int) $body['requests'] : -1;
}

function mock_reset() {
	wp_remote_post( MOCK . '/__reset' );
}

function stats( $params = array() ) {
	$request = new WP_REST_Request( 'GET', '/integrate-rybbit/v1/stats' );
	foreach ( $params as $name => $value ) {
		$request->set_param( $name, $value );
	}
	$response = rest_do_request( $request );
	$data     = $response->get_data();

	return array(
		'status' => $response->get_status(),
		'data'   => $data,
		'code'   => is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '',
	);
}

function configure( $key, $site = 'demo-site' ) {
	update_option( 'rybbit_api_url', MOCK );
	update_option( 'rybbit_site_id', $site );
	update_option( 'rybbit_api_key', $key );
	update_option( 'rybbit_dashboard_cache', '10' );
	update_option( 'rybbit_dashboard_range', '7d' );
	Integrate_Rybbit_Api::clear_cache();
	mock_reset();
}

$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
$admin_id  = (int) $admin_ids[0];
$sub_id    = wp_insert_user(
	array(
		'user_login' => 'rybbit_test_sub_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'subscriber',
	)
);

wp_set_current_user( $admin_id );

WP_CLI::log( 'Options' );
check( 'http is allowed for a host without a dot', Integrate_Rybbit_Options::is_allowed_api_url( 'http://mock-rybbit:3000' ) );
check( 'http is allowed for localhost', Integrate_Rybbit_Options::is_allowed_api_url( 'http://localhost:3000' ) );
check( 'http is refused for a public host', ! Integrate_Rybbit_Options::is_allowed_api_url( 'http://example.com' ) );
check( 'https is allowed', Integrate_Rybbit_Options::is_allowed_api_url( 'https://example.com' ) );
check( 'other schemes are refused', ! Integrate_Rybbit_Options::is_allowed_api_url( 'ftp://example.com' ) );
check( 'unknown range falls back to 7d', '7d' === Integrate_Rybbit_Options::sanitize_dashboard_range( 'bogus' ) );
check( 'cache minutes have a lower limit', '1' === Integrate_Rybbit_Options::sanitize_cache_minutes( '0' ) );
check( 'cache minutes have an upper limit', '1440' === Integrate_Rybbit_Options::sanitize_cache_minutes( '99999' ) );

WP_CLI::log( 'Success' );
configure( 'demo-key' );
check( 'dashboard is enabled with key and site ID', Integrate_Rybbit_Options::is_dashboard_enabled() );
$r = stats( array( 'view' => 'summary' ) );
check( 'summary returns 200', 200 === $r['status'], $r['status'] . ' ' . $r['code'] );
check( 'overview has pageviews', ! empty( $r['data']['overview']['pageviews'] ) );
check( '7d returns 7 day buckets', isset( $r['data']['series'] ) && 7 === count( $r['data']['series'] ) );
check( 'live count is 3', isset( $r['data']['live'] ) && 3 === $r['data']['live'] );
check( 'no warnings', isset( $r['data']['warnings'] ) && array() === $r['data']['warnings'] );
check( 'summary has no top lists', ! isset( $r['data']['top'] ) );

$r = stats( array( 'view' => 'full' ) );
check( 'full returns 200', 200 === $r['status'] );
check( 'top pages: first is /', isset( $r['data']['top']['pages'][0] ) && '/' === $r['data']['top']['pages'][0]['value'] );
check(
	'top pages keep encoded octets',
	isset( $r['data']['top']['pages'] ) && in_array( '/blog/caf%C3%A9-notes/', wp_list_pluck( $r['data']['top']['pages'], 'value' ), true )
);
check( 'top countries: first is DE', isset( $r['data']['top']['countries'][0] ) && 'DE' === $r['data']['top']['countries'][0]['value'] );
check( 'all five lists exist', isset( $r['data']['top'] ) && 5 === count( $r['data']['top'] ) );
check( 'full has the URL of the site in Rybbit', MOCK . '/29' === ( $r['data']['site_url'] ?? '' ), (string) ( $r['data']['site_url'] ?? '' ) );

// The admin classes load only on admin requests. This script runs on the command line.
if ( ! class_exists( 'Integrate_Rybbit_Dashboard' ) ) {
	require_once WP_PLUGIN_DIR . '/integrate-rybbit/admin/class-integrate-rybbit-dashboard.php';
}
ob_start();
( new Integrate_Rybbit_Dashboard() )->render_page();
$page_html = ob_get_clean();
check( 'the page link starts at the Rybbit home page', false !== strpos( $page_html, 'href="' . MOCK . '" data-rybbit-open' ) );

// A new range needs 7 requests: overview, time series and 5 top lists. The live count and the site number come from the cache.
stats( array( 'view' => 'full' ) );
$after_full = mock_requests();
stats( array( 'view' => 'full', 'range' => '30d' ) );
check( 'the site number is cached', 29 === Integrate_Rybbit_Api::get_site_number() && mock_requests() === $after_full + 7, mock_requests() . ' vs ' . $after_full );

configure( 'no-sites-key' );
$r = stats( array( 'view' => 'full' ) );
check( 'a key without "sites:read" still loads the dashboard', 200 === $r['status'] && ! empty( $r['data']['overview'] ), $r['status'] . ' ' . $r['code'] );
check( 'without "sites:read" there is no site URL', ! isset( $r['data']['site_url'] ) );
$after_full = mock_requests();
stats( array( 'view' => 'full', 'range' => '30d' ) );
check( 'a failed site lookup is not repeated', mock_requests() === $after_full + 7, mock_requests() . ' vs ' . $after_full );
configure( 'demo-key' );

$r = stats( array( 'view' => 'live' ) );
check( 'live view returns only the live count', 200 === $r['status'] && array( 'live', 'generated' ) === array_keys( $r['data'] ) );

$r = stats( array( 'range' => '24h', 'view' => 'summary' ) );
check( '24h returns 24 hour buckets', isset( $r['data']['series'] ) && 24 === count( $r['data']['series'] ), $r['code'] );

$r = stats( array( 'range' => '90d', 'view' => 'summary' ) );
check( '90d returns 90 day buckets', isset( $r['data']['series'] ) && 90 === count( $r['data']['series'] ), $r['code'] );

$r = stats( array( 'range' => 'evil' ) );
check( 'invalid range is rejected with 400', 400 === $r['status'] );

$r = stats( array( 'view' => 'evil' ) );
check( 'invalid view is rejected with 400', 400 === $r['status'] );

WP_CLI::log( 'Cache' );
configure( 'demo-key' );
stats( array( 'view' => 'summary' ) );
$after_first = mock_requests();
stats( array( 'view' => 'summary' ) );
check( 'second request is served from the cache', mock_requests() === $after_first, mock_requests() . ' vs ' . $after_first );
update_option( 'rybbit_site_id', 'other-site' );
stats( array( 'view' => 'summary' ) );
check( 'a new site ID clears the cache', mock_requests() > $after_first );
Integrate_Rybbit_Api::clear_cache();
$before = mock_requests();
stats( array( 'view' => 'summary' ) );
check( 'clear_cache() forces new requests', mock_requests() > $before );

WP_CLI::log( 'Access' );
configure( 'demo-key' );
wp_set_current_user( $sub_id );
$r = stats();
check( 'subscriber gets 403', 403 === $r['status'], (string) $r['status'] );
check( 'subscriber triggers no API request', 0 === mock_requests() );
wp_set_current_user( 0 );
$r = stats();
check( 'visitor gets 401', 401 === $r['status'], (string) $r['status'] );
wp_set_current_user( $admin_id );
add_filter(
	'integrate_rybbit_dashboard_capability',
	static function () {
		return 'read';
	}
);
wp_set_current_user( $sub_id );
$r = stats( array( 'view' => 'summary' ) );
check( 'the capability filter can allow subscribers', 200 === $r['status'], (string) $r['status'] );
remove_all_filters( 'integrate_rybbit_dashboard_capability' );
wp_set_current_user( $admin_id );

WP_CLI::log( 'Errors' );
configure( 'bad-key' );
$r = stats( array( 'view' => 'summary' ) );
check( '401 from the API becomes rybbit_unauthorized', 'rybbit_unauthorized' === $r['code'] && 502 === $r['status'], $r['code'] . ' ' . $r['status'] );
check( 'the error does not contain the key', false === strpos( wp_json_encode( $r['data'] ), 'bad-key' ) );

configure( 'forbidden-key' );
$r = stats( array( 'view' => 'summary' ) );
check( '403 from the API becomes rybbit_forbidden', 'rybbit_forbidden' === $r['code'], $r['code'] );

configure( 'no-scope-key' );
$r = stats( array( 'view' => 'summary' ) );
check( '403 with a missing scope becomes rybbit_forbidden', 'rybbit_forbidden' === $r['code'], $r['code'] );
check( 'the message names the missing permission', false !== strpos( wp_json_encode( $r['data'] ), 'analytics:read' ), wp_json_encode( $r['data'] ) );

configure( 'demo-key', 'missing' );
$r = stats( array( 'view' => 'summary' ) );
check( '404 from the API becomes rybbit_not_found', 'rybbit_not_found' === $r['code'], $r['code'] );

configure( '' );
$r = stats( array( 'view' => 'summary' ) );
check( 'no key gives rybbit_no_api_key', 'rybbit_no_api_key' === $r['code'], $r['code'] );
check( 'no key means the dashboard is off', ! Integrate_Rybbit_Options::is_dashboard_enabled() );

configure( 'demo-key', '' );
$r = stats( array( 'view' => 'summary' ) );
check( 'no site ID gives rybbit_no_site_id', 'rybbit_no_site_id' === $r['code'], $r['code'] );

configure( 'demo-key' );
update_option( 'rybbit_api_url', 'http://example.com' );
$r = stats( array( 'view' => 'summary' ) );
check( 'http to a public host gives rybbit_bad_url', 'rybbit_bad_url' === $r['code'], $r['code'] );

configure( 'demo-key' );
update_option( 'rybbit_api_url', 'http://mock-rybbit:9' );
$r = stats( array( 'view' => 'summary' ) );
check( 'a closed port gives rybbit_unreachable', 'rybbit_unreachable' === $r['code'], $r['code'] );

WP_CLI::log( 'Rate limit' );
configure( 'slow-key' );
$r = stats( array( 'view' => 'summary' ) );
check( '429 from the API stays 429', 429 === $r['status'] && 'rybbit_rate_limited' === $r['code'], $r['status'] . ' ' . $r['code'] );
check( 'retry_after comes from the header', isset( $r['data']['data']['retry_after'] ) && 30 === $r['data']['data']['retry_after'] );
$count = mock_requests();
stats( array( 'view' => 'summary' ) );
check( 'while blocked, no new API request is sent', mock_requests() === $count, mock_requests() . ' vs ' . $count );
$r = Integrate_Rybbit_Api::test_connection();
check( 'the connection test ignores the block', is_wp_error( $r ) && 'rybbit_rate_limited' === $r->get_error_code() );
update_option( 'rybbit_api_key', 'demo-key' );
$r = stats( array( 'view' => 'summary' ) );
check( 'saving a new key removes the block', 200 === $r['status'], $r['status'] . ' ' . $r['code'] );

WP_CLI::log( 'Connection test' );
configure( 'demo-key' );
$r = Integrate_Rybbit_Api::test_connection();
check( 'test_connection() works with a valid key', is_array( $r ) && 3 === $r['live'] );
configure( 'bad-key' );
$r = Integrate_Rybbit_Api::test_connection();
check( 'test_connection() reports a bad key', is_wp_error( $r ) && 'rybbit_unauthorized' === $r->get_error_code() );

WP_CLI::log( 'Uninstall' );
configure( 'demo-key' );
stats( array( 'view' => 'full' ) );
global $wpdb;
$like  = $wpdb->esc_like( '_transient_rybbit_api_' ) . '%';
$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
check( 'the cache created transients', $count > 0, (string) $count );
update_option( 'rybbit_delete_data_on_uninstall', '0' );
define( 'WP_UNINSTALL_PLUGIN', 'integrate-rybbit/integrate-rybbit.php' );
require WP_PLUGIN_DIR . '/integrate-rybbit/uninstall.php';
$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
check( 'uninstall removes the transients', 0 === $count, (string) $count );
check( 'uninstall keeps the settings without opt-in', 'demo-key' === get_option( 'rybbit_api_key' ) );

WP_CLI::log( 'API key constant' );
configure( 'demo-key' );
check( 'the key source is the option', 'option' === Integrate_Rybbit_Options::api_key_source() );
define( 'INTEGRATE_RYBBIT_API_KEY', 'bad-key' );
check( 'the key source is the constant', 'constant' === Integrate_Rybbit_Options::api_key_source() );
$r = stats( array( 'view' => 'summary' ) );
check( 'the constant wins over the option', 'rybbit_unauthorized' === $r['code'], $r['code'] );

wp_delete_user( $sub_id );

// Leave the dev site with working demo settings.
configure( 'demo-key' );

WP_CLI::log( '' );
$failures = $GLOBALS['rybbit_test']['failures'];
$total    = $GLOBALS['rybbit_test']['total'];
if ( $failures > 0 ) {
	WP_CLI::error( "$failures of $total checks failed." );
}
WP_CLI::success( "All $total checks passed." );
