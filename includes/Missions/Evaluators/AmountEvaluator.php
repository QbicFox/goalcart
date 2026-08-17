<?php
/**
 * Amount mission evaluator.
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
 * Class AmountEvaluator
 *
 * Evaluates amount missions: subtotal, cart total, or discounted subtotal
 * (configurable calculation basis via Mission::calculation_mode).
 */
class AmountEvaluator implements MissionEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Mission::TYPE_AMOUNT === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Mission $mission, CartContext $context ) {
		$current = $context->amount( $mission->calculation_mode(), $mission->excluded_products() );

		return new MissionResult( $mission, $current, $mission->target() );
	}
}
