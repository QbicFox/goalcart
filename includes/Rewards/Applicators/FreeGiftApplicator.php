<?php
/**
 * Free gift reward applicator.
 *
 * @package GoalCart
 */

namespace GoalCart\Rewards\Applicators;

use GoalCart\Goals\CartContext;
use GoalCart\Goals\GoalResult;
use GoalCart\Rewards\Reward;
use GoalCart\Rewards\RewardApplicator;
use GoalCart\Rewards\RewardResult;
use GoalCart\Rewards\RewardSafety;

defined( 'ABSPATH' ) || exit;

/**
 * Class FreeGiftApplicator
 *
 * Grants a predefined gift product when the goal is met (P05-T02).
 *
 *  - automatic mode: the gift is silently added to the cart (marked with
 *    goalcart_gift custom data) and its price is zeroed during totals
 *    calculation; the RewardEngine removes it again the moment the goal
 *    becomes incomplete
 *  - optional mode: the reward is made available (gift_product_id is
 *    exposed through the RewardResult meta) and the frontend offers the
 *    shopper a single-click "add gift" action
 *
 * Safety: a deleted, unpurchasable, or out-of-stock gift product is never
 * granted (RewardSafety::gift_product_available()).
 */
final class FreeGiftApplicator implements RewardApplicator {

	/**
	 * {@inheritDoc}
	 */
	public function supports( $type ) {
		return Reward::TYPE_FREE_GIFT === $type;
	}

	/**
	 * {@inheritDoc}
	 */
	public function evaluate( Reward $reward, GoalResult $result, ?CartContext $context = null ) {
		if ( $reward->gift_product_id() <= 0 ) {
			return RewardResult::blocked( $reward, $result->goal()->id(), RewardResult::REASON_GIFT_UNAVAILABLE );
		}

		return RewardResult::available(
			$reward,
			$result->goal()->id(),
			0.0,
			array(
				'gift_product_id' => $reward->gift_product_id(),
				'gift_add_mode'   => $reward->gift_add_mode(),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( Reward $reward, RewardResult $evaluation, \WC_Cart $cart, $goal_id ) {
		$gift_id = $reward->gift_product_id();

		if ( ! RewardSafety::gift_product_available( $gift_id ) ) {
			return false;
		}

		// Idempotent: the gift for this goal is already in the cart.
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['goalcart_gift_goal'] ) && (int) $item['goalcart_gift_goal'] === (int) $goal_id ) {
				return true;
			}
		}

		$added = $cart->add_to_cart(
			$gift_id,
			1,
			'',
			array(),
			array(
				'goalcart_gift'      => true,
				'goalcart_gift_goal' => (int) $goal_id,
			)
		);

		return (bool) $added;
	}
}
