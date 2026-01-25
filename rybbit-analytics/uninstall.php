<?php
// Uninstall logic for Rybbit Analytics
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

// Only delete data if the site owner explicitly opted in.
// On multisite, allow opting in either per-site (option) or network-wide (site option).
$delete_all = get_option('rybbit_delete_data_on_uninstall', '0');
if ($delete_all !== '1' && (!is_multisite() || get_site_option('rybbit_delete_data_on_uninstall', '0') !== '1')) {
    return;
}

// Delete plugin options.
$option_names = array(
    'rybbit_site_id',
    'rybbit_script_url',
    'rybbit_do_not_track_admins',
    'rybbit_skip_patterns',
    'rybbit_mask_patterns',
    'rybbit_debounce',
    'rybbit_identify_mode',
    'rybbit_delete_data_on_uninstall',
);

foreach ($option_names as $name) {
    // Remove per-site setting.
    delete_option($name);
}

// Multisite: also remove network-level options if they were ever stored there.
if (is_multisite()) {
    foreach ($option_names as $name) {
        delete_site_option($name);
    }
}
