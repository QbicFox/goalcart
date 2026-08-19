<?php
/**
 * Cart integration service for the FaraCart engine.
 *
 * @package FaraCart
 */

namespace FaraCart\Cart;

use FaraCart\Missions\CartContext;
use FaraCart\Hooks\HookManager;
use FaraCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class CartIntegration
 *
 * The single, request-level source of truth for the current cart snapshot
 * (P06-T01). It converts the live WooCommerce cart into the normalized
 * CartContext the mission engine consumes, memoizes the result per cart
 * contents + args so repeated builds (several totals passes per request,
 * REST reads, frontend refreshes) never repeat work, and listens to the
 * cart lifecycle hooks (P06-T02) so the cache is invalidated the moment
 * the shopper changes anything.
 *
 * Cart Context (P06-T03): the memoized CartContext carries only the
 * numbers the Mission Engine needs, with product categories preloaded in a
 * single batched query (variations resolved from their parent product,
 * the WooCommerce convention) so no per-item term queries run.
 *
 * Performance (P06-T04):
 *  - request-level memoization keyed by the shopper-controlled line data
 *  - product categories preloaded with one wp_get_object_terms() call per
 *    build; WP core caches object-term relations, so later builds are cheap
 *  - the MissionRepository and MissionEngine stay per-request cached
 *
 * WooCommerce Blocks: Store API cart mutations funnel through the classic
 * WC_Cart methods (add_to_cart, remove_cart_item, set_quantity,
 * apply_coupon, remove_coupon), so the classic invalidation hooks below
 * cover Blocks too; the Store API shipping-rate route is hooked explicitly.
 */
final class CartIntegration {

	/**
	 * Settings instance (the Mission Calculation toggles).
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Memoized contexts for the current request (cache key => context).
	 *
	 * @var array<string, CartContext>
	 */
	protected $cache = array();

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Settings instance. Optional so plain
	 *                                `new CartIntegration()` callers (tests,
	 *                                headless contexts) keep working with
	 *                                the default calculation behavior.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ? $settings : new Settings();
	}

	/**
	 * Register the cart lifecycle invalidation hooks.
	 *
	 * Every hook that can change the shopper's cart state clears the
	 * memoized context so the next read reflects the new cart.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$invalidate = array( $this, 'invalidate' );

		foreach ( array(
			'woocommerce_cart_loaded_from_session',       // cart init / session restore
			'woocommerce_add_to_cart',                    // add to cart (classic + Blocks)
			'woocommerce_cart_item_removed',              // remove from cart
			'woocommerce_cart_item_restored',             // restore removed item
			'woocommerce_after_cart_item_quantity_update', // quantity changes
			'woocommerce_applied_coupon',                 // coupon applied
			'woocommerce_removed_coupon',                 // coupon removed
			'woocommerce_shipping_method_chosen',         // shipping method changed (classic)
			'woocommerce_checkout_update_order_review',   // checkout AJAX refresh
			'woocommerce_store_api_cart_select_shipping_rate', // Blocks shipping change
		) as $hook ) {
			$hooks->add_action( $hook, $invalidate );
		}
	}

	/**
	 * Build (and cache) the mission-engine context for the live cart.
	 *
	 * Memoized per cart contents + args for the duration of the request; it
	 * is rebuilt automatically when the cart changes (the cache key embeds
	 * the line data, so a totals pass that updates line values also changes
	 * the key) or when a lifecycle hook invalidates it.
	 *
	 * Timing note: the snapshot reflects the cart's line data at build time.
	 * The mission bases are line-derived, so they stay correct while WC has
	 * reset the aggregate totals; the cart-level aggregates (taxes,
	 * shipping) are captured when readable and may be zeroed mid-calculation
	 * (see the timing note in CartContext::from_cart).
	 *
	 * @param \WC_Cart|null          $cart Live cart; falls back to WC()->cart
	 *                                     after safely initializing it for a
	 *                                     REST request; yields an empty context
	 *                                     when WooCommerce is not available.
	 * @param array<string, mixed>   $args CartContext::from_cart() args
	 *                                     (currency, user_id, is_guest,
	 *                                     exclude_shipping).
	 * @return CartContext
	 */
	public function context( ?\WC_Cart $cart = null, array $args = array() ) {
		if ( null === $cart ) {
			$cart = $this->live_cart();

			if ( null === $cart ) {
				// No cart available: serve (and memoize) an empty snapshot so
				// every caller in the request sees the same instance.
				if ( ! isset( $this->cache['empty'] ) ) {
					$this->cache['empty'] = new CartContext();
				}

				return $this->cache['empty'];
			}
		}

		$key = $this->cache_key( $cart, $args );


		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		// Preload categories once per build (one batched query) and hand
		// them to from_cart so no per-item term queries run. // (tag/attribute missions) preloads tags and attribute
		// taxonomies the same way. the Mission Calculation settings
		// refine the snapshot (tax / discount / shipping / sale / virtual
		// inclusion), unless the caller passed explicit overrides.
		$args['categories'] = $this->load_categories( $cart );
		$args['tags']       = $this->load_tags( $cart );
		$args['attributes'] = $this->load_attributes( $cart );
		$args               = $this->calculation_args( $args );

		$context = CartContext::from_cart( $cart, $args );

		$this->cache[ $key ] = $context;

		return $context;
	}

	/**
	 * Return the live WooCommerce cart, loading its session for REST requests.
	 *
	 * WooCommerce initializes carts automatically for frontend and AJAX
	 * requests, but deliberately skips that work for custom REST routes. The
	 * public progress endpoint and the storefront gift endpoint are
	 * therefore allowed to reach this method with a valid customer cart
	 * stored in the WooCommerce session while `WC()->cart` is still null.
	 * Treating that state as an empty cart turns real progress into zero
	 * and breaks gift claiming with a false "cart is empty" error.
	 *
	 * `wc_load_cart()` is idempotent and is only called after WooCommerce has
	 * initialized, only during REST requests, and only when the cart is
	 * missing. Store API requests that already loaded a cart, normal
	 * storefront/AJAX requests, admin screens, cron, CLI, and explicit-cart
	 * callers do not initialize it again.
	 *
	 * @return \WC_Cart|null
	 */
	public function live_cart() {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return null;
		}

		if ( WC()->cart instanceof \WC_Cart ) {
			return WC()->cart;
		}

		$is_rest_request = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$wc_initialized  = function_exists( 'did_action' )
			&& did_action( 'woocommerce_init' );

		if ( $is_rest_request && $wc_initialized && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		return WC()->cart instanceof \WC_Cart ? WC()->cart : null;
	}

	/**
	 * Clear the memoized context (called by every cart lifecycle hook).
	 *
	 * @return void
	 */
	public function invalidate() {
		$this->cache = array();
	}

	/**
	 * Merge the Mission Calculation settings into the from_cart args.
	 *
	 * Explicit caller args always win; otherwise the settings
	 * apply (each default preserves the previous behavior).
	 *
	 * @param array<string, mixed> $args Context args.
	 * @return array<string, mixed>
	 */
	protected function calculation_args( array $args ) {
		$keys = array(
			'include_tax'      => 'calculation_include_tax',
			'include_discount' => 'calculation_include_discount',
			'include_shipping' => 'calculation_include_shipping',
			'include_sale'     => 'calculation_include_sale',
			'include_virtual'  => 'calculation_include_virtual',
		);

		foreach ( $keys as $arg => $setting ) {
			if ( ! array_key_exists( $arg, $args ) ) {
				$args[ $arg ] = (bool) $this->settings->get( $setting, true );
			}
		}

		return $args;
	}

	/**
	 * Cache key for a cart: hash of the shopper-controlled line data + args.
	 *
	 * The line totals are part of the key so a totals pass that updates line
	 * values (coupon discounts) naturally changes the key and forces a
	 * rebuild. The preloaded category/tag/attribute maps are excluded — they
	 * are derived from the same line data.
	 *
	 * @param \WC_Cart             $cart Live cart.
	 * @param array<string, mixed> $args Context args.
	 * @return string
	 */
	protected function cache_key( \WC_Cart $cart, array $args ) {
		$lines = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			$lines[] = array(
				$key,
				isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
				isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				isset( $item['quantity'] ) ? (float) $item['quantity'] : 1.0,
				isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0,
				isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0,
			);
		}

		unset( $args['categories'], $args['tags'], $args['attributes'] );

		return md5( wp_json_encode( $lines ) . '|' . wp_json_encode( $args ) );
	}

	/**
	 * Load product category ids for every line in one batched query.
	 *
	 * Variations resolve to their parent product's categories (the
	 * WooCommerce convention — categories live on the parent), so the map is
	 * keyed by the canonical product id (the cart item's product_id, which
	 * is the parent id for variations). WP core caches the object-term
	 * relations, so repeated builds within a request are cheap.
	 *
	 * @param \WC_Cart $cart Live cart.
	 * @return array<int, int[]> product id => category term ids.
	 */
	protected function load_categories( \WC_Cart $cart ) {
		$product_ids = array();

		foreach ( $cart->get_cart() as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$product_id = $product_id > 0 ? $product_id : ( isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0 );

			if ( $product_id > 0 ) {
				$product_ids[ $product_id ] = true;
			}
		}

		$map = array();

		if ( empty( $product_ids ) ) {
			return $map;
		}

		$terms = function_exists( 'wp_get_object_terms' )
			? wp_get_object_terms( array_keys( $product_ids ), 'product_cat', array( 'fields' => 'all_with_object_id' ) )
			: array();

		if ( is_wp_error( $terms ) ) {
			return $map;
		}

		foreach ( (array) $terms as $term ) {
			$object_id = (int) $term->object_id;

			if ( ! isset( $map[ $object_id ] ) ) {
				$map[ $object_id ] = array();
			}

			$map[ $object_id ][] = (int) $term->term_id;
		}

		return $map;
	}

	/**
	 * Load product tag ids for every line in one batched query.
	 *
	 * Keyed by the canonical product id exactly like load_categories().
	 *
	 * @param \WC_Cart $cart Live cart.
	 * @return array<int, int[]> product id => tag term ids.
	 */
	protected function load_tags( \WC_Cart $cart ) {
		return $this->load_object_terms( $cart, 'product_tag', 'term_id' );
	}

	/**
	 * Load the attribute taxonomies present on every line.
	 *
	 * Reads each product's attributes in one batched product load (no
	 * per-item DB queries). Keyed by the canonical product id (the cart
	 * item's product_id — the parent for variations, where WooCommerce
	 * stores the attributes).
	 *
	 * @param \WC_Cart $cart Live cart.
	 * @return array<int, string[]> product id => attribute taxonomy slugs.
	 */
	protected function load_attributes( \WC_Cart $cart ) {
		$product_ids = array();

		foreach ( $cart->get_cart() as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$product_id = $product_id > 0 ? $product_id : ( isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0 );

			if ( $product_id > 0 ) {
				$product_ids[ $product_id ] = true;
			}
		}

		$map = array();

		if ( empty( $product_ids ) || ! function_exists( 'wc_get_product' ) ) {
			return $map;
		}

		foreach ( array_keys( $product_ids ) as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$taxonomies = array();

			foreach ( $product->get_attributes() as $attribute ) {
				if ( $attribute instanceof \WC_Product_Attribute && $attribute->get_name() ) {
					$taxonomies[] = sanitize_text_field( (string) $attribute->get_name() );
				}
			}

			if ( ! empty( $taxonomies ) ) {
				$map[ $product_id ] = $taxonomies;
			}
		}

		return $map;
	}

	/**
	 * Load object-term relations for a taxonomy in one batched query.
	 *
	 * @param \WC_Cart $cart     Live cart.
	 * @param string   $taxonomy Taxonomy name.
	 * @param string   $field    Term field to collect ('term_id' | 'slug').
	 * @return array<int, array<int|string>> product id => term values.
	 */
	protected function load_object_terms( \WC_Cart $cart, $taxonomy, $field ) {
		$product_ids = array();

		foreach ( $cart->get_cart() as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$product_id = $product_id > 0 ? $product_id : ( isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0 );

			if ( $product_id > 0 ) {
				$product_ids[ $product_id ] = true;
			}
		}

		$map = array();

		if ( empty( $product_ids ) ) {
			return $map;
		}

		$terms = function_exists( 'wp_get_object_terms' )
			? wp_get_object_terms( array_keys( $product_ids ), $taxonomy, array( 'fields' => 'all_with_object_id' ) )
			: array();

		if ( is_wp_error( $terms ) ) {
			return $map;
		}

		foreach ( (array) $terms as $term ) {
			$object_id = (int) $term->object_id;
			$value     = 'slug' === $field ? $term->slug : $term->term_id;

			if ( ! isset( $map[ $object_id ] ) ) {
				$map[ $object_id ] = array();
			}

			$map[ $object_id ][] = $value;
		}

		return $map;
	}
}
