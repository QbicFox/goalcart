<?php
/**
 * Brand mission evaluator.
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
 * Class BrandEvaluator
 *
 * Evaluates brand missions (Phase 32): the amount or quantity restricted to
 * products of a single brand — modeled as a global product attribute (the
 * conventional `pa_brand` taxonomy, configurable via the
 * `faracart_brand_taxonomy` filter or the mission builder's attribute picker,
 * stored as the first entry of the mission's `attributes`).
 *
 * The evaluation is the attribute evaluator's; the type stays distinct so
 * the admin builder and the frontend can present a branded experience.
 */
class BrandEvaluator implements MissionEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Mission::TYPE_BRAND === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Mission $mission, CartContext $context ) {
		$taxonomy = $mission->brand_taxonomy();

		if ( '' === $taxonomy ) {
			return MissionResult::ineligible( $mission, MissionResult::REASON_NO_MATCHING_ITEMS );
		}

		$current = $context->attribute_value( array( $taxonomy ), $mission->calculation_mode(), $mission->excluded_products() );

		return new MissionResult( $mission, $current, $mission->target() );
	}
}
