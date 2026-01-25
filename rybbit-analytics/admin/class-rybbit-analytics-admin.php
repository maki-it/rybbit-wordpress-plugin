<?php
/**
 * Admin-specific logic for Rybbit Analytics
 */
class Rybbit_Analytics_Admin {
    public function __construct() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Add links on the Plugins page
        add_filter('plugin_action_links_' . plugin_basename(dirname(__DIR__) . '/rybbit-analytics.php'), array($this, 'action_links'));
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
        // Make required fields validate on save.
        register_setting('rybbit_analytics_settings', 'rybbit_site_id', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_required_site_id'),
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_script_url', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_required_script_url'),
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_do_not_track_admins', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                // Checkbox: treat any truthy value as "1".
                return ($value === '1' || $value === 1 || $value === true || $value === 'on') ? '1' : '0';
            },
            'default' => '1',
        ));

        // Script attribute settings
        register_setting('rybbit_analytics_settings', 'rybbit_skip_patterns', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_patterns_newline_list'),
            'default' => '',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_mask_patterns', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_patterns_newline_list'),
            'default' => '',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_debounce', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_debounce_ms'),
            'default' => '500',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_identify_mode', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                $allowed = array('disabled', 'pseudonymized', 'full');
                $value = is_string($value) ? $value : '';
                return in_array($value, $allowed, true) ? $value : 'disabled';
            },
            'default' => 'disabled',
        ));

        // Uninstall behavior
        register_setting('rybbit_analytics_settings', 'rybbit_delete_data_on_uninstall', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return ($value === '1' || $value === 1 || $value === true || $value === 'on') ? '1' : '0';
            },
            'default' => '0',
        ));
    }

    /**
     * Site ID is required.
     */
    public function sanitize_required_site_id($value) {
        $value = trim((string) $value);
        if ($value === '') {
            // Keep previous value and show an error.
            add_settings_error(
                'rybbit_site_id',
                'rybbit_site_id_required',
                'Site ID is required.',
                'error'
            );
            return (string) get_option('rybbit_site_id', '');
        }
        return $value;
    }

    /**
     * Script URL is required and must be a valid URL.
     */
    public function sanitize_required_script_url($value) {
        $value = trim((string) $value);
        if ($value === '') {
            add_settings_error(
                'rybbit_script_url',
                'rybbit_script_url_required',
                'Script URL is required.',
                'error'
            );
            return (string) get_option('rybbit_script_url', '');
        }

        $sanitized = esc_url_raw($value);
        if ($sanitized === '') {
            add_settings_error(
                'rybbit_script_url',
                'rybbit_script_url_invalid',
                'Script URL must be a valid URL (including https://).',
                'error'
            );
            return (string) get_option('rybbit_script_url', '');
        }

        return $sanitized;
    }

    /**
     * Sanitize patterns entered as one-per-line.
     * - Removes empty lines
     * - Normalizes line endings to \n
     */
    public function sanitize_patterns_newline_list($value) {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;
        $lines = preg_split('/\r\n|\r|\n/', $value);
        $out = array();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
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
        $do_not_track_admins = get_option('rybbit_do_not_track_admins', '1');
        $skip_patterns = get_option('rybbit_skip_patterns', '');
        $mask_patterns = get_option('rybbit_mask_patterns', '');
        $debounce = get_option('rybbit_debounce', '500');
        $identify_mode = get_option('rybbit_identify_mode', 'disabled');
        $delete_data_on_uninstall = get_option('rybbit_delete_data_on_uninstall', '0');
        ?>
        <div class="wrap">
            <h1>Rybbit Analytics Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('rybbit_analytics_settings'); ?>
                <?php do_settings_sections('rybbit_analytics_settings'); ?>
                <?php settings_errors(); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="rybbit_site_id">Site ID <span class="required">*</span></label></th>
                        <td>
                            <input type="text" id="rybbit_site_id" name="rybbit_site_id" value="<?php echo esc_attr($site_id); ?>" size="50" required aria-required="true" />
                            <p class="description">Find this in your Rybbit dashboard under your site’s tracking settings.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="rybbit_script_url">Script URL <span class="required">*</span></label></th>
                        <td>
                            <input type="url" id="rybbit_script_url" name="rybbit_script_url" value="<?php echo esc_attr($script_url); ?>" size="50" required aria-required="true" />
                            <p class="description">Example: https://app.rybbit.io/api/script.js</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="rybbit_do_not_track_admins">Do not track Administrators</label></th>
                        <td><input type="checkbox" id="rybbit_do_not_track_admins" name="rybbit_do_not_track_admins" value="1" <?php checked('1', $do_not_track_admins); ?> />
                        <span class="description">If checked, tracking code will not be injected for users with the Administrator role.</span></td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label for="rybbit_identify_mode">Identify logged-in users</label></th>
                        <td>
                            <select id="rybbit_identify_mode" name="rybbit_identify_mode">
                                <option value="disabled" <?php selected($identify_mode, 'disabled'); ?>>Disabled</option>
                                <option value="pseudonymized" <?php selected($identify_mode, 'pseudonymized'); ?>>Pseudonymized (hashed)</option>
                                <option value="full" <?php selected($identify_mode, 'full'); ?>>Full (cleartext email)</option>
                            </select>
                            <p class="description" style="max-width: 720px;">
                                <strong>GDPR/Privacy:</strong> Identifying users is personal data processing. Only enable this if you have a lawful basis (e.g. consent) and have updated your privacy policy / consent banner accordingly.
                                <br />
                                <strong>Pseudonymized</strong> sends a stable WordPress user id plus hashed traits (e.g. SHA-256 email hash).<br />
                                <strong>Full</strong> additionally sends cleartext traits like email and display name. Use only if you explicitly need it.
                            </p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><label for="rybbit_skip_patterns">Skip patterns</label></th>
                        <td>
                            <textarea id="rybbit_skip_patterns" name="rybbit_skip_patterns" rows="5" cols="50" class="large-text code"><?php echo esc_textarea($skip_patterns); ?></textarea>
                            <p class="description">
                                One pattern per line. Matching paths won’t be tracked.<br />
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
                                One pattern per line. Matching paths are tracked but the URL will be replaced with the pattern in analytics.<br />
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

                    <tr valign="top">
                        <th scope="row"><label for="rybbit_delete_data_on_uninstall">Delete data on uninstall</label></th>
                        <td>
                            <input type="checkbox" id="rybbit_delete_data_on_uninstall" name="rybbit_delete_data_on_uninstall" value="1" <?php checked('1', $delete_data_on_uninstall); ?> />
                            <p class="description" style="max-width: 720px;">
                                If enabled, all Rybbit Analytics plugin settings will be permanently removed when the plugin is uninstalled (deleted).
                                This does <strong>not</strong> run when the plugin is simply deactivated.
                                Keep this disabled if you plan to reinstall later.
                            </p>
                        </td>
                    </tr>

                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Add quick links on the Plugins page.
     */
    public function action_links($links) {
        $settings_link = '<a href="options-general.php?page=rybbit-analytics">Settings</a>';

        array_unshift($links, $settings_link);
        return $links;
    }

    // Backwards compatibility if something calls the old method name.
    public function settings_link($links) {
        return $this->action_links($links);
    }
}
