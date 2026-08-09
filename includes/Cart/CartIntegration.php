<?php
/**
 * Cart integration service for the Goal Cart engine.
 *
 * @package GoalCart
 */

namespace GoalCart\Cart;

use GoalCart\Goals\CartContext;
use GoalCart\Hooks\HookManager;
use GoalCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class CartIntegration
 *
 * The single, request-level source of truth for the current cart snapshot
 * (P06-T01). It converts the live WooCommerce cart into the normalized
 * CartContext the goal engine consumes, memoizes the result per cart
 * contents + args so repeated builds (several totals passes per request,
 * REST reads, frontend refreshes) never repeat work, and listens to the
 * cart lifecycle hooks (P06-T02) so the cache is invalidated the moment
 * the shopper changes anything.
 *
 * Cart Context (P06-T03): the memoized CartContext carries only the
 * numbers the Goal Engine needs, with product categories preloaded in a
 * single batched query (variations resolved from their parent product,
 * the WooCommerce convention) so no per-item term queries run.
 *
 * Performance (P06-T04):
 *  - request-level memoization keyed by the shopper-controlled line data
 *  - product categories preloaded with one wp_get_object_terms() call per
 *    build; WP core caches object-term relations, so later builds are cheap
 *  - the GoalRepository and GoalEngine stay per-request cached
 *
 * WooCommerce Blocks: Store API cart mutations funnel through the classic
 * WC_Cart methods (add_to_cart, remove_cart_item, set_quantity,
 * apply_coupon, remove_coupon), so the classic invalidation hooks below
 * cover Blocks too; the Store API shipping-rate route is hooked explicitly.
 */
final class CartIntegration {

	/**
	 * Settings instance (Phase 18: the Goal Calculation toggles).
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
	 * Build (and cache) the goal-engine context for the live cart.
	 *
	 * Memoized per cart contents + args for the duration of the request; it
	 * is rebuilt automatically when the cart changes (the cache key embeds
	 * the line data, so a totals pass that updates line values also changes
	 * the key) or when a lifecycle hook invalidates it.
	 *
	 * Timing note: the snapshot reflects the cart's line data at build time.
	 * The goal bases are line-derived, so they stay correct while WC has
	 * reset the aggregate totals; the cart-level aggregates (taxes,
	 * shipping) are captured when readable and may be zeroed mid-calculation
	 * (see the timing note in CartContext::from_cart).
	 *
	 * @param \WC_Cart|null          $cart Live cart; falls back to WC()->cart
	 *                                     and yields an empty context when
	 *                                     WooCommerce is not available.
	 * @param array<string, mixed>   $args CartContext::from_cart() args
	 *                                     (currency, user_id, is_guest,
	 *                                     exclude_shipping).
	 * @return CartContext
	 */
	public function context( ?\WC_Cart $cart = null, array $args = array() ) {
		if ( null === $cart ) {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				// No cart available: serve (and memoize) an empty snapshot so
				// every caller in the request sees the same instance.
				if ( ! isset( $this->cache['empty'] ) ) {
					$this->cache['empty'] = new CartContext();
				}

				return $this->cache['empty'];
			}

			$cart = WC()->cart;
		}

		$key = $this->cache_key( $cart, $args );

		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		// Preload categories once per build (one batched query) and hand
		// them to from_cart so no per-item term queries run. Phase 18: the
		// Goal Calculation settings refine the snapshot (tax / discount /
		// shipping / sale / virtual inclusion), unless the caller passed
		// explicit overrides.
		$args['categories'] = $this->load_categories( $cart );
		$args               = $this->calculation_args( $args );

		$context = CartContext::from_cart( $cart, $args );

		$this->cache[ $key ] = $context;

		return $context;
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
	 * Merge the Goal Calculation settings into the from_cart args.
	 *
	 * Explicit caller args always win; otherwise the Phase 18 settings
	 * apply (each default preserves the pre-Phase-18 behavior).
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
	 * rebuild. The preloaded category map is excluded — it is derived from
	 * the same line data.
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

		unset( $args['categories'] );

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
}
