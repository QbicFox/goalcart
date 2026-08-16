<?php
/**
 * Admin-facing functionality for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Admin;

use FaraCart\Compatibility;
use FaraCart\Hooks\HookManager;
use FaraCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 *
 * Registers the admin menu, enqueues the React admin app (built by Vite
 * in admin-app/), and renders the dashboard mount point. The dashboard
 * UI itself is implemented in Phase 8 (React Admin Foundation); this
 * class provides the page shell, capability checks and boot data
 * plumbing from the foundation phase.
 */
class Admin {

	/**
	 * Top-level menu slug for the plugin.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'faracart';

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Asset loader for the React admin app.
	 *
	 * @var AssetLoader
	 */
	protected $assets;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings instance.
	 * @param AssetLoader $assets   Asset loader instance.
	 */
	public function __construct( Settings $settings, AssetLoader $assets ) {
		$this->settings = $settings;
		$this->assets   = $assets;
	}

	/**
	 * Register admin hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( 'admin_menu', array( $this, 'register_menu' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$hooks->add_action( 'admin_notices', array( $this, 'render_build_notice' ) );

		// Full-screen dashboard: mark the page with a body class so the
		// admin chrome (admin bar, left menu, notices) can be hidden and
		// the React app gets the whole viewport.
		$hooks->add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
	}

	/**
	 * Get the settings instance.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Get the asset loader.
	 *
	 * @return AssetLoader
	 */
	public function assets() {
		return $this->assets;
	}

	/**
	 * Register the top-level admin menu page.
	 *
	 * The capability is filterable so stores can restrict access without
	 * touching the plugin (Phase 2: capability checks).
	 *
	 * @return void
	 */
	public function register_menu() {
		// Requirements gate: without WooCommerce (or with an unsupported
		// WP/PHP/WC version) the plugin's admin page is not registered;
		// Compatibility::gate() explains why via admin notices.
		if ( ! Compatibility::is_requirements_met() ) {
			return;
		}

		$capability = apply_filters( 'faracart_admin_capability', 'manage_options' );

		add_menu_page(
			__( 'FaraCart', 'faracart' ),
			__( 'FaraCart', 'faracart' ),
			$capability,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-cart',
			58
		);
	}

	/**
	 * Enqueue the admin app assets on the plugin page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		// Page CSS: drops the admin toolbar's #wpbody padding in both
		// display modes and hides the WP admin chrome when the
		// `faracart-fullscreen` body class is present (admin_body_class()).
		wp_enqueue_style(
			'faracart-admin-fullscreen',
			FARACART_URL . 'assets/css/admin-fullscreen.css',
			array(),
			FARACART_VERSION
		);

		$this->assets->enqueue();
	}

	/**
	 * Add page body classes on the FaraCart admin screen.
	 *
	 * `faracart-admin-page` is always added so admin-fullscreen.css can
	 * target the screen in both display modes. `faracart-fullscreen` is
	 * added only when the full-screen setting is on; it hides the
	 * WordPress admin bar, left admin menu and notices so the React
	 * dashboard fills the entire browser viewport.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function admin_body_class( $classes ) {
		if ( $this->is_faracart_screen() ) {
			$classes .= ' faracart-admin-page';

			if ( $this->settings->get( 'fullscreen_dashboard', true ) ) {
				$classes .= ' faracart-fullscreen';
			}
		}

		return trim( (string) $classes );
	}

	/**
	 * Whether the current admin request is the FaraCart page.
	 *
	 * @return bool
	 */
	protected function is_faracart_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && 'toplevel_page_' . self::MENU_SLUG === $screen->id ) {
			return true;
		}

		// Fallback for early contexts where the screen object is not set
		// yet: the menu page is reached via admin.php?page=faracart.
		return isset( $_GET['page'] ) && self::MENU_SLUG === wp_unslash( (string) $_GET['page'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Show a notice when the admin app cannot be loaded.
	 *
	 * Helps developers realise the app needs `npm run build` (or a running
	 * `npm run dev` server) before the dashboard can render.
	 *
	 * @return void
	 */
	public function render_build_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}

		$hint = $this->assets->load_hint();

		if ( '' === $hint ) {
			return;
		}

		// The extra `faracart-build-notice` class keeps this visible in
		// full-screen mode (admin-fullscreen.css hides generic notices).
		printf(
			'<div class="notice notice-warning is-dismissible faracart-build-notice"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'FaraCart:', 'faracart' ),
			wp_kses_post( $hint )
		);
	}

	/**
	 * Render the main dashboard page (React mount point).
	 *
	 * The React app boots inside #faracart-admin and replaces the loading
	 * placeholder once mounted. The `dir` attribute is set explicitly so
	 * the app mirrors itself for RTL locales even before the React shell
	 * mounts.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$dir = is_rtl() ? 'rtl' : 'ltr';

		echo '<div class="wrap">';
		echo '<div id="faracart-admin" dir="' . esc_attr( $dir ) . '" class="faracart-admin-root">';
		echo '<div class="faracart-admin-loading">' . esc_html__( 'Loading FaraCart…', 'faracart' ) . '</div>';
		echo '</div>';
		echo '</div>';
	}
}
