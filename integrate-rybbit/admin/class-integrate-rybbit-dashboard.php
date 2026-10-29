<?php
/**
 * Analytics dashboard: Dashboard widget and admin page.
 *
 * @package Integrate_Rybbit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows Rybbit analytics inside WordPress.
 *
 * This class only prints containers and loads the assets. The script
 * "dashboard.js" reads the data from the REST route and draws the charts.
 */
final class Integrate_Rybbit_Dashboard {

	/**
	 * Slug of the admin page below "Dashboard".
	 */
	const PAGE_SLUG = 'integrate-rybbit-dashboard';

	/**
	 * Hook suffix of the admin page.
	 */
	const HOOK_SUFFIX = 'dashboard_page_integrate-rybbit-dashboard';

	/**
	 * Handle of the script and the style.
	 */
	const HANDLE = 'integrate-rybbit-dashboard';

	/**
	 * Register the admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * The current user may see the data and the API is set up.
	 *
	 * @return bool
	 */
	private function is_available() {
		return current_user_can( Integrate_Rybbit_Options::get_dashboard_capability() ) && Integrate_Rybbit_Options::is_dashboard_enabled();
	}

	/**
	 * Add the page below "Dashboard".
	 */
	public function add_page() {
		if ( ! $this->is_available() ) {
			return;
		}

		add_submenu_page(
			'index.php',
			__( 'Rybbit Analytics', 'integrate-rybbit' ),
			__( 'Rybbit Analytics', 'integrate-rybbit' ),
			Integrate_Rybbit_Options::get_dashboard_capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add the widget to the WordPress dashboard.
	 */
	public function add_widget() {
		if ( ! $this->is_available() ) {
			return;
		}

		wp_add_dashboard_widget(
			'integrate_rybbit_widget',
			__( 'Rybbit Analytics', 'integrate-rybbit' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Load script and style where they are needed.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'index.php', self::HOOK_SUFFIX ), true ) || ! $this->is_available() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			INTEGRATE_RYBBIT_URL . 'admin/css/dashboard.css',
			array(),
			INTEGRATE_RYBBIT_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			INTEGRATE_RYBBIT_URL . 'admin/js/dashboard.js',
			array( 'wp-i18n' ),
			INTEGRATE_RYBBIT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_set_script_translations( self::HANDLE, 'integrate-rybbit', INTEGRATE_RYBBIT_DIR . 'languages' );
		wp_localize_script(
			self::HANDLE,
			'rybbitDashboard',
			array(
				'restUrl'      => rest_url( Integrate_Rybbit_Rest::REST_NAMESPACE . Integrate_Rybbit_Rest::ROUTE ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'defaultRange' => Integrate_Rybbit_Options::get_dashboard_range(),
				'liveInterval' => Integrate_Rybbit_Api::LIVE_TTL,
				'cacheMinutes' => (int) round( Integrate_Rybbit_Options::get_cache_seconds() / 60 ),
				'pageUrl'      => admin_url( 'index.php?page=' . self::PAGE_SLUG ),
				'settingsUrl'  => admin_url( 'options-general.php?page=' . Integrate_Rybbit_Admin::PAGE_SLUG . '#dashboard' ),
			)
		);
	}

	/**
	 * Print the widget.
	 */
	public function render_widget() {
		?>
		<div class="rybbit-dash rybbit-dash--widget" data-rybbit-dash data-view="summary" data-range="<?php echo esc_attr( Integrate_Rybbit_Options::get_dashboard_range() ); ?>">
			<div class="rybbit-dash__body" aria-live="polite">
				<p class="rybbit-dash__status"><?php esc_html_e( 'Loading analytics…', 'integrate-rybbit' ); ?></p>
			</div>
			<noscript><p><?php esc_html_e( 'JavaScript is required to show the analytics.', 'integrate-rybbit' ); ?></p></noscript>
			<p class="rybbit-dash__footer">
				<a href="<?php echo esc_url( admin_url( 'index.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Show all analytics', 'integrate-rybbit' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Print the admin page.
	 */
	public function render_page() {
		if ( ! $this->is_available() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'integrate-rybbit' ), '', array( 'response' => 403 ) );
		}

		$ranges = array(
			'24h' => __( 'Last 24 hours', 'integrate-rybbit' ),
			'7d'  => __( 'Last 7 days', 'integrate-rybbit' ),
			'30d' => __( 'Last 30 days', 'integrate-rybbit' ),
			'90d' => __( 'Last 90 days', 'integrate-rybbit' ),
		);
		$active = Integrate_Rybbit_Options::get_dashboard_range();
		$site   = trim( (string) Integrate_Rybbit_Options::get( 'rybbit_site_id' ) );
		$base   = Integrate_Rybbit_Options::get_api_base_url();
		?>
		<div class="wrap rybbit-dash-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Rybbit Analytics', 'integrate-rybbit' ); ?></h1>
			<?php if ( '' !== $base && '' !== $site ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( $base . '/' . rawurlencode( $site ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Rybbit', 'integrate-rybbit' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<div class="rybbit-dash rybbit-dash--page" data-rybbit-dash data-view="full" data-range="<?php echo esc_attr( $active ); ?>">
				<div class="rybbit-dash__toolbar">
					<label for="rybbit-dash-range" class="screen-reader-text"><?php esc_html_e( 'Time range', 'integrate-rybbit' ); ?></label>
					<select id="rybbit-dash-range" data-rybbit-range>
						<?php foreach ( $ranges as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $active, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" data-rybbit-refresh><?php esc_html_e( 'Refresh', 'integrate-rybbit' ); ?></button>
					<span class="rybbit-dash__updated" data-rybbit-updated></span>
				</div>
				<div class="rybbit-dash__body" aria-live="polite">
					<p class="rybbit-dash__status"><?php esc_html_e( 'Loading analytics…', 'integrate-rybbit' ); ?></p>
				</div>
				<noscript><p><?php esc_html_e( 'JavaScript is required to show the analytics.', 'integrate-rybbit' ); ?></p></noscript>
			</div>
		</div>
		<?php
	}
}
