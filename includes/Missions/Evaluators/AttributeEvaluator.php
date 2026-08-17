<?php
/**
 * Attribute mission evaluator.
 *
 * @package FaraCart
 */

namespace FaraCart\Missions\Evaluators;

use FaraCart\Missions\CartContext;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionEvaluator;
use FaraCart\Missions\MissionResult;

defined( 'ABSPATH' ) || exit;

/**
 * Class AttributeEvaluator
 *
 * Evaluates attribute missions (Phase 32): the amount or quantity restricted
 * to products carrying ANY of the configured global attribute taxonomies
 * (e.g. pa_color, pa_size) — the attribute counterpart of
 * CategoryEvaluator. Which measure applies is chosen by
 * Mission::calculation_mode ('quantity', or an amount basis such as 'subtotal').
 */
class AttributeEvaluator implements MissionEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Mission::TYPE_ATTRIBUTE === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Mission $mission, CartContext $context ) {
		if ( empty( $mission->attributes() ) ) {
			return MissionResult::ineligible( $mission, MissionResult::REASON_NO_MATCHING_ITEMS );
		}

		$current = $context->attribute_value( $mission->attributes(), $mission->calculation_mode(), $mission->excluded_products() );

		return new MissionResult( $mission, $current, $mission->target() );
	}
}
