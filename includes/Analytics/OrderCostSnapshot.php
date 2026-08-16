<?php
/**
 * Order cost snapshot for FaraCart (UPSELL_REFACTOR §21/§22).
 *
 * @package FaraCart
 */

namespace FaraCart\Analytics;

use FaraCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class OrderCostSnapshot
 *
 * UPSELL_REFACTOR §21 — when an order is created, preserve the Product
 * Cost used at that time. Historical profit must NOT be recomputed from
 * the current product cost: if a store edits a product's cost later,
 * every historical order keeps the cost it was actually created with.
 *
 * The snapshot is stored at order-item level (the project's established
 * WooCommerce order-item metadata strategy — no extra database table):
 *
 *   order_item_id → `_faracart_unit_cost` = unit cost at order time
 *
 * The read side lives in RewardCostEstimator (order_item_unit_cost() /
 * order_margin_stats()), which prefers the snapshot over the live
 * product cost — so estimated profit, goal economics and the upsell
 * margin scorer all inherit historical stability automatically.
 *
 * The hook (`woocommerce_checkout_create_order_line_item`) fires for
 * both the classic checkout and the Blocks checkout, before totals are
 * calculated, and the meta is persisted with the order item — no extra
 * write path to maintain.
 */
final class OrderCostSnapshot {

	/**
	 * Reward cost / product cost estimator (the snapshot source).
	 *
	 * @var RewardCostEstimator
	 */
	protected $costs;

	/**
	 * Constructor.
	 *
	 * @param RewardCostEstimator $costs Reward cost / product cost estimator.
	 */
	public function __construct( RewardCostEstimator $costs ) {
		$this->costs = $costs;
	}

	/**
	 * Register the checkout hook.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'snapshot_line_item' ), 10, 4 );
	}

	/**
	 * Stamp the unit-cost snapshot onto a line item at order creation.
	 *
	 * Products without cost data simply get no snapshot (limited
	 * economics, never a guessed number). The value is filterable so
	 * stores plugging their own cost source through
	 * `faracart_product_cost` get the exact same snapshot here.
	 *
	 * @param \WC_Order_Item_Product $item          Line item being created.
	 * @param string                 $cart_item_key Cart item key (unused).
	 * @param array<string, mixed>   $values        Cart item values (unused).
	 * @param \WC_Order              $order         Order being created (unused).
	 * @return void
	 */
	public function snapshot_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! $item || ! method_exists( $item, 'get_product_id' ) || ! method_exists( $item, 'get_variation_id' ) ) {
			return;
		}

		$variation_id = (int) $item->get_variation_id();
		$product_id   = $variation_id > 0 ? $variation_id : (int) $item->get_product_id();

		if ( $product_id < 1 ) {
			return;
		}

		$cost = $this->costs->product_cost( $product_id );

		/**
		 * Filters the order-item cost snapshot written at checkout.
		 *
		 * Returning a float stamps that value as the line's historical
		 * unit cost; returning null (or a non-positive value) leaves the
		 * line without a snapshot (it falls back to the live product cost
		 * in the profit model).
		 *
		 * @param float|null                  $cost       Snapshot unit cost, or null.
		 * @param int                         $product_id Product/variation id.
		 * @param \WC_Order_Item_Product      $item       Line item.
		 */
		$cost = apply_filters( 'faracart_order_cost_snapshot', $cost, $product_id, $item );

		if ( null === $cost || (float) $cost <= 0 ) {
			return;
		}

		$item->update_meta_data( RewardCostEstimator::ORDER_COST_META, round( (float) $cost, 4 ) );
	}

	/**
	 * The snapshot unit cost of an order item (null when absent).
	 *
	 * Convenience read for tests and embedders; the profit model reads it
	 * through RewardCostEstimator::order_item_unit_cost().
	 *
	 * @param \WC_Order_Item_Product $item Order line item.
	 * @return float|null
	 */
	public static function item_snapshot_cost( $item ) {
		if ( ! $item || ! method_exists( $item, 'get_meta' ) ) {
			return null;
		}

		$value = $item->get_meta( RewardCostEstimator::ORDER_COST_META );

		return is_numeric( $value ) && (float) $value > 0 ? (float) $value : null;
	}
}
