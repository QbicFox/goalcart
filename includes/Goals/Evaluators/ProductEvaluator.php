<?php
/**
 * Product goal evaluator.
 *
 * @package FaraCart
 */

namespace FaraCart\Goals\Evaluators;

use FaraCart\Goals\CartContext;
use FaraCart\Goals\Goal;
use FaraCart\Goals\GoalEvaluator;
use FaraCart\Goals\GoalResult;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProductEvaluator
 *
 * Evaluates product goals: quantity or amount restricted to specific
 * products and/or variations. Matching is by effective product id (the
 * variation id when present, otherwise the parent product), so both a
 * variation and its parent can be targeted.
 */
class ProductEvaluator implements GoalEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Goal::TYPE_PRODUCT === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Goal $goal, CartContext $context ) {
		if ( empty( $goal->products() ) ) {
			return GoalResult::ineligible( $goal, GoalResult::REASON_NO_MATCHING_ITEMS );
		}

		$current = $context->product_value( $goal->products(), $goal->calculation_mode(), $goal->excluded_products() );

		return new GoalResult( $goal, $current, $goal->target() );
	}
}
