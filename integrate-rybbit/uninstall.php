<?php
/**
 * Uninstall routine of Integrate Rybbit.
 *
 * Settings are removed only if the site owner opted in under
 * Settings > Integrate Rybbit > Maintenance.
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-integrate-rybbit-options.php';

/**
 * Delete the plugin options of the current site if the site opted in.
 *
 * @param bool $force Delete the options even if the site did not opt in.
 */
function integrate_rybbit_uninstall_site( $force ) {
	if ( ! $force && '1' !== get_option( 'rybbit_delete_data_on_uninstall', '0' ) ) {
		return;
	}

	foreach ( Integrate_Rybbit_Options::all_option_names() as $integrate_rybbit_option ) {
		delete_option( $integrate_rybbit_option );
	}
}

if ( ! is_multisite() ) {
	integrate_rybbit_uninstall_site( false );
	return;
}

// Older versions could read a network-wide opt-in. Keep that behavior.
$integrate_rybbit_network_opt_in = '1' === get_site_option( 'rybbit_delete_data_on_uninstall', '0' );

foreach ( get_sites(
	array(
		'fields' => 'ids',
		'number' => 0,
	)
) as $integrate_rybbit_site_id ) {
	switch_to_blog( $integrate_rybbit_site_id );
	integrate_rybbit_uninstall_site( $integrate_rybbit_network_opt_in );
	restore_current_blog();
}

if ( $integrate_rybbit_network_opt_in ) {
	foreach ( Integrate_Rybbit_Options::all_option_names() as $integrate_rybbit_option ) {
		delete_site_option( $integrate_rybbit_option );
	}
}
