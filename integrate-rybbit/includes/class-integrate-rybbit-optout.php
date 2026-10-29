<?php
/**
 * Opt-out switch for visitors: shortcode and block.
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a switch that lets visitors turn the Rybbit tracking off and on.
 *
 * The HTML is static. The browser reads and writes the state in localStorage.
 * Page caches and CDNs can cache the page without problems.
 */
final class Integrate_Rybbit_Optout {

	/**
	 * Shortcode name.
	 */
	const SHORTCODE = 'rybbit_optout';

	/**
	 * Block name.
	 */
	const BLOCK = 'integrate-rybbit/optout';

	/**
	 * Handle of the frontend script and style.
	 */
	const HANDLE = 'integrate-rybbit-optout';

	/**
	 * Handle of the block editor script.
	 */
	const EDITOR_HANDLE = 'integrate-rybbit-optout-block';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the shortcode, the block and their assets.
	 */
	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		wp_register_script(
			self::HANDLE,
			INTEGRATE_RYBBIT_URL . 'public/js/rybbit-optout.js',
			array(),
			INTEGRATE_RYBBIT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_style(
			self::HANDLE,
			INTEGRATE_RYBBIT_URL . 'public/css/rybbit-optout.css',
			array(),
			INTEGRATE_RYBBIT_VERSION
		);

		wp_register_script(
			self::EDITOR_HANDLE,
			INTEGRATE_RYBBIT_URL . 'blocks/optout/index.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
			INTEGRATE_RYBBIT_VERSION,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( self::EDITOR_HANDLE, 'integrate-rybbit', INTEGRATE_RYBBIT_DIR . 'languages' );
		wp_add_inline_script(
			self::EDITOR_HANDLE,
			'window.integrateRybbitOptout = ' . wp_json_encode( self::get_defaults(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);

		register_block_type(
			INTEGRATE_RYBBIT_DIR . 'blocks/optout',
			array( 'render_callback' => array( $this, 'render_block' ) )
		);
	}

	/**
	 * Default texts. Translations come from the plugin language files.
	 *
	 * @return array<string, string>
	 */
	public static function get_defaults() {
		return array(
			'optout_label'   => __( 'Turn off analytics', 'integrate-rybbit' ),
			'optin_label'    => __( 'Turn on analytics', 'integrate-rybbit' ),
			'tracked_text'   => __( 'Website analytics is turned on for this browser.', 'integrate-rybbit' ),
			'untracked_text' => __( 'Website analytics is turned off for this browser.', 'integrate-rybbit' ),
		);
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'optout_label'   => '',
				'optin_label'    => '',
				'tracked_text'   => '',
				'untracked_text' => '',
				'class'          => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$classes = array( 'integrate-rybbit-optout' );
		foreach ( preg_split( '/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			$classes[] = sanitize_html_class( $class );
		}

		return $this->render( $atts, 'class="' . esc_attr( implode( ' ', array_filter( $classes ) ) ) . '"' );
	}

	/**
	 * Render the block.
	 *
	 * @param array<string, string> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		return $this->render(
			is_array( $attributes ) ? $attributes : array(),
			get_block_wrapper_attributes( array( 'class' => 'integrate-rybbit-optout' ) )
		);
	}

	/**
	 * Name that the tracking script uses for its global object and storage key.
	 *
	 * The script reads "data-namespace" and falls back to "rybbit". It saves the
	 * opt-out in localStorage under "disable-<namespace>".
	 *
	 * @return string
	 */
	public static function get_namespace() {
		$attributes = Integrate_Rybbit_Options::build_script_attributes();
		$namespace  = isset( $attributes['data-namespace'] ) ? trim( (string) $attributes['data-namespace'] ) : '';

		return '' === $namespace ? 'rybbit' : $namespace;
	}

	/**
	 * Build the HTML.
	 *
	 * @param array<string, mixed> $atts               Texts. Empty values use the defaults.
	 * @param string               $wrapper_attributes Escaped attributes of the wrapper element.
	 * @return string
	 */
	private function render( array $atts, $wrapper_attributes ) {
		if ( is_feed() ) {
			return '';
		}

		if ( ! Integrate_Rybbit_Options::is_configured() ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return '';
			}

			return '<p class="integrate-rybbit-optout__notice">'
				. esc_html__( 'Integrate Rybbit is not set up yet. Visitors do not see the opt-out switch.', 'integrate-rybbit' )
				. '</p>';
		}

		$texts = array();
		foreach ( self::get_defaults() as $key => $default ) {
			$value         = isset( $atts[ $key ] ) ? sanitize_text_field( (string) $atts[ $key ] ) : '';
			$texts[ $key ] = '' === $value ? $default : $value;
		}

		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );

		$html = sprintf(
			'<div %1$s data-integrate-rybbit-optout data-namespace="%2$s" data-optout-label="%3$s" data-optin-label="%4$s" data-tracked-text="%5$s" data-untracked-text="%6$s" data-error-text="%7$s">'
			. '<p class="integrate-rybbit-optout__status" role="status" aria-live="polite" hidden></p>'
			. '<button type="button" class="integrate-rybbit-optout__button wp-element-button" hidden></button>'
			. '<noscript><p>%8$s</p></noscript>'
			. '</div>',
			$wrapper_attributes, // Built with esc_attr() or get_block_wrapper_attributes().
			esc_attr( self::get_namespace() ),
			esc_attr( $texts['optout_label'] ),
			esc_attr( $texts['optin_label'] ),
			esc_attr( $texts['tracked_text'] ),
			esc_attr( $texts['untracked_text'] ),
			esc_attr__( 'Your browser blocks local storage. This setting cannot be saved.', 'integrate-rybbit' ),
			esc_html__( 'JavaScript is needed to change this setting.', 'integrate-rybbit' )
		);

		/**
		 * Filter the HTML of the opt-out switch.
		 *
		 * @param string                $html  The HTML.
		 * @param array<string, string> $texts The texts that the switch shows.
		 */
		return (string) apply_filters( 'integrate_rybbit_optout_html', $html, $texts );
	}
}
