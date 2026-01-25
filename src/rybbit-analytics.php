<?php
/**
 * Plugin Name: Rybbit Analytics
 * Description: Adds Rybbit Analytics tracking to your WordPress site with customizable settings
 * Author: Kim Oliver Drechsel <kontakt@maki-it.de>
 * Version: 1.0
 */

// Add settings page to admin menu
function rybbit_analytics_menu() {
    add_options_page(
        'Rybbit Analytics Settings',
        'Rybbit Analytics',
        'manage_options',
        'rybbit-analytics',
        'rybbit_analytics_settings_page'
    );
}
add_action('admin_menu', 'rybbit_analytics_menu');

// Register settings
function rybbit_analytics_register_settings() {
    register_setting('rybbit_analytics_settings', 'rybbit_site_id');
    register_setting('rybbit_analytics_settings', 'rybbit_script_url', array(
        'default' => 'https://app.rybbit.io/api/script.js'
    ));
}
add_action('admin_init', 'rybbit_analytics_register_settings');

// Settings page HTML
function rybbit_analytics_settings_page() {
    $site_id = get_option('rybbit_site_id', '');
    $script_url = get_option('rybbit_script_url', 'https://app.rybbit.io/api/script.js');
    ?>
    <div class="wrap">
        <h1>Rybbit Analytics Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('rybbit_analytics_settings'); ?>
            <?php do_settings_sections('rybbit_analytics_settings'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Site ID</th>
                    <td><input type="text" name="rybbit_site_id" value="<?php echo esc_attr($site_id); ?>" size="50" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Script URL</th>
                    <td><input type="url" name="rybbit_script_url" value="<?php echo esc_attr($script_url); ?>" size="50" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Add tracking script to frontend
function rybbit_add_tracking_script() {
    $site_id = get_option('rybbit_site_id', '');
    $script_url = get_option('rybbit_script_url', 'https://app.rybbit.io/api/script.js');

    if (empty($site_id)) return;

    ?>
    <script>
        (function() {
            var s = document.createElement('script');
            s.src = '<?php echo esc_js($script_url); ?>';
            s.defer = true;
            s.setAttribute('data-site-id', '<?php echo esc_js($site_id); ?>');
            document.head.appendChild(s);
        })();
    </script>
    <?php
}
add_action('wp_head', 'rybbit_add_tracking_script');

// Add settings link to plugin action links
function rybbit_analytics_add_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=rybbit-analytics">Settings</a>';
    array_push($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'rybbit_analytics_add_settings_link');
