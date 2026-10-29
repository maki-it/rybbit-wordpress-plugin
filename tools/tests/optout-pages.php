<?php
/**
 * Creates the pages for the browser test and the screenshot of the opt-out switch.
 *
 * It runs inside the dev WordPress. "make test-optout" and "make screenshots"
 * start it. The pages are created once and updated on every run.
 *
 * @package Integrate_Rybbit
 */

// phpcs:disable

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run this file with "wp eval-file".' );
}

$pages = array(
	array(
		'post_name'    => 'rybbit-optout-test',
		'post_title'   => 'Rybbit opt-out test',
		'post_content' => "<!-- wp:shortcode -->\n[rybbit_optout]\n<!-- /wp:shortcode -->\n\n<!-- wp:integrate-rybbit/optout /-->",
	),
	array(
		'post_name'    => 'rybbit-privacy-demo',
		'post_title'   => 'Privacy policy',
		'post_content' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Website analytics</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>We use Rybbit to understand how visitors use this website.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>You can turn off the analytics for this browser at any time. Use the switch below. We save your choice in your browser.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:integrate-rybbit/optout /-->",
	),
);

foreach ( $pages as $page ) {
	$page['post_type']   = 'page';
	$page['post_status'] = 'publish';

	$existing = get_page_by_path( $page['post_name'] );
	if ( $existing ) {
		$page['ID'] = $existing->ID;
	}

	$id = wp_insert_post( $page, true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}

	WP_CLI::log( 'Page ready: ' . get_permalink( $id ) );
}

update_option( 'rybbit_site_id', 'demo-site' );
WP_CLI::success( 'Test pages are ready.' );
