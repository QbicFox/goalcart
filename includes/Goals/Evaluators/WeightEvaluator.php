<?php
/**
 * Weight goal evaluator.
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
