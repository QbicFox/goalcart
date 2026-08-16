<?php
/**
 * Tag goal evaluator.
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
 * Class TagEvaluator
 *
 * Evaluates tag goals (Phase 32): the amount or quantity restricted to
 * products carrying one or more product tags — the tag counterpart of
 * CategoryEvaluator. Which measure applies is chosen by
 * Goal::calculation_mode ('quantity', or an amount basis such as 'subtotal').
 */
class TagEvaluator implements GoalEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Goal::TYPE_TAG === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Goal $goal, CartContext $context ) {
		if ( empty( $goal->tags() ) ) {
			return GoalResult::ineligible( $goal, GoalResult::REASON_NO_MATCHING_ITEMS );
		}

		$current = $context->tag_value( $goal->tags(), $goal->calculation_mode(), $goal->excluded_products() );

		return new GoalResult( $goal, $current, $goal->target() );
	}
}
