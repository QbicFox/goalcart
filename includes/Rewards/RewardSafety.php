<?php
/**
 * Reward safety guards for the FaraCart engine.
 *
 * @package FaraCart
 */

namespace FaraCart\Rewards;

defined( 'ABSPATH' ) || exit;

/**
 * Class RewardSafety
 *
 * Pure, testable rules enforcing the Phase 5 "Reward Safety" guarantees:
 *
 *  - duplicate rewards: a mission's reward is applied at most once, and
 *    non-stacking rewards of the same type never both apply
 *  - unintended stacking: stacking='none' rewards block same-type rewards
 *  - invalid coupon application: coupon codes are validated against
 *    WooCommerce before they are applied
 *  - reward application to excluded products: handled inside the discount
 *    applicators (eligible base excludes excluded/eligible-only items)
 *  - reward loops: prevented by the RewardEngine (fingerprint + own-fee
 *    exclusion in CartContext), not by this class
 */
final class RewardSafety {

	/**
	 * Whether a reward may be granted given the reward types already applied.
	 *
	 * stacking='stack' rewards always combine; everything else may only be
	 * the first of its type (preventing duplicate/conflicting rewards).
	 *
	 * @param Reward $reward         Candidate reward.
	 * @param string[] $already_applied Reward types already granted.
	 * @return bool
	 */
	public static function stacking_allows( Reward $reward, array $already_applied ) {
		if ( $reward->stacking_is_stack() ) {
			return true;
		}

		return ! in_array( $reward->type(), $already_applied, true );
	}

	/**
	 * Whether a coupon code refers to a real WooCommerce coupon.
	 *
	 * @param string $code Coupon code.
	 * @return bool
	 */
	public static function coupon_exists( $code ) {
		$code = (string) $code;

		if ( '' === $code ) {
			return false;
		}

		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			// WooCommerce not loaded — assume valid so evaluation stays pure;
			// application is re-validated by WC_Cart::apply_coupon().
			return true;
		}

		return (bool) wc_get_coupon_id_by_code( $code );
	}

	/**
	 * Whether a product can be granted as a free gift.
	 *
	 * Guards the "reward product deleted / out of stock" edge case: the
	 * product must exist, be purchasable, and be in stock (or on backorder).
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public static function gift_product_available( $product_id ) {
		$product_id = (int) $product_id;

		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		if ( 'trash' === $product->get_status() ) {
			return false;
		}

		return $product->is_purchasable() && ( $product->is_in_stock() || $product->backorders_allowed() );
	}

	/**
	 * Build the deterministic code for a generated coupon reward.
	 *
	 * The code is derived from the mission id + reward value so the same mission
	 * always maps to the same coupon (idempotent generation).
	 *
	 * @param int $mission_id Mission id.
	 * @return string
	 */
	public static function generated_coupon_code( $mission_id ) {
		return 'FARACART-' . strtoupper( substr( md5( 'faracart-reward-' . (int) $mission_id ), 0, 10 ) );
	}
}
