<?php
/**
 * Cart context for the FaraCart engine.
 *
 * @package FaraCart
 */

namespace FaraCart\Missions;

defined( 'ABSPATH' ) || exit;

/**
 * Class CartContext
 *
 * An immutable snapshot of the customer's cart, normalized so every
 * evaluator computes against the same data. It is UI-independent: it can be
 * built from a live WC_Cart (from_cart()) or from plain data in tests and
 * headless contexts, and it carries only the numbers the engine needs.
 *
 * Amount bases (Mission::calculation_mode):
 *  - subtotal            -> line subtotals before cart discounts, no tax/shipping
 *  - discounted_subtotal -> line totals after cart discounts, no tax/shipping
 *  - total               -> grand total the customer pays (after discounts,
 *                           including taxes and shipping)
 */
final class CartContext {

	/**
	 * Prefix of the negative fees the reward engine adds for discounts.
	 *
	 * CartContext excludes these fees from the `total` basis so a reward can
	 * never change the value it was granted on (reward-loop safety, Phase 5).
	 *
	 * @var string
	 */
	const OWN_FEE_PREFIX = 'faracart_reward_';

	/**
	 * Line subtotal before cart discounts, excluding tax.
	 *
	 * @var float
	 */
	protected $subtotal;

	/**
	 * Grand total the customer pays (after discounts, incl. tax and shipping).
	 *
	 * @var float
	 */
	protected $total;

	/**
	 * Sum of cart discounts.
	 *
	 * @var float
	 */
	protected $discount_total;

	/**
	 * Sum of taxes.
	 *
	 * @var float
	 */
	protected $taxes_total;

	/**
	 * Shipping charges (excl. shipping tax).
	 *
	 * @var float
	 */
	protected $shipping_total;

	/**
	 * ISO currency code.
	 *
	 * @var string
	 */
	protected $currency;

	/**
	 * Cart lines.
	 *
	 * @var CartItem[]
	 */
	protected $items;

	/**
	 * Logged-in customer id (0 for guests).
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * @var bool
	 */
	protected $is_guest;

	/**
	 * Coupon codes currently applied to the cart (Phase 32 cart-state
	 * conditions).
	 *
	 * @var string[]
	 */
	protected $coupons;

	/**
	 * The shipping zone id matching the cart destination (Phase 32
	 * shipping-zone missions). 0 when unknown / not calculated.
	 *
	 * @var int
	 */
	protected $shipping_zone_id;

	/**
	 * Phase 18 (Mission Calculation): whether line taxes are folded into the
	 * money bases.
	 *
	 * @var bool
	 */
	protected $include_tax;

	/**
	 * Phase 18 (Mission Calculation): whether cart discounts count toward the
	 * discounted_subtotal basis.
	 *
	 * @var bool
	 */
	protected $include_discount;

	/**
	 * Build a context from a data array.
	 *
	 * @param array<string, mixed> $data Context data. 'items' entries are
	 *                                   CartItem payloads or CartItem objects.
	 */
	public function __construct( array $data = array() ) {
		$this->subtotal       = isset( $data['subtotal'] ) ? (float) $data['subtotal'] : 0.0;
		$this->total          = isset( $data['total'] ) ? (float) $data['total'] : 0.0;
		$this->discount_total = isset( $data['discount_total'] ) ? (float) $data['discount_total'] : 0.0;
		$this->taxes_total    = isset( $data['taxes_total'] ) ? (float) $data['taxes_total'] : 0.0;
		$this->shipping_total = isset( $data['shipping_total'] ) ? (float) $data['shipping_total'] : 0.0;
		$this->currency       = isset( $data['currency'] ) ? (string) $data['currency'] : '';
		$this->user_id        = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		$this->is_guest       = isset( $data['is_guest'] ) ? (bool) $data['is_guest'] : ( 0 === $this->user_id );
		$this->coupons        = isset( $data['coupons'] ) && is_array( $data['coupons'] ) ? array_map( array( __CLASS__, 'clean_text' ), array_map( 'strval', $data['coupons'] ) ) : array();
		$this->shipping_zone_id = isset( $data['shipping_zone_id'] ) ? (int) $data['shipping_zone_id'] : 0;
		$this->include_tax    = ! empty( $data['include_tax'] );
		$this->include_discount = ! isset( $data['include_discount'] ) || (bool) $data['include_discount'];

		$this->items = array();
		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			foreach ( $data['items'] as $item ) {
				$this->items[] = $item instanceof CartItem ? $item : new CartItem( is_array( $item ) ? $item : array() );
			}
		}
	}

	/**
	 * Build a context from a live WooCommerce cart.
	 *
	 * Extracts the normalized snapshot the engine needs. Amount bases use
	 * the 'edit' context so raw floats come back (no display rounding).
	 *
	 * Timing note (Phase 5): the WooCommerce cart integration evaluates
	 * missions on 'woocommerce_before_calculate_totals', which fires AFTER
	 * WC_Cart::reset_totals() has zeroed the cart's aggregate getters, so
	 * get_subtotal()/get_total() read 0 at that point. The money bases are
	 * therefore computed from the cart LINE ITEMS, which always carry their
	 * values (set at add-to-cart time and refreshed by each totals pass).
	 * Post-calculation callers (REST, admin) get the same numbers because
	 * WC's subtotal is the sum of the line subtotals. The grand `total`
	 * falls back to the after-discount line value while totals are reset;
	 * tax is a cart-level refinement for Phase 6 (shipping is excluded
	 * below anyway).
	 *
	 * Reward-loop safety (Phase 5): FaraCart's own discount fees are
	 * subtracted from the `total` basis, and passing `exclude_shipping`
	 * removes shipping from `total` and `shipping_total`, so a reward can
	 * never change the value it was granted on.
	 *
	 * Phase 6 (Cart Context): the optional 'categories' arg carries a
	 * preloaded product-id => category-ids map (built by CartIntegration in
	 * one batched query) so no per-item term queries run. Category lookups
	 * use the canonical product id: for variations that is the parent id
	 * (WooCommerce assigns categories to the parent product), so category
	 * missions count variations correctly.
	 *
	 * Phase 18 (Settings → Mission Calculation): the optional include_*
	 * args refine the snapshot at build time. All five default to today's
	 * behavior, so an unchanged store calculates exactly as before:
	 *
	 *  - include_tax      (false)  — fold line taxes into the subtotal /
	 *                                discounted_subtotal bases
	 *  - include_discount (true)   — when false the discounted_subtotal
	 *                                basis ignores cart discounts
	 *  - include_shipping (true)   — when false shipping is removed from
	 *                                the total basis (the legacy
	 *                                exclude_shipping arg still wins)
	 *  - include_sale     (true)   — when false products currently on sale
	 *                                are dropped from the snapshot
	 *  - include_virtual  (true)   — when false virtual/downloadable
	 *                                products are dropped
	 *
	 * When items are excluded (sale/virtual), the grand `total` is rebased
	 * onto the remaining lines so every money basis describes the same set
	 * of products.
	 *
	 * @param \WC_Cart                $cart Live cart.
	 * @param array<string, mixed>    $args Optional overrides: currency,
	 *                                      user_id, is_guest, exclude_shipping,
	 *                                      categories (preloaded map),
	 *                                      include_tax, include_discount,
	 *                                      include_shipping, include_sale,
	 *                                      include_virtual.
	 * @return CartContext
	 */
	public static function from_cart( \WC_Cart $cart, array $args = array() ) {
		$items           = array();
		$category_map    = isset( $args['categories'] ) && is_array( $args['categories'] ) ? $args['categories'] : array();
		$tags_map        = isset( $args['tags'] ) && is_array( $args['tags'] ) ? $args['tags'] : array();
		$attributes_map  = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : array();
		$include_tax     = ! empty( $args['include_tax'] );
		$include_discount = ! isset( $args['include_discount'] ) || (bool) $args['include_discount'];
		$include_shipping = ! isset( $args['include_shipping'] ) || (bool) $args['include_shipping'];
		$include_sale     = ! isset( $args['include_sale'] ) || (bool) $args['include_sale'];
		$include_virtual  = ! isset( $args['include_virtual'] ) || (bool) $args['include_virtual'];

		$excluded_items = false;

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ? $cart_item['data'] : null;

			if ( null === $product ) {
				continue;
			}

			// Phase 18 exclusion toggles (sale / virtual).
			if (
				( ! $include_sale && method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() )
				|| ( ! $include_virtual && ( $product->is_virtual() || $product->is_downloadable() ) )
			) {
				$excluded_items = true;
				continue;
			}

			// Canonical id for category lookups: the cart item's product_id
			// (the parent for variations, where WC stores the categories).
			$category_product_id = isset( $cart_item['product_id'] ) && (int) $cart_item['product_id'] > 0
				? (int) $cart_item['product_id']
				: (int) $product->get_id();			$categories = isset( $category_map[ $category_product_id ] )
				? array_map( 'intval', (array) $category_map[ $category_product_id ] )
				: ( function_exists( 'wp_get_post_terms' ) ? wp_get_post_terms( $category_product_id, 'product_cat', array( 'fields' => 'ids' ) ) : array() );

			// Phase 32 (brand/tag/attribute missions): tags and attribute
			// taxonomies are preloaded in the same batched way as categories
			// (CartIntegration builds one object-terms query + one product
			// attribute pass), so no per-item queries run on the storefront.
			$tags = isset( $tags_map[ $category_product_id ] )
				? array_map( 'intval', (array) $tags_map[ $category_product_id ] )
				: ( method_exists( $product, 'get_tag_ids' ) ? $product->get_tag_ids() : array() );

			$attributes = isset( $attributes_map[ $category_product_id ] )
				? array_map( array( __CLASS__, 'clean_text' ), array_map( 'strval', (array) $attributes_map[ $category_product_id ] ) )
				: self::product_attributes( $product );

			// The money bases must reflect the line's CURRENT quantity even
			// while WooCommerce is mid-calculation: WC_Cart::set_quantity()
			// updates the line's `quantity` immediately but leaves
			// `line_subtotal`/`line_total` at their previous values until the
			// post-before_calculate_totals totals pass refreshes them. The
			// mission engine evaluates on 'woocommerce_before_calculate_totals'
			// (see the timing note above), so relying on the stored line
			// totals would read the OLD quantity — a cart that just crossed
			// the mission threshold upward would still evaluate below it (gift
			// not granted) and a cart that crossed downward would still
			// evaluate above it (gift not revoked). When the product is
			// available, the line value is therefore recomputed as
			// price × quantity — the same math the totals pass will run —
			// and the stored (possibly stale) value is only trusted when no
			// product price can be read.
			$quantity = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1.0;
			$price    = method_exists( $product, 'get_price' ) ? (float) $product->get_price( 'edit' ) : 0.0;

			if ( $price > 0 ) {
				$line_subtotal = $price * $quantity;
				$line_total    = $line_subtotal;
			} else {
				$line_subtotal = isset( $cart_item['line_subtotal'] ) ? (float) $cart_item['line_subtotal'] : 0.0;
				$line_total    = isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0;
			}

			$items[] = new CartItem(
				array(
					'product_id'    => $product->get_id(),
					'variation_id'  => ! empty( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0,
					'name'          => $product->get_name(),
					'quantity'      => $quantity,
					'line_subtotal' => $line_subtotal,
					'line_total'    => $line_total,
					'line_tax'      => isset( $cart_item['line_tax'] ) ? (float) $cart_item['line_tax'] : 0.0,
					'price'         => $price,
					'weight'        => (float) $product->get_weight(),
					'categories'    => $categories,
					'tags'          => $tags,
					'attributes'    => $attributes,
					'virtual'       => $product->is_virtual(),
					'downloadable'  => $product->is_downloadable(),
				)
			);
		}

		// Money bases come from the line items — always current, including
		// while the cart's aggregate totals are reset mid-calculation (see
		// the timing note above). Phase 18: include_tax folds each line's
		// tax into the bases; include_discount=false makes the
		// discounted_subtotal basis ignore cart discounts.
		$subtotal = 0.0;

		foreach ( $items as $item ) {
			$subtotal += $item->line_subtotal() + ( $include_tax ? $item->line_tax() : 0.0 );
		}

		$after_discount = 0.0;

		if ( $include_discount ) {
			foreach ( $items as $item ) {
				$after_discount += $item->line_total() + ( $include_tax ? $item->line_tax() : 0.0 );
			}
		} else {
			$after_discount = $subtotal;
		}

		$exclude_shipping = ! $include_shipping || ! empty( $args['exclude_shipping'] );

		// Aggregate getters are authoritative after a totals pass; while
		// they read 0 (reset state) the line-derived values are used.
		$total          = (float) $cart->get_total( 'edit' );
		$discount_total = (float) $cart->get_discount_total();
		$taxes_total    = (float) $cart->get_total_tax();
		$shipping_total = (float) $cart->get_shipping_total();

		if ( $total <= 0 && $subtotal > 0 ) {
			$total          = $after_discount;
			$discount_total = max( 0.0, $subtotal - $after_discount );
			$taxes_total    = 0.0;
		}

		// When sale/virtual items were excluded, the cart aggregates still
		// include them — rebase the grand total onto the remaining lines so
		// the total basis matches the filtered subtotal bases.
		if ( $excluded_items ) {
			$total          = $after_discount;
			$discount_total = max( 0.0, $subtotal - $after_discount );
		}

		// Reward-loop safety: our own discount fees are added back so a
		// discount reward can never reduce the `total` basis it was granted on.
		$total = max( 0.0, $total + self::own_fees_total( $cart ) );

		if ( $exclude_shipping ) {
			$total          = max( 0.0, $total - $shipping_total );
			$shipping_total = 0.0;
		}

		return new self(
			array(
				'subtotal'         => $subtotal,
				'total'            => $total,
				'discount_total'   => $discount_total,
				'taxes_total'      => $taxes_total,
				'shipping_total'   => $shipping_total,
				'currency'         => isset( $args['currency'] ) ? (string) $args['currency'] : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ),
				'user_id'          => isset( $args['user_id'] ) ? (int) $args['user_id'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ),
				'is_guest'         => isset( $args['is_guest'] ) ? (bool) $args['is_guest'] : ( function_exists( 'is_user_logged_in' ) ? ! is_user_logged_in() : true ),
				// Phase 32 (advanced conditions): applied coupon codes and the
				// shipping zone matching the destination are part of the
				// snapshot so customer/order/cart/shipping conditions can be
				// evaluated without touching the request state.
				'coupons'          => isset( $args['coupons'] ) && is_array( $args['coupons'] )
					? $args['coupons']
					: ( method_exists( $cart, 'get_applied_coupons' ) ? $cart->get_applied_coupons() : array() ),
				'shipping_zone_id' => isset( $args['shipping_zone_id'] ) ? (int) $args['shipping_zone_id'] : self::resolve_shipping_zone_id(),
				'items'            => $items,
				'include_tax'      => $include_tax,
				'include_discount' => $include_discount,
			)
		);
	}

	/**
	 * The attribute taxonomy slugs present on a product (variations fall
	 * back to their parent's attributes via the canonical id passed in).
	 *
	 * @param \WC_Product $product Product.
	 * @return string[]
	 */
	protected static function product_attributes( \WC_Product $product ) {
		$taxonomies = array();

		if ( ! method_exists( $product, 'get_attributes' ) ) {
			return $taxonomies;
		}

		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && $attribute->get_name() ) {
				$taxonomies[] = self::clean_text( (string) $attribute->get_name() );
			}
		}

		return $taxonomies;
	}

	/**
	 * Sanitize a plain string without hard-depending on WP being loaded.
	 *
	 * The engine value objects run inside WordPress (where
	 * sanitize_text_field exists), but the standalone regression tests
	 * stub a minimal WP surface, so a guarded fallback keeps them
	 * runnable — same output in both paths.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	protected static function clean_text( $value ) {
		$value = (string) $value;

		if ( function_exists( 'sanitize_text_field' ) ) {
			return sanitize_text_field( $value );
		}

		// Minimal WP-free fallback for the standalone tests.
		return trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : $value );
	}

	/**
	 * The shipping zone id matching the customer's cart destination.
	 *
	 * Uses the WooCommerce public shipping-zone API against the customer's
	 * shipping destination; returns 0 when unavailable (no WC, zone 0,
	 * destination unknown).
	 *
	 * @return int
	 */
	protected static function resolve_shipping_zone_id() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->customer ) {
			return 0;
		}

		$customer = WC()->customer;

		// The standalone regression tests stub the customer as a plain
		// object; without the WC getters there is no destination to match.
		if ( ! method_exists( $customer, 'get_shipping_country' ) ) {
			return 0;
		}

		$country  = method_exists( $customer, 'get_shipping_country' ) && $customer->get_shipping_country() ? $customer->get_shipping_country() : ( method_exists( $customer, 'get_billing_country' ) ? $customer->get_billing_country() : '' );
		$state    = method_exists( $customer, 'get_shipping_state' ) && $customer->get_shipping_state() ? $customer->get_shipping_state() : ( method_exists( $customer, 'get_billing_state' ) ? $customer->get_billing_state() : '' );
		$postcode = method_exists( $customer, 'get_shipping_postcode' ) && $customer->get_shipping_postcode() ? $customer->get_shipping_postcode() : ( method_exists( $customer, 'get_billing_postcode' ) ? $customer->get_billing_postcode() : '' );
		$city     = method_exists( $customer, 'get_shipping_city' ) && $customer->get_shipping_city() ? $customer->get_shipping_city() : ( method_exists( $customer, 'get_billing_city' ) ? $customer->get_billing_city() : '' );
		$address  = method_exists( $customer, 'get_shipping_address' ) && $customer->get_shipping_address() ? $customer->get_shipping_address() : ( method_exists( $customer, 'get_billing_address' ) ? $customer->get_billing_address() : '' );
		$address2 = method_exists( $customer, 'get_shipping_address_2' ) && $customer->get_shipping_address_2() ? $customer->get_shipping_address_2() : ( method_exists( $customer, 'get_billing_address_2' ) ? $customer->get_billing_address_2() : '' );

		$package = array(
			'destination' => array(
				'country'   => $country,
				'state'     => $state,
				'postcode'  => $postcode,
				'city'      => $city,
				'address'   => $address,
				'address_2' => $address2,
			),
		);

		if ( class_exists( '\WC_Shipping_Zones' ) && method_exists( '\WC_Shipping_Zones', 'get_zone_matching_package' ) ) {
			$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );

			return $zone ? (int) $zone->get_id() : 0;
		}

		if ( function_exists( 'wc_get_shipping_zone' ) ) {
			$zone = wc_get_shipping_zone();

			return $zone ? (int) $zone->get_id() : 0;
		}

		return 0;
	}

	/**
	 * Sum of FaraCart's own (negative) discount fees in the cart.
	 *
	 * @param \WC_Cart $cart Live cart.
	 * @return float Positive magnitude of the own fees (0 when none).
	 */
	protected static function own_fees_total( \WC_Cart $cart ) {
		$total = 0.0;

		foreach ( $cart->fees_api()->get_fees() as $fee ) {
			if ( isset( $fee->id ) && 0 === strpos( (string) $fee->id, self::OWN_FEE_PREFIX ) ) {
				$total += (float) $fee->amount;
			}
		}

		// Fees are negative (discounts); return the positive magnitude.
		return max( 0.0, -1 * $total );
	}

	/**
	 * All items, optionally excluding product ids.
	 *
	 * @param int[] $exclude Product ids to drop.
	 * @return CartItem[]
	 */
	public function items( array $exclude = array() ) {
		if ( empty( $exclude ) ) {
			return $this->items;
		}

		$exclude = array_flip( array_map( 'intval', $exclude ) );

		return array_values(
			array_filter( $this->items, function ( CartItem $item ) use ( $exclude ) {
				return ! isset( $exclude[ $item->product_id() ] );
			} )
		);
	}

	/**
	 * Cart value for the requested calculation basis.
	 *
	 * When product ids are excluded, item-scoped bases (subtotal,
	 * discounted_subtotal) are recomputed from the remaining lines, and the
	 * grand total is reduced by the excluded lines' after-discount value
	 * (taxes and shipping are cart-level and stay counted).
	 *
	 * @param string $mode    Mission::MODE_*.
	 * @param int[]  $exclude Product ids to drop.
	 * @return float
	 */
	public function amount( $mode = Mission::MODE_SUBTOTAL, array $exclude = array() ) {
		$mode    = (string) $mode;
		$exclude = array_map( 'intval', $exclude );

		if ( Mission::MODE_TOTAL === $mode ) {
			if ( empty( $exclude ) ) {
				return $this->total;
			}

			return $this->total - $this->sum_lines( 'line_total', array_flip( $exclude ) );
		}

		// Phase 18 (Mission Calculation): the include_tax / include_discount
		// flags shape the subtotal-style bases. The precomputed subtotal
		// already carries the folded tax, so the no-exclusion subtotal path
		// returns it directly.
		if ( Mission::MODE_DISCOUNTED_SUBTOTAL === $mode ) {
			$field = $this->include_discount ? 'line_total' : 'line_subtotal';
		} else {
			$field = 'line_subtotal';
		}

		if ( empty( $exclude ) ) {
			if ( Mission::MODE_SUBTOTAL === $mode ) {
				return $this->subtotal;
			}

			$sum = $this->sum_lines( $field );

			if ( $this->include_tax ) {
				$sum += $this->sum_tax();
			}

			return $sum;
		}

		$flip = array_flip( $exclude );

		$sum = $this->sum_lines( $field, $flip, true );

		if ( $this->include_tax ) {
			$sum += $this->sum_tax( $flip, true );
		}

		return $sum;
	}

	/**
	 * Sum a per-line amount over cart lines.
	 *
	 * @param string  $field       'line_subtotal' | 'line_total'.
	 * @param int[]   $only        Flipped product-id set to INCLUDE (exclusion mode).
	 * @param bool    $excluding   When true, $only lists ids to exclude instead.
	 * @return float
	 */
	protected function sum_lines( $field, array $only = array(), $excluding = false ) {
		$sum = 0.0;

		foreach ( $this->items as $item ) {
			$included = empty( $only ) ? true : isset( $only[ $item->product_id() ] );

			if ( $excluding ) {
				$included = ! $included;
			}

			if ( $included ) {
				$sum += 'line_total' === $field ? $item->line_total() : $item->line_subtotal();
			}
		}

		return $sum;
	}

	/**
	 * Sum line taxes over cart lines (Phase 18: include_tax).
	 *
	 * @param int[] $only      Flipped product-id set to INCLUDE (exclusion mode).
	 * @param bool  $excluding When true, $only lists ids to exclude instead.
	 * @return float
	 */
	protected function sum_tax( array $only = array(), $excluding = false ) {
		$sum = 0.0;

		foreach ( $this->items as $item ) {
			$included = empty( $only ) ? true : isset( $only[ $item->product_id() ] );

			if ( $excluding ) {
				$included = ! $included;
			}

			if ( $included ) {
				$sum += $item->line_tax();
			}
		}

		return $sum;
	}

	/**
	 * Total item quantity (decimal-aware).
	 *
	 * @param int[] $exclude Product ids to drop.
	 * @return float
	 */
	public function total_quantity( array $exclude = array() ) {
		$sum = 0.0;
		foreach ( $this->items( $exclude ) as $item ) {
			$sum += $item->quantity();
		}

		return $sum;
	}

	/**
	 * Number of unique products (or variations) in the cart.
	 *
	 * @param int[] $exclude Product ids to drop.
	 * @return int
	 */
	public function distinct_product_count( array $exclude = array() ) {
		$seen = array();
		foreach ( $this->items( $exclude ) as $item ) {
			$seen[ $item->effective_product_id() ] = true;
		}

		return count( $seen );
	}

	/**
	 * Total cart weight (sum of quantity x unit weight).
	 *
	 * @param int[] $exclude Product ids to drop.
	 * @return float
	 */
	public function total_weight( array $exclude = array() ) {
		$sum = 0.0;
		foreach ( $this->items( $exclude ) as $item ) {
			$sum += $item->weight() * $item->quantity();
		}

		return $sum;
	}

	/**
	 * Category-restricted value: quantity or amount.
	 *
	 * @param int[]  $category_ids Category term ids.
	 * @param string $mode         quantity | subtotal | total | discounted_subtotal.
	 * @param int[]  $exclude      Product ids to drop.
	 * @return float
	 */
	public function category_value( array $category_ids, $mode = Mission::MODE_QUANTITY, array $exclude = array() ) {
		$category_ids = array_flip( array_map( 'intval', $category_ids ) );

		$sum = 0.0;
		foreach ( $this->items( $exclude ) as $item ) {
			if ( ! $this->item_in_categories( $item, $category_ids ) ) {
				continue;
			}

			if ( Mission::MODE_QUANTITY === $mode ) {
				$sum += $item->quantity();
			} elseif ( Mission::MODE_TOTAL === $mode ) {
				$sum += $item->line_total();
			} elseif ( Mission::MODE_DISCOUNTED_SUBTOTAL === $mode ) {
				$sum += $item->line_total();
			} else {
				$sum += $item->line_subtotal();
			}
		}

		return $sum;
	}

	/**
	 * Product-restricted value: quantity or amount.
	 *
	 * Matches variations and their parent products.
	 *
	 * @param int[]  $product_ids Product/variation ids.
	 * @param string $mode        quantity | subtotal | total | discounted_subtotal.
	 * @param int[]  $exclude     Product ids to drop.
	 * @return float
	 */
	public function product_value( array $product_ids, $mode = Mission::MODE_QUANTITY, array $exclude = array() ) {
		$product_ids = array_flip( array_map( 'intval', $product_ids ) );

		$sum = 0.0;
		foreach ( $this->items( $exclude ) as $item ) {
			if ( ! isset( $product_ids[ $item->effective_product_id() ] ) && ! isset( $product_ids[ $item->product_id() ] ) ) {
				continue;
			}

			if ( Mission::MODE_QUANTITY === $mode ) {
				$sum += $item->quantity();
			} elseif ( Mission::MODE_TOTAL === $mode || Mission::MODE_DISCOUNTED_SUBTOTAL === $mode ) {
				$sum += $item->line_total();
			} else {
				$sum += $item->line_subtotal();
			}
		}

		return $sum;
	}

	/**
	 * Tag-restricted value: quantity or amount (Phase 32).
	 *
	 * @param int[]  $tag_ids Product tag term ids.
	 * @param string $mode    quantity | subtotal | total | discounted_subtotal.
	 * @param int[]  $exclude Product ids to drop.
	 * @return float
	 */
	public function tag_value( array $tag_ids, $mode = Mission::MODE_QUANTITY, array $exclude = array() ) {
		$tag_ids = array_flip( array_map( 'intval', $tag_ids ) );

		$sum = 0.0;
		foreach ( $this->items( $exclude ) as $item ) {
			if ( ! $this->item_has_tags( $item, $tag_ids ) ) {
				continue;
			}

			$sum += $this->line_value( $item, $mode );
		}

		return $sum;
	}

	/**
	 * Attribute-restricted value: quantity or amount (Phase 32). Matches
	 * products carrying ANY of the configured attribute taxonomies (brand
	 * missions configure their brand taxonomy here).
	 *
	 * @param string[] $taxonomies Global attribute taxonomy slugs.
	 * @param string   $mode       quantity | subtotal | total | discounted_subtotal.
	 * @param int[]    $exclude    Product ids to drop.
	 * @return float
	 */
	public function attribute_value( array $taxonomies, $mode = Mission::MODE_QUANTITY, array $exclude = array() ) {
		$taxonomies = array_flip( array_map( 'sanitize_text_field', array_map( 'strval', $taxonomies ) ) );

		$sum = 0.0;
		foreach ( $this->items( $exclude ) as $item ) {
			if ( ! $this->item_has_attributes( $item, $taxonomies ) ) {
				continue;
			}

			$sum += $this->line_value( $item, $mode );
		}

		return $sum;
	}

	/**
	 * The per-line value for a money/quantity mode.
	 *
	 * @param CartItem $item Item.
	 * @param string   $mode Mission::MODE_*.
	 * @return float
	 */
	protected function line_value( CartItem $item, $mode ) {
		if ( Mission::MODE_QUANTITY === $mode ) {
			return $item->quantity();
		}

		if ( Mission::MODE_TOTAL === $mode || Mission::MODE_DISCOUNTED_SUBTOTAL === $mode ) {
			return $item->line_total();
		}

		return $item->line_subtotal();
	}

	/**
	 * Whether an item carries any of the given tag ids.
	 *
	 * @param CartItem $item    Item.
	 * @param int[]    $tag_ids Flipped tag id set.
	 * @return bool
	 */
	protected function item_has_tags( CartItem $item, array $tag_ids ) {
		foreach ( $item->tags() as $id ) {
			if ( isset( $tag_ids[ $id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an item carries any of the given attribute taxonomies.
	 *
	 * @param CartItem $item        Item.
	 * @param string[] $taxonomies  Flipped taxonomy set.
	 * @return bool
	 */
	protected function item_has_attributes( CartItem $item, array $taxonomies ) {
		foreach ( $item->attributes() as $taxonomy ) {
			if ( isset( $taxonomies[ $taxonomy ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an item belongs to any of the given category ids.
	 *
	 * @param CartItem  $item         Item.
	 * @param int[]     $category_ids Flipped category id set.
	 * @return bool
	 */
	protected function item_in_categories( CartItem $item, array $category_ids ) {
		foreach ( $item->categories() as $id ) {
			if ( isset( $category_ids[ $id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return string[]
	 */
	public function coupons() {
		return $this->coupons;
	}

	/**
	 * @return int
	 */
	public function shipping_zone_id() {
		return $this->shipping_zone_id;
	}

	/**
	 * @return float
	 */
	public function subtotal() {
		return $this->subtotal;
	}

	/**
	 * @return float
	 */
	public function total() {
		return $this->total;
	}

	/**
	 * @return float
	 */
	public function discount_total() {
		return $this->discount_total;
	}

	/**
	 * @return float
	 */
	public function taxes_total() {
		return $this->taxes_total;
	}

	/**
	 * @return float
	 */
	public function shipping_total() {
		return $this->shipping_total;
	}

	/**
	 * @return string
	 */
	public function currency() {
		return $this->currency;
	}

	/**
	 * @return int
	 */
	public function user_id() {
		return $this->user_id;
	}

	/**
	 * @return bool
	 */
	public function is_guest() {
		return $this->is_guest;
	}

	/**
	 * @return bool
	 */
	public function is_empty() {
		return empty( $this->items );
	}
}
