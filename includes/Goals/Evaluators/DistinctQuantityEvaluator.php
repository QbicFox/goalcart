<?php
/**
 * Distinct quantity goal evaluator.
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
 * Class DistinctQuantityEvaluator
 *
 * Evaluates distinct-quantity goals: the number of unique products/SKUs in
 * the cart (a product bought twice counts once; variations count as
 * distinct SKUs).
 */
class DistinctQuantityEvaluator implements GoalEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Goal::TYPE_DISTINCT_QUANTITY === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Goal $goal, CartContext $context ) {
		$current = $context->distinct_product_count( $goal->excluded_products() );

		return new GoalResult( $goal, $current, $goal->target() );
	}
}
