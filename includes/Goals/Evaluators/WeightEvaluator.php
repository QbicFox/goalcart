<?php
/**
 * Weight goal evaluator.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals\Evaluators;

use GoalCart\Goals\CartContext;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEvaluator;
use GoalCart\Goals\GoalResult;

defined( 'ABSPATH' ) || exit;

/**
 * Class WeightEvaluator
 *
 * Evaluates weight goals: the total cart weight (quantity x unit weight,
 * in the store's configured unit). Products without a weight contribute 0.
 */
class WeightEvaluator implements GoalEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Goal::TYPE_WEIGHT === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Goal $goal, CartContext $context ) {
		$current = $context->total_weight( $goal->excluded_products() );

		return new GoalResult( $goal, $current, $goal->target() );
	}
}
