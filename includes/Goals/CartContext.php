<?php
/**
 * Cart context for the Goal Cart engine.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals;

defined( 'ABSPATH' ) || exit;

/**
 * Class CartContext
 *
 * An immutable snapshot of the customer's cart, normalized so every
 * evaluator computes against the same data. It is UI-independent: it can be
 * built from a live WC_Cart (from_cart()) or from plain data in tests and
 * headless contexts, and it carries only the numbers the engine needs.
 *
 * Amount bases (Goal::calculation_mode):
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
	const OWN_FEE_PREFIX = 'goalcart_reward_';

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
	 * goals on 'woocommerce_before_calculate_totals', which fires AFTER
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
	 * Reward-loop safety (Phase 5): Goal Cart's own discount fees are
	 * subtracted from the `total` basis, and passing `exclude_shipping`
	 * removes shipping from `total` and `shipping_total`, so a reward can
	 * never change the value it was granted on.
	 *
	 * @param \WC_Cart                $cart Live cart.
	 * @param array<string, mixed>    $args Optional overrides: currency,
	 *                                      user_id, is_guest, exclude_shipping.
	 * @return CartContext
	 */
	public static function from_cart( \WC_Cart $cart, array $args = array() ) {
		$items = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ? $cart_item['data'] : null;

			if ( null === $product ) {
				continue;
			}

			$items[] = new CartItem(
				array(
					'product_id'    => $product->get_id(),
					'variation_id'  => ! empty( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0,
					'name'          => $product->get_name(),
					'quantity'      => isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1.0,
					'line_subtotal' => isset( $cart_item['line_subtotal'] ) ? (float) $cart_item['line_subtotal'] : 0.0,
					'line_total'    => isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0,
					'price'         => (float) $product->get_price(),
					'weight'        => (float) $product->get_weight(),
					'categories'    => function_exists( 'wp_get_post_terms' ) ? wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) ) : array(),
					'virtual'       => $product->is_virtual(),
					'downloadable'  => $product->is_downloadable(),
				)
			);
		}

		// Money bases come from the line items — always current, including
		// while the cart's aggregate totals are reset mid-calculation (see
		// the timing note above).
		$subtotal      = 0.0;
		$after_discount = 0.0;

		foreach ( $items as $item ) {
			$subtotal       += $item->line_subtotal();
			$after_discount += $item->line_total();
		}

		$exclude_shipping = ! empty( $args['exclude_shipping'] );

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

		// Reward-loop safety: our own discount fees are added back so a
		// discount reward can never reduce the `total` basis it was granted on.
		$total = max( 0.0, $total + self::own_fees_total( $cart ) );

		if ( $exclude_shipping ) {
			$total          = max( 0.0, $total - $shipping_total );
			$shipping_total = 0.0;
		}

		return new self(
			array(
				'subtotal'       => $subtotal,
				'total'          => $total,
				'discount_total' => $discount_total,
				'taxes_total'    => $taxes_total,
				'shipping_total' => $shipping_total,
				'currency'       => isset( $args['currency'] ) ? (string) $args['currency'] : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ),
				'user_id'        => isset( $args['user_id'] ) ? (int) $args['user_id'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ),
				'is_guest'       => isset( $args['is_guest'] ) ? (bool) $args['is_guest'] : ( function_exists( 'is_user_logged_in' ) ? ! is_user_logged_in() : true ),
				'items'          => $items,
			)
		);
	}

	/**
	 * Sum of Goal Cart's own (negative) discount fees in the cart.
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
	 * @param string $mode    Goal::MODE_*.
	 * @param int[]  $exclude Product ids to drop.
	 * @return float
	 */
	public function amount( $mode = Goal::MODE_SUBTOTAL, array $exclude = array() ) {
		$mode    = (string) $mode;
		$exclude = array_map( 'intval', $exclude );

		if ( empty( $exclude ) ) {
			if ( Goal::MODE_TOTAL === $mode ) {
				return $this->total;
			}

			if ( Goal::MODE_DISCOUNTED_SUBTOTAL === $mode ) {
				return $this->sum_lines( 'line_total' );
			}

			return $this->subtotal;
		}

		$flip = array_flip( $exclude );

		if ( Goal::MODE_TOTAL === $mode ) {
			return $this->total - $this->sum_lines( 'line_total', $flip );
		}

		if ( Goal::MODE_DISCOUNTED_SUBTOTAL === $mode ) {
			return $this->sum_lines( 'line_total', $flip, true );
		}

		return $this->sum_lines( 'line_subtotal', $flip, true );
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
	public function category_value( array $category_ids, $mode = Goal::MODE_QUANTITY, array $exclude = array() ) {
		$category_ids = array_flip( array_map( 'intval', $category_ids ) );

		$sum = 0.0;
		foreach ( $this->items( $exclude ) as $item ) {
			if ( ! $this->item_in_categories( $item, $category_ids ) ) {
				continue;
			}

			if ( Goal::MODE_QUANTITY === $mode ) {
				$sum += $item->quantity();
			} elseif ( Goal::MODE_TOTAL === $mode ) {
				$sum += $item->line_total();
			} elseif ( Goal::MODE_DISCOUNTED_SUBTOTAL === $mode ) {
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
	public function product_value( array $product_ids, $mode = Goal::MODE_QUANTITY, array $exclude = array() ) {
		$product_ids = array_flip( array_map( 'intval', $product_ids ) );

		$sum = 0.0;
		foreach ( $this->items( $exclude ) as $item ) {
			if ( ! isset( $product_ids[ $item->effective_product_id() ] ) && ! isset( $product_ids[ $item->product_id() ] ) ) {
				continue;
			}

			if ( Goal::MODE_QUANTITY === $mode ) {
				$sum += $item->quantity();
			} elseif ( Goal::MODE_TOTAL === $mode || Goal::MODE_DISCOUNTED_SUBTOTAL === $mode ) {
				$sum += $item->line_total();
			} else {
				$sum += $item->line_subtotal();
			}
		}

		return $sum;
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
