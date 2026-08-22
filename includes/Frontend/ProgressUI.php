<?php
/**
 * Storefront progress UI for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Frontend;

use FaraCart\Hooks\HookManager;
use FaraCart\Settings\Settings;
use FaraCart\Utils\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProgressUI
 *
 * Frontend Progress UI — the customer-facing widget layer. It
 * renders empty widget containers at the display locations and lets the
 * vanilla JS library (`assets/js/frontend.js`, no build step — mirroring
 * the reference plugin's frontend pattern) fetch `/faracart/v1/progress`
 * and fill them in, so the server never renders progress markup directly
 * and cart changes update the UI through WooCommerce's own JS events.
 *
 * Display locations (P11: Cart, Mini Cart, Checkout, Shop, Product page,
 * configurable widget/shortcode):
 *
 *  - `woocommerce_before_cart` / `woocommerce_after_cart`
 *                                           → full widget on the cart page
 *  - `woocommerce_before_mini_cart` / `woocommerce_after_mini_cart`
 *                                           → compact widget in the mini cart
 *  - `woocommerce_before_checkout_form` / `woocommerce_after_checkout_form`
 *                                           → full widget on checkout
 *  - `woocommerce_archive_description` / `woocommerce_after_shop_loop`
 *                                           → compact widget on shop/archives
 *  - `woocommerce_single_product_summary` / `woocommerce_after_single_product_summary`
 *                                           → compact widget on product pages
 *  - `[faracart_progress]` shortcode       → widget anywhere (full/compact)
 *
 * Duplicate-render guard (P11): each location renders at most once per
 * request (a rendered-location registry), every container has a unique id,
 * and the JS mounts each container exactly once (`data-faracart-widget`),
 * so a page can never end up with two widgets in the same spot.
 *
 * Master toggle: the `enabled` setting gates everything, overridable with
 * the `faracart_frontend_enabled` filter. The per-location set is
 * filterable via `faracart_frontend_locations` (wires the
 * frontend settings to it). Logged-in site admins browsing the
 * storefront do not see the shopper-facing widgets by default
 * (`is_visible_to_user()`, filterable via `faracart_frontend_visible_to_user`).
 */
final class ProgressUI {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE = 'faracart_progress';

	/**
	 * Gutenberg block name rendered server-side.
	 *
	 * The block renders the same progress widget as the shortcode via a
	 * render callback, so Gutenberg/Block Editor users can drop the widget
	 * into any content without a JS block editor build. The admin-app build
	 * is untouched: no new dependency, matching the reference plugin's
	 * asset convention (widgets are server-rendered containers filled by
	 * the existing storefront JS).
	 *
	 * @var string
	 */
	const BLOCK = 'faracart/progress';

	/**
	 * Asset handle for the frontend JS/CSS.
	 *
	 * @var string
	 */
	const HANDLE = 'faracart-frontend';

	/**
	 * Storefront progress template variants.
	 *
	 * The six design templates (template-1 … template-6). The retired
	 * variants (basic / percentage / milestone / card / ring) are
	 * no longer registered and are never mapped — an unregistered value
	 * falls back to template-1.
	 *
	 * @var string[]
	 */
	const TEMPLATES = array( 'template-1', 'template-2', 'template-3', 'template-4', 'template-5', 'template-6' );

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Locations already rendered this request (duplicate guard).
	 *
	 * @var array<string, true>
	 */
	protected $rendered = array();

	/**
	 * Shortcode instance counter (unique container ids).
	 *
	 * @var int
	 */
	protected $shortcode_index = 0;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the frontend hooks and the shortcode.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		// Shortcode registration must wait for 'init' (add_shortcode runs
		// safely from init onward; the_content is always after init). The
		// Gutenberg block registers the same init sequencing via
		// register_block_type.
		$hooks->add_action( 'init', array( $this, 'register_shortcode' ) );
		$hooks->add_action( 'init', array( $this, 'register_block' ) );

		// Assets only load on pages that can actually show a widget.
		$hooks->add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Config printed early in the footer (priority 5) so the footer
		// script — enqueued with in_footer — finds `window.faracartFrontend`
		// ready (reference plugin's config-before-script convention).
		$hooks->add_action( 'wp_footer', array( $this, 'print_config' ), 5 );

		// Display locations (each guarded against double injection). Both
		// boundary hooks are registered so changing the global position
		// setting takes effect without requiring a hook re-registration.
		$hooks->add_action( 'woocommerce_before_cart', array( $this, 'render_cart_widget' ) );
		$hooks->add_action( 'woocommerce_after_cart', array( $this, 'render_cart_widget_bottom' ) );
		$hooks->add_action( 'woocommerce_before_mini_cart', array( $this, 'render_mini_cart_widget_top' ) );
		$hooks->add_action( 'woocommerce_after_mini_cart', array( $this, 'render_mini_cart_widget' ) );
		$hooks->add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_widget' ) );
		$hooks->add_action( 'woocommerce_after_checkout_form', array( $this, 'render_checkout_widget_bottom' ) );
		$hooks->add_action( 'woocommerce_archive_description', array( $this, 'render_shop_widget' ) );
		$hooks->add_action( 'woocommerce_after_shop_loop', array( $this, 'render_shop_widget_bottom' ) );
		$hooks->add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_widget' ), 45 );
		$hooks->add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_product_widget_bottom' ), 20 );

		// WooCommerce Blocks: the classic WooCommerce actions
		// (woocommerce_before_cart, woocommerce_before_checkout_form) only
		// fire on the classic templates, so a store running the Cart or
		// Checkout block never triggers them. The render_block filter is a
		// public WordPress API: append the full widget after the Cart and
		// Checkout blocks. The duplicate-render registry already guarantees
		// at most one widget per location, so a page can never show it twice.
		//
		// Priority 20 (not the default 10): WooCommerce's own render_block
		// filter (BlockTypesController::add_data_attributes, priority 10)
		// stamps `data-block-name` onto the FIRST HTML tag of the block
		// content. With the widget prepended at priority 10 (top position),
		// that attribute lands on our widget container instead of the Cart
		// / Checkout block, so the block's client-side app hydrates the
		// widget as if it were the checkout and the page never finishes
		// loading. Running after WooCommerce lets it tag the real block
		// first, then the widget is inserted as a clean sibling.
		$hooks->add_filter( 'render_block', array( $this, 'render_block_widget' ), 20, 2 );

		// Floating missions/campaigns button + drawer (on widget pages).
		$hooks->add_action( 'wp_footer', array( $this, 'render_floating_button' ), 20 );
	}

	/**
	 * Register the `[faracart_progress]` shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Register the Gutenberg `faracart/progress` block.
	 *
	 * Server-rendered via render_block_type(): the Block Editor inserts a
	 * faracart/progress block and the render callback below outputs the
	 * same empty widget container the shortcode emits, which the shared
	 * storefront JS fills. No JS block editor build, no new dependency —
	 * consistent with the reference plugin's server-rendered widget
	 * convention.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // Gutenberg not available.
		}

		if ( class_exists( 'WP_Block_Type_Registry' ) && \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return; // Already registered (repeat init passes / test harness).
		}

		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 2,
				'title'           => __( 'FaraCart Progress', 'faracart' ),
				'category'        => 'widgets',
				'icon'            => 'cart',
				'description'     => __( 'Show cart missions, progress and rewards (FaraCart).', 'faracart' ),
				'keywords'        => array( 'mission', 'cart', 'progress', 'aov' ),
				'supports'        => array(
					'anchor'     => true,
					'align'      => true,
					'html'       => false,
					'reusable'   => true,
				),
				'attributes'      => array(
					'variant'  => array(
						'type'    => 'string',
						'default' => 'full',
						'enum'    => array( 'full', 'compact' ),
					),
					'template' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'example'         => array(
					'attributes' => array( 'variant' => 'full' ),
				),
				'render_callback' => array( $this, 'render_progress_block' ),
			)
		);
	}

	/**
	 * Render the `faracart/progress` block.
	 *
	 * Same contract as the shortcode: an inert, uniquely-id'd widget
	 * container the storefront JS mounts. Block ids share the shortcode
	 * counter so every container on the page (shortcodes, location
	 * injections and blocks alike) stays unique.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_progress_block( $attributes, $content = '' ) {
		if ( is_admin() || ! $this->is_enabled() ) {
			return '';
		}

		$attributes = shortcode_atts(
			array(
				'variant'  => 'full',
				'template' => '',
			),
			is_array( $attributes ) ? $attributes : array(),
			self::BLOCK
		);

		$this->shortcode_index++;

		return $this->widget_container( 'faracart-block-' . $this->shortcode_index, $attributes['variant'], $attributes['template'] );
	}

	/**
	 * Whether the frontend progress UI is enabled for the current visitor.
	 *
	 * Master toggle = the `enabled` setting, overridable with the
	 * `faracart_frontend_enabled` filter (reference `is_tracking_allowed()`
	 * convention). A logged-in site admin browsing the storefront is
	 * additionally hidden from the shopper-facing widgets by default —
	 * see `is_visible_to_user()`.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$enabled = (bool) apply_filters( 'faracart_frontend_enabled', $this->settings->get( 'enabled', true ) );

		return $enabled && $this->is_visible_to_user();
	}

	/**
	 * Whether the current user should see the storefront progress widgets.
	 *
	 * Shopper-facing widgets are hidden from logged-in site admins by
	 * default so staff browsing or testing the storefront never see the
	 * customer funnel (progress bars, rewards, suggestions).
	 * "Admin" is the same capability the admin menu uses
	 * (`faracart_admin_capability` filter, default `manage_options`), so
	 * every user who can administer the plugin is treated as staff. The
	 * whole decision is filterable with `faracart_frontend_visible_to_user`
	 * (e.g. hide for every logged-in user, or for shop managers too).
	 *
	 * @return bool
	 */
	public function is_visible_to_user() {
		$capability = (string) apply_filters( 'faracart_admin_capability', 'manage_options' );
		$visible    = ! ( is_user_logged_in() && current_user_can( $capability ) );

		return (bool) apply_filters( 'faracart_frontend_visible_to_user', $visible );
	}

	/**
	 * The enabled display locations.
	 *
	 * Settings → Frontend: driven by the `frontend_locations`
	 * setting (the default set ships all five locations), still filterable
	 * via faracart_frontend_locations. Unknown keys are dropped so a bad
	 * stored value can never register a location.
	 *
	 * @return string[]
	 */
	public function locations() {
		$allowed = array( 'cart', 'mini-cart', 'checkout', 'shop', 'product' );
		$stored  = array_map( 'strval', (array) $this->settings->get( 'frontend_locations', array() ) );

		$locations = array_values( array_intersect( $allowed, $stored ) );

		return (array) apply_filters( 'faracart_frontend_locations', $locations );
	}

	/**
	 * The position of page widgets (top or bottom), normalized and filterable.
	 *
	 * Controls the regular cart, mini-cart, checkout, shop and product
	 * widgets only.
	 *
	 * @return string
	 */
	public function position() {
		$position = apply_filters( 'faracart_frontend_position', $this->settings->get( 'frontend_position', 'top' ) );

		return in_array( $position, array( 'top', 'bottom' ), true ) ? $position : 'top';
	}

	/**
	 * Enqueue the frontend stylesheet and script.
	 *
	 * Assets load only when the master toggle is on and the current page
	 * can render a widget (cart/checkout/shop/product or a page carrying
	 * the shortcode).
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( is_admin() || ! $this->is_enabled() || ! $this->page_needs_widget() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			FARACART_URL . 'assets/css/frontend.css',
			array(),
			$this->asset_version( 'assets/css/frontend.css' )
		);

		wp_enqueue_script(
			self::HANDLE,
			FARACART_URL . 'assets/js/frontend.js',
			array(),
			$this->asset_version( 'assets/js/frontend.js' ),
			array( 'in_footer' => true )
		);

		// The appearance tokens + custom CSS ride along with the stylesheet
		// (WP's canonical inline-style channel), so the storefront gets one
		// style payload and the theme can still override any token.
		wp_add_inline_style( self::HANDLE, $this->appearance_css() );
	}

	/**
	 * Cache-busting version for a frontend asset.
	 *
	 * The static FARACART_VERSION only changes between releases, so the
	 * storefront would keep serving stale cached JS/CSS after every edit
	 * in between. filemtime() gives each file change a fresh version —
	 * the standard WP pattern for versioned static assets — and falls
	 * back to FARACART_VERSION when the file cannot be stat'd.
	 *
	 * @param string $relative Asset path relative to the plugin root.
	 * @return string
	 */
	protected function asset_version( $relative ) {
		// @phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- stat
		// failure is handled by the fallback below; silence keeps the log clean.
		$mtime = @filemtime( FARACART_PATH . $relative );

		return false === $mtime ? FARACART_VERSION : (string) $mtime;
	}

	/**
	 * Print the frontend config object for the JS library.
	 *
	 * Mirrors the reference plugin: a single inline script tag
	 * (`window.faracartFrontend`) printed early in `wp_footer`, consumed by
	 * vanilla JS with a must-never-throw contract.
	 *
	 * @return void
	 */
	public function print_config() {
		if ( is_admin() || ! $this->is_enabled() || ! $this->page_needs_widget() ) {
			return;
		}

		wp_print_inline_script_tag(
			'window.faracartFrontend = ' . wp_json_encode( $this->frontend_config() ) . ';',
			array( 'id' => 'faracart-frontend-config', 'type' => 'text/javascript' )
		);
	}

	/**
	 * The frontend config payload (endpoint, labels, page metadata).
	 *
	 * adds the active template, the animation flag and the
	 * resolved appearance tokens so the JS can render template variants
	 * and mirror the Appearance settings without another round-trip.
	 * adds the WooCommerce currency configuration and the mobile behavior
	 * so the JS formats money from the store settings and hides widgets
	 * per the FaraCart display settings.
	 * adds the countdown + celebration toggles and the
	 * gift-selection endpoint/nonce.
	 * Kept as its own method so tests can assert the shape without
	 * capturing output.
	 *
	 * @return array<string, mixed>
	 */
	public function frontend_config() {
		$appearance = $this->appearance();

		// Currency follows WooCommerce's own configuration — the single
		// source of truth for the storefront JS money formatter.
		$currency = Currency::frontend_config();

		return array(
			'endpoint'  => esc_url_raw( rest_url( 'faracart/v1/progress' ) ),
			'refresh'   => (int) apply_filters( 'faracart_frontend_refresh_interval', 0 ),
			'currency'  => $currency['currency'],
			'currencySymbol' => $currency['currencySymbol'],
			'currencyPosition' => $currency['currencyPosition'],
			'currencyDecimals' => $currency['currencyDecimals'],
			'currencyDecimalSeparator' => $currency['currencyDecimalSeparator'],
			'currencyThousandSeparator' => $currency['currencyThousandSeparator'],
			// Internationalization: the site locale reaches the
			// JS so Intl.NumberFormat renders locale-aware digits and
			// grouping (e.g. Persian digits for fa_IR) instead of the
			// browser's default locale.
			'locale'    => get_locale(),
			'isRtl'     => is_rtl(),
			'position'  => $this->position(),
			'template'  => $this->template(),
			'animation' => (bool) apply_filters( 'faracart_frontend_animation', $this->settings->get( 'frontend_animation', true ) ),
			'mobile'    => $this->mobile_behavior(),
			'appearance' => $appearance,
			'labels'    => $this->reward_labels(),
			// (countdown + celebration).
			'countdown' => (bool) apply_filters( 'faracart_frontend_countdown', $this->settings->get( 'frontend_countdown', true ) ),
			'celebrate' => (bool) apply_filters( 'faracart_frontend_celebrate', $this->settings->get( 'frontend_celebrate', true ) ),
			// free gift selection: the storefront gift picker
			// posts to this endpoint with this nonce; empty nonce = the
			// plugin is disabled (picker hidden).
			'giftEndpoint' => esc_url_raw( rest_url( 'faracart/v1/gift' ) ),
			'giftNonce'   => $this->settings->get( 'enabled', true )
				? wp_create_nonce( \FaraCart\REST\GiftController::GIFT_NONCE_ACTION )
				: '',
			// Floating widget (floating missions/campaigns button + drawer): the
			// resolved position config, per-device visibility and display
			// options the storefront JS applies. Position axes are physical
			// sides (the admin's choice must keep its visual result in RTL),
			// so the JS positions with physical offsets, not logical ones.
			'floating'  => $this->floating_config(),
			// Frontend Upsell Integration: the smart upsell
			// panel's contract — the public rank endpoint (live-cart mission
			// gap + deterministic ranking), the nonce-guarded track
			// endpoint (impression/clicked/added into the upsell_events
			// log) and the localized labels. Absent/disabled = the JS
			// renders no panel.
			'upsells'   => $this->upsell_config(),
		);
	}

	/**
	 * The storefront smart-upsell panel config.
	 *
	 * Mirrors the UpsellRanker gate (master enabled + analytics toggles +
	 * the faracart_upsells_enabled filter) so the panel only renders when
	 * the ranking engine is on. The track endpoint rides on the same
	 * tracking nonce window.faracartTracking already carries — the JS
	 * reuses it, so no second nonce is needed. Every string is
	 * translatable; the JS falls back to its English literals when the
	 * locale file misses a key.
	 *
	 * @return array<string, mixed>
	 */
	public function upsell_config() {
		$enabled = (bool) $this->settings->get( 'enabled', true )
			&& (bool) $this->settings->get( 'analytics_enabled', true );

		/**
		 * Filter whether smart upsell ranking is on (same gate as the
		 * ranker — keep the two in sync).
		 *
		 * @param bool $enabled Whether smart upsells are enabled.
		 */
		$enabled = (bool) apply_filters( 'faracart_upsells_enabled', $enabled );

		if ( ! $enabled ) {
			return array(
				'enabled'       => false,
				'endpoint'      => '',
				'trackEndpoint' => '',
				'limit'         => 0,
				'labels'        => array(),
			);
		}

		return array(
			'enabled'       => true,
			'endpoint'      => esc_url_raw( rest_url( 'faracart/v1/upsell/rank' ) ),
			'trackEndpoint' => esc_url_raw( rest_url( 'faracart/v1/upsell/track' ) ),
			'limit'         => max( 1, min( 6, (int) apply_filters( 'faracart_frontend_upsell_limit', 3 ) ) ),
			'labels'        => array(
				// Unified customer-facing copy: suggestions and upsells are
				// one experience now, so the heading never names the
				// internal strategy.
				'heading'     => __( 'Products suggested for you', 'faracart' ),
				'add'         => __( 'Add to cart', 'faracart' ),
				'adding'      => __( 'Adding…', 'faracart' ),
				'added'       => __( 'Added', 'faracart' ),
				'unavailable' => __( 'No recommendations available right now.', 'faracart' ),
			),
		);
	}

	/**
	 * The resolved floating-widget config for the storefront JS.
	 *
	 * Carries the master toggle, the per-device position (desktop always;
	 * mobile reused from desktop when floating_mobile_use_desktop is on),
	 * the per-device visibility flags and the display options (button
	 * size, animation, custom icon/label). The drawer always opens toward
	 * the screen center from the position preset — no direction setting.
	 * Every value is normalized so a malformed stored setting can never
	 * reach the JS.
	 *
	 * @return array<string, mixed>
	 */
	public function floating_config() {
		$desktop = $this->floating_position( 'desktop' );
		$mobile  = $this->floating_position( 'mobile' );

		return array(
			'enabled'          => (bool) apply_filters( 'faracart_floating_enabled', $this->settings->get( 'floating_enabled', false ) ),
			'desktop'          => $desktop,
			'mobile'           => $mobile,
			'mobileUseDesktop' => (bool) $this->settings->get( 'floating_mobile_use_desktop', true ),
			'showDesktop'      => (bool) $this->settings->get( 'floating_show_desktop', true ),
			'showMobile'       => (bool) $this->settings->get( 'floating_show_mobile', true ),
			'buttonSize'       => min( 96, max( 32, (int) $this->settings->get( 'floating_button_size', 56 ) ) ),
			'animation'        => (bool) $this->settings->get( 'floating_animation', true ),
			'icon'             => trim( sanitize_text_field( (string) $this->settings->get( 'floating_icon', '' ) ) ),
			'label'            => trim( sanitize_text_field( (string) $this->settings->get( 'floating_label', '' ) ) ),
			'labels'           => array(
				'open'   => __( 'View your cart missions', 'faracart' ),
				'close'  => __( 'Close', 'faracart' ),
			),
		);
	}

	/**
	 * One floating-widget position object (desktop or mobile), normalized.
	 *
	 * The preset is the only position control; unknown presets fall back to
	 * the default and offsets are clamped so a malformed stored value can
	 * never reach the JS.
	 *
	 * @param string $scope 'desktop' | 'mobile'.
	 * @return array<string, string|int>
	 */
	public function floating_position( $scope ) {
		$defaults = $this->settings->defaults();
		$key      = 'floating_' . ( 'mobile' === $scope ? 'mobile' : 'desktop' );
		$default  = isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ? $defaults[ $key ] : array();
		$stored   = $this->settings->get( $key, array() );
		$stored   = is_array( $stored ) ? $stored : array();

		$presets = array( 'top-left', 'top-right', 'center-left', 'center-right', 'bottom-left', 'bottom-right' );
		$preset  = isset( $stored['preset'] ) && in_array( $stored['preset'], $presets, true )
			? $stored['preset']
			: ( $default['preset'] ?? 'bottom-right' );

		return array(
			'preset'   => $preset,
			'offset_x' => isset( $stored['offset_x'] ) ? min( 200, max( 0, (int) $stored['offset_x'] ) ) : (int) ( $default['offset_x'] ?? 20 ),
			'offset_y' => isset( $stored['offset_y'] ) ? min( 200, max( 0, (int) $stored['offset_y'] ) ) : (int) ( $default['offset_y'] ?? 80 ),
		);
	}

	/**
	 * The storefront mobile behavior.
	 *
	 * Settings → Frontend: show | hide — when 'hide', the JS
	 * skips rendering widgets on small screens. Filterable via
	 * faracart_frontend_mobile.
	 *
	 * @return string
	 */
	public function mobile_behavior() {
		$behavior = apply_filters( 'faracart_frontend_mobile', $this->settings->get( 'frontend_mobile', 'show' ) );

		return 'hide' === $behavior ? 'hide' : 'show';
	}

	/**
	 * The active storefront template variant.
	 *
	 * Settings-driven, overridable with the `faracart_frontend_template`
	 * filter, and normalized to the template enum so a bad stored value
	 * can never reach the JS.
	 *
	 * @return string
	 */
	public function template() {
		$template = apply_filters( 'faracart_frontend_template', $this->settings->get( 'frontend_template', 'template-1' ) );

		if ( in_array( $template, self::TEMPLATES, true ) ) {
			return $template;
		}

		// Unregistered / retired ids (e.g. a stored 'card') are never
		// mapped — fall back to the default template.
		return 'template-1';
	}

	/**
	 * The resolved appearance tokens (colors, radius, bar height).
	 *
	 * Every value is normalized (hex colors fall back to their defaults)
	 * so the inline style output and the JS config always carry safe,
	 * well-formed CSS values.
	 *
	 * @return array<string, string|int>
	 */
	public function appearance() {
		$defaults = $this->settings->defaults();
		$colors   = array( 'frontend_accent', 'frontend_bg', 'frontend_border', 'frontend_text' );

		$appearance = array();

		foreach ( $colors as $key ) {
			$color = sanitize_hex_color( $this->settings->get( $key ) );
			$appearance[ str_replace( 'frontend_', '', $key ) ] = $color ? $color : $defaults[ $key ];
		}

		$appearance['radius']    = min( 40, max( 0, (int) $this->settings->get( 'frontend_radius', 10 ) ) );
		$appearance['barHeight'] = min( 48, max( 4, (int) $this->settings->get( 'frontend_bar_height', 10 ) ) );

		return $appearance;
	}

	/**
	 * The inline stylesheet for the storefront widgets.
	 *
	 * A small token block overriding the CSS custom properties with the
	 * resolved appearance (the stylesheet reads `var(--faracart-*)`), plus
	 * the admin-authored custom CSS appended verbatim. Injected through
	 * wp_add_inline_style so it is versioned and scoped with the main
	 * stylesheet.
	 *
	 * @return string
	 */
	public function appearance_css() {
		$a = $this->appearance();

		$css = sprintf(
			'.faracart-widget, #faracart-floating { --faracart-accent:%1$s; --faracart-bg:%2$s; --faracart-border:%3$s; --faracart-text:%4$s; --faracart-radius:%5$dpx; --faracart-bar-height:%6$dpx; }',
			$a['accent'],
			$a['bg'],
			$a['border'],
			$a['text'],
			(int) $a['radius'],
			(int) $a['barHeight']
		);

		$custom = trim( (string) $this->settings->get( 'frontend_custom_css', '' ) );

		if ( '' !== $custom ) {
			$css .= "\n" . $custom;
		}

		return $css;
	}

	/**
	 * Shortcode callback: `[faracart_progress variant="full|compact"]`.
	 *
	 * Every instance renders its own uniquely-id'd container so a page can
	 * host several widgets without collisions. The optional `template`
	 * attribute overrides the global Appearance template per widget.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		if ( is_admin() || ! $this->is_enabled() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'variant'  => 'full',
				'template' => '',
			),
			(array) $atts,
			self::SHORTCODE
		);

		$this->shortcode_index++;

		return $this->widget_container( 'faracart-shortcode-' . $this->shortcode_index, $atts['variant'], $atts['template'] );
	}

	/**
	 * Render the cart-page widget (full variant).
	 *
	 * @return void
	 */
	public function render_cart_widget() {
		$this->render_widget( 'cart', 'full', 'top' );
	}

	/** Render the cart-page widget at the bottom boundary. */
	public function render_cart_widget_bottom() {
		$this->render_widget( 'cart', 'full', 'bottom' );
	}

	/**
	 * Render the mini-cart widget (compact variant).
	 *
	 * Fires inside the mini-cart fragment; the JS re-mounts the container
	 * after each `wc_fragments_refreshed` event.
	 *
	 * @return void
	 */
	public function render_mini_cart_widget_top() {
		$this->render_widget( 'mini-cart', 'compact', 'top' );
	}

	public function render_mini_cart_widget() {
		$this->render_widget( 'mini-cart', 'compact', 'bottom' );
	}

	/**
	 * Render the checkout widget (full variant).
	 *
	 * @return void
	 */
	public function render_checkout_widget() {
		$this->render_widget( 'checkout', 'full', 'top' );
	}

	/** Render the checkout widget at the bottom boundary. */
	public function render_checkout_widget_bottom() {
		$this->render_widget( 'checkout', 'full', 'bottom' );
	}

	/**
	 * Render the shop/archive widget (compact variant).
	 *
	 * @return void
	 */
	public function render_shop_widget() {
		$this->render_widget( 'shop', 'compact', 'top' );
	}

	/** Render the shop/archive widget at the bottom boundary. */
	public function render_shop_widget_bottom() {
		$this->render_widget( 'shop', 'compact', 'bottom' );
	}

	/**
	 * Render the single-product widget (compact variant).
	 *
	 * Priority 45 lands it just below the add-to-cart button (30) and meta
	 * (40), the conventional spot for a cart-mission nudge.
	 *
	 * @return void
	 */
	public function render_product_widget() {
		$this->render_widget( 'product', 'compact', 'top' );
	}

	/** Render the product widget at the bottom boundary. */
	public function render_product_widget_bottom() {
		$this->render_widget( 'product', 'compact', 'bottom' );
	}

	/**
	 * Render the floating missions/campaigns button container.
	 *
	 * Gated on the floating_enabled setting (and the master enabled
	 * toggle + widget pages like every other widget). The container is
	 * inert markup — the JS builds the button + drawer and keeps it hidden
	 * until the cart has an eligible mission to show.
	 *
	 * @return void
	 */
	public function render_floating_button() {
		if ( is_admin() || ! $this->is_enabled() || ! $this->page_needs_widget() ) {
			return;
		}

		if ( ! (bool) apply_filters( 'faracart_floating_enabled', $this->settings->get( 'floating_enabled', false ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		echo '<div id="faracart-floating" class="faracart-floating" aria-hidden="true"></div>';
	}

	/**
	 * Build an empty widget container.
	 *
	 * The container carries `data-faracart-widget` (JS mount marker), a
	 * variant flag the JS uses to decide which components to render
	 * (full = progress + milestones + reward + suggestions, compact =
	 * progress + message + reward chip), and — when a per-widget template
	 * override exists — a `data-faracart-template` marker. The configured
	 * custom CSS class is appended to the container so theme authors can
	 * target it. Output is `esc_html`'d attribute values over static
	 * strings.
	 *
	 * @param string $id       Unique container id.
	 * @param string $variant  full|compact.
	 * @param string $template Optional template override (normalized).
	 * @return string
	 */
	public function widget_container( $id, $variant = 'full', $template = '' ) {
		$variant = 'compact' === $variant ? 'compact' : 'full';

		$class = 'faracart-widget faracart-widget--' . esc_attr( $variant );
		$extra = trim( (string) $this->settings->get( 'frontend_css_class', '' ) );

		if ( '' !== $extra ) {
			$class .= ' ' . esc_attr( $extra );
		}

		$markup = '<div id="' . esc_attr( $id ) . '" class="' . $class . '"'
			. ' data-faracart-widget data-faracart-variant="' . esc_attr( $variant ) . '"'
			. ' role="status" aria-live="polite"></div>';

		if ( in_array( $template, self::TEMPLATES, true ) ) {
			$markup = str_replace(
				' data-faracart-widget',
				' data-faracart-widget data-faracart-template="' . esc_attr( $template ) . '"',
				$markup
			);
		}

		return $markup;
	}

	/**
	 * Place the progress widget around WooCommerce Cart/Checkout blocks.
	 *
	 * WooCommerce Compatibility: the classic template actions
	 * (woocommerce_before_cart, woocommerce_before_checkout_form,
	 * woocommerce_after_mini_cart) never fire on pages rendered from the
	 * WooCommerce Blocks Cart/Checkout, so this `render_block` filter is the
	 * supported public hook that covers block-based storefronts. The
	 * duplicate-render registry keeps the widget at most once per location,
	 * so a classic + block hybrid page can never show it twice.
	 *
	 * The Mini Cart block gets a compact widget (the header toggle has no
	 * room for a full widget); the Cart and Checkout blocks get the full
	 * widget, matching the classic locations' variants.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         Block data (blockName, attrs).
	 * @return string
	 */
	public function render_block_widget( $block_content, $block ) {
		if ( is_admin() || ! $this->is_enabled() ) {
			return $block_content;
		}

		if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
			return $block_content;
		}

		$block_name = (string) $block['blockName'];
		$location   = '';
		$variant    = 'full';

		if ( 'woocommerce/cart' === $block_name ) {
			$location = 'cart';
		} elseif ( 'woocommerce/checkout' === $block_name ) {
			$location = 'checkout';
		} elseif ( 'woocommerce/mini-cart' === $block_name ) {
			$location = 'mini-cart';
			$variant  = 'compact';
		}

		if ( '' === $location || ! in_array( $location, $this->locations(), true ) ) {
			return $block_content;
		}

		// Duplicate-render guard shared with the classic actions.
		if ( isset( $this->rendered[ $location ] ) ) {
			return $block_content;
		}
		$this->rendered[ $location ] = true;

		// phpcs:ignore WordPress.Security.EscapeOutput -- widget_container escapes its own attributes.
		$widget = $this->widget_container( 'faracart-' . $location, $variant );

		return 'top' === $this->position() ? $widget . $block_content : $block_content . $widget;
	}

	/**
	 * Render a display-location widget (duplicate-guarded).
	 *
	 * Each location renders at most once per request; the JS mount guard
	 * additionally prevents double mounting on fragment refreshes.
	 *
	 * @param string      $location Location key.
	 * @param string      $variant  full|compact.
	 * @param string|null $position Required page boundary, or null for either.
	 * @return void
	 */
	protected function render_widget( $location, $variant, $position = null ) {
		if ( is_admin() || ! $this->is_enabled() ) {
			return;
		}

		if ( null !== $position && $position !== $this->position() ) {
			return;
		}

		if ( ! in_array( $location, $this->locations(), true ) ) {
			return;
		}

		// Duplicate-render guard: never inject the same location twice.
		if ( isset( $this->rendered[ $location ] ) ) {
			return;
		}
		$this->rendered[ $location ] = true;

		// phpcs:ignore WordPress.Security.EscapeOutput -- widget_container escapes its own attributes.
		echo $this->widget_container( 'faracart-' . $location, $variant );
	}

	/**
	 * Whether the current page can render a widget.
	 *
	 * Cart/checkout/shop/product pages, plus any page whose content
	 * contains the shortcode (works for Gutenberg shortcode blocks too —
	 * they keep the raw tag in the post content).
	 *
	 * @return bool
	 */
	protected function page_needs_widget() {
		if ( ! function_exists( 'is_cart' ) ) {
			return false; // WooCommerce not active.
		}

		if ( is_cart() || is_checkout() || is_shop() || is_product() ) {
			return true;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		// Direct shortcode in the content, or a Gutenberg page that embeds
		// the faracart/progress block  — the block renders
		// server-side, so the storefront assets must load on the page.
		return has_shortcode( $post->post_content, self::SHORTCODE )
			|| ( function_exists( 'has_block' ) && has_block( self::BLOCK, $post ) );
	}

	/**
	 * Localized reward type labels for the JS reward status component.
	 *
	 * adds the countdown and gift-picker strings.
	 *
	 * @return array<string, string>
	 */
	protected function reward_labels() {
		return array(
			'free_shipping'    => __( 'Free shipping', 'faracart' ),
			'percent_discount' => __( 'Percentage discount', 'faracart' ),
			'fixed_discount'   => __( 'Fixed discount', 'faracart' ),
			'free_gift'        => __( 'Free gift', 'faracart' ),
			'coupon'           => __( 'Coupon', 'faracart' ),
			'countdown'        => __( 'Ends in', 'faracart' ),
			'countdown_ended'  => __( 'Ended', 'faracart' ),
			'gift_picker'      => __( 'Pick your free gift', 'faracart' ),
			'gift_chosen'      => __( 'Gift added to your cart', 'faracart' ),
			// Design-template storefront copy (the six progress templates).
			'shopping_mission'    => __( 'Shopping mission', 'faracart' ),
			'progress'         => __( 'Progress', 'faracart' ),
			'paid'             => __( 'Paid', 'faracart' ),
			'remaining'        => __( 'Remaining', 'faracart' ),
			'left'             => __( '%s left', 'faracart' ),
			'add'              => __( 'Add', 'faracart' ),
			'add_more'         => __( 'Add %s more', 'faracart' ),
			'view_products'    => __( 'View products', 'faracart' ),
			'only_price'       => __( 'Only %s', 'faracart' ),
			'recommend_heading' => __( 'Add these products to reach your mission faster:', 'faracart' ),
			'completed'        => __( 'Completed', 'faracart' ),
			'mission_reached'     => __( 'Mission completed', 'faracart' ),
			'reward_active'    => __( '%s is active', 'faracart' ),
			'expired'          => __( 'Expired', 'faracart' ),
			'mission_ended'       => __( 'This mission has ended', 'faracart' ),
			'almost_done'      => __( 'Almost there!', 'faracart' ),
			'congrats'         => __( 'Congratulations!', 'faracart' ),
			'with_purchase'    => __( 'With a purchase of', 'faracart' ),
			'finish_today'     => __( 'Finish today — your reward is waiting', 'faracart' ),
			'unavailable'      => __( 'No recommendations available right now.', 'faracart' ),
		);
	}
}
