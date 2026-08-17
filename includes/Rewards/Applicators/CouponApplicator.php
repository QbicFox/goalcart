<?php
/**
 * Coupon reward applicator.
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
 * Class CouponApplicator
 *
 * Applies a coupon according to configured rules when the mission is met
 * (P05-T02). Two modes:
 *
 *  - existing coupon: reward_meta['coupon_code'] names a real WooCommerce
 *    coupon that is applied to the cart (and validated first)
 *  - generated coupon: reward_meta['coupon_generate']=true builds a
 *    deterministic, reusable coupon from the reward rules (discount type,
 *    value, max value, eligible/excluded products and categories) and
 *    applies it
 *
 * Safety: codes are validated before application (RewardSafety::coupon_exists()),
 * generated coupons are individual-use by default (unless stacking='stack'),
 * and the RewardEngine removes exactly the coupons it applied when a mission
 * becomes incomplete — the shopper's own coupons are never touched.
 */
final class CouponApplicator implements RewardApplicator {

	/**
	 * Option storing mission_id => generated coupon code, cleaned on uninstall.
	 *
	 * @var string
	 */
	const GENERATED_OPTION = 'faracart_generated_coupons';

	/**
	 * Meta key marking coupons created by this applicator.
	 *
	 * @var string
	 */
	const OWNERSHIP_META = '_faracart_generated';

	/**
	 * Per-request memo of resolved codes (mission_id => code).
	 *
	 * The RewardEngine reconciles rewards on every totals pass, so the
	 * resolved/generated code for a mission is looked up multiple times per
	 * request; this cache keeps that to one option/DB resolution per mission.
	 *
	 * @var array<int, string>
	 */
	protected static $code_cache = array();

	/**
	 * {@inheritDoc}
	 */
	public function supports( $type ) {
		return Reward::TYPE_COUPON === $type;
	}

	/**
	 * {@inheritDoc}
	 */
	public function evaluate( Reward $reward, MissionResult $result, ?CartContext $context = null ) {
		$code = $reward->coupon_code();

		if ( '' === $code && ! $reward->coupon_generate() ) {
			return RewardResult::blocked( $reward, $result->mission()->id(), RewardResult::REASON_INVALID_COUPON );
		}

		if ( '' !== $code && ! RewardSafety::coupon_exists( $code ) ) {
			return RewardResult::blocked( $reward, $result->mission()->id(), RewardResult::REASON_INVALID_COUPON );
		}

		return RewardResult::available(
			$reward,
			$result->mission()->id(),
			0.0,
			array(
				'coupon_code' => $code,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( Reward $reward, RewardResult $evaluation, \WC_Cart $cart, $mission_id ) {
		$code = $this->resolve_coupon_code( $reward, $mission_id );

		if ( '' === $code ) {
			return false;
		}

		if ( $cart->has_discount( $code ) ) {
			return true;
		}

		$cart->apply_coupon( $code );

		return $cart->has_discount( $code );
	}

	/**
	 * Resolve the coupon code to apply (existing or generated).
	 *
	 * The result is memoized per mission for the duration of the request so
	 * the per-pass reconciliation never re-queries or re-generates.
	 *
	 * @param Reward $reward  Reward configuration.
	 * @param int    $mission_id Mission id.
	 * @return string Empty when the reward has no usable code.
	 */
	public function resolve_coupon_code( Reward $reward, $mission_id ) {
		$mission_id = (int) $mission_id;

		if ( isset( self::$code_cache[ $mission_id ] ) ) {
			return self::$code_cache[ $mission_id ];
		}

		$code = '';

		if ( '' !== $reward->coupon_code() ) {
			$code = $reward->coupon_code();
		} elseif ( $reward->coupon_generate() ) {
			$code = $this->generate_coupon( $reward, $mission_id );
		}

		self::$code_cache[ $mission_id ] = $code;

		return $code;
	}

	/**
	 * Create (once) the deterministic coupon for a mission and return its code.
	 *
	 * Uses WooCommerce's public WC_Coupon API only. The coupon persists so
	 * the same mission always maps to the same code; it is removed on plugin
	 * uninstall. Generated coupons carry a '_faracart_generated' marker so
	 * a pre-existing store coupon that happens to reuse a FARACART-* code
	 * is never mistaken for this reward's coupon.
	 *
	 * @param Reward $reward  Reward configuration.
	 * @param int    $mission_id Mission id.
	 * @return string Empty when the coupon could not be created.
	 */
	protected function generate_coupon( Reward $reward, $mission_id ) {
		$generated = get_option( self::GENERATED_OPTION, array() );
		$generated = is_array( $generated ) ? $generated : array();
		$code      = RewardSafety::generated_coupon_code( $mission_id );

		if ( isset( $generated[ $mission_id ] ) && $code === $generated[ $mission_id ] && RewardSafety::coupon_exists( $code ) ) {
			$coupon_id = wc_get_coupon_id_by_code( $code );

			if ( $coupon_id && (int) get_post_meta( $coupon_id, self::OWNERSHIP_META, true ) ) {
				return $code;
			}
		}

		if ( ! class_exists( 'WC_Coupon' ) ) {
			return '';
		}

		$coupon = new \WC_Coupon();

		$coupon->set_code( $code );
		$coupon->set_discount_type( $reward->coupon_discount_type() );
		$coupon->set_amount( (float) $reward->value() );

		if ( null !== $reward->max_value() && $reward->max_value() > 0 && method_exists( $coupon, 'set_maximum_discount_amount' ) ) {
			$coupon->set_maximum_discount_amount( (float) $reward->max_value() );
		}

		if ( ! empty( $reward->eligible_products() ) ) {
			$coupon->set_product_ids( $reward->eligible_products() );
		}

		if ( ! empty( $reward->eligible_categories() ) ) {
			$coupon->set_product_categories( $reward->eligible_categories() );
		}

		if ( ! empty( $reward->excluded_products() ) ) {
			$coupon->set_excluded_product_ids( $reward->excluded_products() );
		}

		// Unintended stacking: non-stacking coupon rewards are individual-use.
		$coupon->set_individual_use( ! $reward->stacking_is_stack() );

		// The reward is personal: one use per customer.
		$coupon->set_usage_limit_per_user( 1 );

		// Mark ownership so the deterministic code is only trusted when it
		// really refers to this mission's coupon.
		$coupon->update_meta_data( self::OWNERSHIP_META, 1 );

		$coupon->save();

		// A failed save (e.g. the code is already taken by another coupon)
		// must not apply a stranger's coupon — report it as unusable.
		if ( ! $coupon->get_id() ) {
			return '';
		}

		$generated[ $mission_id ] = $coupon->get_code();
		update_option( self::GENERATED_OPTION, $generated, false );

		return $coupon->get_code();
	}
}
