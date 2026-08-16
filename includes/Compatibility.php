<?php
/**
 * Environment compatibility checks for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart;

defined( 'ABSPATH' ) || exit;

/**
 * Class Compatibility
 *
 * Gatekeeper for the plugin bootstrap: verifies the WordPress, PHP and
 * WooCommerce versions plus WooCommerce availability before the plugin is
 * allowed to boot, and renders a single admin notice listing everything
 * that is missing.
 *
 * Deviation note (Agent rule 15): the reference plugin (WooInsights)
 * works without WooCommerce and therefore has no equivalent class; Goal
 * Cart's core value is cart revenue optimization, so WooCommerce is a
 * hard dependency. A single top-level class (mirroring the top-level
 * Plugin/Container convention) was chosen over scattering checks across
 * components so the bootstrap gate stays in one place and the main file
 * can call it before any service exists.
 */
class Compatibility {

	/**
	 * Minimum required WordPress version.
	 *
	 * @var string
	 */
	const REQUIRED_WP = '6.3';

	/**
	 * Minimum required PHP version.
	 *
	 * @var string
	 */
	const REQUIRED_PHP = '7.4';

	/**
	 * Minimum required WooCommerce version.
	 *
	 * @var string
	 */
	const REQUIRED_WC = '8.0';

	/**
	 * Whether all environment requirements are met.
	 *
	 * @return bool
	 */
	public static function is_requirements_met() {
		return self::is_wordpress_compatible()
			&& self::is_php_compatible()
			&& self::is_woocommerce_active()
			&& self::is_woocommerce_compatible();
	}

	/**
	 * Whether the WordPress version meets the minimum.
	 *
	 * @return bool
	 */
	public static function is_wordpress_compatible() {
		global $wp_version;

		return version_compare( (string) $wp_version, self::REQUIRED_WP, '>=' );
	}

	/**
	 * Whether the PHP version meets the minimum.
	 *
	 * @return bool
	 */
	public static function is_php_compatible() {
		return version_compare( PHP_VERSION, self::REQUIRED_PHP, '>=' );
	}

	/**
	 * Whether WooCommerce is installed and active.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether the WooCommerce version meets the minimum.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_compatible() {
		return defined( 'WC_VERSION' ) && version_compare( (string) WC_VERSION, self::REQUIRED_WC, '>=' );
	}

	/**
	 * Gate the plugin on plugins_loaded (priority 5), after every plugin
	 * file has been loaded.
	 *
	 * Deliberately not run at file scope: WordPress requires active plugins
	 * sequentially in active_plugins order, so class_exists('WooCommerce')
	 * and WC_VERSION may be unavailable simply because the FaraCart main
	 * file was required before WooCommerce's. By plugins_loaded both are
	 * reliable (including network-activated WooCommerce in multisite).
	 * When requirements are not met, an admin notice is registered; the
	 * admin menu is additionally guarded in Admin::register_menu().
	 *
	 * @return void
	 */
	public static function gate() {
		if ( self::is_requirements_met() ) {
			return;
		}

		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/**
	 * Render the requirements failure notice.
	 *
	 * @return void
	 */
	public static function render_notices() {
		$problems = array();

		if ( ! self::is_wordpress_compatible() ) {
			$problems[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				__( 'WordPress %1$s or newer is required (current: %2$s).', 'faracart' ),
				self::REQUIRED_WP,
				isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '?'
			);
		}

		if ( ! self::is_php_compatible() ) {
			$problems[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'PHP %1$s or newer is required (current: %2$s).', 'faracart' ),
				self::REQUIRED_PHP,
				PHP_VERSION
			);
		}

		if ( ! self::is_woocommerce_active() ) {
			$problems[] = __( 'WooCommerce must be installed and activated.', 'faracart' );
		} elseif ( ! self::is_woocommerce_compatible() ) {
			$problems[] = sprintf(
				/* translators: 1: required WooCommerce version, 2: current WooCommerce version. */
				__( 'WooCommerce %1$s or newer is required (current: %2$s).', 'faracart' ),
				self::REQUIRED_WC,
				defined( 'WC_VERSION' ) ? WC_VERSION : '?'
			);
		}

		if ( empty( $problems ) ) {
			return;
		}

		$list = '';

		foreach ( $problems as $problem ) {
			$list .= '<li>' . esc_html( $problem ) . '</li>';
		}

		printf(
			'<div class="notice notice-error faracart-compat-notice"><p><strong>%1$s</strong></p><ul>%2$s</ul></div>',
			esc_html__( 'FaraCart is inactive because the site does not meet its requirements:', 'faracart' ),
			$list // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item is escaped above.
		);
	}
}
