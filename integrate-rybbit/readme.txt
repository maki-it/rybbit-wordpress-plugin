=== Integrate Rybbit ===
Contributors: makiit
Tags: rybbit, analytics, statistics, privacy, tracking
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.0
License: GPLv3 or later
License URI: https://github.com/maki-it/rybbit-wordpress-plugin/blob/main/LICENSE

Add the Rybbit Analytics tracking script to WordPress. Exclude roles, mask URLs, identify users and tune session replay privacy.

== Description ==

Integrate Rybbit adds the [Rybbit](https://rybbit.com) tracking script to your WordPress site.
Rybbit is an open source and privacy-focused web analytics platform.

Enter your Site ID and the plugin does the rest. All other settings are optional.

= Features =

* Works with Rybbit Cloud and with self-hosted Rybbit instances.
* Do not track selected user roles. Administrators are excluded by default.
* Optional tracking inside wp-admin. It is off by default.
* Load the script with `defer` (recommended) or `async`.
* Skip patterns: do not track matching URL paths.
* Mask patterns: track matching URL paths, but replace the path with the pattern.
* Patterns support wildcards (`*` and `**`) and regular expressions (`re:` prefix).
* Add a custom tag to all events, for example to compare environments.
* Optional user identification for logged-in users. You choose how much data Rybbit receives:
  * Pseudonymized mode: no cleartext email. Rybbit receives the user ID, roles, username, display name and a hash of the email.
  * Full mode: Rybbit also receives the cleartext email.
  * User ID strategies: WordPress scoped ID, keyed hash (HMAC), user ID, login, email or a user meta field.
* Session replay privacy settings: block, ignore and mask elements, mask inputs, sampling and DOM slimming.
* Debug tab with the effective settings and the generated script attributes.
* Accessible settings page with keyboard support for the tabs.
* Developer filters to change the behavior from code.

= Developer filters =

* `integrate_rybbit_should_track` (bool): Load the tracking script on the current request or not.
* `integrate_rybbit_script_attributes` (array): Change the `data-*` attributes of the script tag. Use it for Rybbit options without a setting, for example `data-namespace`.
* `integrate_rybbit_identify_user_id` (string): Change the user ID that is sent to Rybbit.
* `integrate_rybbit_identify_traits` (array): Change the user traits that are sent to Rybbit.

Example:

`add_filter( 'integrate_rybbit_script_attributes', function ( $attributes ) {
	$attributes['data-namespace'] = 'myRybbit';
	return $attributes;
} );`

== Installation ==

1. In wp-admin, go to Plugins > Add New and search for "Integrate Rybbit". You can also upload the plugin zip file or extract it to `/wp-content/plugins/integrate-rybbit`.
2. Activate the plugin on the Plugins screen.
3. Go to Settings > Integrate Rybbit.
4. Enter your Rybbit Site ID and save the settings. Tracking starts immediately.
5. If you host Rybbit yourself, change the Script URL to `https://your-rybbit-domain/api/script.js`.

== Frequently Asked Questions ==

= How do I get my Site ID? =

Open your Rybbit dashboard. The Site ID is in the tracking settings of your site.

= Can I use a self-hosted Rybbit instance? =

Yes. Set the Script URL on the Tracking tab to the `script.js` URL of your instance, for example `https://analytics.example.com/api/script.js`.

= Why do I not see my own visits? =

Administrators are excluded by default. Change this on the Tracking tab. Ad blockers and browser privacy features can also block the tracking script. The Debug tab shows if your user is excluded by role.

= Does the plugin work with page caching? =

Yes. The script tag is the same for all visitors. User identification runs only for logged-in users, and page cache plugins usually do not cache pages for logged-in users. After a logout, the plugin marks the next page as not cacheable, because this page clears the Rybbit user ID.

= Is user identification compliant with the GDPR? =

User identification is off by default. If you turn it on, Rybbit receives a user ID for logged-in users. It also receives the roles, username and display name of the user. Full mode also sends the cleartext email. Make sure that you have a legal basis for this and update your privacy policy. The pseudonymized mode does not send the cleartext email, but username and display name are still personal data. To send less data, remove traits with the `integrate_rybbit_identify_traits` filter. The HMAC strategy gives a user ID that does not show the WordPress user ID. It uses the security keys of the site. If you change these keys, all users get a new ID in Rybbit.

= What happens to my settings when I delete the plugin? =

The settings stay in the database by default. To remove them, enable "Delete all plugin data on uninstall" on the Maintenance tab before you delete the plugin.

= Does the plugin set cookies? =

The plugin sets one technical cookie (`rybbit_clear_user_id`) when a user logs out. The next page view clears the Rybbit user ID and deletes the cookie. If user identification is active, the plugin also stores a small value in the local storage of the browser. This prevents duplicate identify requests. For the data that the Rybbit script stores in the browser, see the [Rybbit documentation](https://rybbit.com/docs).

== Screenshots ==

1. Tracking tab: Site ID, script URL, excluded roles and script loading.
2. Privacy tab: user identification settings.
3. Script attributes tab: skip patterns, mask patterns and debounce.
4. Session replay tab: privacy settings for session recordings.

== Changelog ==

= 0.0.0 =
* Development version. The release workflow replaces this section with the notes of the GitHub releases.

== External services ==

This plugin integrates with the Rybbit analytics service to provide tracking functionality.

The plugin loads the Rybbit tracking script from the configured Script URL. By default, this is `https://app.rybbit.io/api/script.js`.
The script sends tracking data to the Rybbit instance of the Script URL. This data includes, for example, page views, referrers, screen sizes, browser and device information, and custom events.
If session replay is active in Rybbit, the script also sends recordings of the page. If user identification is active, the plugin sends a user ID and optional user traits.

If you don't host your own Rybbit instance, data collected by this plugin will be sent to the [Rybbit servers](https://app.rybbit.io/) for processing.
See the [Rybbit Data Processing Agreement](https://rybbit.com/dpa) to find out more about how Rybbit handles the tracking data.

By using this plugin, you agree to Rybbit's [terms of service](https://rybbit.com/terms-and-conditions) and [privacy policy](https://rybbit.com/privacy).

== Third-party assets / trademarks ==

This is a free and open-source WordPress plugin developed independently for integration with Rybbit.
The use of the Rybbit name and logo in this plugin has been explicitly approved by the owner of Rybbit.
All Rybbit trademarks, logos, and brand assets remain the property of their respective owner.

This plugin is not an official Rybbit product and is not otherwise affiliated with or endorsed by Rybbit.

== License ==

GPLv3 or later
