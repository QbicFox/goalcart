<?php
/**
 * Distinct quantity mission evaluator.
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
 * Class DistinctQuantityEvaluator
 *
 * Evaluates distinct-quantity missions: the number of unique products/SKUs in
 * the cart (a product bought twice counts once; variations count as
 * distinct SKUs).
 */
class DistinctQuantityEvaluator implements MissionEvaluator {

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Mission::TYPE_DISTINCT_QUANTITY === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Mission $mission, CartContext $context ) {
		$current = $context->distinct_product_count( $mission->excluded_products() );

		return new MissionResult( $mission, $current, $mission->target() );
	}
}
