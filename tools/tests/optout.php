<?php
/**
 * Smoke test of the opt-out shortcode and block.
 *
 * It runs inside the dev WordPress. Start it with "make test-optout". The test
 * restores the plugin settings that it changes.
 *
 * @package Integrate_Rybbit
 */

// phpcs:disable

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run this file with "wp eval-file".' );
}

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

function reset_assets() {
	wp_dequeue_script( Integrate_Rybbit_Optout::HANDLE );
	wp_dequeue_style( Integrate_Rybbit_Optout::HANDLE );
}

$saved = array(
	'rybbit_site_id'    => get_option( 'rybbit_site_id' ),
	'rybbit_script_url' => get_option( 'rybbit_script_url' ),
);

WP_CLI::log( 'Registration' );
check( 'the shortcode exists', shortcode_exists( Integrate_Rybbit_Optout::SHORTCODE ) );
check( 'the block exists', WP_Block_Type_Registry::get_instance()->is_registered( Integrate_Rybbit_Optout::BLOCK ) );
check( 'the frontend script is registered', wp_script_is( Integrate_Rybbit_Optout::HANDLE, 'registered' ) );
check( 'the frontend style is registered', wp_style_is( Integrate_Rybbit_Optout::HANDLE, 'registered' ) );
check( 'the editor script is registered', wp_script_is( Integrate_Rybbit_Optout::EDITOR_HANDLE, 'registered' ) );

WP_CLI::log( 'Plugin not set up' );
update_option( 'rybbit_site_id', '' );
wp_set_current_user( 0 );
reset_assets();
check( 'visitors see nothing', '' === do_shortcode( '[rybbit_optout]' ) );
check( 'no script is loaded', ! wp_script_is( Integrate_Rybbit_Optout::HANDLE, 'enqueued' ) );

$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
wp_set_current_user( (int) $admin_ids[0] );
$html = do_shortcode( '[rybbit_optout]' );
check( 'admins see a hint', false !== strpos( $html, 'integrate-rybbit-optout__notice' ) );
check( 'the hint has no switch', false === strpos( $html, '<button' ) );
wp_set_current_user( 0 );

WP_CLI::log( 'Shortcode' );
update_option( 'rybbit_site_id', 'test-site' );
update_option( 'rybbit_script_url', 'https://rybbit.example.com/api/script.js' );
reset_assets();
check( 'the script is not loaded before the shortcode runs', ! wp_script_is( Integrate_Rybbit_Optout::HANDLE, 'enqueued' ) );

$html = do_shortcode( '[rybbit_optout]' );
check( 'the wrapper is rendered', false !== strpos( $html, 'data-integrate-rybbit-optout' ) );
check( 'the wrapper has the default class', false !== strpos( $html, 'class="integrate-rybbit-optout"' ) );
check( 'the default namespace is rybbit', false !== strpos( $html, 'data-namespace="rybbit"' ) );
check( 'the status region is live', false !== strpos( $html, 'role="status" aria-live="polite"' ) );
check( 'the button is a real button', false !== strpos( $html, '<button type="button"' ) );
check( 'the button starts hidden', 1 === preg_match( '/<button[^>]* hidden><\/button>/', $html ) );
check( 'there is a noscript hint', false !== strpos( $html, '<noscript>' ) );
check( 'the default texts are used', false !== strpos( $html, 'data-optout-label="Turn off analytics"' ) && false !== strpos( $html, 'data-untracked-text="Website analytics is turned off for this browser."' ) );
check( 'the script is loaded', wp_script_is( Integrate_Rybbit_Optout::HANDLE, 'enqueued' ) );
check( 'the style is loaded', wp_style_is( Integrate_Rybbit_Optout::HANDLE, 'enqueued' ) );

$html = do_shortcode( '[rybbit_optout optout_label="Stop" optin_label="Start" tracked_text="On" untracked_text="Off" class="my-class other"]' );
check( 'custom texts are used', false !== strpos( $html, 'data-optout-label="Stop"' ) && false !== strpos( $html, 'data-optin-label="Start"' ) && false !== strpos( $html, 'data-tracked-text="On"' ) && false !== strpos( $html, 'data-untracked-text="Off"' ) );
check( 'custom classes are added', false !== strpos( $html, 'class="integrate-rybbit-optout my-class other"' ) );

$html = do_shortcode( '[rybbit_optout optout_label=\'"><script>alert(1)</script>\' class=\'a"b onclick=x\']' );
check( 'tags are removed from the texts', false === strpos( $html, '<script>' ) );
check( 'quotes in the texts are escaped', false !== strpos( $html, 'data-optout-label="&quot;&gt;"' ), $html );
check( 'class names are sanitized', false !== strpos( $html, 'class="integrate-rybbit-optout ab onclickx"' ), $html );

WP_CLI::log( 'Namespace' );
$filter = static function ( $attributes ) {
	$attributes['data-namespace'] = 'my-tracker';
	return $attributes;
};
add_filter( 'integrate_rybbit_script_attributes', $filter );
check( 'the namespace comes from the script attributes', 'my-tracker' === Integrate_Rybbit_Optout::get_namespace() );
check( 'the namespace is in the HTML', false !== strpos( do_shortcode( '[rybbit_optout]' ), 'data-namespace="my-tracker"' ) );
remove_filter( 'integrate_rybbit_script_attributes', $filter );
$blank = static function ( $attributes ) {
	$attributes['data-namespace'] = '  ';
	return $attributes;
};
add_filter( 'integrate_rybbit_script_attributes', $blank );
check( 'a blank namespace falls back to rybbit', 'rybbit' === Integrate_Rybbit_Optout::get_namespace() );
remove_filter( 'integrate_rybbit_script_attributes', $blank );

WP_CLI::log( 'Block' );
reset_assets();
$html = do_blocks( '<!-- wp:integrate-rybbit/optout {"optout_label":"Block off","tracked_text":"Block on"} /-->' );
check( 'the block renders the wrapper', false !== strpos( $html, 'data-integrate-rybbit-optout' ) );
check( 'the block has the block class', false !== strpos( $html, 'wp-block-integrate-rybbit-optout' ) );
check( 'the block uses its attributes', false !== strpos( $html, 'data-optout-label="Block off"' ) && false !== strpos( $html, 'data-tracked-text="Block on"' ) );
check( 'the block falls back to default texts', false !== strpos( $html, 'data-optin-label="Turn on analytics"' ) );
check( 'the block loads the script', wp_script_is( Integrate_Rybbit_Optout::HANDLE, 'enqueued' ) );

WP_CLI::log( 'Filter' );
$wrap = static function ( $html, $texts ) {
	return '<!--' . $texts['optout_label'] . '-->' . $html;
};
add_filter( 'integrate_rybbit_optout_html', $wrap, 10, 2 );
check( 'the HTML can be changed with a filter', 0 === strpos( do_shortcode( '[rybbit_optout]' ), '<!--Turn off analytics-->' ) );
remove_filter( 'integrate_rybbit_optout_html', $wrap, 10 );

WP_CLI::log( 'Roles' );
update_option( 'rybbit_excluded_roles', array( 'administrator' ) );
wp_set_current_user( (int) $admin_ids[0] );
check( 'the switch also shows for excluded roles', false !== strpos( do_shortcode( '[rybbit_optout]' ), 'data-integrate-rybbit-optout' ) );
delete_option( 'rybbit_excluded_roles' );
wp_set_current_user( 0 );

foreach ( $saved as $name => $value ) {
	if ( false === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}

WP_CLI::log( '' );
$failures = $GLOBALS['rybbit_test']['failures'];
$total    = $GLOBALS['rybbit_test']['total'];
if ( $failures > 0 ) {
	WP_CLI::error( "$failures of $total checks failed." );
}
WP_CLI::success( "All $total checks passed." );
