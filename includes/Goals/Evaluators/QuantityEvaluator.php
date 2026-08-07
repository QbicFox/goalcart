<?php
/**
 * Quantity goal evaluator.
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
 * Class QuantityEvaluator
 *
 * Evaluates quantity goals: total item quantity in the cart (decimal-aware).
 */
class QuantityEvaluator implements GoalEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Goal::TYPE_QUANTITY === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Goal $goal, CartContext $context ) {
		$current = $context->total_quantity( $goal->excluded_products() );

		return new GoalResult( $goal, $current, $goal->target() );
	}
}
