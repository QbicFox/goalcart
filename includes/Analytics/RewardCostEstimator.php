<?php
/**
 * Reward cost & profit impact estimator for Goal Cart (Phase 33.2).
 *
 * @package GoalCart
 */

namespace GoalCart\Analytics;

use GoalCart\Goals\Goal;
use GoalCart\Rewards\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Class RewardCostEstimator
 *
 * Phase 33.2 (Revenue Attribution) — estimates the cost of goal rewards and
 * the profit impact of the revenue the goals influenced, without ever
 * modifying WooCommerce pricing/cost data.
 *
 * Reward cost (P33.2): every reward type maps to a deterministic cost model
 * based on the goal's own reward configuration plus, where available, the
 * order it was granted on:
 *
 *  - percent_discount  → min(order_total × value%, reward_max_value)
 *  - fixed_discount    → reward_value
 *  - coupon            → generated coupons follow the same percent/fixed
 *                        model from the coupon settings; pre-existing
 *                        coupon codes fall back to the configured value
 *  - free_shipping     → the order's shipping total (needs order data)
 *  - free_gift         → the gift product's cost when the store provides
 *                        product cost data (never invented otherwise)
 *
 * Profit impact (P33.2): estimated_profit = incremental_revenue ×
 * margin% − reward_cost − shipping_cost. Margin data comes from WooCommerce
 * product cost fields when the store actually has them; without margin data
 * the estimator returns profit as unavailable and the caller reports
 * revenue-only analytics (graceful degradation — no invented margins).
 *
 * Product margin (P33.2): reads the standard WooCommerce `_cost` field and
 * the common `_wc_cog_cost` cost-of-goods field through the
 * `goalcart_product_cost` filter so stores can plug their own cost source.
 * Returns null whenever a product has no cost data — never a guessed number.
 */
final class RewardCostEstimator {

	/**
	 * The product-cost sources the estimator consults, in order, plus the
	 * variation-fallback behavior — stable identifiers the React layer can
	 * translate for the "how to enable profit" help panel (Improvement.md
	 * Phase 3 / §10). Labels are kept in the UI layer; these keys never
	 * change across versions.
	 *
	 * `_goalcart_product_cost` is Goal Cart's own product-cost field (the
	 * WooCommerce product-edit metabox ProductCostField writes it) and
	 * takes precedence when present; WooCommerce's standard `_cost` and the
	 * common `_wc_cog_cost` cost-of-goods field are the fallbacks for
	 * stores that already store costs elsewhere.
	 *
	 * @var string[]
	 */
	const COST_SOURCES = array(
		'_goalcart_product_cost',
		'_cost',
		'_wc_cog_cost',
		'goalcart_product_cost',
		'variation_fallback',
	);

	/**
	 * The plugin's own product-cost meta key (ProductCostField admin UI).
	 *
	 * @var string
	 */
	const PRODUCT_COST_META = '_goalcart_product_cost';

	/**
	 * The order-item meta key holding the unit-cost snapshot written when
	 * the order is created (OrderCostSnapshot). Historical profit always
	 * prefers this snapshot over the current product cost, so later cost
	 * edits never rewrite history.
	 *
	 * @var string
	 */
	const ORDER_COST_META = '_goalcart_unit_cost';

	/**
	 * Per-request memo of store_has_cost_data() — one indexed scan per
	 * request that touches profit, never per order.
	 *
	 * @var bool|null
	 */
	protected $store_cost_checked = null;

	/**
	 * Estimate the cost of a goal's reward granted on an order.
	 *
	 * Deterministic and transparent: the returned array always carries the
	 * cost model's basis and an `available` flag, so the caller can show
	 * "unavailable" (with the reason) instead of a guessed number when the
	 * model needs data the store does not provide.
	 *
	 * @param Goal            $goal        The goal that granted the reward.
	 * @param float           $order_total Order total the reward applied to
	 *                                     (0 when unknown).
	 * @param array<string, mixed> $context Optional: 'order_id',
	 *                                     'shipping_total'.
	 * @return array{estimated_cost: float, available: bool, basis: string, type: string|null}
	 */
	public function estimate_reward_cost( Goal $goal, $order_total = 0.0, array $context = array() ) {
		$reward = Reward::from_goal( $goal );

		if ( ! $reward->has_config() ) {
			return $this->result( 0.0, true, 'no reward configured', $reward->type() );
		}

		$order_total = max( 0.0, (float) $order_total );
		$value       = null !== $reward->value() ? (float) $reward->value() : 0.0;
		$max_value   = null !== $reward->max_value() ? (float) $reward->max_value() : 0.0;

		switch ( $reward->type() ) {
			case Reward::TYPE_FIXED_DISCOUNT:
				return $this->result( $value, true, 'fixed discount amount', $reward->type() );

			case Reward::TYPE_PERCENT_DISCOUNT:
				$cost = $order_total * ( $value / 100.0 );

				if ( $max_value > 0 && $cost > $max_value ) {
					$cost = $max_value;
				}

				return $this->result( $cost, true, 'percent of order total, capped at reward max', $reward->type() );

			case Reward::TYPE_COUPON:
				if ( Reward::COUPON_FIXED_CART === $reward->coupon_discount_type() ) {
					return $this->result( $value, true, 'fixed cart coupon amount (configured value)', $reward->type() );
				}

				$cost = $order_total * ( $value / 100.0 );

				if ( $max_value > 0 && $cost > $max_value ) {
					$cost = $max_value;
				}

				// Estimate from the goal's coupon configuration — a
				// pre-existing coupon code's actual terms are not read (the
				// basis label makes the estimate transparent).
				return $this->result( $cost, true, 'percent coupon of order total, capped at reward max (configured value)', $reward->type() );

			case Reward::TYPE_FREE_SHIPPING:
				$shipping = isset( $context['shipping_total'] ) ? (float) $context['shipping_total'] : null;

				if ( null === $shipping && ! empty( $context['order_id'] ) ) {
					$shipping = $this->order_shipping_total( (int) $context['order_id'] );
				}

				if ( null === $shipping ) {
					return $this->result( 0.0, false, 'shipping cost unavailable for this order', $reward->type() );
				}

				return $this->result( max( 0.0, $shipping ), true, 'shipping cost absorbed by the store', $reward->type() );

			case Reward::TYPE_FREE_GIFT:
				$gift_id = (int) $reward->gift_product_id();

				if ( $gift_id < 1 && ! empty( $reward->gift_products() ) ) {
					$gift_id = (int) $reward->gift_products()[0];
				}

				$cost = $gift_id > 0 ? $this->product_cost( $gift_id ) : null;

				if ( null === $cost ) {
					return $this->result( 0.0, false, 'gift product cost unavailable', $reward->type() );
				}

				return $this->result( $cost, true, 'gift product cost', $reward->type() );
		}

		return $this->result( 0.0, false, 'unsupported reward type', $reward->type() );
	}

	/**
	 * The shipping total of an order (0.0 when the order has none).
	 *
	 * @param int $order_id Order id.
	 * @return float|null Null when the order cannot be read.
	 */
	public function order_shipping_total( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order ) {
			return null;
		}

		return (float) $order->get_shipping_total();
	}

	/**
	 * The product cost when the store provides one (null otherwise).
	 *
	 * Reads, in order: the `goalcart_product_cost` filter (lets stores plug
	 * their own cost source), the standard WooCommerce `_cost` field, and
	 * the common cost-of-goods `_wc_cog_cost` field. Variations fall back
	 * to their parent product. Never invents a cost.
	 *
	 * @param int $product_id Product or variation id.
	 * @return float|null
	 */
	public function product_cost( $product_id ) {
		$product_id = (int) $product_id;

		if ( $product_id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return null;
		}

		/**
		 * Filter the product cost used by the profit/cost estimation.
		 *
		 * Returning a float enables margin-aware analytics for that
		 * product; returning null means "no cost data" and the estimator
		 * degrades gracefully. The product type is NOT guaranteed — during
		 * the variation fallback this filter is also called with the
		 * parent product (a WC_Product_Variable), so guard against
		 * variation-only methods (e.g. get_variation_id()) inside.
		 *
		 * @param float|null   $cost    Estimated cost, or null.
		 * @param \WC_Product  $product Product object (simple, variation
		 *                              or variable — type not guaranteed).
		 */
		$cost = apply_filters( 'goalcart_product_cost', null, $product );

		if ( null === $cost ) {
			$cost = $this->raw_product_cost( $product );
		}

		// Variation fallback: a variation with no cost of its own inherits
		// the parent's cost. The parent runs through the SAME source chain
		// (filter first, then raw meta) so stores that plug costs via the
		// goalcart_product_cost filter are honored for variations too.
		if ( null === $cost && $product->get_parent_id() > 0 ) {
			$parent = wc_get_product( $product->get_parent_id() );

			if ( $parent ) {
				$cost = apply_filters( 'goalcart_product_cost', null, $parent );

				if ( null === $cost ) {
					$cost = $this->raw_product_cost( $parent );
				}
			}
		}

		// A stored cost of zero/negative is "no cost data" — treating it as
		// a real 100%-margin value would assume a margin the store never
		// declared (Improvement.md §40).
		if ( null === $cost || (float) $cost <= 0 ) {
			return null;
		}

		return (float) $cost;
	}

	/**
	 * Whether any product in the store carries cost data.
	 *
	 * UI-ready availability metadata (Phase 3): one cheap indexed scan
	 * (postmeta JOIN posts, LIMIT 1) tells the UI whether Estimated Profit
	 * can ever become available, letting it distinguish "no cost data
	 * anywhere" (show the set-up guidance, §10) from "some orders lack
	 * cost data" (show coverage, §11). Never scans orders or products.
	 *
	 * @return bool
	 */
	public function store_has_cost_data() {
		if ( null !== $this->store_cost_checked ) {
			return $this->store_cost_checked;
		}

		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.meta_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type IN (%s, %s)
				   AND pm.meta_key IN (%s, %s, %s)
				   AND pm.meta_value != ''
				   AND pm.meta_value IS NOT NULL
				   AND CAST(pm.meta_value AS DECIMAL(19,4)) > 0
				 LIMIT 1",
				'product',
				'product_variation',
				self::PRODUCT_COST_META,
				'_cost',
				'_wc_cog_cost'
			)
		);

		$this->store_cost_checked = (bool) $found;

		return $this->store_cost_checked;
	}

	/**
	 * Read the raw cost from the product's own meta fields.
	 *
	 * Priority: Goal Cart's own `_goalcart_product_cost` field first, then
	 * WooCommerce's standard `_cost`, then the cost-of-goods
	 * `_wc_cog_cost`. Zero/negative values count as "no cost data" — the
	 * same contract as product_cost().
	 *
	 * @param \WC_Product $product Product object.
	 * @return float|null
	 */
	protected function raw_product_cost( $product ) {
		foreach ( array( self::PRODUCT_COST_META, '_cost', '_wc_cog_cost' ) as $key ) {
			$value = $product->get_meta( $key );

			if ( is_numeric( $value ) && (float) $value > 0 ) {
				return (float) $value;
			}
		}

		return null;
	}

	/**
	 * Margin stats for a single product when cost data exists.
	 *
	 * @param int $product_id Product id.
	 * @return array{cost: float, price: float, margin: float, margin_pct: float}|null
	 */
	public function product_margin( $product_id ) {
		$cost = $this->product_cost( $product_id );

		if ( null === $cost || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( (int) $product_id );

		if ( ! $product ) {
			return null;
		}

		$price   = (float) $product->get_price();
		$margin  = $price - $cost;
		$margin_pct = $price > 0 ? $margin / $price : 0.0;

		return array(
			'cost'        => $cost,
			'price'       => $price,
			'margin'      => round( $margin, 4 ),
			'margin_pct'  => round( $margin_pct, 6 ),
		);
	}

	/**
	 * Margin stats for an order, or null when any line lacks cost data.
	 *
	 * Graceful by design: if even one line item has no product cost the
	 * whole order is treated as having no margin data — never a partial
	 * guess.
	 *
	 * Historical stability (UPSELL_REFACTOR §21/§22): each line's cost
	 * prefers the `_goalcart_unit_cost` snapshot OrderCostSnapshot wrote at
	 * order creation, so later product-cost edits never rewrite historical
	 * profit. Orders created before the snapshot feature simply have no
	 * snapshot and fall back to the current product cost — the pre-feature
	 * behavior.
	 *
	 * @param \WC_Order|int $order Order object or id.
	 * @return array{cost: float, revenue: float, margin: float, margin_pct: float}|null
	 */
	public function order_margin_stats( $order ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		if ( is_numeric( $order ) ) {
			$order = wc_get_order( (int) $order );
		}

		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return null;
		}

		$items = $order->get_items();

		if ( empty( $items ) ) {
			return null;
		}

		$revenue = 0.0;
		$cost    = 0.0;

		// Estimate: line revenue reflects cart discounts (get_total),
		// while the cost uses the full unit cost × quantity — a discounted
		// line therefore reports a lower margin than its undiscounted
		// equivalent. Deterministic and documented; not a store-cost claim.
		foreach ( $items as $item ) {
			// Variation first — the snapshot is stamped with the variation id
			// (OrderCostSnapshot) and a variation may carry its own cost; the
			// parent fallback is handled inside product_cost().
			$product_id = $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
			$unit_cost  = $this->order_item_unit_cost( $item, $product_id );

			// Any line without cost data → no margin data for the order.
			if ( null === $unit_cost ) {
				return null;
			}

			$line_total = (float) $item->get_total();
			$quantity   = max( 1, (int) $item->get_quantity() );

			$revenue += $line_total;
			$cost    += $unit_cost * $quantity;
		}

		if ( $revenue <= 0 ) {
			return null;
		}

		$margin     = $revenue - $cost;
		$margin_pct = $margin / $revenue;

		return array(
			'cost'       => round( $cost, 4 ),
			'revenue'    => round( $revenue, 4 ),
			'margin'     => round( $margin, 4 ),
			'margin_pct' => round( $margin_pct, 6 ),
		);
	}

	/**
	 * The unit cost of an order line item: the snapshot first, then the
	 * current product cost.
	 *
	 * The `_goalcart_unit_cost` snapshot is written by OrderCostSnapshot
	 * when the order is created; preferring it keeps historical profit
	 * stable when product costs change later. Line items without a
	 * snapshot (pre-feature orders, or products that had no cost at order
	 * time) fall back to the current product cost.
	 *
	 * @param \WC_Order_Item_Product $item       Order line item.
	 * @param int                    $product_id Resolved product/variation id.
	 * @return float|null
	 */
	public function order_item_unit_cost( $item, $product_id ) {
		$snapshot = $item->get_meta( self::ORDER_COST_META );

		if ( is_numeric( $snapshot ) && (float) $snapshot > 0 ) {
			return (float) $snapshot;
		}

		return $this->product_cost( $product_id );
	}

	/**
	 * Product-level cost coverage (UPSELL_REFACTOR §25).
	 *
	 * How much of the catalog carries cost data: published products with a
	 * direct cost meta (Goal Cart's own field, `_cost` or `_wc_cog_cost`)
	 * OR at least one variation with cost data, over the total published
	 * product count. Two bounded indexed EXISTS scans — never a full
	 * product scan. Coverage semantics are guaranteed: every product in
	 * the numerator demonstrably has a positive cost somewhere in its
	 * chain (product or one of its variations).
	 *
	 * @return array{total_products: int, products_with_cost: int, coverage_pct: float|null, available: bool}
	 */
	public function cost_coverage() {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				'product',
				'publish'
			)
		);

		if ( $total < 1 ) {
			return array(
				'total_products'     => 0,
				'products_with_cost' => 0,
				'coverage_pct'       => null,
				'available'          => false,
			);
		}

		$cost_keys = array( self::PRODUCT_COST_META, '_cost', '_wc_cog_cost' );
		$in        = implode( ',', array_fill( 0, count( $cost_keys ), '%s' ) );

		$with_cost = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				 FROM {$wpdb->posts} p
				 WHERE p.post_type = %s AND p.post_status = %s
				   AND (
					 EXISTS (
						 SELECT 1 FROM {$wpdb->postmeta} m
						 WHERE m.post_id = p.ID AND m.meta_key IN ({$in})
						   AND m.meta_value != '' AND m.meta_value IS NOT NULL
						   AND CAST(m.meta_value AS DECIMAL(19,4)) > 0
					 )
					 OR EXISTS (
						 SELECT 1 FROM {$wpdb->posts} v
						 INNER JOIN {$wpdb->postmeta} vm ON vm.post_id = v.ID
						 WHERE v.post_type = %s AND v.post_parent = p.ID
						   AND vm.meta_key IN ({$in})
						   AND vm.meta_value != '' AND vm.meta_value IS NOT NULL
						   AND CAST(vm.meta_value AS DECIMAL(19,4)) > 0
					 )
				   )",
				array_merge(
					array( 'product', 'publish' ),
					$cost_keys,
					array( 'product_variation' ),
					$cost_keys
				)
			)
		);

		return array(
			'total_products'     => $total,
			'products_with_cost' => $with_cost,
			'coverage_pct'       => $total > 0 ? round( $with_cost / $total * 100, 1 ) : null,
			'available'          => $total > 0,
		);
	}

	/**
	 * Estimate the profit impact of attributed revenue.
	 *
	 * estimated_profit = incremental_revenue × margin_pct − reward_cost −
	 * shipping_cost. When margin data is unavailable the profit is reported
	 * as unavailable (null) with the reason — revenue-only analytics keep
	 * working (never a fabricated number).
	 *
	 * @param array<string, mixed> $inputs incremental_revenue, margin_pct
	 *                                     (null = unavailable), reward_cost,
	 *                                     shipping_cost (may be null).
	 * @return array{estimated_profit: float|null, available: bool, reason: string|null, reason_code: string, incremental_revenue: float, reward_cost: float, shipping_cost: float|null, margin_pct: float|null}
	 */
	public function profit_impact( array $inputs ) {
		$incremental = max( 0.0, (float) ( isset( $inputs['incremental_revenue'] ) ? $inputs['incremental_revenue'] : 0.0 ) );
		$reward_cost = max( 0.0, (float) ( isset( $inputs['reward_cost'] ) ? $inputs['reward_cost'] : 0.0 ) );
		$shipping    = isset( $inputs['shipping_cost'] ) && null !== $inputs['shipping_cost']
			? max( 0.0, (float) $inputs['shipping_cost'] )
			: null;
		$margin_pct  = isset( $inputs['margin_pct'] ) && null !== $inputs['margin_pct']
			? (float) $inputs['margin_pct']
			: null;

		if ( null === $margin_pct ) {
			return array(
				'estimated_profit'  => null,
				'available'         => false,
				'reason'            => __( 'Product cost data is not available — profit impact unavailable (revenue-only analytics).', 'goalcart' ),
				// Stable machine-readable code (Improvement.md §39): the UI
				// translates it; the human reason stays in 'reason'.
				'reason_code'       => 'missing_product_cost',
				'incremental_revenue' => $incremental,
				'reward_cost'       => $reward_cost,
				'shipping_cost'     => $shipping,
				'margin_pct'        => null,
			);
		}

		$estimated = ( $incremental * $margin_pct ) - $reward_cost - ( null !== $shipping ? $shipping : 0.0 );

		return array(
			'estimated_profit'    => round( $estimated, 4 ),
			'available'           => true,
			'reason'              => null,
			'reason_code'         => 'available',
			'incremental_revenue' => $incremental,
			'reward_cost'         => $reward_cost,
			'shipping_cost'       => $shipping,
			'margin_pct'          => round( $margin_pct, 6 ),
		);
	}

	/**
	 * Build a reward-cost result array.
	 *
	 * @param float       $cost      Estimated cost.
	 * @param bool        $available Whether the model had the data it needs.
	 * @param string      $basis     Human-readable cost-model description.
	 * @param string|null $type      Reward type.
	 * @return array{estimated_cost: float, available: bool, basis: string, type: string|null}
	 */
	protected function result( $cost, $available, $basis, $type ) {
		return array(
			'estimated_cost' => round( max( 0.0, (float) $cost ), 4 ),
			'available'      => (bool) $available,
			'basis'          => (string) $basis,
			'type'           => $type,
		);
	}
}
