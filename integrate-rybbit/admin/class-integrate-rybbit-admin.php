<?php
/**
 * Admin settings page.
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the settings, the settings page and its assets.
 */
final class Integrate_Rybbit_Admin {

	/**
	 * Menu slug of the settings page.
	 */
	const PAGE_SLUG = 'integrate-rybbit';

	/**
	 * Hook suffix of the settings page.
	 */
	const HOOK_SUFFIX = 'settings_page_integrate-rybbit';

	/**
	 * Script handle of the settings page script.
	 */
	const SCRIPT_HANDLE = 'integrate-rybbit-admin-settings';

	/**
	 * Register the admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . INTEGRATE_RYBBIT_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
	}

	/**
	 * Add the page below "Settings".
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Integrate Rybbit', 'integrate-rybbit' ),
			__( 'Integrate Rybbit', 'integrate-rybbit' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin list.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function add_action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'integrate-rybbit' ) )
		);
		return $links;
	}

	/**
	 * Register every option with type, default and sanitizer.
	 */
	public function register_settings() {
		$callbacks = array(
			'rybbit_site_id'                    => array( $this, 'sanitize_site_id' ),
			'rybbit_script_url'                 => array( $this, 'sanitize_script_url' ),
			'rybbit_script_loading'             => array( $this, 'sanitize_script_loading' ),
			'rybbit_track_wp_admin'             => array( 'Integrate_Rybbit_Options', 'sanitize_checkbox' ),
			'rybbit_excluded_roles'             => array( 'Integrate_Rybbit_Options', 'normalize_roles' ),
			'rybbit_skip_patterns'              => array( 'Integrate_Rybbit_Options', 'sanitize_lines' ),
			'rybbit_mask_patterns'              => array( 'Integrate_Rybbit_Options', 'sanitize_lines' ),
			'rybbit_debounce'                   => array( 'Integrate_Rybbit_Options', 'sanitize_debounce' ),
			'rybbit_tag'                        => array( 'Integrate_Rybbit_Options', 'sanitize_tag' ),
			'rybbit_custom_events'              => array( $this, 'sanitize_custom_events' ),
			'rybbit_identify_mode'              => array( $this, 'sanitize_identify_mode' ),
			'rybbit_identify_userid_strategy'   => array( $this, 'sanitize_userid_strategy' ),
			'rybbit_identify_userid_meta_key'   => 'sanitize_key',
			'rybbit_replay_mask_text_selectors' => array( 'Integrate_Rybbit_Options', 'sanitize_lines' ),
			'rybbit_replay_block_class'         => array( $this, 'sanitize_block_class' ),
			'rybbit_replay_block_selector'      => array( 'Integrate_Rybbit_Options', 'sanitize_css_selector' ),
			'rybbit_replay_ignore_class'        => array( $this, 'sanitize_ignore_class' ),
			'rybbit_replay_ignore_selector'     => array( 'Integrate_Rybbit_Options', 'sanitize_css_selector' ),
			'rybbit_replay_mask_text_class'     => array( $this, 'sanitize_mask_text_class' ),
			'rybbit_replay_mask_all_inputs'     => array( 'Integrate_Rybbit_Options', 'sanitize_checkbox' ),
			'rybbit_replay_mask_input_options'  => array( $this, 'sanitize_mask_input_options' ),
			'rybbit_replay_collect_fonts'       => array( 'Integrate_Rybbit_Options', 'sanitize_checkbox' ),
			'rybbit_replay_sampling'            => array( $this, 'sanitize_sampling' ),
			'rybbit_replay_slim_dom_options'    => array( $this, 'sanitize_slim_dom_options' ),
			'rybbit_api_key'                    => array( $this, 'sanitize_api_key' ),
			'rybbit_api_url'                    => array( $this, 'sanitize_api_url' ),
			'rybbit_dashboard_cache'            => array( 'Integrate_Rybbit_Options', 'sanitize_cache_minutes' ),
			'rybbit_dashboard_range'            => array( 'Integrate_Rybbit_Options', 'sanitize_dashboard_range' ),
			'rybbit_delete_data_on_uninstall'   => array( 'Integrate_Rybbit_Options', 'sanitize_checkbox' ),
		);

		foreach ( Integrate_Rybbit_Options::defaults() as $name => $default ) {
			register_setting(
				Integrate_Rybbit_Options::SETTINGS_GROUP,
				$name,
				array(
					'type'              => is_array( $default ) ? 'array' : 'string',
					'default'           => $default,
					'sanitize_callback' => $callbacks[ $name ],
					'show_in_rest'      => false,
				)
			);
		}
	}

	/**
	 * Return the stored value of an option.
	 *
	 * A sanitizer uses this to keep the old value when the new value is invalid.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private function old_value( $name ) {
		return Integrate_Rybbit_Options::get( $name );
	}

	/**
	 * Add a settings error once.
	 *
	 * WordPress runs the sanitizer twice when an option is not stored yet.
	 * This check prevents duplicate messages.
	 *
	 * @param string $setting Option name.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	private function add_error( $setting, $code, $message ) {
		foreach ( get_settings_errors( $setting ) as $error ) {
			if ( isset( $error['code'] ) && $code === $error['code'] ) {
				return;
			}
		}
		add_settings_error( $setting, $code, $message );
	}

	/**
	 * Sanitize the site ID. The field is required.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_site_id( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			$this->add_error(
				'rybbit_site_id',
				'rybbit_site_id_required',
				__( 'Site ID is required.', 'integrate-rybbit' )
			);
			return (string) $this->old_value( 'rybbit_site_id' );
		}
		return $value;
	}

	/**
	 * Sanitize the script URL. The field is required and must use HTTPS.
	 *
	 * HTTP is allowed for localhost to support local Rybbit instances.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_script_url( $value ) {
		$value = trim( (string) $value );
		$old   = (string) $this->old_value( 'rybbit_script_url' );

		if ( '' === $value ) {
			$this->add_error(
				'rybbit_script_url',
				'rybbit_script_url_required',
				__( 'Script URL is required.', 'integrate-rybbit' )
			);
			return $old;
		}

		$url    = esc_url_raw( $value, array( 'https', 'http' ) );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $url || ! is_string( $host ) || '' === $host ) {
			$this->add_error(
				'rybbit_script_url',
				'rybbit_script_url_invalid',
				__( 'Script URL is not a valid URL.', 'integrate-rybbit' )
			);
			return $old;
		}

		$is_local = in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '[::1]' ), true );
		if ( 'https' !== $scheme && ! $is_local ) {
			$this->add_error(
				'rybbit_script_url',
				'rybbit_script_url_https',
				__( 'Script URL must use HTTPS.', 'integrate-rybbit' )
			);
			return $old;
		}

		return $url;
	}

	/**
	 * Sanitize the API key.
	 *
	 * The field never shows the saved key. An empty field keeps the saved key.
	 * The "Remove key" checkbox deletes it. A key from wp-config.php is not
	 * saved here.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_api_key( $value ) {
		$old = (string) get_option( 'rybbit_api_key', '' );

		// WordPress checks the settings nonce in options.php before it calls this method.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['rybbit_api_key_remove'] ) ) {
			return '';
		}

		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return $old;
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-.~+\/=]{8,512}$/', $value ) ) {
			$this->add_error(
				'rybbit_api_key',
				'rybbit_api_key_invalid',
				__( 'The API key has invalid characters or a wrong length. It was not saved.', 'integrate-rybbit' )
			);
			return $old;
		}

		return $value;
	}

	/**
	 * Sanitize the API URL. It must use HTTPS because the API key is sent to it.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_api_url( $value ) {
		$value = trim( is_string( $value ) ? $value : '' );
		if ( '' === $value ) {
			return '';
		}

		$url = untrailingslashit( esc_url_raw( $value, array( 'https', 'http' ) ) );
		if ( '' === $url || ! Integrate_Rybbit_Options::is_allowed_api_url( $url ) ) {
			$this->add_error(
				'rybbit_api_url',
				'rybbit_api_url_invalid',
				__( 'The API URL must be a valid HTTPS URL. HTTP works only for local hosts. It was not saved.', 'integrate-rybbit' )
			);
			return (string) $this->old_value( 'rybbit_api_url' );
		}

		return $url;
	}

	/**
	 * Sanitize the script loading strategy.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_script_loading( $value ) {
		return 'async' === $value ? 'async' : 'defer';
	}

	/**
	 * Sanitize the identify mode.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_identify_mode( $value ) {
		return in_array( $value, Integrate_Rybbit_Options::IDENTIFY_MODES, true ) ? $value : 'disabled';
	}

	/**
	 * Sanitize the custom event rules.
	 *
	 * WordPress passes null when the form has no rule rows. That clears the list.
	 *
	 * @param mixed $value Raw rules from the form.
	 * @return array<int, array<string, mixed>>
	 */
	public function sanitize_custom_events( $value ) {
		$rejected = 0;
		$rules    = Integrate_Rybbit_Options::normalize_custom_events( $value, $rejected );

		if ( $rejected > 0 ) {
			$this->add_error(
				'rybbit_custom_events',
				'rybbit_custom_events_rejected',
				sprintf(
					/* translators: 1: number of dropped rules, 2: maximum number of rules */
					_n(
						'%1$d custom event rule was not saved. A rule needs a valid selector, an event name and properties as "key=value" lines. You can save at most %2$d rules.',
						'%1$d custom event rules were not saved. A rule needs a valid selector, an event name and properties as "key=value" lines. You can save at most %2$d rules.',
						$rejected,
						'integrate-rybbit'
					),
					$rejected,
					Integrate_Rybbit_Options::CUSTOM_EVENTS_MAX
				)
			);
		}

		return $rules;
	}

	/**
	 * Sanitize the user ID strategy.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_userid_strategy( $value ) {
		return in_array( $value, Integrate_Rybbit_Options::USERID_STRATEGIES, true ) ? $value : 'wp_scoped';
	}

	/**
	 * Sanitize the replay block class.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_block_class( $value ) {
		return Integrate_Rybbit_Options::sanitize_css_class( $value, 'rr-block' );
	}

	/**
	 * Sanitize the replay ignore class.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_ignore_class( $value ) {
		return Integrate_Rybbit_Options::sanitize_css_class( $value, 'rr-ignore' );
	}

	/**
	 * Sanitize the replay mask text class.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_mask_text_class( $value ) {
		return Integrate_Rybbit_Options::sanitize_css_class( $value, 'rr-mask' );
	}

	/**
	 * Sanitize the mask input options JSON.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_mask_input_options( $value ) {
		return $this->sanitize_json_option( 'rybbit_replay_mask_input_options', $value, false );
	}

	/**
	 * Sanitize the sampling JSON.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_sampling( $value ) {
		return $this->sanitize_json_option( 'rybbit_replay_sampling', $value, false );
	}

	/**
	 * Sanitize the slimDOM options. Accepts a JSON object, "true" or "false".
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_slim_dom_options( $value ) {
		return $this->sanitize_json_option( 'rybbit_replay_slim_dom_options', $value, true );
	}

	/**
	 * Validate a JSON option. Keep the old value if the JSON is invalid.
	 *
	 * @param string $name          Option name.
	 * @param mixed  $value         Raw value.
	 * @param bool   $allow_boolean Accept "true" and "false".
	 * @return string
	 */
	private function sanitize_json_option( $name, $value, $allow_boolean ) {
		$normalized = Integrate_Rybbit_Options::normalize_json_object( $value, $allow_boolean );
		if ( null === $normalized ) {
			$this->add_error(
				$name,
				$name . '_invalid_json',
				$allow_boolean
					? __( 'Invalid slimDOM options. Use true, false or a JSON object.', 'integrate-rybbit' )
					: __( 'Invalid JSON. Please provide a JSON object (example: {"password":true,"email":true}).', 'integrate-rybbit' )
			);
			return (string) $this->old_value( $name );
		}
		return $normalized;
	}

	/**
	 * Load the styles and script of the settings page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'integrate-rybbit-admin-settings',
			INTEGRATE_RYBBIT_URL . 'admin/css/settings.css',
			array(),
			INTEGRATE_RYBBIT_VERSION
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			INTEGRATE_RYBBIT_URL . 'admin/js/settings.js',
			array( 'wp-i18n' ),
			INTEGRATE_RYBBIT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'integrate-rybbit' );
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'rybbitAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rybbit_admin_settings' ),
			)
		);
	}

	/**
	 * Tabs of the settings page. Key is the tab slug, value is the label.
	 *
	 * @return array<string, string>
	 */
	private function get_tabs() {
		return array(
			'tracking'    => __( 'Tracking', 'integrate-rybbit' ),
			'privacy'     => __( 'Privacy', 'integrate-rybbit' ),
			'script'      => __( 'Script Attributes', 'integrate-rybbit' ),
			'events'      => __( 'Custom Events', 'integrate-rybbit' ),
			'replay'      => __( 'Session Replay', 'integrate-rybbit' ),
			'dashboard'   => __( 'Dashboard', 'integrate-rybbit' ),
			'maintenance' => __( 'Maintenance', 'integrate-rybbit' ),
			'debug'       => __( 'Debug', 'integrate-rybbit' ),
			'about'       => __( 'About', 'integrate-rybbit' ),
		);
	}

	/**
	 * Value of a JSON option for display in a textarea.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private function json_for_display( $name ) {
		$value = trim( (string) Integrate_Rybbit_Options::get( $name ) );
		if ( '' === $value || 'true' === $value || 'false' === $value ) {
			return $value;
		}
		$decoded = json_decode( $value );
		return is_object( $decoded ) ? Integrate_Rybbit_Options::encode_json( $decoded, true ) : $value;
	}

	/**
	 * Print a "Reset to defaults" button for a JSON textarea.
	 *
	 * @param string $name Option name. Also the textarea ID.
	 */
	private function render_reset_button( $name ) {
		$defaults = Integrate_Rybbit_Options::replay_json_defaults();
		$pretty   = Integrate_Rybbit_Options::encode_json( $defaults[ $name ], true );
		printf(
			'<button type="button" class="button rybbit-reset-json" data-rybbit-reset-target="%1$s" data-rybbit-reset-value-b64="%2$s">%3$s</button>',
			esc_attr( '#' . $name ),
			// Base64 keeps quotes and line breaks of the JSON intact in the attribute.
			esc_attr( base64_encode( $pretty ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			esc_html__( 'Reset to defaults', 'integrate-rybbit' )
		);
	}

	/**
	 * Print a checkbox field.
	 *
	 * @param string $name  Option name.
	 * @param string $label Checkbox label.
	 */
	private function render_checkbox( $name, $label ) {
		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( '1', Integrate_Rybbit_Options::get( $name ), false ),
			esc_html( $label )
		);
	}

	/**
	 * Print a text input field.
	 *
	 * @param string $name        Option name.
	 * @param array  $args        Optional. Keys: type, class, placeholder, required, maxlength, describedby.
	 */
	private function render_input( $name, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'        => 'text',
				'class'       => 'regular-text',
				'placeholder' => '',
				'required'    => false,
				'maxlength'   => 0,
				'min'         => null,
				'describedby' => '',
			)
		);

		$extra = '';
		if ( '' !== $args['placeholder'] ) {
			$extra .= ' placeholder="' . esc_attr( $args['placeholder'] ) . '"';
		}
		if ( $args['required'] ) {
			$extra .= ' required aria-required="true"';
		}
		if ( $args['maxlength'] > 0 ) {
			$extra .= ' maxlength="' . esc_attr( (string) $args['maxlength'] ) . '"';
		}
		if ( null !== $args['min'] ) {
			$extra .= ' min="' . esc_attr( (string) $args['min'] ) . '"';
		}
		if ( '' !== $args['describedby'] ) {
			$extra .= ' aria-describedby="' . esc_attr( $args['describedby'] ) . '"';
		}

		printf(
			'<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="%4$s"%5$s />',
			esc_attr( $args['type'] ),
			esc_attr( $name ),
			esc_attr( (string) Integrate_Rybbit_Options::get( $name ) ),
			esc_attr( $args['class'] ),
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped above.
		);
	}

	/**
	 * Print a textarea field.
	 *
	 * @param string $name        Option name.
	 * @param string $value       Value to show.
	 * @param int    $rows        Number of rows.
	 * @param string $describedby Optional ID of the description element.
	 * @param string $placeholder Optional placeholder.
	 */
	private function render_textarea( $name, $value, $rows, $describedby = '', $placeholder = '' ) {
		printf(
			'<textarea id="%1$s" name="%1$s" rows="%2$d" class="large-text code"%3$s%4$s>%5$s</textarea>',
			esc_attr( $name ),
			(int) $rows,
			'' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '',
			'' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '',
			esc_textarea( $value )
		);
	}

	/**
	 * Print the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs  = $this->get_tabs();
		$first = (string) array_key_first( $tabs );
		?>
		<div class="wrap">
			<div class="rybbit-header">
				<span class="rybbit-icon" aria-hidden="true"><span class="rybbit-icon-image"></span></span>
				<div>
					<h1><?php esc_html_e( 'Integrate Rybbit', 'integrate-rybbit' ); ?></h1>
					<p class="rybbit-subtitle"><?php esc_html_e( 'Configure tracking and privacy settings for the Rybbit tracking script.', 'integrate-rybbit' ); ?></p>
				</div>
			</div>

			<div class="nav-tab-wrapper" role="tablist" aria-label="<?php esc_attr_e( 'Integrate Rybbit settings', 'integrate-rybbit' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<?php $is_first = ( $first === $slug ); ?>
					<a href="#<?php echo esc_attr( $slug ); ?>"
						id="rybbit-tab-<?php echo esc_attr( $slug ); ?>"
						class="nav-tab rybbit-nav-tab<?php echo $is_first ? ' nav-tab-active' : ''; ?>"
						role="tab"
						aria-selected="<?php echo $is_first ? 'true' : 'false'; ?>"
						aria-controls="rybbit-panel-<?php echo esc_attr( $slug ); ?>"
						tabindex="<?php echo $is_first ? '0' : '-1'; ?>"
						data-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>

			<div class="rybbit-settings-card">
				<form method="post" action="options.php">
					<?php settings_fields( Integrate_Rybbit_Options::SETTINGS_GROUP ); ?>

					<?php
					foreach ( array_keys( $tabs ) as $slug ) {
						printf(
							'<div class="rybbit-tab-panel" id="rybbit-panel-%1$s" data-tab="%1$s" role="tabpanel" aria-labelledby="rybbit-tab-%1$s" tabindex="0"%2$s>',
							esc_attr( $slug ),
							$first === $slug ? '' : ' hidden'
						);
						$this->{'render_tab_' . $slug}();
						echo '</div>';
					}

					submit_button();
					?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Print the "Tracking" tab.
	 */
	private function render_tab_tracking() {
		$excluded = Integrate_Rybbit_Options::get_excluded_roles();
		$loading  = Integrate_Rybbit_Options::get_script_strategy();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="rybbit_site_id"><?php esc_html_e( 'Site ID', 'integrate-rybbit' ); ?> <span class="required" aria-hidden="true">*</span></label>
				</th>
				<td>
					<?php
					$this->render_input(
						'rybbit_site_id',
						array(
							'class'       => 'regular-text rybbit-input-wide',
							'required'    => true,
							'describedby' => 'rybbit_site_id_description',
						)
					);
					?>
					<p class="description" id="rybbit_site_id_description"><?php esc_html_e( "Find this in your Rybbit dashboard under your site's tracking settings.", 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="rybbit_script_url"><?php esc_html_e( 'Script URL', 'integrate-rybbit' ); ?> <span class="required" aria-hidden="true">*</span></label>
				</th>
				<td>
					<?php
					$this->render_input(
						'rybbit_script_url',
						array(
							'type'        => 'url',
							'class'       => 'regular-text rybbit-input-url',
							'required'    => true,
							'describedby' => 'rybbit_script_url_description',
						)
					);
					?>
					<p class="description" id="rybbit_script_url_description">
						<?php
						printf(
							/* translators: %s: Example script URL. */
							esc_html__( 'Example: %s', 'integrate-rybbit' ),
							'<code>' . esc_html( Integrate_Rybbit_Options::DEFAULT_SCRIPT_URL ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_excluded_roles"><?php esc_html_e( 'Do not track these roles', 'integrate-rybbit' ); ?></label></th>
				<td>
					<select id="rybbit_excluded_roles" name="rybbit_excluded_roles[]" multiple size="8" class="rybbit-input-wide" aria-describedby="rybbit_excluded_roles_description">
						<?php foreach ( wp_roles()->get_names() as $role_slug => $role_name ) : ?>
							<option value="<?php echo esc_attr( $role_slug ); ?>" <?php selected( in_array( $role_slug, $excluded, true ) ); ?>><?php echo esc_html( translate_user_role( $role_name ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description" id="rybbit_excluded_roles_description"><?php esc_html_e( 'Selected roles will not receive the tracking script (frontend and wp-admin).', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_script_loading"><?php esc_html_e( 'Script loading', 'integrate-rybbit' ); ?></label></th>
				<td>
					<select id="rybbit_script_loading" name="rybbit_script_loading" class="rybbit-select-wide" aria-describedby="rybbit_script_loading_description">
						<option value="defer" <?php selected( $loading, 'defer' ); ?>><?php esc_html_e( 'Defer (recommended)', 'integrate-rybbit' ); ?></option>
						<option value="async" <?php selected( $loading, 'async' ); ?>><?php esc_html_e( 'Async', 'integrate-rybbit' ); ?></option>
					</select>
					<p class="description" id="rybbit_script_loading_description">
						<?php
						printf(
							/* translators: 1: Code word defer. 2: Code word async. */
							esc_html__( 'Controls whether the tracking script tag uses %1$s (default) or %2$s.', 'integrate-rybbit' ),
							'<code>defer</code>',
							'<code>async</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'wp-admin', 'integrate-rybbit' ); ?></th>
				<td>
					<?php $this->render_checkbox( 'rybbit_track_wp_admin', __( 'Also load the tracking script in wp-admin', 'integrate-rybbit' ) ); ?>
					<p class="description"><?php esc_html_e( 'Off by default. Most sites only need frontend tracking. Excluded roles are never tracked.', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Print the "Privacy" tab.
	 */
	private function render_tab_privacy() {
		$mode     = (string) Integrate_Rybbit_Options::get( 'rybbit_identify_mode' );
		$strategy = (string) Integrate_Rybbit_Options::get( 'rybbit_identify_userid_strategy' );

		$strategies = array(
			'wp_scoped'  => __( 'WordPress scoped (default)', 'integrate-rybbit' ),
			'hmac'       => __( 'Keyed hash (HMAC, hides the WordPress user ID)', 'integrate-rybbit' ),
			'wp_user_id' => __( 'WordPress user ID (numeric)', 'integrate-rybbit' ),
			'user_login' => __( 'Username (user_login)', 'integrate-rybbit' ),
			'email'      => __( 'Email address', 'integrate-rybbit' ),
			'user_meta'  => __( 'Custom user meta value', 'integrate-rybbit' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="rybbit_identify_mode"><?php esc_html_e( 'Identify logged-in users', 'integrate-rybbit' ); ?></label></th>
				<td>
					<select id="rybbit_identify_mode" name="rybbit_identify_mode" class="rybbit-select-wide" aria-describedby="rybbit_identify_mode_description">
						<option value="disabled" <?php selected( $mode, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'integrate-rybbit' ); ?></option>
						<option value="pseudonymized" <?php selected( $mode, 'pseudonymized' ); ?>><?php esc_html_e( 'Pseudonymized (hashed)', 'integrate-rybbit' ); ?></option>
						<option value="full" <?php selected( $mode, 'full' ); ?>><?php esc_html_e( 'Full (cleartext email)', 'integrate-rybbit' ); ?></option>
					</select>
					<p class="description rybbit-description-wide" id="rybbit_identify_mode_description">
						<strong><?php esc_html_e( 'GDPR/Privacy:', 'integrate-rybbit' ); ?></strong>
						<?php esc_html_e( 'Identifying users is personal data processing. Only enable this if you have a lawful basis (e.g. consent) and have updated your privacy policy and consent banner accordingly.', 'integrate-rybbit' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_identify_userid_strategy"><?php esc_html_e( 'User ID used for identification', 'integrate-rybbit' ); ?></label></th>
				<td>
					<select id="rybbit_identify_userid_strategy" name="rybbit_identify_userid_strategy" class="rybbit-select-wide" aria-describedby="rybbit_identify_userid_strategy_description">
						<?php foreach ( $strategies as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $strategy, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<div class="description rybbit-description-wide" id="rybbit_identify_userid_strategy_description">
						<p>
							<?php
							printf(
								/* translators: %s: "userId". */
								esc_html__( 'Controls what is sent as the Rybbit %s when identifying logged-in users.', 'integrate-rybbit' ),
								'<code>userId</code>'
							);
							?>
						</p>
						<p>
							<?php
							printf(
								/* translators: 1: strategy name, 2: example userId. */
								esc_html__( '%1$s sends the site ID and the user ID, for example %2$s. The value stays the same for each user. It shows the WordPress user ID.', 'integrate-rybbit' ),
								'<strong>' . esc_html__( 'WordPress scoped', 'integrate-rybbit' ) . '</strong>',
								'<code>wp:1:42</code>'
							);
							?>
						</p>
						<p>
							<?php
							printf(
								/* translators: 1: strategy name, 2: example userId. */
								esc_html__( '%1$s sends a hash of the user ID, for example %2$s. It does not show the WordPress user ID. The hash uses the security keys of this site. If you change these keys, all users get a new ID in Rybbit.', 'integrate-rybbit' ),
								'<strong>' . esc_html__( 'Keyed hash', 'integrate-rybbit' ) . '</strong>',
								'<code>hmac:9f2c&hellip;</code>'
							);
							?>
						</p>
						<p><?php esc_html_e( 'Note: "Username" and "Custom user meta value" send the value in cleartext, also in pseudonymized mode.', 'integrate-rybbit' ); ?></p>
					</div>

					<div class="rybbit-meta-key-wrap" data-rybbit-user-meta-key<?php echo 'user_meta' === $strategy ? '' : ' hidden'; ?>>
						<label for="rybbit_identify_userid_meta_key"><strong><?php esc_html_e( 'User meta key', 'integrate-rybbit' ); ?></strong></label><br />
						<?php
						$this->render_input(
							'rybbit_identify_userid_meta_key',
							array(
								'class'       => 'regular-text rybbit-input-wide',
								'placeholder' => __( 'e.g. customer_id', 'integrate-rybbit' ),
								'describedby' => 'rybbit_identify_userid_meta_key_description',
							)
						);
						?>
						<p class="description" id="rybbit_identify_userid_meta_key_description"><?php esc_html_e( 'The value of this user meta field will be used as the Rybbit userId.', 'integrate-rybbit' ); ?></p>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Identify payload preview', 'integrate-rybbit' ); ?></th>
				<td>
					<pre id="rybbit_identify_payload" class="rybbit-identify-payload" aria-live="polite"></pre>
					<p class="description">
						<?php esc_html_e( 'Preview of the identify payload that would be sent for the current logged-in user.', 'integrate-rybbit' ); ?>
						<a href="#" class="rybbit-refresh-payload" role="button"><?php esc_html_e( 'Refresh preview', 'integrate-rybbit' ); ?></a>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Visitor opt-out', 'integrate-rybbit' ); ?></th>
				<td>
					<p>
						<?php
						printf(
							/* translators: 1: Shortcode, 2: Block name. */
							esc_html__( 'Let visitors turn the tracking off. Add the shortcode %1$s or the block %2$s to a page, for example your privacy policy.', 'integrate-rybbit' ),
							'<code>[' . esc_html( Integrate_Rybbit_Optout::SHORTCODE ) . ']</code>',
							'<strong>' . esc_html__( 'Rybbit Opt-out', 'integrate-rybbit' ) . '</strong>'
						);
						?>
					</p>
					<p class="description rybbit-description-wide">
						<?php
						printf(
							/* translators: %s: Name of the storage entry, for example "disable-rybbit". */
							esc_html__( 'The choice is saved in the browser of the visitor under %s. It applies to this website and this browser only.', 'integrate-rybbit' ),
							'<code>disable-' . esc_html( Integrate_Rybbit_Optout::get_namespace() ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Option value as newline separated text for a textarea.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private function lines_for_display( $name ) {
		return implode( "\n", Integrate_Rybbit_Options::lines_to_list( Integrate_Rybbit_Options::get( $name ) ) );
	}

	/**
	 * Print the "Script Attributes" tab.
	 */
	private function render_tab_script() {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="rybbit_skip_patterns"><?php esc_html_e( 'Skip patterns', 'integrate-rybbit' ); ?></label></th>
				<td>
					<?php $this->render_textarea( 'rybbit_skip_patterns', $this->lines_for_display( 'rybbit_skip_patterns' ), 8, 'rybbit_skip_patterns_description' ); ?>
					<div class="description rybbit-description-wide" id="rybbit_skip_patterns_description">
						<p><?php esc_html_e( 'One pattern per line. Matching URL paths will not be tracked.', 'integrate-rybbit' ); ?></p>
						<p>
							<strong><?php esc_html_e( 'Wildcards:', 'integrate-rybbit' ); ?></strong>
							<?php
							printf(
								/* translators: 1: Single asterisk wildcard. 2: Slash character. 3: Double asterisk wildcard. */
								esc_html__( '%1$s matches within one path segment (does not cross %2$s). %3$s matches across multiple segments (can include %2$s).', 'integrate-rybbit' ),
								'<code>*</code>',
								'<code>/</code>',
								'<code>**</code>'
							);
							?>
						</p>
						<p>
							<strong><?php esc_html_e( 'Regular expressions:', 'integrate-rybbit' ); ?></strong>
							<?php
							printf(
								/* translators: 1: "re:", 2: Example pattern. */
								esc_html__( 'Start a line with %1$s to use a regular expression, for example %2$s.', 'integrate-rybbit' ),
								'<code>re:</code>',
								'<code>re:^/product/\d+$</code>'
							);
							?>
						</p>
						<p><strong><?php esc_html_e( 'Examples:', 'integrate-rybbit' ); ?></strong></p>
						<ul class="rybbit-list">
							<?php
							$examples = array(
								/* translators: 1: Pattern, 2: Matching path, 3: Path that does not match. */
								array( __( '%1$s matches %2$s but not %3$s', 'integrate-rybbit' ), '/admin/*', '/admin/dashboard', '/admin/users/list' ),
								/* translators: 1: Pattern, 2: First matching path, 3: Second matching path. */
								array( __( '%1$s matches %2$s and %3$s', 'integrate-rybbit' ), '/admin/**', '/admin/dashboard', '/admin/users/list' ),
								/* translators: 1: Pattern, 2: Matching path, 3: Path that does not match. */
								array( __( '%1$s matches %2$s but not %3$s', 'integrate-rybbit' ), '/blog/*/comments', '/blog/post-123/comments', '/blog/category/post/comments' ),
							);
							foreach ( $examples as $example ) {
								printf(
									'<li>%s</li>',
									sprintf(
										esc_html( $example[0] ),
										'<code>' . esc_html( $example[1] ) . '</code>',
										'<code>' . esc_html( $example[2] ) . '</code>',
										'<code>' . esc_html( $example[3] ) . '</code>'
									)
								);
							}
							?>
						</ul>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_mask_patterns"><?php esc_html_e( 'Mask patterns', 'integrate-rybbit' ); ?></label></th>
				<td>
					<?php $this->render_textarea( 'rybbit_mask_patterns', $this->lines_for_display( 'rybbit_mask_patterns' ), 8, 'rybbit_mask_patterns_description' ); ?>
					<div class="description rybbit-description-wide" id="rybbit_mask_patterns_description">
						<p><?php esc_html_e( 'One pattern per line. Matching URL paths are tracked, but the recorded path is replaced with the pattern (privacy masking).', 'integrate-rybbit' ); ?></p>
						<p>
							<strong><?php esc_html_e( 'Example:', 'integrate-rybbit' ); ?></strong>
							<?php
							printf(
								/* translators: 1: Pattern, 2: Visited path. */
								esc_html__( 'If you set %1$s and a user visits %2$s, analytics stores %1$s instead of the real URL.', 'integrate-rybbit' ),
								'<code>/account/**</code>',
								'<code>/account/orders/123</code>'
							);
							?>
						</p>
						<p><?php esc_html_e( 'Wildcards and regular expressions work the same as in skip patterns.', 'integrate-rybbit' ); ?></p>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_debounce"><?php esc_html_e( 'Debounce (ms)', 'integrate-rybbit' ); ?></label></th>
				<td>
					<?php
					$this->render_input(
						'rybbit_debounce',
						array(
							'type'        => 'number',
							'class'       => 'small-text',
							'min'         => 0,
							'describedby' => 'rybbit_debounce_description',
						)
					);
					?>
					<p class="description" id="rybbit_debounce_description"><?php esc_html_e( 'Delay before tracking a pageview after History API URL changes. Set to 0 to disable.', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_tag"><?php esc_html_e( 'Tag', 'integrate-rybbit' ); ?></label></th>
				<td>
					<?php
					$this->render_input(
						'rybbit_tag',
						array(
							'class'       => 'regular-text rybbit-input-wide',
							'maxlength'   => Integrate_Rybbit_Options::TAG_MAX_LENGTH,
							'placeholder' => __( 'e.g. production', 'integrate-rybbit' ),
							'describedby' => 'rybbit_tag_description',
						)
					);
					?>
					<p class="description" id="rybbit_tag_description"><?php esc_html_e( 'Optional. Rybbit adds this tag to every event of this site. Use it to separate environments or site versions. Maximum 256 characters.', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="description rybbit-description-wide">
			<?php esc_html_e( 'Outbound link tracking, error tracking, Web Vitals, session replay and hash routing are turned on or off per site in the Rybbit dashboard.', 'integrate-rybbit' ); ?>
		</p>
		<?php
	}

	/**
	 * Print the "Custom Events" tab.
	 */
	private function render_tab_events() {
		$rules = Integrate_Rybbit_Options::normalize_custom_events( Integrate_Rybbit_Options::get( 'rybbit_custom_events' ) );
		?>
		<p class="description rybbit-description-wide">
			<?php
			printf(
				/* translators: %s: Rybbit function name. */
				esc_html__( 'Send a custom event to Rybbit when a visitor clicks an element. The plugin calls %s for you. You do not need to write JavaScript.', 'integrate-rybbit' ),
				'<code>rybbit.event()</code>'
			);
			?>
		</p>
		<div class="rybbit-event-rules" data-rybbit-event-rules data-next-index="<?php echo esc_attr( (string) count( $rules ) ); ?>" data-max="<?php echo esc_attr( (string) Integrate_Rybbit_Options::CUSTOM_EVENTS_MAX ); ?>">
			<p class="description" data-rybbit-event-empty<?php echo empty( $rules ) ? '' : ' hidden'; ?>><?php esc_html_e( 'No event rules yet.', 'integrate-rybbit' ); ?></p>
			<div class="rybbit-event-rule-list" data-rybbit-event-list>
				<?php
				foreach ( $rules as $index => $rule ) {
					$this->render_event_rule( (string) $index, $rule );
				}
				?>
			</div>
			<p>
				<button type="button" class="button" data-rybbit-event-add><?php esc_html_e( 'Add event rule', 'integrate-rybbit' ); ?></button>
			</p>
			<template data-rybbit-event-template>
				<?php $this->render_event_rule( '__INDEX__', array() ); ?>
			</template>
		</div>
		<div class="description rybbit-description-wide">
			<p>
				<?php
				printf(
					/* translators: %d: Maximum number of rules. */
					esc_html__( 'You can add up to %d rules. A rule also matches when the visitor clicks inside a matching element, for example on an icon in a button. Visitors with an excluded role do not send events.', 'integrate-rybbit' ),
					(int) Integrate_Rybbit_Options::CUSTOM_EVENTS_MAX
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: HTML attribute name, 2: HTML attribute name. */
					esc_html__( 'You can also mark elements in your HTML with the %1$s and %2$s attributes. Rybbit reads them without this plugin.', 'integrate-rybbit' ),
					'<code>data-rybbit-event</code>',
					'<code>data-rybbit-prop-*</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Print one custom event rule.
	 *
	 * The template for new rules uses the same markup with the placeholder index.
	 *
	 * @param string               $index Row index. "__INDEX__" for the template.
	 * @param array<string, mixed> $rule  Stored rule.
	 */
	private function render_event_rule( $index, array $rule ) {
		$rule = wp_parse_args(
			$rule,
			array(
				'selector' => '',
				'event'    => '',
				'props'    => array(),
			)
		);

		$base = 'rybbit_custom_events[' . $index . ']';
		$id   = 'rybbit_custom_events_' . $index;
		?>
		<div class="rybbit-event-rule" data-rybbit-event-rule>
			<div class="rybbit-event-rule-fields">
				<p>
					<label for="<?php echo esc_attr( $id . '_selector' ); ?>"><strong><?php esc_html_e( 'CSS selector', 'integrate-rybbit' ); ?></strong></label><br />
					<input type="text" class="regular-text code rybbit-input-wide" id="<?php echo esc_attr( $id . '_selector' ); ?>" name="<?php echo esc_attr( $base . '[selector]' ); ?>" value="<?php echo esc_attr( (string) $rule['selector'] ); ?>" maxlength="<?php echo esc_attr( (string) Integrate_Rybbit_Options::EVENT_SELECTOR_MAX_LENGTH ); ?>" placeholder="<?php echo esc_attr( '.buy-button, #signup-form button' ); ?>" data-rybbit-event-selector />
				</p>
				<p>
					<label for="<?php echo esc_attr( $id . '_event' ); ?>"><strong><?php esc_html_e( 'Event name', 'integrate-rybbit' ); ?></strong></label><br />
					<input type="text" class="regular-text rybbit-input-wide" id="<?php echo esc_attr( $id . '_event' ); ?>" name="<?php echo esc_attr( $base . '[event]' ); ?>" value="<?php echo esc_attr( (string) $rule['event'] ); ?>" maxlength="<?php echo esc_attr( (string) Integrate_Rybbit_Options::EVENT_NAME_MAX_LENGTH ); ?>" placeholder="<?php echo esc_attr( 'buy_click' ); ?>" data-rybbit-event-name />
				</p>
				<p>
					<label for="<?php echo esc_attr( $id . '_props' ); ?>"><strong><?php esc_html_e( 'Properties (optional)', 'integrate-rybbit' ); ?></strong></label><br />
					<textarea class="large-text code" rows="3" id="<?php echo esc_attr( $id . '_props' ); ?>" name="<?php echo esc_attr( $base . '[props]' ); ?>" placeholder="<?php echo esc_attr( "plan=pro\nsource=header" ); ?>" aria-describedby="<?php echo esc_attr( $id . '_props_description' ); ?>" data-rybbit-event-props><?php echo esc_textarea( Integrate_Rybbit_Options::format_event_props( $rule['props'] ) ); ?></textarea>
					<span class="description" id="<?php echo esc_attr( $id . '_props_description' ); ?>"><?php esc_html_e( 'One key=value pair per line. Values are sent as text. Maximum 2 KB in total.', 'integrate-rybbit' ); ?></span>
				</p>
			</div>
			<button type="button" class="button-link button-link-delete rybbit-event-remove" data-rybbit-event-remove><?php esc_html_e( 'Remove rule', 'integrate-rybbit' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Print the "Session Replay" tab.
	 */
	private function render_tab_replay() {
		?>
		<p class="description rybbit-description-wide"><?php esc_html_e( 'These options only apply when session replay is turned on for this site in the Rybbit dashboard.', 'integrate-rybbit' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="rybbit_replay_mask_text_selectors"><?php esc_html_e( 'Mask text selectors', 'integrate-rybbit' ); ?></label></th>
				<td>
					<?php $this->render_textarea( 'rybbit_replay_mask_text_selectors', $this->lines_for_display( 'rybbit_replay_mask_text_selectors' ), 6, 'rybbit_replay_mask_text_selectors_description' ); ?>
					<p class="description rybbit-description-wide" id="rybbit_replay_mask_text_selectors_description">
						<?php
						printf(
							/* translators: 1: Example selector, 2: Example selector. */
							esc_html__( 'One CSS selector per line. Text content of matching elements is masked in session replays. Example: %1$s or %2$s.', 'integrate-rybbit' ),
							'<code>.user-name</code>',
							'<code>#email</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<?php
			/* translators: %s: Default CSS class. */
			$this->render_class_row( 'rybbit_replay_block_class', __( 'Block class', 'integrate-rybbit' ), __( 'Elements with this CSS class are not recorded at all. Default: %s', 'integrate-rybbit' ) );
			$this->render_selector_row( 'rybbit_replay_block_selector', __( 'Block selector', 'integrate-rybbit' ), __( 'CSS selector for elements to exclude entirely from recording.', 'integrate-rybbit' ), '.sensitive-content, #payment-modal' );
			/* translators: %s: Default CSS class. */
			$this->render_class_row( 'rybbit_replay_ignore_class', __( 'Ignore class', 'integrate-rybbit' ), __( 'Elements with this CSS class appear in the replay, but their interactions are not recorded. Default: %s', 'integrate-rybbit' ) );
			$this->render_selector_row( 'rybbit_replay_ignore_selector', __( 'Ignore selector', 'integrate-rybbit' ), __( 'CSS selector for inputs and elements whose events are ignored.', 'integrate-rybbit' ), "input[name='credit-card']" );
			/* translators: %s: Default CSS class. */
			$this->render_class_row( 'rybbit_replay_mask_text_class', __( 'Mask text class', 'integrate-rybbit' ), __( 'Elements with this CSS class have their text masked. Default: %s', 'integrate-rybbit' ) );
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mask all inputs', 'integrate-rybbit' ); ?></th>
				<td>
					<?php $this->render_checkbox( 'rybbit_replay_mask_all_inputs', __( 'Mask all input values for privacy', 'integrate-rybbit' ) ); ?>
					<p class="description"><?php esc_html_e( 'When enabled, session replay shows input values as asterisks.', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_replay_mask_input_options"><?php esc_html_e( 'Mask input options (JSON)', 'integrate-rybbit' ); ?></label></th>
				<td>
					<div class="rybbit-button-row"><?php $this->render_reset_button( 'rybbit_replay_mask_input_options' ); ?></div>
					<?php $this->render_textarea( 'rybbit_replay_mask_input_options', $this->json_for_display( 'rybbit_replay_mask_input_options' ), 5, 'rybbit_replay_mask_input_options_description' ); ?>
					<p class="description rybbit-description-wide" id="rybbit_replay_mask_input_options_description">
						<?php
						printf(
							/* translators: %s: Example JSON. */
							esc_html__( 'JSON object that controls which input types are masked. Example: %s', 'integrate-rybbit' ),
							'<code>{"password":true,"email":true,"tel":true}</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Collect fonts', 'integrate-rybbit' ); ?></th>
				<td>
					<?php $this->render_checkbox( 'rybbit_replay_collect_fonts', __( 'Collect website fonts for accurate replays', 'integrate-rybbit' ) ); ?>
					<p class="description"><?php esc_html_e( 'Disable to reduce replay data size.', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_replay_sampling"><?php esc_html_e( 'Sampling (JSON)', 'integrate-rybbit' ); ?></label></th>
				<td>
					<div class="rybbit-button-row"><?php $this->render_reset_button( 'rybbit_replay_sampling' ); ?></div>
					<?php $this->render_textarea( 'rybbit_replay_sampling', $this->json_for_display( 'rybbit_replay_sampling' ), 9, 'rybbit_replay_sampling_description' ); ?>
					<p class="description rybbit-description-wide" id="rybbit_replay_sampling_description">
						<?php esc_html_e( 'Optional JSON object to reduce replay volume.', 'integrate-rybbit' ); ?>
						<a href="https://rybbit.com/docs/script#sampling-configuration" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Sampling configuration docs', 'integrate-rybbit' ); ?></a>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_replay_slim_dom_options"><?php esc_html_e( 'SlimDOM options', 'integrate-rybbit' ); ?></label></th>
				<td>
					<div class="rybbit-button-row"><?php $this->render_reset_button( 'rybbit_replay_slim_dom_options' ); ?></div>
					<?php $this->render_textarea( 'rybbit_replay_slim_dom_options', $this->json_for_display( 'rybbit_replay_slim_dom_options' ), 9, 'rybbit_replay_slim_dom_options_description' ); ?>
					<p class="description rybbit-description-wide" id="rybbit_replay_slim_dom_options_description">
						<?php
						printf(
							/* translators: 1: Code word true. 2: Code word false. */
							esc_html__( 'Optional DOM slimming for replays. Set %1$s to enable all slimDOM options, %2$s to disable them, or use a JSON object for fine-grained control.', 'integrate-rybbit' ),
							'<code>true</code>',
							'<code>false</code>'
						);
						?>
						<a href="https://rybbit.com/docs/script#slimdom-options" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'SlimDOM options docs', 'integrate-rybbit' ); ?></a>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Print a table row with a CSS class input.
	 *
	 * @param string $name        Option name.
	 * @param string $label       Field label.
	 * @param string $description Description with "%s" for the default class.
	 */
	private function render_class_row( $name, $label, $description ) {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php $this->render_input( $name, array( 'describedby' => $name . '_description' ) ); ?>
				<p class="description" id="<?php echo esc_attr( $name . '_description' ); ?>">
					<?php printf( esc_html( $description ), '<code>' . esc_html( (string) Integrate_Rybbit_Options::default_value( $name ) ) . '</code>' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Print a table row with a CSS selector input.
	 *
	 * @param string $name        Option name.
	 * @param string $label       Field label.
	 * @param string $description Field description.
	 * @param string $placeholder Example selector.
	 */
	private function render_selector_row( $name, $label, $description, $placeholder ) {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php
				$this->render_input(
					$name,
					array(
						'class'       => 'regular-text rybbit-input-wide',
						'placeholder' => $placeholder,
						'describedby' => $name . '_description',
					)
				);
				?>
				<p class="description" id="<?php echo esc_attr( $name . '_description' ); ?>"><?php echo esc_html( $description ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Print the "Dashboard" tab.
	 */
	private function render_tab_dashboard() {
		$source   = Integrate_Rybbit_Options::api_key_source();
		$base_url = Integrate_Rybbit_Options::get_api_base_url();
		$ranges   = array(
			'24h' => __( 'Last 24 hours', 'integrate-rybbit' ),
			'7d'  => __( 'Last 7 days', 'integrate-rybbit' ),
			'30d' => __( 'Last 30 days', 'integrate-rybbit' ),
			'90d' => __( 'Last 90 days', 'integrate-rybbit' ),
		);
		$range    = Integrate_Rybbit_Options::get_dashboard_range();
		?>
		<p class="description rybbit-description-wide">
			<?php esc_html_e( 'Show your Rybbit analytics in the WordPress dashboard. The plugin reads the numbers from the Rybbit API with an API key. The browser of a visitor never sees the key.', 'integrate-rybbit' ); ?>
			<?php esc_html_e( 'Rybbit Cloud offers API keys on the Standard plan and above. Self-hosted instances have no such limit.', 'integrate-rybbit' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="rybbit_api_key"><?php esc_html_e( 'API key', 'integrate-rybbit' ); ?></label></th>
				<td>
					<?php if ( 'constant' === $source ) : ?>
						<div class="notice notice-info inline"><p>
							<?php
							printf(
								/* translators: %s: PHP constant name. */
								esc_html__( 'The API key is set with the constant %s in wp-config.php. This key is used instead of the field below.', 'integrate-rybbit' ),
								'<code>' . esc_html( Integrate_Rybbit_Options::API_KEY_CONSTANT ) . '</code>'
							);
							?>
						</p></div>
					<?php endif; ?>
					<input type="password" id="rybbit_api_key" name="rybbit_api_key" value="" class="regular-text rybbit-input-wide" autocomplete="new-password" spellcheck="false" aria-describedby="rybbit_api_key_description"
						placeholder="<?php echo 'option' === $source ? esc_attr__( 'A key is saved. Enter a new key to replace it.', 'integrate-rybbit' ) : ''; ?>" />
					<p class="description" id="rybbit_api_key_description"><?php esc_html_e( 'Create the key in Rybbit under Organization settings, API keys. The plugin only needs read access. The saved key is never shown again.', 'integrate-rybbit' ); ?></p>
					<?php if ( '' !== (string) get_option( 'rybbit_api_key', '' ) ) : ?>
						<p>
							<label for="rybbit_api_key_remove">
								<input type="checkbox" id="rybbit_api_key_remove" name="rybbit_api_key_remove" value="1" />
								<?php esc_html_e( 'Remove the saved API key', 'integrate-rybbit' ); ?>
							</label>
						</p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'For more safety, you can define the key in wp-config.php instead.', 'integrate-rybbit' ); ?> <code>define( 'INTEGRATE_RYBBIT_API_KEY', '…' );</code></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_api_url"><?php esc_html_e( 'API URL', 'integrate-rybbit' ); ?></label></th>
				<td>
					<?php
					$this->render_input(
						'rybbit_api_url',
						array(
							'type'        => 'url',
							'class'       => 'regular-text rybbit-input-url',
							'placeholder' => '' !== $base_url ? $base_url : 'https://app.rybbit.io',
							'describedby' => 'rybbit_api_url_description',
						)
					);
					?>
					<p class="description" id="rybbit_api_url_description">
						<?php
						if ( '' !== $base_url ) {
							printf(
								/* translators: %s: URL. */
								esc_html__( 'Leave this empty to use the address of your Script URL. Now used: %s', 'integrate-rybbit' ),
								'<code>' . esc_html( $base_url ) . '</code>'
							);
						} else {
							esc_html_e( 'Leave this empty to use the address of your Script URL. Set it only if the API runs on another address.', 'integrate-rybbit' );
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_dashboard_range"><?php esc_html_e( 'Default time range', 'integrate-rybbit' ); ?></label></th>
				<td>
					<select id="rybbit_dashboard_range" name="rybbit_dashboard_range">
						<?php foreach ( $ranges as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $range, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rybbit_dashboard_cache"><?php esc_html_e( 'Cache time (minutes)', 'integrate-rybbit' ); ?></label></th>
				<td>
					<?php
					$this->render_input(
						'rybbit_dashboard_cache',
						array(
							'type'        => 'number',
							'class'       => 'small-text',
							'min'         => Integrate_Rybbit_Options::CACHE_MINUTES_MIN,
							'describedby' => 'rybbit_dashboard_cache_description',
						)
					);
					?>
					<p class="description" id="rybbit_dashboard_cache_description"><?php esc_html_e( 'The plugin keeps API answers for this time. A longer time saves API requests. This matters on plans with request limits.', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection', 'integrate-rybbit' ); ?></th>
				<td>
					<div class="rybbit-button-row">
						<button type="button" class="button" id="rybbit-test-api"><?php esc_html_e( 'Test connection', 'integrate-rybbit' ); ?></button>
						<button type="button" class="button" id="rybbit-clear-api-cache"><?php esc_html_e( 'Clear cache', 'integrate-rybbit' ); ?></button>
					</div>
					<p class="rybbit-api-result" id="rybbit-api-result" role="status" aria-live="polite"></p>
					<p class="description"><?php esc_html_e( 'The test uses the saved settings. Save your changes first.', 'integrate-rybbit' ); ?></p>
					<?php if ( Integrate_Rybbit_Options::is_dashboard_enabled() && current_user_can( Integrate_Rybbit_Options::get_dashboard_capability() ) ) : ?>
						<p><a href="<?php echo esc_url( admin_url( 'index.php?page=' . Integrate_Rybbit_Dashboard::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Open the analytics page', 'integrate-rybbit' ); ?></a></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Print the "Maintenance" tab.
	 */
	private function render_tab_maintenance() {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'integrate-rybbit' ); ?></th>
				<td>
					<?php $this->render_checkbox( 'rybbit_delete_data_on_uninstall', __( 'Remove all plugin settings when the plugin is uninstalled (deleted).', 'integrate-rybbit' ) ); ?>
					<p class="description"><?php esc_html_e( 'This does not run when the plugin is only deactivated. On multisite, each site uses its own setting.', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Print the "Debug" tab.
	 */
	private function render_tab_debug() {
		$settings = array();
		foreach ( array_keys( Integrate_Rybbit_Options::defaults() ) as $name ) {
			$settings[ $name ] = Integrate_Rybbit_Options::get( $name );
		}
		// The debug output must never show the secret key. It shows where the key comes from.
		$settings['rybbit_api_key'] = Integrate_Rybbit_Options::api_key_source();

		$user     = wp_get_current_user();
		$excluded = Integrate_Rybbit_Options::is_user_excluded();

		$attributes = array();
		if ( Integrate_Rybbit_Options::is_configured() ) {
			foreach ( Integrate_Rybbit_Options::build_script_attributes() as $key => $value ) {
				$decoded            = is_string( $value ) ? json_decode( $value ) : null;
				$attributes[ $key ] = ( is_array( $decoded ) || is_object( $decoded ) ) ? $decoded : $value;
			}
		}

		$script = array(
			'configured' => Integrate_Rybbit_Options::is_configured(),
			'src'        => Integrate_Rybbit_Options::get_script_src(),
			'strategy'   => Integrate_Rybbit_Options::get_script_strategy(),
			'attributes' => (object) $attributes,
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Effective settings', 'integrate-rybbit' ); ?></th>
				<td>
					<pre class="rybbit-identify-payload"><?php echo esc_html( Integrate_Rybbit_Options::encode_json( $settings, true ) ); ?></pre>
					<p class="description"><?php esc_html_e( 'Values shown here reflect what is currently saved in the database.', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Current user', 'integrate-rybbit' ); ?></th>
				<td>
					<ul class="rybbit-list">
						<li><?php esc_html_e( 'Logged in:', 'integrate-rybbit' ); ?> <strong><?php echo is_user_logged_in() ? esc_html__( 'yes', 'integrate-rybbit' ) : esc_html__( 'no', 'integrate-rybbit' ); ?></strong></li>
						<li><?php esc_html_e( 'User ID:', 'integrate-rybbit' ); ?> <code><?php echo (int) $user->ID; ?></code></li>
						<li><?php esc_html_e( 'Roles:', 'integrate-rybbit' ); ?> <code><?php echo esc_html( implode( ', ', (array) $user->roles ) ); ?></code></li>
						<li><?php esc_html_e( 'Excluded by role:', 'integrate-rybbit' ); ?> <strong><?php echo $excluded ? esc_html__( 'yes', 'integrate-rybbit' ) : esc_html__( 'no', 'integrate-rybbit' ); ?></strong></li>
					</ul>
					<p class="description"><?php esc_html_e( 'If "Excluded by role" is "yes", this user does not receive the tracking script.', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tracking script data', 'integrate-rybbit' ); ?></th>
				<td>
					<pre class="rybbit-identify-payload"><?php echo esc_html( Integrate_Rybbit_Options::encode_json( $script, true ) ); ?></pre>
					<p class="description"><?php esc_html_e( 'Script URL and attributes of the tracking script tag. Attributes with the default value are left out.', 'integrate-rybbit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Quick checks', 'integrate-rybbit' ); ?></th>
				<td>
					<ul class="rybbit-list">
						<li><?php esc_html_e( 'Site ID and Script URL must be set. Otherwise the plugin outputs nothing.', 'integrate-rybbit' ); ?></li>
						<li><?php esc_html_e( 'The script loads on the frontend. It loads in wp-admin only if "Also load the tracking script in wp-admin" is on.', 'integrate-rybbit' ); ?></li>
						<li><?php esc_html_e( 'Role exclusion applies to the frontend and to wp-admin.', 'integrate-rybbit' ); ?></li>
					</ul>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Print the "About" tab.
	 */
	private function render_tab_about() {
		$links = array(
			'https://github.com/maki-it/rybbit-wordpress-plugin' => __( 'GitHub repository', 'integrate-rybbit' ),
			'https://rybbit.com'      => __( 'Rybbit website', 'integrate-rybbit' ),
			'https://rybbit.com/docs' => __( 'Rybbit documentation', 'integrate-rybbit' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Installed plugin version', 'integrate-rybbit' ); ?></th>
				<td><code class="rybbit-installed-version"><?php echo esc_html( INTEGRATE_RYBBIT_VERSION ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Links', 'integrate-rybbit' ); ?></th>
				<td>
					<ul class="rybbit-list">
						<?php foreach ( $links as $url => $label ) : ?>
							<li><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $label ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Troubleshooting', 'integrate-rybbit' ); ?></th>
				<td>
					<ul class="rybbit-list">
						<li><?php esc_html_e( 'Make sure Site ID and Script URL are set.', 'integrate-rybbit' ); ?></li>
						<li><?php esc_html_e( 'If tracking does not fire for logged-in users, check "Do not track these roles".', 'integrate-rybbit' ); ?></li>
						<li><?php esc_html_e( 'To identify users, turn it on in the Privacy tab and check the payload preview.', 'integrate-rybbit' ); ?></li>
						<li><?php esc_html_e( 'Ad blockers and some caching or optimization plugins can block or change the tracking script.', 'integrate-rybbit' ); ?></li>
					</ul>
				</td>
			</tr>
		</table>
		<?php
	}
}
