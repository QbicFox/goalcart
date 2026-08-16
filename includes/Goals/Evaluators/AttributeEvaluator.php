<?php
/**
 * Attribute goal evaluator.
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
 * Class AttributeEvaluator
 *
 * Evaluates attribute goals (Phase 32): the amount or quantity restricted
 * to products carrying ANY of the configured global attribute taxonomies
 * (e.g. pa_color, pa_size) — the attribute counterpart of
 * CategoryEvaluator. Which measure applies is chosen by
 * Goal::calculation_mode ('quantity', or an amount basis such as 'subtotal').
 */
class AttributeEvaluator implements GoalEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Goal::TYPE_ATTRIBUTE === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Goal $goal, CartContext $context ) {
		if ( empty( $goal->attributes() ) ) {
			return GoalResult::ineligible( $goal, GoalResult::REASON_NO_MATCHING_ITEMS );
		}

		$current = $context->attribute_value( $goal->attributes(), $goal->calculation_mode(), $goal->excluded_products() );

		return new GoalResult( $goal, $current, $goal->target() );
	}
}
