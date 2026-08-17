<?php
/**
 * Category mission evaluator.
 *
 * @package FaraCart
 */

namespace FaraCart\Missions\Evaluators;

use FaraCart\Missions\CartContext;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionEvaluator;
use FaraCart\Missions\MissionResult;

defined( 'ABSPATH' ) || exit;

/**
 * Class CategoryEvaluator
 *
 * Evaluates category missions: the amount or quantity restricted to one or
 * more product categories. Which measure applies is chosen by
 * Mission::calculation_mode ('quantity', or an amount basis such as 'subtotal').
 */
class CategoryEvaluator implements MissionEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Mission::TYPE_CATEGORY === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Mission $mission, CartContext $context ) {
		if ( empty( $mission->categories() ) ) {
			return MissionResult::ineligible( $mission, MissionResult::REASON_NO_MATCHING_ITEMS );
		}

		$current = $context->category_value( $mission->categories(), $mission->calculation_mode(), $mission->excluded_products() );

		return new MissionResult( $mission, $current, $mission->target() );
	}
}
