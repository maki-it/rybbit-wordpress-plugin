<?php
/**
 * Public-facing logic for Rybbit Analytics
 */
class Rybbit_Analytics_Public {
    public function __construct() {
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_head', array($this, 'add_tracking_script'));
    }
    public function enqueue_scripts() {
        // Enqueue frontend scripts/styles
    }
    public function add_tracking_script() {
        $site_id = get_option('rybbit_site_id', '');
        $script_url = get_option('rybbit_script_url', 'https://app.rybbit.io/api/script.js');
        $do_not_track_admins = get_option('rybbit_do_not_track_admins', '');

        // Script attributes
        $skip_patterns = get_option('rybbit_skip_patterns', '[]');
        $mask_patterns = get_option('rybbit_mask_patterns', '[]');
        $debounce = get_option('rybbit_debounce', '500');

        if (empty($site_id) || empty($script_url)) {
            return;
        }
        if ($do_not_track_admins === '1' && is_user_logged_in() && current_user_can('administrator')) {
            return;
        }

        // Normalize debounce to a string integer.
        $debounce = is_string($debounce) ? trim($debounce) : (string) $debounce;
        if ($debounce === '' || !preg_match('/^\d+$/', $debounce)) {
            $debounce = '500';
        }

        // Ensure JSON strings for patterns. If an admin saved newline-separated values (older versions),
        // we keep it safe by trying to parse and re-encode.
        $skip_patterns_json = $this->normalize_json_array_string($skip_patterns);
        $mask_patterns_json = $this->normalize_json_array_string($mask_patterns);
        ?>
        <!-- Rybbit Analytics Tracking -->
        <script>
        (function() {
            var s = document.createElement('script');
            s.src = '<?php echo esc_js($script_url); ?>';
            s.async = true;
            s.setAttribute('data-site-id', '<?php echo esc_js($site_id); ?>');
            s.setAttribute('data-skip-patterns', '<?php echo esc_js($skip_patterns_json); ?>');
            s.setAttribute('data-mask-patterns', '<?php echo esc_js($mask_patterns_json); ?>');
            s.setAttribute('data-debounce', '<?php echo esc_js($debounce); ?>');
            document.head.appendChild(s);
        })();
        </script>
        <!-- /Rybbit Analytics Tracking -->
        <?php
    }

    /**
     * Best-effort normalization: accept JSON array string or newline-separated list.
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
