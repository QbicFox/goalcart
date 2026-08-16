<?php
/**
 * Fixed discount reward applicator.
 *
 * @package FaraCart
 */

namespace FaraCart\Rewards\Applicators;

use FaraCart\Rewards\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Class FixedDiscountApplicator
 *
 * Grants a fixed amount off the cart on goal completion, clamped to the
 * eligible cart value so the discount can never exceed what the shopper
 * actually owes on eligible items. Applied as a negative cart fee (see
 * AbstractDiscountApplicator).
 */
final class FixedDiscountApplicator extends AbstractDiscountApplicator {

	/**
	 * {@inheritDoc}
	 */
	public function type() {
		return Reward::TYPE_FIXED_DISCOUNT;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function raw_amount( Reward $reward, $base ) {
		return (float) $reward->value();
	}
}
