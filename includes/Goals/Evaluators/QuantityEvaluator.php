<?php
/**
 * Quantity goal evaluator.
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
