<?php
/**
 * Mission evaluator interface.
 *
 * @package FaraCart
 */

namespace FaraCart\Missions;

defined( 'ABSPATH' ) || exit;

/**
 * Interface MissionEvaluator
 *
 * Every mission type is evaluated by a dedicated, stateless evaluator that
 * reads a CartContext and produces a MissionResult (P04-T02 pipeline step 2).
 * Evaluators are resolved through the MissionEvaluatorRegistry, which is
 * filterable so stores can add custom mission types.
 */
interface MissionEvaluator {

	/**
	 * Whether this evaluator handles the given mission type.
	 *
	 * @param string $type Mission type (Mission::TYPE_*).
	 * @return bool
	 */
	public function supports( $type );

	/**
	 * Evaluate a mission against a cart context.
	 *
	 * @param Mission        $mission    Mission to evaluate.
	 * @param CartContext $context Cart snapshot.
	 * @return MissionResult
	 */
	public function evaluate( Mission $mission, CartContext $context );
}
