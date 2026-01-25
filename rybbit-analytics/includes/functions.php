<?php
// General plugin functions for Rybbit Analytics

if (!function_exists('rybbit_analytics_get_identify_payload')) {
    /**
     * Builds the identify payload for the current logged-in user.
     *
     * Modes:
     *  - disabled: returns null
     *  - pseudonymized: sends userId + non-sensitive traits + hashed email
     *  - full: sends userId + cleartext traits (email, display_name, username)
     *
     * Returns:
     *  - null if mode is disabled or user is not logged in
     *  - array{userId: string, traits: array<string,mixed>} otherwise
     */
    function rybbit_analytics_get_identify_payload($mode = 'pseudonymized') {
        $mode = is_string($mode) ? $mode : 'pseudonymized';
        if ($mode === 'disabled') {
            return null;
        }

        if (!is_user_logged_in()) {
            return null;
        }

        $user = wp_get_current_user();
        if (!$user || empty($user->ID)) {
            return null;
        }

        // Stable, site-scoped id (helps on multisite setups)
        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $user_id = 'wp:' . $blog_id . ':' . (int) $user->ID;

        // Always safe-ish traits
        $traits = array(
            'roles' => array_values((array) $user->roles),
        );

        if ($mode === 'pseudonymized') {
            // Prefer a non-email display name.
            if (!empty($user->user_login)) {
                $traits['username'] = (string) $user->user_login;
            } elseif (!empty($user->display_name)) {
                $traits['username'] = (string) $user->display_name;
            }

            // Provide fallback display name if available.
            if (!empty($user->display_name)) {
                $traits['name'] = (string) $user->display_name;
            }

            // Hash only (no cleartext email)
            if (!empty($user->user_email) && function_exists('hash')) {
                $email = strtolower(trim((string) $user->user_email));
                $traits['email_hash'] = hash('sha256', $email);
            }
        } elseif ($mode === 'full') {
            // Cleartext traits (PII) - only if site owner explicitly enables it.
            // Use Rybbit special fields.
            if (!empty($user->user_login)) {
                $traits['username'] = (string) $user->user_login;
            }
            if (!empty($user->display_name)) {
                $traits['name'] = (string) $user->display_name;
            }
            if (!empty($user->user_email)) {
                $traits['email'] = (string) $user->user_email;
            }
        } else {
            // Unknown values default to disabled
            return null;
        }

        return array(
            'userId' => $user_id,
            'traits' => $traits,
        );
    }
}
