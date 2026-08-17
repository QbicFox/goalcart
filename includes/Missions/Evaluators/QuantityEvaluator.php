<?php
/**
 * Quantity mission evaluator.
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
 * Class QuantityEvaluator
 *
 * Evaluates quantity missions: total item quantity in the cart (decimal-aware).
 */
class QuantityEvaluator implements MissionEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Mission::TYPE_QUANTITY === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Mission $mission, CartContext $context ) {
		$current = $context->total_quantity( $mission->excluded_products() );

		return new MissionResult( $mission, $current, $mission->target() );
	}
}
