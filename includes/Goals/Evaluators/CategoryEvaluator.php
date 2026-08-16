<?php
/**
 * Category goal evaluator.
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
 * Class CategoryEvaluator
 *
 * Evaluates category goals: the amount or quantity restricted to one or
 * more product categories. Which measure applies is chosen by
 * Goal::calculation_mode ('quantity', or an amount basis such as 'subtotal').
 */
class CategoryEvaluator implements GoalEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Goal::TYPE_CATEGORY === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Goal $goal, CartContext $context ) {
		if ( empty( $goal->categories() ) ) {
			return GoalResult::ineligible( $goal, GoalResult::REASON_NO_MATCHING_ITEMS );
		}

		$current = $context->category_value( $goal->categories(), $goal->calculation_mode(), $goal->excluded_products() );

		return new GoalResult( $goal, $current, $goal->target() );
	}
}
