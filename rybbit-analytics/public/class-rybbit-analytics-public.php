<?php
/**
 * Public-facing logic for Rybbit Analytics
 */
class Rybbit_Analytics_Public {
    public function __construct() {
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_head', array($this, 'add_tracking_script'));

        // Clear Rybbit user id on WP logout screens
        add_action('login_head', array($this, 'maybe_clear_user_on_logout_screen'));
    }

    /**
     * On wp-login.php?action=logout (and related), clear any persisted Rybbit user id.
     */
    public function maybe_clear_user_on_logout_screen() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'logout') {
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

    public function enqueue_scripts() {
        // Enqueue frontend scripts/styles
    }
    public function add_tracking_script() {
        $site_id = get_option('rybbit_site_id', '');
        $script_url = get_option('rybbit_script_url', 'https://app.rybbit.io/api/script.js');
        $do_not_track_admins = get_option('rybbit_do_not_track_admins', '1');

        $skip_patterns = get_option('rybbit_skip_patterns', '[]');
        $mask_patterns = get_option('rybbit_mask_patterns', '[]');
        $debounce = get_option('rybbit_debounce', '500');

        if (empty($site_id) || empty($script_url)) {
            return;
        }

        if ($do_not_track_admins === '1' && is_user_logged_in() && current_user_can('administrator')) {
            return;
        }

        // Normalize/validate attribute values.
        $debounce = is_string($debounce) ? trim($debounce) : (string) $debounce;
        if ($debounce === '' || !preg_match('/^\d+$/', $debounce)) {
            $debounce = '500';
        }

        // Patterns are stored as one-per-line; convert them to JSON arrays for the script attributes.
        $skip_patterns_json = $this->patterns_to_json_array_string($skip_patterns);
        $mask_patterns_json = $this->patterns_to_json_array_string($mask_patterns);

        ?>
        <!-- Rybbit Analytics Tracking -->
        <script
            src="<?php echo esc_url($script_url); ?>"
            async
            data-site-id="<?php echo esc_attr($site_id); ?>"
            data-skip-patterns='<?php echo esc_attr($skip_patterns_json); ?>'
            data-mask-patterns='<?php echo esc_attr($mask_patterns_json); ?>'
            data-debounce="<?php echo esc_attr($debounce); ?>"
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
                    var traits = <?php echo $traits_json ? $traits_json : '{}'; ?>;

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
}
