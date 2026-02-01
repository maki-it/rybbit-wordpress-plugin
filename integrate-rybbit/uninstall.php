<?php
// Uninstall logic for Integrate Rybbit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

// Only delete data if the site owner explicitly opted in.
// On multisite, allow opting in either per-site (option) or network-wide (site option).
$rybbit_delete_all = get_option('rybbit_delete_data_on_uninstall', '0');
if ($rybbit_delete_all !== '1' && (!is_multisite() || get_site_option('rybbit_delete_data_on_uninstall', '0') !== '1')) {
    return;
}

// Delete plugin options.
$rybbit_option_names = array(
    'rybbit_site_id',
    'rybbit_script_url',
    'rybbit_script_loading',
    'rybbit_do_not_track_admins',
    'rybbit_excluded_roles',
    'rybbit_skip_patterns',
    'rybbit_mask_patterns',
    'rybbit_debounce',
    'rybbit_identify_mode',
    'rybbit_identify_userid_strategy',
    'rybbit_identify_userid_meta_key',
    'rybbit_delete_data_on_uninstall',
);

foreach ($rybbit_option_names as $rybbit_name) {
    // Remove per-site setting.
    delete_option($rybbit_name);
}

// Multisite: also remove network-level options if they were ever stored there.
if (is_multisite()) {
    foreach ($rybbit_option_names as $rybbit_name) {
        delete_site_option($rybbit_name);
    }
}
