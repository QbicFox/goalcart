<?php
/**
 * Storefront progress UI for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Frontend;

use GoalCart\Hooks\HookManager;
use GoalCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProgressUI
 *
 * Phase 11 (Frontend Progress UI) — the customer-facing widget layer. It
 * renders empty widget containers at the display locations and lets the
 * vanilla JS library (`assets/js/frontend.js`, no build step — mirroring
 * the reference plugin's frontend pattern) fetch `/goalcart/v1/progress`
 * and fill them in, so the server never renders progress markup directly
 * and cart changes update the UI through WooCommerce's own JS events.
 *
 * Display locations (P11: Cart, Mini Cart, Checkout, Shop, Product page,
 * configurable widget/shortcode, sticky bar):
 *
 *  - `woocommerce_before_cart`             → full widget on the cart page
 *  - `woocommerce_after_mini_cart`         → compact widget in the mini cart
 *  - `woocommerce_before_checkout_form`    → full widget on checkout
 *  - `woocommerce_archive_description`     → compact widget on shop/archives
 *  - `woocommerce_single_product_summary`  → compact widget on product pages
 *  - `[goalcart_progress]` shortcode       → widget anywhere (full/compact)
 *  - `wp_footer`                           → sticky bottom progress bar
 *
 * Duplicate-render guard (P11): each location renders at most once per
 * request (a rendered-location registry), every container has a unique id,
 * and the JS mounts each container exactly once (`data-goalcart-widget`),
 * so a page can never end up with two widgets in the same spot.
 *
 * Master toggle: the `enabled` setting gates everything, overridable with
 * the `goalcart_frontend_enabled` filter. The per-location set is
 * filterable via `goalcart_frontend_locations` (Phase 18 wires the
 * frontend settings to it). Logged-in site admins browsing the
 * storefront do not see the shopper-facing widgets by default
 * (`is_visible_to_user()`, filterable via `goalcart_frontend_visible_to_user`).
 */
final class ProgressUI {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE = 'goalcart_progress';

	/**
	 * Gutenberg block name rendered server-side (Phase 21).
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
	const BLOCK = 'goalcart/progress';

	/**
	 * Asset handle for the frontend JS/CSS.
	 *
	 * @var string
	 */
	const HANDLE = 'goalcart-frontend';

	/**
	 * Storefront progress template variants (Phase 12).
	 *
	 * @var string[]
	 */
	const TEMPLATES = array( 'basic', 'percentage', 'milestone', 'card' );

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
		// register_block_type (Phase 21).
		$hooks->add_action( 'init', array( $this, 'register_shortcode' ) );
		$hooks->add_action( 'init', array( $this, 'register_block' ) );

		// Assets only load on pages that can actually show a widget.
		$hooks->add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Config printed early in the footer (priority 5) so the footer
		// script — enqueued with in_footer — finds `window.goalcartFrontend`
		// ready (reference plugin's config-before-script convention).
		$hooks->add_action( 'wp_footer', array( $this, 'print_config' ), 5 );

		// Display locations (each guarded against double injection).
		$hooks->add_action( 'woocommerce_before_cart', array( $this, 'render_cart_widget' ) );
		$hooks->add_action( 'woocommerce_after_mini_cart', array( $this, 'render_mini_cart_widget' ) );
		$hooks->add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_widget' ) );
		$hooks->add_action( 'woocommerce_archive_description', array( $this, 'render_shop_widget' ) );
		$hooks->add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_widget' ), 45 );

		// WooCommerce Blocks (Phase 19): the classic WooCommerce actions
		// (woocommerce_before_cart, woocommerce_before_checkout_form) only
		// fire on the classic templates, so a store running the Cart or
		// Checkout block never triggers them. The render_block filter is a
		// public WordPress API: append the full widget after the Cart and
		// Checkout blocks. The duplicate-render registry already guarantees
		// at most one widget per location, so a page can never show it twice.
		$hooks->add_filter( 'render_block', array( $this, 'render_block_widget' ), 10, 2 );

		// Sticky bottom bar (rendered after the footer, on widget pages).
		$hooks->add_action( 'wp_footer', array( $this, 'render_sticky_bar' ), 20 );
	}

	/**
	 * Register the `[goalcart_progress]` shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Register the Gutenberg `goalcart/progress` block (Phase 21).
	 *
	 * Server-rendered via render_block_type(): the Block Editor inserts a
	 * goalcart/progress block and the render callback below outputs the
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
				'title'           => __( 'Goal Cart Progress', 'goalcart' ),
				'category'        => 'widgets',
				'icon'            => 'cart',
				'description'     => __( 'Show cart goals, progress and rewards (Goal Cart).', 'goalcart' ),
				'keywords'        => array( 'goal', 'cart', 'progress', 'aov' ),
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
	 * Render the `goalcart/progress` block (Phase 21).
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

		return $this->widget_container( 'goalcart-block-' . $this->shortcode_index, $attributes['variant'], $attributes['template'] );
	}

	/**
	 * Whether the frontend progress UI is enabled for the current visitor.
	 *
	 * Master toggle = the `enabled` setting, overridable with the
	 * `goalcart_frontend_enabled` filter (reference `is_tracking_allowed()`
	 * convention). A logged-in site admin browsing the storefront is
	 * additionally hidden from the shopper-facing widgets by default —
	 * see `is_visible_to_user()`.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$enabled = (bool) apply_filters( 'goalcart_frontend_enabled', $this->settings->get( 'enabled', true ) );

		return $enabled && $this->is_visible_to_user();
	}

	/**
	 * Whether the current user should see the storefront progress widgets.
	 *
	 * Shopper-facing widgets are hidden from logged-in site admins by
	 * default so staff browsing or testing the storefront never see the
	 * customer funnel (progress bars, rewards, suggestions, sticky bar).
	 * "Admin" is the same capability the admin menu uses
	 * (`goalcart_admin_capability` filter, default `manage_options`), so
	 * every user who can administer the plugin is treated as staff. The
	 * whole decision is filterable with `goalcart_frontend_visible_to_user`
	 * (e.g. hide for every logged-in user, or for shop managers too).
	 *
	 * @return bool
	 */
	public function is_visible_to_user() {
		$capability = (string) apply_filters( 'goalcart_admin_capability', 'manage_options' );
		$visible    = ! ( is_user_logged_in() && current_user_can( $capability ) );

		return (bool) apply_filters( 'goalcart_frontend_visible_to_user', $visible );
	}

	/**
	 * The enabled display locations.
	 *
	 * Phase 18 (Settings → Frontend): driven by the `frontend_locations`
	 * setting (the default set ships all six locations), still filterable
	 * via goalcart_frontend_locations. Unknown keys are dropped so a bad
	 * stored value can never register a location.
	 *
	 * @return string[]
	 */
	public function locations() {
		$allowed = array( 'cart', 'mini-cart', 'checkout', 'shop', 'product', 'sticky' );
		$stored  = array_map( 'strval', (array) $this->settings->get( 'frontend_locations', array() ) );

		$locations = array_values( array_intersect( $allowed, $stored ) );

		return (array) apply_filters( 'goalcart_frontend_locations', $locations );
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
			GOALCART_URL . 'assets/css/frontend.css',
			array(),
			$this->asset_version( 'assets/css/frontend.css' )
		);

		wp_enqueue_script(
			self::HANDLE,
			GOALCART_URL . 'assets/js/frontend.js',
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
	 * The static GOALCART_VERSION only changes between releases, so the
	 * storefront would keep serving stale cached JS/CSS after every edit
	 * in between. filemtime() gives each file change a fresh version —
	 * the standard WP pattern for versioned static assets — and falls
	 * back to GOALCART_VERSION when the file cannot be stat'd.
	 *
	 * @param string $relative Asset path relative to the plugin root.
	 * @return string
	 */
	protected function asset_version( $relative ) {
		// @phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- stat
		// failure is handled by the fallback below; silence keeps the log clean.
		$mtime = @filemtime( GOALCART_PATH . $relative );

		return false === $mtime ? GOALCART_VERSION : (string) $mtime;
	}

	/**
	 * Print the frontend config object for the JS library.
	 *
	 * Mirrors the reference plugin: a single inline script tag
	 * (`window.goalcartFrontend`) printed early in `wp_footer`, consumed by
	 * vanilla JS with a must-never-throw contract.
	 *
	 * @return void
	 */
	public function print_config() {
		if ( is_admin() || ! $this->is_enabled() || ! $this->page_needs_widget() ) {
			return;
		}

		wp_print_inline_script_tag(
			'window.goalcartFrontend = ' . wp_json_encode( $this->frontend_config() ) . ';',
			array( 'id' => 'goalcart-frontend-config', 'type' => 'text/javascript' )
		);
	}

	/**
	 * The frontend config payload (endpoint, labels, page metadata).
	 *
	 * Phase 12 adds the active template, the animation flag and the
	 * resolved appearance tokens so the JS can render template variants
	 * and mirror the Appearance settings without another round-trip.
	 * Phase 18 adds the currency display style and the mobile behavior so
	 * the JS formats money and hides widgets per the Settings page.
	 * Kept as its own method so tests can assert the shape without
	 * capturing output.
	 *
	 * @return array<string, mixed>
	 */
	public function frontend_config() {
		$appearance = $this->appearance();

		return array(
			'endpoint'  => esc_url_raw( rest_url( 'goalcart/v1/progress' ) ),
			'refresh'   => (int) apply_filters( 'goalcart_frontend_refresh_interval', 0 ),
			'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			// Phase 27 (Internationalization): the site locale reaches the
			// JS so Intl.NumberFormat renders locale-aware digits and
			// grouping (e.g. Persian digits for fa_IR) instead of the
			// browser's default locale.
			'locale'    => get_locale(),
			'isRtl'     => is_rtl(),
			'template'  => $this->template(),
			'animation' => (bool) apply_filters( 'goalcart_frontend_animation', $this->settings->get( 'frontend_animation', true ) ),
			'currencyDisplay' => $this->currency_display(),
			'mobile'    => $this->mobile_behavior(),
			'appearance' => $appearance,
			'labels'    => $this->reward_labels(),
		);
	}

	/**
	 * The storefront currency display style.
	 *
	 * Phase 18 (Settings → General): symbol | code | name — passed to the
	 * JS so Intl.NumberFormat renders the store currency the configured
	 * way. Filterable via goalcart_currency_display.
	 *
	 * @return string
	 */
	public function currency_display() {
		$display = apply_filters( 'goalcart_currency_display', $this->settings->get( 'currency_display', 'symbol' ) );

		return in_array( $display, array( 'symbol', 'code', 'name' ), true ) ? $display : 'symbol';
	}

	/**
	 * The storefront mobile behavior.
	 *
	 * Phase 18 (Settings → Frontend): show | hide — when 'hide', the JS
	 * skips rendering widgets on small screens. Filterable via
	 * goalcart_frontend_mobile.
	 *
	 * @return string
	 */
	public function mobile_behavior() {
		$behavior = apply_filters( 'goalcart_frontend_mobile', $this->settings->get( 'frontend_mobile', 'show' ) );

		return 'hide' === $behavior ? 'hide' : 'show';
	}

	/**
	 * The active storefront template variant.
	 *
	 * Settings-driven, overridable with the `goalcart_frontend_template`
	 * filter, and normalized to the template enum so a bad stored value
	 * can never reach the JS.
	 *
	 * @return string
	 */
	public function template() {
		$template = apply_filters( 'goalcart_frontend_template', $this->settings->get( 'frontend_template', 'basic' ) );

		return in_array( $template, self::TEMPLATES, true ) ? $template : 'basic';
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
	 * resolved appearance (the stylesheet reads `var(--goalcart-*)`), plus
	 * the admin-authored custom CSS appended verbatim. Injected through
	 * wp_add_inline_style so it is versioned and scoped with the main
	 * stylesheet.
	 *
	 * @return string
	 */
	public function appearance_css() {
		$a = $this->appearance();

		$css = sprintf(
			'.goalcart-widget, #goalcart-sticky { --goalcart-accent:%1$s; --goalcart-bg:%2$s; --goalcart-border:%3$s; --goalcart-text:%4$s; --goalcart-radius:%5$dpx; --goalcart-bar-height:%6$dpx; }',
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
	 * Shortcode callback: `[goalcart_progress variant="full|compact"]`.
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

		return $this->widget_container( 'goalcart-shortcode-' . $this->shortcode_index, $atts['variant'], $atts['template'] );
	}

	/**
	 * Render the cart-page widget (full variant).
	 *
	 * @return void
	 */
	public function render_cart_widget() {
		$this->render_widget( 'cart', 'full' );
	}

	/**
	 * Render the mini-cart widget (compact variant).
	 *
	 * Fires inside the mini-cart fragment; the JS re-mounts the container
	 * after each `wc_fragments_refreshed` event.
	 *
	 * @return void
	 */
	public function render_mini_cart_widget() {
		$this->render_widget( 'mini-cart', 'compact' );
	}

	/**
	 * Render the checkout widget (full variant).
	 *
	 * @return void
	 */
	public function render_checkout_widget() {
		$this->render_widget( 'checkout', 'full' );
	}

	/**
	 * Render the shop/archive widget (compact variant).
	 *
	 * @return void
	 */
	public function render_shop_widget() {
		$this->render_widget( 'shop', 'compact' );
	}

	/**
	 * Render the single-product widget (compact variant).
	 *
	 * Priority 45 lands it just below the add-to-cart button (30) and meta
	 * (40), the conventional spot for a cart-goal nudge.
	 *
	 * @return void
	 */
	public function render_product_widget() {
		$this->render_widget( 'product', 'compact' );
	}

	/**
	 * Render the sticky bottom progress bar.
	 *
	 * Gated on the 'sticky' location being enabled (Phase 18: the
	 * frontend_locations setting), so stores can turn the bar off from
	 * Settings. The JS keeps it hidden until the cart has progress to
	 * show; the container itself is inert markup.
	 *
	 * @return void
	 */
	public function render_sticky_bar() {
		if ( is_admin() || ! $this->is_enabled() || ! $this->page_needs_widget() ) {
			return;
		}

		if ( ! in_array( 'sticky', $this->locations(), true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		echo '<div id="goalcart-sticky" class="goalcart-sticky" aria-hidden="true"></div>';
	}

	/**
	 * Build an empty widget container.
	 *
	 * The container carries `data-goalcart-widget` (JS mount marker), a
	 * variant flag the JS uses to decide which components to render
	 * (full = progress + milestones + reward + suggestions, compact =
	 * progress + message + reward chip), and — when a per-widget template
	 * override exists — a `data-goalcart-template` marker. The configured
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

		$class = 'goalcart-widget goalcart-widget--' . esc_attr( $variant );
		$extra = trim( (string) $this->settings->get( 'frontend_css_class', '' ) );

		if ( '' !== $extra ) {
			$class .= ' ' . esc_attr( $extra );
		}

		$markup = '<div id="' . esc_attr( $id ) . '" class="' . $class . '"'
			. ' data-goalcart-widget data-goalcart-variant="' . esc_attr( $variant ) . '"'
			. ' role="status" aria-live="polite"></div>';

		if ( in_array( $template, self::TEMPLATES, true ) ) {
			$markup = str_replace(
				' data-goalcart-widget',
				' data-goalcart-widget data-goalcart-template="' . esc_attr( $template ) . '"',
				$markup
			);
		}

		return $markup;
	}

	/**
	 * Append the full progress widget after WooCommerce Cart/Checkout blocks.
	 *
	 * Phase 19 (WooCommerce Compatibility): the classic template actions
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
		return $block_content . $this->widget_container( 'goalcart-' . $location, $variant );
	}

	/**
	 * Render a display-location widget (duplicate-guarded).
	 *
	 * Each location renders at most once per request; the JS mount guard
	 * additionally prevents double mounting on fragment refreshes.
	 *
	 * @param string $location Location key.
	 * @param string $variant  full|compact.
	 * @return void
	 */
	protected function render_widget( $location, $variant ) {
		if ( is_admin() || ! $this->is_enabled() ) {
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
		echo $this->widget_container( 'goalcart-' . $location, $variant );
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
		// the goalcart/progress block (Phase 21) — the block renders
		// server-side, so the storefront assets must load on the page.
		return has_shortcode( $post->post_content, self::SHORTCODE )
			|| ( function_exists( 'has_block' ) && has_block( self::BLOCK, $post ) );
	}

	/**
	 * Localized reward type labels for the JS reward status component.
	 *
	 * @return array<string, string>
	 */
	protected function reward_labels() {
		return array(
			'free_shipping'    => __( 'Free shipping', 'goalcart' ),
			'percent_discount' => __( 'Percentage discount', 'goalcart' ),
			'fixed_discount'   => __( 'Fixed discount', 'goalcart' ),
			'free_gift'        => __( 'Free gift', 'goalcart' ),
			'coupon'           => __( 'Coupon', 'goalcart' ),
		);
	}
}
