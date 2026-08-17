<?php
/**
 * Shared discount reward applicator logic.
 *
 * @package FaraCart
 */

namespace FaraCart\Rewards\Applicators;

use FaraCart\Missions\CartContext;
use FaraCart\Missions\CartItem;
use FaraCart\Missions\MissionResult;
use FaraCart\Rewards\Reward;
use FaraCart\Rewards\RewardApplicator;
use FaraCart\Rewards\RewardResult;

defined( 'ABSPATH' ) || exit;

/**
 * Class AbstractDiscountApplicator
 *
 * Shared engine for percentage and fixed discounts (P05-T02): compute the
 * discount amount from the eligible cart value (respecting eligible
 * products/categories and excluded products), apply it as a negative cart
 * fee, and never discount more than the eligible value.
 *
 * Discounts are applied through WooCommerce's public
 * 'woocommerce_cart_calculate_fees' pipeline as negative fees, so they are
 * recalculated on every totals pass, never persisted, and drop out
 * automatically the moment a mission becomes incomplete (no stale rewards).
 */
abstract class AbstractDiscountApplicator implements RewardApplicator {

	/**
	 * The reward type handled by this applicator.
	 *
	 * @return string Reward::TYPE_* constant.
	 */
	abstract public function type();

	/**
	 * Raw discount amount before capping/clamping.
	 *
	 * @param Reward $reward Reward configuration.
	 * @param float  $base   Eligible cart value.
	 * @return float
	 */
	abstract protected function raw_amount( Reward $reward, $base );

	/**
	 * {@inheritDoc}
	 */
	public function supports( $type ) {
		return $this->type() === $type;
	}

	/**
	 * {@inheritDoc}
	 */
	public function evaluate( Reward $reward, MissionResult $result, ?CartContext $context = null ) {
		$amount = 0.0;

		if ( null !== $context ) {
			$amount = $this->compute_amount( $reward, $context );
		}

		return RewardResult::available( $reward, $result->mission()->id(), $amount );
	}

	/**
	 * Compute the discount amount for the given cart snapshot.
	 *
	 * Pure math over a CartContext; shared by the engine and the tests.
	 *
	 * @param Reward      $reward  Reward configuration.
	 * @param CartContext $context Cart snapshot.
	 * @return float
	 */
	public function compute_amount( Reward $reward, CartContext $context ) {
		$base = $this->eligible_base( $reward, $context );

		if ( $base <= 0 ) {
			return 0.0;
		}

		$amount = $this->raw_amount( $reward, $base );

		// Never discount more than the eligible value (prevents negative
		// totals and discounting excluded items indirectly).
		$amount = min( $amount, $base );

		return round( max( 0.0, (float) $amount ), 4 );
	}

	/**
	 * Sum the after-discount value of the items the reward applies to.
	 *
	 * Reward-safety: excluded products never contribute, and eligible
	 * products/categories restrict the base when configured.
	 *
	 * @param Reward      $reward  Reward configuration.
	 * @param CartContext $context Cart snapshot.
	 * @return float
	 */
	protected function eligible_base( Reward $reward, CartContext $context ) {
		$base = 0.0;

		$excluded   = array_flip( $reward->excluded_products() );
		$products   = array_flip( $reward->eligible_products() );
		$categories = array_flip( $reward->eligible_categories() );

		foreach ( $context->items() as $item ) {
			if ( $this->is_excluded( $item, $excluded ) ) {
				continue;
			}

			if ( ! empty( $products ) && ! $this->is_one_of( $item, $products ) ) {
				continue;
			}

			if ( ! empty( $categories ) && ! $this->in_categories( $item, $categories ) ) {
				continue;
			}

			$base += $item->line_total();
		}

		return $base;
	}

	/**
	 * Whether a cart item is excluded from the reward.
	 *
	 * @param CartItem $item     Cart item.
	 * @param int[]    $excluded Flipped excluded product id set.
	 * @return bool
	 */
	protected function is_excluded( CartItem $item, array $excluded ) {
		return isset( $excluded[ $item->product_id() ] ) || isset( $excluded[ $item->effective_product_id() ] );
	}

	/**
	 * Whether a cart item is one of the eligible products.
	 *
	 * @param CartItem $item     Cart item.
	 * @param int[]    $products Flipped eligible product id set.
	 * @return bool
	 */
	protected function is_one_of( CartItem $item, array $products ) {
		return isset( $products[ $item->product_id() ] ) || isset( $products[ $item->effective_product_id() ] );
	}

	/**
	 * Whether a cart item belongs to any of the eligible categories.
	 *
	 * @param CartItem $item       Cart item.
	 * @param int[]    $categories Flipped category id set.
	 * @return bool
	 */
	protected function in_categories( CartItem $item, array $categories ) {
		foreach ( $item->categories() as $id ) {
			if ( isset( $categories[ $id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( Reward $reward, RewardResult $evaluation, \WC_Cart $cart, $mission_id ) {
		$amount = $evaluation->amount();

		if ( $amount <= 0 ) {
			return true;
		}

		$cart->fees_api()->add_fee(
			array(
				'id'      => CartContext::OWN_FEE_PREFIX . (int) $mission_id,
				'name'    => '' !== $reward->label() ? $reward->label() : __( 'Mission reward', 'faracart' ),
				'amount'  => -1 * $amount,
				'taxable' => false,
			)
		);

		return true;
	}
}
