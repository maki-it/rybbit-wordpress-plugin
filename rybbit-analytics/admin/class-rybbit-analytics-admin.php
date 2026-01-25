<?php
/**
 * Admin-specific logic for Rybbit Analytics
 */
class Rybbit_Analytics_Admin {
    public function __construct() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('plugin_action_links_' . plugin_basename(plugin_dir_path(__DIR__) . '../rybbit-analytics.php'), array($this, 'settings_link'));
    }
    public function add_menu() {
        // Add admin menu
        add_options_page(
            'Rybbit Analytics Settings',
            'Rybbit Analytics',
            'manage_options',
            'rybbit-analytics',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('rybbit_analytics_settings', 'rybbit_site_id');
        register_setting('rybbit_analytics_settings', 'rybbit_script_url');
        register_setting('rybbit_analytics_settings', 'rybbit_do_not_track_admins');

        // Script attribute settings
        register_setting('rybbit_analytics_settings', 'rybbit_skip_patterns', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_json_string_array'),
            'default' => '[]',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_mask_patterns', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_json_string_array'),
            'default' => '[]',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_debounce', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_debounce_ms'),
            'default' => '500',
        ));
    }

    /**
     * Accepts either:
     *  - a JSON string array (e.g. ["/admin/**","/login"]) OR
     *  - a newline-separated list of patterns
     * Returns a normalized JSON string array.
     */
    public function sanitize_json_string_array($value) {
        if ($value === null) {
            return '[]';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '[]';
        }

        // If it's valid JSON already, keep it (but only if it's an array of strings).
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (!is_array($decoded)) {
                return '[]';
            }
            $out = array();
            foreach ($decoded as $item) {
                if (is_string($item)) {
                    $item = trim($item);
                    if ($item !== '') {
                        $out[] = $item;
                    }
                }
            }
            return wp_json_encode(array_values($out));
        }

        // Otherwise interpret as newline-separated patterns.
        $lines = preg_split('/\r\n|\r|\n/', $value);
        $out = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return wp_json_encode(array_values($out));
    }

    /**
     * Debounce in ms. Must be an integer >= 0.
     */
    public function sanitize_debounce_ms($value) {
        if ($value === null) {
            return '500';
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '500';
        }
        if (!preg_match('/^\d+$/', $value)) {
            return '500';
        }
        return (string) max(0, intval($value, 10));
    }

    public function settings_page() {
        $site_id = get_option('rybbit_site_id', '');
        $script_url = get_option('rybbit_script_url', 'https://app.rybbit.io/api/script.js');
        $do_not_track_admins = get_option('rybbit_do_not_track_admins', '');
        $skip_patterns = get_option('rybbit_skip_patterns', '[]');
        $mask_patterns = get_option('rybbit_mask_patterns', '[]');
        $debounce = get_option('rybbit_debounce', '500');
        ?>
        <div class="wrap">
            <h1>Rybbit Analytics Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('rybbit_analytics_settings'); ?>
                <?php do_settings_sections('rybbit_analytics_settings'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="rybbit_site_id">Site ID</label></th>
                        <td><input type="text" id="rybbit_site_id" name="rybbit_site_id" value="<?php echo esc_attr($site_id); ?>" size="50" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="rybbit_script_url">Script URL</label></th>
                        <td><input type="url" id="rybbit_script_url" name="rybbit_script_url" value="<?php echo esc_attr($script_url); ?>" size="50" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="rybbit_do_not_track_admins">Do not track Administrators</label></th>
                        <td><input type="checkbox" id="rybbit_do_not_track_admins" name="rybbit_do_not_track_admins" value="1" <?php checked('1', $do_not_track_admins); ?> />
                        <span class="description">If checked, tracking code will not be injected for users with the Administrator role.</span></td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label for="rybbit_skip_patterns">Skip patterns</label></th>
                        <td>
                            <textarea id="rybbit_skip_patterns" name="rybbit_skip_patterns" rows="5" cols="50" class="large-text code"><?php echo esc_textarea($skip_patterns); ?></textarea>
                            <p class="description">
                                JSON array (e.g. ["/wp-admin/**"]) or one pattern per line. Matching paths won’t be tracked.<br />
                                Wildcards: <code>*</code> matches within one path segment; <code>**</code> matches across segments.<br />
                                Examples: <code>/admin/*</code> matches <code>/admin/dashboard</code> but not <code>/admin/users/list</code>. <code>/admin/**</code> matches both.
                            </p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label for="rybbit_mask_patterns">Mask patterns</label></th>
                        <td>
                            <textarea id="rybbit_mask_patterns" name="rybbit_mask_patterns" rows="5" cols="50" class="large-text code"><?php echo esc_textarea($mask_patterns); ?></textarea>
                            <p class="description">
                                JSON array or one pattern per line. Matching paths are tracked but the URL will be replaced with the pattern in analytics.<br />
                                Wildcards: <code>*</code> matches within one segment; <code>**</code> matches across segments.
                            </p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label for="rybbit_debounce">Debounce (ms)</label></th>
                        <td>
                            <input type="number" min="0" step="1" id="rybbit_debounce" name="rybbit_debounce" value="<?php echo esc_attr($debounce); ?>" class="small-text" />
                            <p class="description">Delay before tracking a pageview after History API URL changes. Set to 0 to disable.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function settings_link($links) {
        $settings_link = '<a href="options-general.php?page=rybbit-analytics">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}
