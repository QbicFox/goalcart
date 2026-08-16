<?php
/**
 * Distinct quantity goal evaluator.
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
