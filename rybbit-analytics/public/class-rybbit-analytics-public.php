<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Public-facing logic for Rybbit Analytics
 */
class Rybbit_Analytics_Public {
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
        ?>
        <script>
        (function() {
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
        })();
        </script>
        <?php
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
        ?>
        <script>
        (function() {
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
        })();
        </script>
        <?php
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

        // JSON fields: best-effort ensure they're either empty, 'true'/'false' (slimdom), or valid JSON object strings.
        $replay_mask_input_options = trim((string) $replay_mask_input_options);
        $replay_sampling = trim((string) $replay_sampling);
        $replay_slim_dom_options = trim((string) $replay_slim_dom_options);

        // Only emit optional JSON attributes when non-empty to avoid overriding script defaults.
        $has_replay_mask_input_options = ($replay_mask_input_options !== '');
        $has_replay_sampling = ($replay_sampling !== '');
        $has_replay_slim_dom_options = ($replay_slim_dom_options !== '');

        // Pre-escape JSON-ish values for single-quoted attributes so view-source matches docs.
        $skip_patterns_attr = $this->esc_attr_single_quote($skip_patterns_json);
        $mask_patterns_attr = $this->esc_attr_single_quote($mask_patterns_json);
        $replay_selectors_attr = $this->esc_attr_single_quote($replay_selectors_json);
        $replay_mask_input_options_attr = $this->esc_attr_single_quote($replay_mask_input_options);
        $replay_sampling_attr = $this->esc_attr_single_quote($replay_sampling);
        $replay_slim_dom_options_attr = $this->esc_attr_single_quote($replay_slim_dom_options);

        ?>
        <!-- Rybbit Analytics Tracking -->
        <script
            src="<?php echo esc_url($script_url); ?>"
            <?php echo ($script_loading === 'async') ? 'async' : 'defer'; ?>
            data-site-id="<?php echo esc_attr($site_id); ?>"
            data-skip-patterns='<?php echo $skip_patterns_attr; ?>'
            data-mask-patterns='<?php echo $mask_patterns_attr; ?>'
            data-debounce="<?php echo esc_attr($debounce); ?>"
            data-replay-mask-text-selectors='<?php echo $replay_selectors_attr; ?>'
            data-replay-block-class="<?php echo esc_attr($replay_block_class); ?>"
            <?php if ($replay_block_selector !== '') : ?>
            data-replay-block-selector="<?php echo esc_attr($replay_block_selector); ?>"
            <?php endif; ?>
            data-replay-ignore-class="<?php echo esc_attr($replay_ignore_class); ?>"
            <?php if ($replay_ignore_selector !== '') : ?>
            data-replay-ignore-selector="<?php echo esc_attr($replay_ignore_selector); ?>"
            <?php endif; ?>
            data-replay-mask-text-class="<?php echo esc_attr($replay_mask_text_class); ?>"
            data-replay-mask-all-inputs="<?php echo esc_attr($replay_mask_all_inputs_str); ?>"
            <?php if ($has_replay_mask_input_options) : ?>
            data-replay-mask-input-options='<?php echo $replay_mask_input_options_attr; ?>'
            <?php endif; ?>
            data-replay-collect-fonts="<?php echo esc_attr($replay_collect_fonts_str); ?>"
            <?php if ($has_replay_sampling) : ?>
            data-replay-sampling='<?php echo $replay_sampling_attr; ?>'
            <?php endif; ?>
            <?php if ($has_replay_slim_dom_options) : ?>
            data-replay-slim-dom-options='<?php echo $replay_slim_dom_options_attr; ?>'
            <?php endif; ?>
        ></script>
        <!-- End Rybbit Analytics Tracking -->
        <?php

        // Identify logged-in users (optional; default disabled)
        $identify_mode = get_option('rybbit_identify_mode', 'disabled');
        if ($identify_mode !== 'disabled') {
            $payload = function_exists('rybbit_analytics_get_identify_payload')
                ? rybbit_analytics_get_identify_payload($identify_mode)
                : null;

            if (is_array($payload) && !empty($payload['userId'])) {
                $user_id = (string) $payload['userId'];
                $traits_json = wp_json_encode(isset($payload['traits']) ? $payload['traits'] : array());
                ?>
                <script>
                (function() {
                    var userId = <?php echo wp_json_encode($user_id); ?>;
                    var traits = <?php echo esc_js($traits_json ? $traits_json : '{}'); ?>;

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
                })();
                </script>
                <?php
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
