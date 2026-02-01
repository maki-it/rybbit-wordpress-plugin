<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Public-facing logic for Integrate Rybbit
 */
class Integrate_Rybbit_Public {
    /**
     * Stores tracking script attributes for use in script_loader_tag filter.
     * @var array|null
     */
    private $tracking_script_attributes = null;

    public function __construct() {
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_head', array($this, 'add_tracking_script'));

        // If a logout happened, clear any persisted Rybbit user id on the next page load.
        add_action('wp_head', array($this, 'maybe_clear_user_after_logout'));

        // Optional: also track wp-admin pages
        add_action('admin_head', array($this, 'maybe_add_tracking_script_admin'));

        // Clear Rybbit user id on WP logout screens
        add_action('login_head', array($this, 'maybe_clear_user_on_logout_screen'));

        // Mark logout in a way we can detect on the next request (covers logout flows that *don't* hit wp-login.php?action=logout).
        add_action('wp_logout', array($this, 'mark_logout_for_user_id_clearing'));
    }

    /**
     * Inject tracking into wp-admin when enabled.
     */
    public function maybe_add_tracking_script_admin() {
        $track_admin = get_option('rybbit_track_wp_admin', '0');
        if ($track_admin !== '1') {
            return;
        }

        // Avoid running on AJAX/REST/admin-ajax contexts.
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        $this->add_tracking_script();
    }

    /**
     * On wp-login.php?action=logout (and related), clear any persisted Rybbit user id.
     */
    public function maybe_clear_user_on_logout_screen() {
        // Read and sanitize request data without tripping PHPCS nonce warnings.
        $action = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        if ( empty( $action ) || $action !== 'logout' ) {
            return;
        }

        // If WordPress provides a logout nonce, verify it before running.
        // Some flows may not include it at this point; in that case we just gate on action=logout.
        $logout_nonce = filter_input( INPUT_GET, '_wpnonce', FILTER_UNSAFE_RAW );
        if ( ! empty( $logout_nonce ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $logout_nonce ) ), 'log-out' ) ) {
            return;
        }

        $identify_mode = get_option('rybbit_identify_mode', 'disabled');
        if ($identify_mode === 'disabled') {
            return;
        }

        // Enqueue a dummy script handle and attach inline script for clearing user ID.
        wp_register_script('rybbit-clear-user-logout', false, array(), INTEGRATE_RYBBIT_VERSION, false);
        wp_enqueue_script('rybbit-clear-user-logout');

        $inline_script = "(function() {
            var attempts = 0;
            var maxAttempts = 20;
            var interval = 100;

            function tryClear() {
                attempts++;
                try {
                    if (window.rybbit && typeof window.rybbit.clearUserId === 'function') {
                        window.rybbit.clearUserId();
                        return true;
                    }
                } catch (e) {
                    return true;
                }
                return false;
            }

            if (tryClear()) {
                return;
            }

            var timer = setInterval(function() {
                if (tryClear() || attempts >= maxAttempts) {
                    clearInterval(timer);
                }
            }, interval);
        })();";

        wp_add_inline_script('rybbit-clear-user-logout', $inline_script);
    }

    /**
     * Set a short-lived cookie marker on logout so we can clear the ID on the next page load.
     */
    public function mark_logout_for_user_id_clearing() {
        $identify_mode = get_option('rybbit_identify_mode', 'disabled');
        if ($identify_mode === 'disabled') {
            return;
        }

        // Use a short lifetime; we only need the very next request.
        $expires = time() + 300; // 5 minutes
        $path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        // Best-effort: set both non-SSL and SSL flags based on current request.
        $secure = is_ssl();
        $httponly = false; // needs to be readable by PHP on next request; JS doesn't need it.

        setcookie('rybbit_clear_user_id', '1', $expires, $path, $domain, $secure, $httponly);
    }

    /**
     * If a logout marker cookie is present, clear any persisted Rybbit user id.
     * This handles logout flows that redirect away from wp-login.php action=logout.
     */
    public function maybe_clear_user_after_logout() {
        if (empty($_COOKIE['rybbit_clear_user_id'])) {
            return;
        }

        $identify_mode = get_option('rybbit_identify_mode', 'disabled');
        if ($identify_mode === 'disabled') {
            // Clear marker cookie to avoid it sticking around.
            $this->clear_logout_marker_cookie();
            return;
        }

        // Clear marker cookie early to avoid repeated calls even if JS errors.
        $this->clear_logout_marker_cookie();

        // Enqueue a dummy script handle and attach inline script for clearing user ID.
        wp_register_script('rybbit-clear-user-after-logout', false, array(), INTEGRATE_RYBBIT_VERSION, false);
        wp_enqueue_script('rybbit-clear-user-after-logout');

        $inline_script = "(function() {
            var attempts = 0;
            var maxAttempts = 20;
            var interval = 100;

            function tryClear() {
                attempts++;
                try {
                    if (window.rybbit && typeof window.rybbit.clearUserId === 'function') {
                        window.rybbit.clearUserId();
                        return true;
                    }
                } catch (e) {
                    return true;
                }
                return false;
            }

            if (tryClear()) {
                return;
            }

            var timer = setInterval(function() {
                if (tryClear() || attempts >= maxAttempts) {
                    clearInterval(timer);
                }
            }, interval);
        })();";

        wp_add_inline_script('rybbit-clear-user-after-logout', $inline_script);
    }

    /**
     * Clear the logout marker cookie.
     */
    private function clear_logout_marker_cookie() {
        $path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        // Clear both secure and non-secure variants.
        setcookie('rybbit_clear_user_id', '', time() - 3600, $path, $domain, false, false);
        setcookie('rybbit_clear_user_id', '', time() - 3600, $path, $domain, true, false);

        unset($_COOKIE['rybbit_clear_user_id']);
    }

    public function enqueue_scripts() {
        // Enqueue frontend scripts/styles
    }
    /**
     * Escape a value for a single-quoted HTML attribute.
     *
     * We intentionally keep double quotes (") unescaped so JSON strings look like the docs:
     *   data-foo='{"a":1}'
     */
    private function esc_attr_single_quote($value) {
        $value = (string) $value;
        // Escape & and < and > for HTML safety.
        $value = htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8');
        // In a single-quoted attribute, only single quotes must be escaped.
        $value = str_replace("'", '&#039;', $value);
        return $value;
    }

    /**
     * Filter callback to add custom data attributes to the Rybbit tracking script tag.
     *
     * @param string $tag    The script tag HTML.
     * @param string $handle The script handle.
     * @param string $src    The script source URL.
     * @return string Modified script tag with data attributes.
     */
    public function add_tracking_script_attributes($tag, $handle, $src) {
        if ($handle !== 'rybbit-tracking-script' || empty($this->tracking_script_attributes)) {
            return $tag;
        }

        // Build custom attributes
        $custom_attrs = array();
        foreach ($this->tracking_script_attributes as $key => $value) {
            if ($value !== null) {
                $custom_attrs[] = $key . '="' . esc_attr($value) . '"';
            }
        }

        // Replace the opening <script tag with one that includes our custom attributes
        if (!empty($custom_attrs)) {
            $attrs_string = ' ' . implode(' ', $custom_attrs);
            $tag = preg_replace('/<script /', '<script' . $attrs_string . ' ', $tag, 1);
        }

        return $tag;
    }

    public function add_tracking_script() {
        $site_id = get_option('rybbit_site_id', '');
        $script_url = get_option('rybbit_script_url', 'https://app.rybbit.io/api/script.js');
        $script_loading = get_option('rybbit_script_loading', 'defer');

        $excluded_roles_opt = get_option('rybbit_excluded_roles', null);
        $do_not_track_admins = get_option('rybbit_do_not_track_admins', '1');

        $skip_patterns = get_option('rybbit_skip_patterns', '');
        $mask_patterns = get_option('rybbit_mask_patterns', '');
        $debounce = get_option('rybbit_debounce', '500');

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
        $replay_sampling = get_option('rybbit_replay_sampling', '{"mousemove":false,"mouseInteraction":{"MouseUp":false,"MouseDown":false,"Click":true,"ContextMenu":false,"DblClick":true,"Focus":true,"Blur":true,"TouchStart":false,"TouchEnd":false},"scroll":500,"input":"last","media":800}');
        $replay_slim_dom_options = get_option('rybbit_replay_slim_dom_options', '{"script":false,"comment":true,"headFavicon":true,"headWhitespace":true,"headMetaDescKeywords":true,"headMetaSocial":true,"headMetaRobots":true,"headMetaHttpEquiv":true,"headMetaAuthorship":true,"headMetaVerification":true}');

        if (empty($site_id) || empty($script_url)) {
            return;
        }

        // Role-based exclusions:
        // - If the new setting exists, use it.
        // - Otherwise fall back to the legacy admin-only checkbox.
        $excluded_roles = array();
        if ($excluded_roles_opt !== null) {
            $excluded_roles = $this->normalize_roles_option_to_array($excluded_roles_opt);
        } elseif ($do_not_track_admins === '1') {
            $excluded_roles = array('administrator');
        }

        if (!empty($excluded_roles) && is_user_logged_in()) {
            $user = wp_get_current_user();
            $user_roles = ($user && isset($user->roles)) ? (array) $user->roles : array();
            if (!empty(array_intersect($excluded_roles, $user_roles))) {
                return;
            }
        }

        // Normalize/validate attribute values.
        $debounce = is_string($debounce) ? trim($debounce) : (string) $debounce;
        if ($debounce === '' || !preg_match('/^\d+$/', $debounce)) {
            $debounce = '500';
        }

        // Patterns are stored as one-per-line; convert them to JSON arrays for the script attributes.
        $skip_patterns_json = $this->patterns_to_json_array_string($skip_patterns);
        $mask_patterns_json = $this->patterns_to_json_array_string($mask_patterns);

        // Normalize replay attribute values.
        $replay_selectors_json = $this->normalize_json_array_string($replay_mask_text_selectors);

        $replay_block_class = sanitize_html_class(trim((string) $replay_block_class));
        if ($replay_block_class === '') {
            $replay_block_class = 'rr-block';
        }

        $replay_ignore_class = sanitize_html_class(trim((string) $replay_ignore_class));
        if ($replay_ignore_class === '') {
            $replay_ignore_class = 'rr-ignore';
        }

        $replay_mask_text_class = sanitize_html_class(trim((string) $replay_mask_text_class));
        if ($replay_mask_text_class === '') {
            $replay_mask_text_class = 'rr-mask';
        }

        $replay_block_selector = trim((string) $replay_block_selector);
        $replay_ignore_selector = trim((string) $replay_ignore_selector);

        $replay_mask_all_inputs_str = ($replay_mask_all_inputs === '1') ? 'true' : 'false';
        $replay_collect_fonts_str = ($replay_collect_fonts === '1') ? 'true' : 'false';

        // ----- Default values (from tracking-script.mdx) -----
        $default_skip_patterns_json = '[]';
        $default_mask_patterns_json = '[]';
        $default_debounce = '500';

        $default_replay_mask_text_selectors_json = '[]';
        $default_replay_block_class = 'rr-block';
        $default_replay_ignore_class = 'rr-ignore';
        $default_replay_mask_text_class = 'rr-mask';
        $default_replay_mask_all_inputs_str = 'true';
        $default_replay_collect_fonts_str = 'true';
        $default_replay_mask_input_options_json = '{"password":true,"email":true}';
        $default_replay_sampling_json = '{"mousemove":false,"mouseInteraction":{"MouseUp":false,"MouseDown":false,"Click":true,"ContextMenu":false,"DblClick":true,"Focus":true,"Blur":true,"TouchStart":false,"TouchEnd":false},"scroll":500,"input":"last","media":800}';
        $default_replay_slim_dom_options_json = '{"script":false,"comment":true,"headFavicon":true,"headWhitespace":true,"headMetaDescKeywords":true,"headMetaSocial":true,"headMetaRobots":true,"headMetaHttpEquiv":true,"headMetaAuthorship":true,"headMetaVerification":true}';

        // Normalize defaults to the same canonical JSON strings we store/output.
        $default_replay_mask_input_options_json = wp_json_encode(json_decode($default_replay_mask_input_options_json, true));
        $default_replay_sampling_json = wp_json_encode(json_decode($default_replay_sampling_json, true));
        $default_replay_slim_dom_options_json = wp_json_encode(json_decode($default_replay_slim_dom_options_json, true));

        // Current JSON fields are stored normalized via sanitize callbacks, but might be pretty-printed.
        // Normalize them for reliable comparison.
        $replay_mask_input_options_norm = $replay_mask_input_options;
        $tmp = json_decode($replay_mask_input_options_norm, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
            $replay_mask_input_options_norm = wp_json_encode($tmp);
        }
        $replay_sampling_norm = $replay_sampling;
        $tmp = json_decode($replay_sampling_norm, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
            $replay_sampling_norm = wp_json_encode($tmp);
        }
        $replay_slim_dom_options_norm = $replay_slim_dom_options;
        $tmp = json_decode($replay_slim_dom_options_norm, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
            $replay_slim_dom_options_norm = wp_json_encode($tmp);
        }

        // Decide which attributes to emit: omit when empty or equal to documented defaults.
        $has_skip_patterns = ($skip_patterns_json !== $default_skip_patterns_json);
        $has_mask_patterns = ($mask_patterns_json !== $default_mask_patterns_json);
        $has_debounce = ($debounce !== $default_debounce);

        $has_replay_selectors = ($replay_selectors_json !== $default_replay_mask_text_selectors_json);
        $has_replay_block_class = ($replay_block_class !== $default_replay_block_class);
        $has_replay_block_selector = ($replay_block_selector !== '');
        $has_replay_ignore_class = ($replay_ignore_class !== $default_replay_ignore_class);
        $has_replay_ignore_selector = ($replay_ignore_selector !== '');
        $has_replay_mask_text_class = ($replay_mask_text_class !== $default_replay_mask_text_class);
        $has_replay_mask_all_inputs = ($replay_mask_all_inputs_str !== $default_replay_mask_all_inputs_str);
        $has_replay_mask_input_options = ($replay_mask_input_options_norm !== '' && $replay_mask_input_options_norm !== $default_replay_mask_input_options_json);
        $has_replay_collect_fonts = ($replay_collect_fonts_str !== $default_replay_collect_fonts_str);
        $has_replay_sampling = ($replay_sampling_norm !== '' && $replay_sampling_norm !== $default_replay_sampling_json);
        $has_replay_slim_dom_options = ($replay_slim_dom_options_norm !== '' && $replay_slim_dom_options_norm !== $default_replay_slim_dom_options_json);

        // Pre-escape JSON-ish values for single-quoted attributes so view-source matches docs.
        $skip_patterns_attr = $this->esc_attr_single_quote($skip_patterns_json);
        $mask_patterns_attr = $this->esc_attr_single_quote($mask_patterns_json);
        $replay_selectors_attr = $this->esc_attr_single_quote($replay_selectors_json);
        $replay_mask_input_options_attr = $this->esc_attr_single_quote($replay_mask_input_options_norm);
        $replay_sampling_attr = $this->esc_attr_single_quote($replay_sampling_norm);
        $replay_slim_dom_options_attr = $this->esc_attr_single_quote($replay_slim_dom_options_norm);

        // Build the attribute lines explicitly to avoid odd whitespace from PHP templating.
        $attr_lines = array();
        $attr_lines[] = 'data-site-id="' . esc_attr($site_id) . '"';

        if ($has_skip_patterns) {
            $attr_lines[] = "data-skip-patterns='" . $skip_patterns_attr . "'";
        }
        if ($has_mask_patterns) {
            $attr_lines[] = "data-mask-patterns='" . $mask_patterns_attr . "'";
        }
        if ($has_debounce) {
            $attr_lines[] = 'data-debounce="' . esc_attr($debounce) . '"';
        }

        // Session replay
        if ($has_replay_selectors) {
            $attr_lines[] = "data-replay-mask-text-selectors='" . $replay_selectors_attr . "'";
        }
        if ($has_replay_block_class) {
            $attr_lines[] = 'data-replay-block-class="' . esc_attr($replay_block_class) . '"';
        }
        if ($has_replay_block_selector) {
            $attr_lines[] = 'data-replay-block-selector="' . esc_attr($replay_block_selector) . '"';
        }
        if ($has_replay_ignore_class) {
            $attr_lines[] = 'data-replay-ignore-class="' . esc_attr($replay_ignore_class) . '"';
        }
        if ($has_replay_ignore_selector) {
            $attr_lines[] = 'data-replay-ignore-selector="' . esc_attr($replay_ignore_selector) . '"';
        }
        if ($has_replay_mask_text_class) {
            $attr_lines[] = 'data-replay-mask-text-class="' . esc_attr($replay_mask_text_class) . '"';
        }
        if ($has_replay_mask_all_inputs) {
            $attr_lines[] = 'data-replay-mask-all-inputs="' . esc_attr($replay_mask_all_inputs_str) . '"';
        }
        if ($has_replay_mask_input_options) {
            $attr_lines[] = "data-replay-mask-input-options='" . $replay_mask_input_options_attr . "'";
        }
        if ($has_replay_collect_fonts) {
            $attr_lines[] = 'data-replay-collect-fonts="' . esc_attr($replay_collect_fonts_str) . '"';
        }
        if ($has_replay_sampling) {
            $attr_lines[] = "data-replay-sampling='" . $replay_sampling_attr . "'";
        }
        if ($has_replay_slim_dom_options) {
            $attr_lines[] = "data-replay-slim-dom-options='" . $replay_slim_dom_options_attr . "'";
        }

        // Parse attribute lines into an associative array for the filter callback
        $this->tracking_script_attributes = array();
        foreach ($attr_lines as $attr_line) {
            // Match both single-quoted and double-quoted attributes
            if (preg_match('/^([a-z-]+)=(["\'])(.*)\\2$/s', $attr_line, $matches)) {
                $attr_name = $matches[1];
                $attr_value = $matches[3];
                $this->tracking_script_attributes[$attr_name] = $attr_value;
            }
        }

        // Enqueue the external tracking script using WordPress's enqueue system
        // Use 'defer' strategy if WordPress 6.3+ is available, otherwise we'll add it via filter
        $in_footer = true;
        $strategy = ($script_loading === 'async') ? 'async' : 'defer';

        // Register and enqueue the script
        wp_register_script(
            'rybbit-tracking-script',
            $script_url,
            array(),
            INTEGRATE_RYBBIT_VERSION,  // Use plugin version for cache busting
            array(
                'in_footer' => $in_footer,
                'strategy'  => $strategy
            )
        );

        wp_enqueue_script('rybbit-tracking-script');

        // Add filter to inject custom data attributes
        add_filter('script_loader_tag', array($this, 'add_tracking_script_attributes'), 10, 3);

        // Identify logged-in users (optional; default disabled)
        $identify_mode = get_option('rybbit_identify_mode', 'disabled');
        if ($identify_mode !== 'disabled') {
            $payload = function_exists('integrate_rybbit_get_identify_payload')
                ? integrate_rybbit_get_identify_payload($identify_mode)
                : null;

            if (is_array($payload) && !empty($payload['userId'])) {
                $user_id = (string) $payload['userId'];
                $traits = isset($payload['traits']) ? $payload['traits'] : array();

                // Enqueue a dummy script handle and attach inline script for user identification.
                wp_register_script('rybbit-identify-user', false, array(), INTEGRATE_RYBBIT_VERSION, false);
                wp_enqueue_script('rybbit-identify-user');

                $inline_script = sprintf(
                    "(function() {
                        var userId = %s;
                        var traits = %s;

                        // Try for ~2 seconds to wait for the tracker to load.
                        var attempts = 0;
                        var maxAttempts = 20;
                        var interval = 100;

                        function tryIdentifyOrUpdateTraits() {
                            attempts++;
                            try {
                                if (!window.rybbit) {
                                    return false;
                                }

                                // Identify once. On later loads, only update traits.
                                var currentUserId = (typeof window.rybbit.getUserId === 'function') ? window.rybbit.getUserId() : null;

                                if (currentUserId !== userId) {
                                    if (typeof window.rybbit.identify === 'function') {
                                        window.rybbit.identify(userId, traits);
                                        return true;
                                    }
                                    return false;
                                }

                                if (typeof window.rybbit.setTraits === 'function') {
                                    window.rybbit.setTraits(traits);
                                    return true;
                                }

                                // Fallback: if setTraits isn't available, re-identify.
                                if (typeof window.rybbit.identify === 'function') {
                                    window.rybbit.identify(userId, traits);
                                    return true;
                                }
                            } catch (e) {
                                // Ignore
                                return true;
                            }
                            return false;
                        }

                        if (tryIdentifyOrUpdateTraits()) {
                            return;
                        }

                        var timer = setInterval(function() {
                            if (tryIdentifyOrUpdateTraits() || attempts >= maxAttempts) {
                                clearInterval(timer);
                            }
                        }, interval);
                    })();",
                    wp_json_encode($user_id),
                    wp_json_encode($traits)
                );

                wp_add_inline_script('rybbit-identify-user', $inline_script);
            }
        }
    }

    /**
     * Convert either a newline-separated list OR an existing JSON array string
     * into a normalized JSON string array.
     */
    private function patterns_to_json_array_string($value) {
        if ($value === null) {
            return '[]';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '[]';
        }

        // Backward-compatibility: accept stored JSON array string.
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

        $lines = preg_split('/\r\n|\r|\n/', $value);
        $out = array();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return wp_json_encode(array_values($out));
    }

    /**
     * Best-effort normalization: accept JSON array string or newline-separated list.
     * Returns a JSON string array.
     */
    private function normalize_json_array_string($value) {
        if ($value === null) {
            return '[]';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '[]';
        }

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

        // Fallback: newline separated values.
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
     * Normalize the excluded roles option to an array of role slugs.
     * Accepts:
     * - array (preferred)
     * - JSON string array (legacy)
     * - anything else -> []
     */
    private function normalize_roles_option_to_array($value) {
        if (is_array($value)) {
            $roles = $value;
        } else {
            $decoded = json_decode((string) $value, true);
            $roles = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : array();
        }

        $out = array();
        foreach ($roles as $r) {
            $r = sanitize_key((string) $r);
            if ($r !== '') {
                $out[] = $r;
            }
        }

        return array_values(array_unique($out));
    }
}
