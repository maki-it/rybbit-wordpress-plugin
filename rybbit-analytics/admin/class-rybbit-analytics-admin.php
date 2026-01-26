<?php
if ( ! defined( 'ABSPATH' ) ) exit;
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
            'default' => '1',
        ));

        // Role-based exclusion list (multi-select). Stored as an array of role slugs.
        register_setting('rybbit_analytics_settings', 'rybbit_excluded_roles', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_roles_array'),
            'default' => array('administrator'),
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

        // Script loading mode: defer (default) or async.
        register_setting('rybbit_analytics_settings', 'rybbit_script_loading', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                $value = is_string($value) ? strtolower(trim($value)) : '';
                return in_array($value, array('defer', 'async'), true) ? $value : 'defer';
            },
            'default' => 'defer',
        ));

        // Session Replay (rrweb) script attribute settings
        register_setting('rybbit_analytics_settings', 'rybbit_replay_mask_text_selectors', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_selectors_newline_list'),
            'default' => '',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_replay_block_class', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return $this->sanitize_css_class_with_default($value, 'rr-block');
            },
            'default' => 'rr-block',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_replay_block_selector', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_css_selector_text'),
            'default' => '',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_replay_ignore_class', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return $this->sanitize_css_class_with_default($value, 'rr-ignore');
            },
            'default' => 'rr-ignore',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_replay_ignore_selector', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_css_selector_text'),
            'default' => '',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_replay_mask_text_class', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return $this->sanitize_css_class_with_default($value, 'rr-mask');
            },
            'default' => 'rr-mask',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_replay_mask_all_inputs', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return ($value === '1' || $value === 1 || $value === true || $value === 'on') ? '1' : '0';
            },
            'default' => '1',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_replay_mask_input_options', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return $this->sanitize_json_object_string_or_empty($value, 'rybbit_replay_mask_input_options');
            },
            'default' => '{"password":true,"email":true}',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_replay_collect_fonts', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return ($value === '1' || $value === 1 || $value === true || $value === 'on') ? '1' : '0';
            },
            'default' => '1',
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_replay_sampling', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return $this->sanitize_json_object_string_or_empty($value, 'rybbit_replay_sampling');
            },
            'default' => $replay_sampling_default_json_pretty,
        ));
        register_setting('rybbit_analytics_settings', 'rybbit_replay_slim_dom_options', array(
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return $this->sanitize_slim_dom_options($value);
            },
            'default' => $replay_slim_dom_default_json_pretty,
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
     * Sanitize roles input.
     *
     * Accepts:
     * - PHP array (from multi-select)
     * - JSON array string (legacy)
     * - newline-separated string
     *
     * Returns a cleaned array of existing role slugs.
     */
    public function sanitize_roles_array($value) {
        // Normalize to array.
        if (is_array($value)) {
            $roles = $value;
        } else {
            $str = trim((string) $value);
            if ($str === '') {
                $roles = array();
            } else {
                $decoded = json_decode($str, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $roles = $decoded;
                } else {
                    // Fallback: newline-separated.
                    $roles = preg_split('/\r\n|\r|\n/', $str);
                    if (!is_array($roles)) {
                        $roles = array();
                    }
                }
            }
        }

        $roles = array_values(array_filter(array_map(function ($r) {
            $r = sanitize_key((string) $r);
            return $r !== '' ? $r : null;
        }, $roles)));

        // Only allow existing roles.
        global $wp_roles;
        if (!isset($wp_roles) || !is_object($wp_roles)) {
            $wp_roles = wp_roles();
        }
        $existing = is_object($wp_roles) ? array_keys((array) $wp_roles->roles) : array();

        return array_values(array_intersect($roles, $existing));
    }

    /**
     * Back-compat wrapper: old name returned JSON; now return JSON for callers that expect it.
     */
    public function sanitize_roles_json_array($value) {
        $roles = $this->sanitize_roles_array($value);
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

        $settings_css_path = plugin_dir_path(__FILE__) . 'css/settings.css';
        $tabs_css_path = plugin_dir_path(__FILE__) . 'css/tabs.css';
        $settings_js_path = plugin_dir_path(__FILE__) . 'js/settings.js';

        $settings_css_ver = file_exists($settings_css_path) ? (string) filemtime($settings_css_path) : '1.0.0';
        $tabs_css_ver = file_exists($tabs_css_path) ? (string) filemtime($tabs_css_path) : '1.0.0';
        $settings_js_ver = file_exists($settings_js_path) ? (string) filemtime($settings_js_path) : '1.0.0';

        wp_enqueue_style(
            'rybbit-analytics-admin-settings',
            plugin_dir_url(__FILE__) . 'css/settings.css',
            array(),
            $settings_css_ver
        );

        wp_enqueue_style(
            'rybbit-analytics-admin-tabs',
            plugin_dir_url(__FILE__) . 'css/tabs.css',
            array('rybbit-analytics-admin-settings'),
            $tabs_css_ver
        );

        wp_enqueue_script(
            'rybbit-analytics-admin-settings',
            plugin_dir_url(__FILE__) . 'js/settings.js',
            array(),
            $settings_js_ver,
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
        $script_loading = get_option('rybbit_script_loading', 'defer');
        $skip_patterns = get_option('rybbit_skip_patterns', '');
        $mask_patterns = get_option('rybbit_mask_patterns', '');
        $debounce = get_option('rybbit_debounce', '500');
        $identify_mode = get_option('rybbit_identify_mode', 'disabled');
        $delete_data_on_uninstall = get_option('rybbit_delete_data_on_uninstall', '1');
        // On fresh installs, the option may not exist yet; fall back to the intended default.
        $excluded_roles_opt = get_option('rybbit_excluded_roles', array('administrator'));
        $excluded_roles = $this->sanitize_roles_array($excluded_roles_opt);
        $identify_userid_strategy = get_option('rybbit_identify_userid_strategy', 'wp_scoped');
        $identify_userid_meta_key = get_option('rybbit_identify_userid_meta_key', '');

        // Documented defaults (from tracking-script.mdx)
        $replay_sampling_default_json_pretty = wp_json_encode(array(
            'mousemove' => false,
            'mouseInteraction' => array(
                'MouseUp' => false,
                'MouseDown' => false,
                'Click' => true,
                'ContextMenu' => false,
                'DblClick' => true,
                'Focus' => true,
                'Blur' => true,
                'TouchStart' => false,
                'TouchEnd' => false,
            ),
            'scroll' => 500,
            'input' => 'last',
            'media' => 800,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $replay_slim_dom_default_json_pretty = wp_json_encode(array(
            'script' => false,
            'comment' => true,
            'headFavicon' => true,
            'headWhitespace' => true,
            'headMetaDescKeywords' => true,
            'headMetaSocial' => true,
            'headMetaRobots' => true,
            'headMetaHttpEquiv' => true,
            'headMetaAuthorship' => true,
            'headMetaVerification' => true,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Session Replay options
        $replay_mask_text_selectors = get_option('rybbit_replay_mask_text_selectors', '');
        $replay_block_class = get_option('rybbit_replay_block_class', 'rr-block');
        $replay_block_selector = get_option('rybbit_replay_block_selector', '');
        $replay_ignore_class = get_option('rybbit_replay_ignore_class', 'rr-ignore');
        $replay_ignore_selector = get_option('rybbit_replay_ignore_selector', '');
        $replay_mask_text_class = get_option('rybbit_replay_mask_text_class', 'rr-mask');
        $replay_mask_all_inputs = get_option('rybbit_replay_mask_all_inputs', '1');
        $replay_mask_input_options = get_option('rybbit_replay_mask_input_options', '{"password":true,"email":true}');
        $replay_collect_fonts = get_option('rybbit_replay_collect_fonts', '1');
        $replay_sampling = get_option('rybbit_replay_sampling', $replay_sampling_default_json_pretty);
        $replay_slim_dom_options = get_option('rybbit_replay_slim_dom_options', $replay_slim_dom_default_json_pretty);

        // Pretty-print JSON fields for display (saved values may be normalized/minified).
        $replay_mask_input_options_display = is_string($replay_mask_input_options) ? trim($replay_mask_input_options) : '';
        if ($replay_mask_input_options_display !== '') {
            $decoded = json_decode($replay_mask_input_options_display, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $replay_mask_input_options_display = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        $replay_sampling_display = is_string($replay_sampling) ? trim($replay_sampling) : '';
        if ($replay_sampling_display !== '') {
            $decoded = json_decode($replay_sampling_display, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $replay_sampling_display = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        $replay_slim_dom_options_display = is_string($replay_slim_dom_options) ? trim($replay_slim_dom_options) : '';
        if ($replay_slim_dom_options_display !== '') {
            $lower = strtolower($replay_slim_dom_options_display);
            if ($lower !== 'true' && $lower !== 'false') {
                $decoded = json_decode($replay_slim_dom_options_display, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $replay_slim_dom_options_display = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }
            } else {
                $replay_slim_dom_options_display = $lower;
            }
        }

        // Encode defaults for safe transport in HTML data attributes.
        $replay_sampling_default_b64 = base64_encode($replay_sampling_default_json_pretty ? $replay_sampling_default_json_pretty : '{}');
        $replay_slim_dom_default_b64 = base64_encode($replay_slim_dom_default_json_pretty ? $replay_slim_dom_default_json_pretty : '{}');

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
                    <?php
                    // Inline SVG to avoid extra requests.
                    $icon_path = plugin_dir_path(__FILE__) . 'assets/rybbit-icon.svg';
                    if (file_exists($icon_path)) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo file_get_contents($icon_path);
                    } else {
                        echo '<span class="dashicons dashicons-chart-area"></span>';
                    }
                    ?>
                </div>
                <div>
                    <h1>Rybbit Analytics</h1>
                    <p class="rybbit-subtitle">Configure tracking and privacy settings for your WordPress site.</p>
                </div>
            </div>

            <h2 class="nav-tab-wrapper" role="tablist" aria-label="Rybbit Analytics settings">
                <a href="#tracking" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="true" data-tab="tracking">Tracking</a>
                <a href="#privacy" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="false" data-tab="privacy">Privacy</a>
                <a href="#script" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="false" data-tab="script">Script Attributes</a>
                <a href="#replay" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="false" data-tab="replay">Session Replay</a>
                <a href="#maintenance" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="false" data-tab="maintenance">Maintenance</a>
                <a href="#debug" class="nav-tab rybbit-nav-tab" role="tab" aria-selected="false" data-tab="debug">Debug</a>
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
                            <tr>
                                <th scope="row"><label for="rybbit_script_loading">Script loading</label></th>
                                <td>
                                    <select id="rybbit_script_loading" name="rybbit_script_loading" class="rybbit-select-wide">
                                        <option value="defer" <?php selected($script_loading, 'defer'); ?>>Defer (recommended)</option>
                                        <option value="async" <?php selected($script_loading, 'async'); ?>>Async</option>
                                    </select>
                                    <p class="description">Controls whether the tracking script tag uses <code>defer</code> (default) or <code>async</code>.</p>
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
                                        One pattern per line. Matching URL paths won’t be tracked.
                                    </p>
                                    <p class="description" style="margin-top: 6px;">
                                        <strong>Wildcards:</strong>
                                        <code>*</code> matches within one path segment (doesn’t cross <code>/</code>),
                                        <code>**</code> matches across multiple segments (can include <code>/</code>).
                                    </p>
                                    <p class="description" style="margin-top: 6px;">
                                        <strong>Examples:</strong><br />
                                        <code>/admin/*</code> matches <code>/admin/dashboard</code> but not <code>/admin/users/list</code><br />
                                        <code>/admin/**</code> matches <code>/admin/dashboard</code> and <code>/admin/users/list</code><br />
                                        <code>/blog/*/comments</code> matches <code>/blog/post-123/comments</code> but not <code>/blog/category/post/comments</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_mask_patterns">Mask patterns</label></th>
                                <td>
                                    <textarea id="rybbit_mask_patterns" name="rybbit_mask_patterns" rows="8" class="large-text code rybbit-input-wide"><?php echo esc_textarea($mask_patterns); ?></textarea>
                                    <p class="description">
                                        One pattern per line. Matching URL paths are tracked, but the recorded path will be replaced with the pattern (privacy masking).
                                    </p>
                                    <p class="description" style="margin-top: 6px;">
                                        <strong>Example:</strong><br />
                                        If you set <code>/account/**</code> and a user visits <code>/account/orders/123</code>, analytics will store <code>/account/**</code> instead of the real URL.
                                    </p>
                                    <p class="description" style="margin-top: 6px;">
                                        <strong>Wildcards:</strong> Same as Skip patterns (<code>*</code> within a segment, <code>**</code> across segments).
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

                    <div class="rybbit-tab-panel" data-tab="replay" role="tabpanel" style="display:none">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="rybbit_replay_mask_text_selectors">Mask text selectors</label></th>
                                <td>
                                    <textarea id="rybbit_replay_mask_text_selectors" name="rybbit_replay_mask_text_selectors" rows="6" class="large-text code rybbit-input-wide"><?php echo esc_textarea($replay_mask_text_selectors); ?></textarea>
                                    <p class="description" style="max-width: 720px;">
                                        One CSS selector per line. Text content of matching elements will be masked in session replays.
                                        Example: <code>.user-name</code> or <code>#email</code>.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_replay_block_class">Block class</label></th>
                                <td>
                                    <input type="text" id="rybbit_replay_block_class" name="rybbit_replay_block_class" value="<?php echo esc_attr($replay_block_class); ?>" class="regular-text" />
                                    <p class="description">Elements with this CSS class won’t be recorded at all (default: <code>rr-block</code>).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_replay_block_selector">Block selector</label></th>
                                <td>
                                    <input type="text" id="rybbit_replay_block_selector" name="rybbit_replay_block_selector" value="<?php echo esc_attr($replay_block_selector); ?>" class="regular-text rybbit-input-wide" placeholder=".sensitive-content, #payment-modal" />
                                    <p class="description">CSS selector for elements to exclude entirely from recording.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_replay_ignore_class">Ignore class</label></th>
                                <td>
                                    <input type="text" id="rybbit_replay_ignore_class" name="rybbit_replay_ignore_class" value="<?php echo esc_attr($replay_ignore_class); ?>" class="regular-text" />
                                    <p class="description">Elements with this CSS class appear in replay, but interactions aren’t recorded (default: <code>rr-ignore</code>).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_replay_ignore_selector">Ignore selector</label></th>
                                <td>
                                    <input type="text" id="rybbit_replay_ignore_selector" name="rybbit_replay_ignore_selector" value="<?php echo esc_attr($replay_ignore_selector); ?>" class="regular-text rybbit-input-wide" placeholder="input[name='credit-card']" />
                                    <p class="description">CSS selector for inputs/elements whose events should be ignored.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_replay_mask_text_class">Mask text class</label></th>
                                <td>
                                    <input type="text" id="rybbit_replay_mask_text_class" name="rybbit_replay_mask_text_class" value="<?php echo esc_attr($replay_mask_text_class); ?>" class="regular-text" />
                                    <p class="description">Elements with this CSS class have their text masked (default: <code>rr-mask</code>).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_replay_mask_all_inputs">Mask all inputs</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="rybbit_replay_mask_all_inputs" name="rybbit_replay_mask_all_inputs" value="1" <?php checked('1', $replay_mask_all_inputs); ?> />
                                        Mask all input values for privacy
                                    </label>
                                    <p class="description">When enabled, session replay masks input values as asterisks.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_replay_mask_input_options">Mask input options (JSON)</label></th>
                                <td>
                                    <?php
                                    // Default from tracking-script.mdx
                                    $replay_mask_input_options_default_json = wp_json_encode(array(
                                        'password' => true,
                                        'email' => true,
                                    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                    $replay_mask_input_options_default_b64 = base64_encode($replay_mask_input_options_default_json);
                                    ?>
                                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom: 6px;">
                                        <button
                                            type="button"
                                            class="button button-secondary rybbit-reset-json"
                                            data-rybbit-reset-target="#rybbit_replay_mask_input_options"
                                            data-rybbit-reset-value-b64="<?php echo esc_attr($replay_mask_input_options_default_b64); ?>"
                                        >Reset to defaults</button>
                                    </div>
                                    <textarea id="rybbit_replay_mask_input_options" name="rybbit_replay_mask_input_options" rows="5" class="large-text code rybbit-input-wide"><?php echo esc_textarea($replay_mask_input_options_display); ?></textarea>
                                    <p class="description" style="max-width: 720px;">JSON object to control which input types are masked. Example: <code>{"password":true,"email":true,"tel":true}</code>.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_replay_collect_fonts">Collect fonts</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="rybbit_replay_collect_fonts" name="rybbit_replay_collect_fonts" value="1" <?php checked('1', $replay_collect_fonts); ?> />
                                        Collect website fonts for accurate replays
                                    </label>
                                    <p class="description">Disable to reduce replay data size.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rybbit_replay_sampling">Sampling (JSON)</label></th>
                                <td>
                                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom: 6px;">
                                        <button
                                            type="button"
                                            class="button button-secondary rybbit-reset-json"
                                            data-rybbit-reset-target="#rybbit_replay_sampling"
                                            data-rybbit-reset-value-b64="<?php echo esc_attr($replay_sampling_default_b64); ?>"
                                        >Reset to defaults</button>
                                    </div>
                                    <textarea id="rybbit_replay_sampling" name="rybbit_replay_sampling" rows="9" class="large-text code rybbit-input-wide"><?php echo esc_textarea($replay_sampling_display); ?></textarea>

                                    <p class="description" style="max-width: 720px; margin-top: 6px;">
                                        Optional JSON object to reduce replay volume.
                                        <a href="https://rybbit.com/docs/script#sampling-configuration" target="_blank" rel="noopener noreferrer">Sampling configuration docs</a>.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="rybbit_replay_slim_dom_options">SlimDOM options</label></th>
                                <td>
                                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom: 6px;">
                                        <button
                                            type="button"
                                            class="button button-secondary rybbit-reset-json"
                                            data-rybbit-reset-target="#rybbit_replay_slim_dom_options"
                                            data-rybbit-reset-value-b64="<?php echo esc_attr($replay_slim_dom_default_b64); ?>"
                                        >Reset to defaults</button>
                                    </div>
                                    <textarea id="rybbit_replay_slim_dom_options" name="rybbit_replay_slim_dom_options" rows="9" class="large-text code rybbit-input-wide"><?php echo esc_textarea($replay_slim_dom_options_display); ?></textarea>
                                    <p class="description" style="max-width: 720px;">
                                        Optional replay DOM slimming config. Set to <code>true</code> to enable all slimDOM options, <code>false</code> to disable them, or provide a JSON object for fine-grained control.
                                        <a href="https://rybbit.com/docs/script#slimdom-options" target="_blank" rel="noopener noreferrer">SlimDOM options docs</a>.
                                    </p>
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

                    <div class="rybbit-tab-panel" data-tab="debug" role="tabpanel" style="display:none">
                        <?php
                        $excluded_roles_effective = $excluded_roles;
                        $current_user = wp_get_current_user();
                        $current_roles = ($current_user && isset($current_user->roles)) ? (array) $current_user->roles : array();

                        $is_excluded_by_role = false;
                        if (!empty($excluded_roles_effective) && is_user_logged_in()) {
                            $is_excluded_by_role = !empty(array_intersect($excluded_roles_effective, $current_roles));
                        }

                        // Build a script tag preview equivalent to the output logic.
                        $debounce_preview = is_string($debounce) ? trim($debounce) : (string) $debounce;
                        if ($debounce_preview === '' || !preg_match('/^\d+$/', $debounce_preview)) {
                            $debounce_preview = '500';
                        }

                        // (Admin UI stores one pattern per line)
                        $skip_lines = preg_split('/\r\n|\r|\n/', (string) $skip_patterns);
                        $skip_arr = array_values(array_filter(array_map('trim', is_array($skip_lines) ? $skip_lines : array())));
                        $mask_lines = preg_split('/\r\n|\r|\n/', (string) $mask_patterns);
                        $mask_arr = array_values(array_filter(array_map('trim', is_array($mask_lines) ? $mask_lines : array())));

                        $skip_json = wp_json_encode($skip_arr);
                        $mask_json = wp_json_encode($mask_arr);

                        $script_loading_preview = get_option('rybbit_script_loading', 'defer');

                        // Session replay preview values (normalize to the same shapes we output).
                        $replay_selectors_lines = preg_split('/\r\n|\r|\n/', (string) $replay_mask_text_selectors);
                        $replay_selectors_arr = array_values(array_filter(array_map('trim', is_array($replay_selectors_lines) ? $replay_selectors_lines : array())));
                        $replay_selectors_json = wp_json_encode($replay_selectors_arr);

                        // Preview values that correspond to how the tracking script is output.
                        // Note: keep this as data (not a literal script tag) to satisfy WordPress enqueue sniffs.
                        $tracking_script_preview = array(
                            'src' => esc_url_raw($script_url),
                            'loading' => ($script_loading_preview === 'async') ? 'async' : 'defer',
                            'attributes' => array(
                                'data-site-id' => (string) $site_id,
                                'data-skip-patterns' => ($skip_json ? $skip_json : '[]'),
                                'data-mask-patterns' => ($mask_json ? $mask_json : '[]'),
                                'data-debounce' => (string) $debounce_preview,
                                // Session Replay attributes
                                'data-replay-mask-text-selectors' => ($replay_selectors_json ? $replay_selectors_json : '[]'),
                                'data-replay-block-class' => (string) $replay_block_class,
                                'data-replay-block-selector' => (string) $replay_block_selector,
                                'data-replay-ignore-class' => (string) $replay_ignore_class,
                                'data-replay-ignore-selector' => (string) $replay_ignore_selector,
                                'data-replay-mask-text-class' => (string) $replay_mask_text_class,
                                'data-replay-mask-all-inputs' => ($replay_mask_all_inputs === '1') ? 'true' : 'false',
                                'data-replay-mask-input-options' => (string) $replay_mask_input_options,
                                'data-replay-collect-fonts' => ($replay_collect_fonts === '1') ? 'true' : 'false',
                                'data-replay-sampling' => (string) $replay_sampling,
                                'data-replay-slim-dom-options' => (string) $replay_slim_dom_options,
                            ),
                        );

                        // Default values (from tracking-script.mdx) used to decide which attributes are emitted.
                        $defaults_for_omit = array(
                            'data-skip-patterns' => '[]',
                            'data-mask-patterns' => '[]',
                            'data-debounce' => '500',
                            'data-replay-mask-text-selectors' => '[]',
                            'data-replay-block-class' => 'rr-block',
                            'data-replay-ignore-class' => 'rr-ignore',
                            'data-replay-mask-text-class' => 'rr-mask',
                            'data-replay-mask-all-inputs' => 'true',
                            'data-replay-collect-fonts' => 'true',
                            'data-replay-mask-input-options' => '{"password":true,"email":true}',
                            'data-replay-sampling' => '{"mousemove":false,"mouseInteraction":{"MouseUp":false,"MouseDown":false,"Click":true,"ContextMenu":false,"DblClick":true,"Focus":true,"Blur":true,"TouchStart":false,"TouchEnd":false},"scroll":500,"input":"last","media":800}',
                            'data-replay-slim-dom-options' => '{"script":false,"comment":true,"headFavicon":true,"headWhitespace":true,"headMetaDescKeywords":true,"headMetaSocial":true,"headMetaRobots":true,"headMetaHttpEquiv":true,"headMetaAuthorship":true,"headMetaVerification":true}',
                        );

                        // Normalize the JSON defaults to canonical encoding to match stored/preview strings.
                        $defaults_for_omit['data-replay-mask-input-options'] = wp_json_encode(json_decode($defaults_for_omit['data-replay-mask-input-options'], true));
                        $defaults_for_omit['data-replay-sampling'] = wp_json_encode(json_decode($defaults_for_omit['data-replay-sampling'], true));
                        $defaults_for_omit['data-replay-slim-dom-options'] = wp_json_encode(json_decode($defaults_for_omit['data-replay-slim-dom-options'], true));

                        $tracking_script_preview_for_debug = $tracking_script_preview;

                        // Normalize potentially pretty-printed JSON strings for comparisons.
                        foreach (array('data-replay-mask-input-options', 'data-replay-sampling', 'data-replay-slim-dom-options') as $k) {
                            if (isset($tracking_script_preview_for_debug['attributes'][$k])) {
                                $v = (string) $tracking_script_preview_for_debug['attributes'][$k];
                                $decoded = json_decode($v, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $tracking_script_preview_for_debug['attributes'][$k] = wp_json_encode($decoded);
                                }
                            }
                        }

                        // Omit attributes that match defaults (and omit empty selectors/selectors).
                        foreach ($tracking_script_preview_for_debug['attributes'] as $attr_key => $attr_val) {
                            // Always keep site id.
                            if ($attr_key === 'data-site-id') {
                                continue;
                            }

                            // Omit empty selector attrs (these are optional).
                            if (in_array($attr_key, array('data-replay-block-selector', 'data-replay-ignore-selector'), true)) {
                                if (trim((string) $attr_val) === '') {
                                    unset($tracking_script_preview_for_debug['attributes'][$attr_key]);
                                }
                                continue;
                            }

                            if (isset($defaults_for_omit[$attr_key]) && (string) $attr_val === (string) $defaults_for_omit[$attr_key]) {
                                unset($tracking_script_preview_for_debug['attributes'][$attr_key]);
                            }
                        }

                        // Decode JSON-bearing attributes for readable debug output.
                        $decode_json_if_possible = function ($value) {
                            if (!is_string($value)) {
                                return $value;
                            }
                            $trimmed = trim($value);
                            if ($trimmed === '') {
                                return $value;
                            }
                            if ($trimmed[0] !== '{' && $trimmed[0] !== '[') {
                                return $value;
                            }
                            $decoded = json_decode($trimmed, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                return $decoded;
                            }
                            return $value;
                        };

                        if (isset($tracking_script_preview_for_debug['attributes']) && is_array($tracking_script_preview_for_debug['attributes'])) {
                            foreach (array(
                                'data-skip-patterns',
                                'data-mask-patterns',
                                'data-replay-mask-text-selectors',
                                'data-replay-mask-input-options',
                                'data-replay-sampling',
                                'data-replay-slim-dom-options',
                            ) as $k) {
                                if (isset($tracking_script_preview_for_debug['attributes'][$k])) {
                                    $tracking_script_preview_for_debug['attributes'][$k] = $decode_json_if_possible($tracking_script_preview_for_debug['attributes'][$k]);
                                }
                            }
                        }
                        ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Effective settings</th>
                                <td>
                                    <pre class="rybbit-identify-payload"><?php
                                        echo esc_html(wp_json_encode(array(
                                            'site_id' => $site_id,
                                            'script_url' => $script_url,
                                            'excluded_roles' => $excluded_roles_effective,
                                            'skip_patterns_lines' => $skip_arr,
                                            'mask_patterns_lines' => $mask_arr,
                                            'debounce' => $debounce_preview,
                                            'identify_mode' => $identify_mode,
                                            'identify_userid_strategy' => $identify_userid_strategy,
                                            'identify_userid_meta_key' => $identify_userid_meta_key,
                                        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                    ?></pre>
                                    <p class="description">Values shown here reflect what’s currently saved in the database.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Current user</th>
                                <td>
                                    <p>
                                        Logged in: <strong><?php echo is_user_logged_in() ? 'yes' : 'no'; ?></strong><br />
                                        User ID: <code><?php echo is_user_logged_in() ? (int) get_current_user_id() : 0; ?></code><br />
                                        Roles: <code><?php echo esc_html(implode(', ', $current_roles)); ?></code><br />
                                        Excluded by role: <strong><?php echo $is_excluded_by_role ? 'yes' : 'no'; ?></strong>
                                    </p>
                                    <p class="description">If excluded by role is “yes”, the tracking script is disabled for this user.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Tracking script data</th>
                                <td>
                                    <pre class="rybbit-identify-payload"><?php
                                        echo esc_html(wp_json_encode($tracking_script_preview_for_debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                    ?></pre>
                                    <p class="description">This shows the script URL and attributes that will be applied when the tracking script is output.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Quick checks</th>
                                <td>
                                    <ul style="margin: 0; padding-left: 18px;">
                                        <li><strong>Site ID</strong> and <strong>Script URL</strong> must be set, otherwise nothing is output.</li>
                                        <li>Script is injected into <code>wp_head</code> (frontend) and <code>admin_head</code> (wp-admin).</li>
                                        <li>Role exclusion applies equally to frontend and wp-admin.</li>
                                    </ul>
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
                                <th scope="row">Installed plugin version</th>
                                <td>
                                    <code class="rybbit-installed-version"><?php echo esc_html($version !== '' ? $version : 'unknown'); ?></code>
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

    /**
     * Sanitize a newline-separated list of CSS selectors.
     * - Removes empty lines
     * - Normalizes line endings to \n
     */
    public function sanitize_selectors_newline_list($value) {
        // Reuse the same semantics as patterns list, but keep name explicit.
        return $this->sanitize_patterns_newline_list($value);
    }

    /**
     * Sanitize a CSS class name with a fallback default.
     */
    private function sanitize_css_class_with_default($value, $default) {
        $value = trim((string) $value);
        $value = sanitize_html_class($value);
        if ($value === '') {
            return (string) $default;
        }
        return $value;
    }

    /**
     * Sanitize a CSS selector text field.
     * We don't attempt to validate CSS selector syntax; we just store a trimmed string.
     */
    public function sanitize_css_selector_text($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        // Allow common selector characters; store as plain text.
        return sanitize_text_field($value);
    }

    /**
     * Sanitize a JSON object string or empty string.
     *
     * - Empty => '' (omit attribute)
     * - Valid JSON object => normalized wp_json_encode(object)
     * - Invalid => keep previous value and add a settings error
     */
    private function sanitize_json_object_string_or_empty($value, $option_name_for_error) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            add_settings_error(
                $option_name_for_error,
                $option_name_for_error . '_invalid_json',
                'Invalid JSON. Please provide a JSON object (example: {"password":true,"email":true}).',
                'error'
            );
            return (string) get_option($option_name_for_error, '');
        }

        return (string) wp_json_encode($decoded);
    }

    /**
     * SlimDOM options accept:
     * - '' (omit)
     * - 'true' or 'false'
     * - JSON object string
     */
    private function sanitize_slim_dom_options($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $lower = strtolower($value);
        if ($lower === 'true' || $lower === 'false') {
            return $lower;
        }

        return $this->sanitize_json_object_string_or_empty($value, 'rybbit_replay_slim_dom_options');
    }
}
