<?php
/**
 * Version/update helpers for Rybbit Analytics.
 */
class Rybbit_Analytics_Updates {
    const TRANSIENT_KEY = 'rybbit_analytics_latest_version';
    const TRANSIENT_TTL = 6 * HOUR_IN_SECONDS;

    /**
     * Minimal interval between forced checks to avoid slow admin loads / API rate limits.
     */
    const FORCE_CHECK_MIN_INTERVAL = 5 * MINUTE_IN_SECONDS;

    /**
     * Returns the plugin version from the main plugin file header.
     */
    public static function get_installed_version() {
        if (!function_exists('get_file_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_file_data(
            plugin_dir_path(__DIR__) . 'rybbit-analytics.php',
            array('Version' => 'Version'),
            'plugin'
        );

        $v = isset($data['Version']) ? trim((string) $data['Version']) : '';
        return $v !== '' ? $v : null;
    }

    /**
     * Fetch latest GitHub release tag (strips leading "v").
     * Uses caching to avoid slowing down admin.
     *
     * @param bool $force When true, bypass cache (bounded by FORCE_CHECK_MIN_INTERVAL).
     */
    public static function get_latest_version($force = false) {
        $force = (bool) $force;

        if ($force) {
            // Rate-limit forced checks so opening the About tab repeatedly doesn't spam GitHub.
            $last_forced = get_transient(self::TRANSIENT_KEY . '_last_forced');
            if (!is_int($last_forced)) {
                $last_forced = is_numeric($last_forced) ? (int) $last_forced : 0;
            }
            if ($last_forced <= 0 || (time() - $last_forced) >= self::FORCE_CHECK_MIN_INTERVAL) {
                delete_transient(self::TRANSIENT_KEY);
                set_transient(self::TRANSIENT_KEY . '_last_forced', time(), self::FORCE_CHECK_MIN_INTERVAL);
            }
        }

        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = 'https://api.github.com/repos/maki-it/rybbit-wordpress-plugin/releases/latest';

        $response = wp_remote_get($url, array(
            'timeout' => 6,
            'headers' => array(
                // GitHub API may require a UA.
                'User-Agent' => 'WordPress; Rybbit Analytics Plugin',
                'Accept' => 'application/vnd.github+json',
            ),
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            return null;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }

        $tag = isset($json['tag_name']) ? (string) $json['tag_name'] : '';
        $tag = trim($tag);
        if ($tag === '') {
            return null;
        }

        // Normalize: remove leading v.
        if (substr($tag, 0, 1) === 'v') {
            $tag = substr($tag, 1);
        }

        // Basic semver-ish validation.
        if (!preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $tag)) {
            return null;
        }

        set_transient(self::TRANSIENT_KEY, $tag, self::TRANSIENT_TTL);
        return $tag;
    }

    /**
     * Returns true if installed version is older than latest.
     */
    public static function is_update_available() {
        $installed = self::get_installed_version();
        $latest = self::get_latest_version();

        if (!$installed || !$latest) {
            return null; // unknown
        }

        // version_compare understands semver-ish strings.
        return version_compare($installed, $latest, '<');
    }
}
