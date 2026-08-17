<?php
/**
 * Weight mission evaluator.
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
 * Class WeightEvaluator
 *
 * Evaluates weight missions: the total cart weight (quantity x unit weight,
 * in the store's configured unit). Products without a weight contribute 0.
 */
class WeightEvaluator implements MissionEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Mission::TYPE_WEIGHT === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Mission $mission, CartContext $context ) {
		$current = $context->total_weight( $mission->excluded_products() );

		return new MissionResult( $mission, $current, $mission->target() );
	}
}
