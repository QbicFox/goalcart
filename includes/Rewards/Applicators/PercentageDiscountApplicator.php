<?php
/**
 * Percentage discount reward applicator.
 *
 * @package FaraCart
 */

namespace FaraCart\Rewards\Applicators;

use FaraCart\Rewards\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Class PercentageDiscountApplicator
 *
 * Grants "X% off the cart" on mission completion. The percentage applies to
 * the eligible cart value (after existing discounts, excluding tax), is
 * capped by the optional max discount, and is applied as a negative cart
 * fee (see AbstractDiscountApplicator).
 */
final class PercentageDiscountApplicator extends AbstractDiscountApplicator {

	/**
	 * {@inheritDoc}
	 */
	public function type() {
		return Reward::TYPE_PERCENT_DISCOUNT;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function raw_amount( Reward $reward, $base ) {
		$percentage = (float) $reward->value();
		$amount     = $base * ( $percentage / 100.0 );

		if ( null !== $reward->max_value() && $reward->max_value() > 0 ) {
			$amount = min( $amount, (float) $reward->max_value() );
		}

		return $amount;
	}
}
