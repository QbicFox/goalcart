<?php
/**
 * Tag mission evaluator.
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
 * Class TagEvaluator
 *
 * Evaluates tag missions (Phase 32): the amount or quantity restricted to
 * products carrying one or more product tags — the tag counterpart of
 * CategoryEvaluator. Which measure applies is chosen by
 * Mission::calculation_mode ('quantity', or an amount basis such as 'subtotal').
 */
class TagEvaluator implements MissionEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Mission::TYPE_TAG === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Mission $mission, CartContext $context ) {
		if ( empty( $mission->tags() ) ) {
			return MissionResult::ineligible( $mission, MissionResult::REASON_NO_MATCHING_ITEMS );
		}

		$current = $context->tag_value( $mission->tags(), $mission->calculation_mode(), $mission->excluded_products() );

		return new MissionResult( $mission, $current, $mission->target() );
	}
}
