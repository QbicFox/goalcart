<?php
/**
 * Amount goal evaluator.
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
 * Class AmountEvaluator
 *
 * Evaluates amount goals: subtotal, cart total, or discounted subtotal
 * (configurable calculation basis via Goal::calculation_mode).
 */
class AmountEvaluator implements GoalEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Goal::TYPE_AMOUNT === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Goal $goal, CartContext $context ) {
		$current = $context->amount( $goal->calculation_mode(), $goal->excluded_products() );

		return new GoalResult( $goal, $current, $goal->target() );
	}
}
