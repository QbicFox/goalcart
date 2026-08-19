<?php
/**
 * Free gift reward applicator.
 *
 * @package FaraCart
 */

namespace FaraCart\Rewards\Applicators;

use FaraCart\Missions\CartContext;
use FaraCart\Missions\MissionResult;
use FaraCart\Rewards\Reward;
use FaraCart\Rewards\RewardApplicator;
use FaraCart\Rewards\RewardResult;
use FaraCart\Rewards\RewardSafety;

defined( 'ABSPATH' ) || exit;

/**
 * Class FreeGiftApplicator
 *
 * Grants a predefined gift product when the mission is met (P05-T02).
 *
 *  - automatic mode: the gift is silently added to the cart (marked with
 *    faracart_gift custom data) and its price is zeroed during totals
 *    calculation; the RewardEngine removes it again the moment the mission
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
	 *
	 * Choose mode (Phase 32, free gift selection) surfaces the full gift
	 * candidate list so the storefront can render the picker; the chosen
	 * product is added by the public gift endpoint (never automatically).
	 */
	public function evaluate( Reward $reward, MissionResult $result, ?CartContext $context = null ) {
		if ( $reward->is_gift_choose() ) {
			if ( empty( $reward->gift_products() ) ) {
				return RewardResult::blocked( $reward, $result->mission()->id(), RewardResult::REASON_GIFT_UNAVAILABLE );
			}

			return RewardResult::available(
				$reward,
				$result->mission()->id(),
				0.0,
				array(
					'gift_add_mode' => $reward->gift_add_mode(),
					'gift_products' => array_map( 'intval', $reward->gift_products() ),
				)
			);
		}

		if ( $reward->gift_product_id() <= 0 ) {
			return RewardResult::blocked( $reward, $result->mission()->id(), RewardResult::REASON_GIFT_UNAVAILABLE );
		}

		return RewardResult::available(
			$reward,
			$result->mission()->id(),
			0.0,
			array(
				'gift_product_id' => $reward->gift_product_id(),
				'gift_add_mode'   => $reward->gift_add_mode(),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int|null $chosen_product_id Optional override: the shopper's
	 *                                    chosen gift product (Phase 32
	 *                                    choose mode; ignored otherwise).
	 */
	public function apply( Reward $reward, RewardResult $evaluation, \WC_Cart $cart, $mission_id, $chosen_product_id = null ) {
		$gift_id = $reward->is_gift_choose()
			? (int) $chosen_product_id
			: $reward->gift_product_id();

		if ( ! $reward->is_gift_allowed( $gift_id ) || ! RewardSafety::gift_product_available( $gift_id ) ) {
			return false;
		}

		// Idempotent — but product-aware: the gift for this mission is already
		// in the cart ONLY when it carries the requested product. A line
		// for the same mission with a different product is stale (a
		// re-configured reward, or a re-chosen selectable candidate) and
		// must be swapped by the caller (RewardEngine::add_chosen_gift for
		// the shopper flow, reconcile_gifts for admin re-configuration), so
		// returning false here lets the reconcile pass revoke it instead of
		// treating it as already applied.
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['faracart_gift_mission'] ) && (int) $item['faracart_gift_mission'] === (int) $mission_id ) {
				$current = isset( $item['faracart_gift_product'] ) ? (int) $item['faracart_gift_product'] : 0;

				return $current === $gift_id;
			}
		}

		$added = $cart->add_to_cart(
			$gift_id,
			1,
			'',
			array(),
			array(
				'faracart_gift'         => true,
				'faracart_gift_mission'    => (int) $mission_id,
				'faracart_gift_product' => (int) $gift_id,
				'faracart_gift_mode'    => $reward->gift_add_mode(),
			)
		);

		return (bool) $added;
	}
}
