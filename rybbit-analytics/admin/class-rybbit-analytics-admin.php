<?php
/**
 * Admin-specific logic for Rybbit Analytics
 */
class Rybbit_Analytics_Admin {
    public function __construct() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Add links on the Plugins page
        if (defined('RYBBIT_ANALYTICS_PLUGIN_BASENAME')) {
            add_filter('plugin_action_links_' . RYBBIT_ANALYTICS_PLUGIN_BASENAME, array($this, 'action_links'));
        }
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

        // Role-based exclusion list (multi-select). Stored as JSON array of role slugs.
        register_setting('rybbit_analytics_settings', 'rybbit_excluded_roles', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_roles_json_array'),
            'default' => wp_json_encode(array('administrator')),
        ));

        // Choose which value is used as the Rybbit userId.
        register_setting('rybbit_analytics_settings', 'rybbit_identify_userid_strategy', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                $allowed = array('wp_scoped', 'wp_user_id', 'user_login', 'email', 'user_meta');
                $value = is_string($value) ? $value : '';
                return in_array($value, $allowed, true) ? $value : 'wp_scoped';
            },
            'default' => 'wp_scoped',
        ));

        // Only used when strategy=user_meta
        register_setting('rybbit_analytics_settings', 'rybbit_identify_userid_meta_key', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                $value = sanitize_key((string) $value);
                return $value;
            },
            'default' => '',
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

    /**
     * Sanitize roles input as a JSON array of role slugs.
     * Accepts either a PHP array from multi-select or a JSON string.
     */
    public function sanitize_roles_json_array($value) {
        if (is_array($value)) {
            $roles = $value;
        } else {
            $value = trim((string) $value);
            if ($value === '') {
                return '[]';
            }
            $decoded = json_decode($value, true);
            $roles = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : array();
        }

        $roles = array_values(array_filter(array_map(function ($r) {
            $r = sanitize_key((string) $r);
            return $r !== '' ? $r : null;
        }, $roles)));

        global $wp_roles;
        if (!isset($wp_roles) || !is_object($wp_roles)) {
            $wp_roles = wp_roles();
        }
        $existing = is_object($wp_roles) ? array_keys((array) $wp_roles->roles) : array();

        $roles = array_values(array_intersect($roles, $existing));
        return wp_json_encode($roles);
    }

    /**
     * Load admin assets only on the plugin settings page.
     */
    public function enqueue_admin_assets($hook_suffix) {
        // Our settings page is Settings -> Rybbit Analytics
        if ($hook_suffix !== 'settings_page_rybbit-analytics') {
            return;
        }

        wp_enqueue_style(
            'rybbit-analytics-admin-settings',
            plugin_dir_url(__FILE__) . 'css/settings.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_style(
            'rybbit-analytics-admin-tabs',
            plugin_dir_url(__FILE__) . 'css/tabs.css',
            array('rybbit-analytics-admin-settings'),
            '1.0.0'
        );

        wp_enqueue_script(
            'rybbit-analytics-admin-settings',
            plugin_dir_url(__FILE__) . 'js/settings.js',
            array(),
            '1.0.0',
            true
        );

        wp_localize_script('rybbit-analytics-admin-settings', 'rybbitAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rybbit_admin_settings'),
        ));
    }

    public function settings_page() {
        $site_id = get_option('rybbit_site_id', '');
        $script_url = get_option('rybbit_script_url', 'https://app.rybbit.io/api/script.js');
        $skip_patterns = get_option('rybbit_skip_patterns', '');
        $mask_patterns = get_option('rybbit_mask_patterns', '');
        $debounce = get_option('rybbit_debounce', '500');
        $identify_mode = get_option('rybbit_identify_mode', 'disabled');
        $delete_data_on_uninstall = get_option('rybbit_delete_data_on_uninstall', '0');
        $excluded_roles_json = get_option('rybbit_excluded_roles', '[]');
        $excluded_roles = json_decode((string) $excluded_roles_json, true);
        if (!is_array($excluded_roles)) {
            $excluded_roles = array();
        }
        $identify_userid_strategy = get_option('rybbit_identify_userid_strategy', 'wp_scoped');
        $identify_userid_meta_key = get_option('rybbit_identify_userid_meta_key', '');

        // Build available roles list.
        global $wp_roles;
        if (!isset($wp_roles) || !is_object($wp_roles)) {
            $wp_roles = wp_roles();
        }
        $roles_list = is_object($wp_roles) ? (array) $wp_roles->roles : array();
        ?>
        <div class="wrap">
            <div class="rybbit-header">
                <div class="rybbit-icon" aria-hidden="true">
                    <span class="dashicons dashicons-chart-area"></span>
                </div>
                <div>
                    <h1>Rybbit Analytics</h1>
                    <p class="rybbit-subtitle">Configure tracking and privacy settings for your WordPress site.</p>
                </div>
            </div>

            <h2 class="nav-tab-wrapper" role="tablist" aria-label="Rybbit Analytics settings">
                <a href="#tracking" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="true" data-tab="tracking">Tracking</a>
                <a href="#privacy" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="false" data-tab="privacy">Privacy</a>
                <a href="#script" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="false" data-tab="script">Script attributes</a>
                <a href="#maintenance" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="false" data-tab="maintenance">Maintenance</a>
                <a href="#about" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="false" data-tab="about">About</a>
            </h2>

            <div class="rybbit-settings-card">
                <form method="post" action="options.php">
                    <?php settings_fields('rybbit_analytics_settings'); ?>
                    <?php settings_errors('rybbit_analytics_settings'); ?>

                    <!-- removed do_settings_sections('rybbit_analytics_settings'); since no sections are registered -->

                    <div class="rybbit-tab-panel" data-tab="tracking" role="tabpanel">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="rybbit_site_id">Site ID <span class="required">*</span></label></th>
                                <td>
                                    <input type="text" id="rybbit_site_id" name="rybbit_site_id" value="<?php echo esc_attr($site_id); ?>" class="regular-text rybbit-input-wide" required aria-required="true" />
                                    <p class="description">Find this in your Rybbit dashboard under your site’s tracking settings.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_script_url">Script URL <span class="required">*</span></label></th>
                                <td>
                                    <input type="url" id="rybbit_script_url" name="rybbit_script_url" value="<?php echo esc_attr($script_url); ?>" class="regular-text rybbit-input-url" required aria-required="true" />
                                    <p class="description">Example: https://app.rybbit.io/api/script.js</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_excluded_roles">Do not track these roles</label></th>
                                <td>
                                    <select id="rybbit_excluded_roles" name="rybbit_excluded_roles[]" multiple size="8" class="rybbit-input-wide">
                                        <?php foreach ($roles_list as $role_slug => $role_data) :
                                            $name = isset($role_data['name']) ? $role_data['name'] : $role_slug;
                                        ?>
                                            <option value="<?php echo esc_attr($role_slug); ?>" <?php echo in_array($role_slug, $excluded_roles, true) ? 'selected' : ''; ?>>
                                                <?php echo esc_html($name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Selected roles will not receive the tracking script (frontend and wp-admin).</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="rybbit-tab-panel" data-tab="privacy" role="tabpanel" style="display:none">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="rybbit_identify_mode">Identify logged-in users</label></th>
                                <td>
                                    <select id="rybbit_identify_mode" name="rybbit_identify_mode" class="rybbit-select-wide">
                                        <option value="disabled" <?php selected($identify_mode, 'disabled'); ?>>Disabled</option>
                                        <option value="pseudonymized" <?php selected($identify_mode, 'pseudonymized'); ?>>Pseudonymized (hashed)</option>
                                        <option value="full" <?php selected($identify_mode, 'full'); ?>>Full (cleartext email)</option>
                                    </select>
                                    <p class="description" style="max-width: 720px;">
                                        <strong>GDPR/Privacy:</strong> Identifying users is personal data processing. Only enable this if you have a lawful basis (e.g. consent) and have updated your privacy policy / consent banner accordingly.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_identify_userid_strategy">User ID used for identification</label></th>
                                <td>
                                    <select id="rybbit_identify_userid_strategy" name="rybbit_identify_userid_strategy" class="rybbit-select-wide">
                                        <option value="wp_scoped" <?php selected($identify_userid_strategy, 'wp_scoped'); ?>>WordPress scoped (recommended)</option>
                                        <option value="wp_user_id" <?php selected($identify_userid_strategy, 'wp_user_id'); ?>>WordPress user ID (numeric)</option>
                                        <option value="user_login" <?php selected($identify_userid_strategy, 'user_login'); ?>>Username (user_login)</option>
                                        <option value="email" <?php selected($identify_userid_strategy, 'email'); ?>>Email address</option>
                                        <option value="user_meta" <?php selected($identify_userid_strategy, 'user_meta'); ?>>Custom user meta value</option>
                                    </select>
                                    <p class="description">Controls what is sent as the Rybbit <code>userId</code> when identifying logged-in users.</p>

                                    <div class="rybbit-meta-key-wrap" data-rybbit-user-meta-key>
                                        <label for="rybbit_identify_userid_meta_key"><strong>User meta key</strong></label><br />
                                        <input type="text" id="rybbit_identify_userid_meta_key" name="rybbit_identify_userid_meta_key" value="<?php echo esc_attr($identify_userid_meta_key); ?>" placeholder="e.g. customer_id" class="regular-text rybbit-input-wide" />
                                        <p class="description">The value of this user meta field will be used as the Rybbit userId.</p>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Identify payload preview</label></th>
                                <td>
                                    <pre id="rybbit_identify_payload" class="rybbit-identify-payload" data-rybbit-preview-status=""></pre>
                                    <p class="description">
                                        Preview of the identify payload that would be sent for the current logged-in user.
                                        <a href="#" class="rybbit-refresh-payload">Refresh preview</a>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="rybbit-tab-panel" data-tab="script" role="tabpanel" style="display:none">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="rybbit_skip_patterns">Skip patterns</label></th>
                                <td>
                                    <textarea id="rybbit_skip_patterns" name="rybbit_skip_patterns" rows="8" class="large-text code rybbit-input-wide"><?php echo esc_textarea($skip_patterns); ?></textarea>
                                    <p class="description">
                                        One pattern per line. Matching paths won’t be tracked.
                                        Wildcards: <code>*</code> matches within one segment; <code>**</code> matches across segments.
                                        Examples: <code>/admin/*</code>, <code>/admin/**</code>, <code>/blog/*/comments</code>.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_mask_patterns">Mask patterns</label></th>
                                <td>
                                    <textarea id="rybbit_mask_patterns" name="rybbit_mask_patterns" rows="8" class="large-text code rybbit-input-wide"><?php echo esc_textarea($mask_patterns); ?></textarea>
                                    <p class="description">
                                        One pattern per line. Matching paths are tracked but the URL path will be replaced with the pattern in analytics.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_debounce">Debounce (ms)</label></th>
                                <td>
                                    <input type="number" min="0" step="1" id="rybbit_debounce" name="rybbit_debounce" value="<?php echo esc_attr($debounce); ?>" class="small-text" />
                                    <p class="description">Delay before tracking a pageview after History API URL changes. Set to 0 to disable.</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="rybbit-tab-panel" data-tab="maintenance" role="tabpanel" style="display:none">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="rybbit_delete_data_on_uninstall">Delete data on uninstall</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="rybbit_delete_data_on_uninstall" name="rybbit_delete_data_on_uninstall" value="1" <?php checked('1', $delete_data_on_uninstall); ?> />
                                        Remove all plugin settings when the plugin is uninstalled (deleted).
                                    </label>
                                    <p class="description">This does not run when the plugin is simply deactivated.</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="rybbit-tab-panel" data-tab="about" role="tabpanel" style="display:none">
                        <?php
                        $data = get_file_data(plugin_dir_path(__DIR__) . 'rybbit-analytics.php', array('Version' => 'Version'), 'plugin');
                        $version = isset($data['Version']) ? (string) $data['Version'] : '';
                        ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Plugin version</th>
                                <td>
                                    <code><?php echo esc_html($version !== '' ? $version : 'unknown'); ?></code>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Links</th>
                                <td>
                                    <p>
                                        <a href="https://github.com/maki-it/rybbit-wordpress-plugin" target="_blank" rel="noopener noreferrer">GitHub repository</a>
                                    </p>
                                    <p>
                                        <a href="https://rybbit.com/docs" target="_blank" rel="noopener noreferrer">Rybbit documentation</a>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Troubleshooting</th>
                                <td>
                                    <ul style="margin: 0; padding-left: 18px;">
                                        <li>Make sure <strong>Site ID</strong> and <strong>Script URL</strong> are set.</li>
                                        <li>If tracking doesn’t fire for logged-in users, check <strong>Do not track these roles</strong>.</li>
                                        <li>For identify(), enable it under <strong>Privacy</strong> and verify the preview payload.</li>
                                    </ul>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <?php submit_button(); ?>
                </form>
            </div>
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
