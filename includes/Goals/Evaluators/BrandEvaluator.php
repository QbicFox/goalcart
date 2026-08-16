<?php
/**
 * Brand goal evaluator.
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
 * Class BrandEvaluator
 *
 * Evaluates brand goals (Phase 32): the amount or quantity restricted to
 * products of a single brand — modeled as a global product attribute (the
 * conventional `pa_brand` taxonomy, configurable via the
 * `faracart_brand_taxonomy` filter or the goal builder's attribute picker,
 * stored as the first entry of the goal's `attributes`).
 *
 * The evaluation is the attribute evaluator's; the type stays distinct so
 * the admin builder and the frontend can present a branded experience.
 */
class BrandEvaluator implements GoalEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Goal::TYPE_BRAND === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Goal $goal, CartContext $context ) {
		$taxonomy = $goal->brand_taxonomy();

		if ( '' === $taxonomy ) {
			return GoalResult::ineligible( $goal, GoalResult::REASON_NO_MATCHING_ITEMS );
		}

		$current = $context->attribute_value( array( $taxonomy ), $goal->calculation_mode(), $goal->excluded_products() );

		return new GoalResult( $goal, $current, $goal->target() );
	}
}
