<?php
/**
 * Composite mission evaluator.
 *
 * @package FaraCart
 */

namespace FaraCart\Missions\Evaluators;

use FaraCart\Missions\CartContext;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionEngine;
use FaraCart\Missions\MissionEvaluator;
use FaraCart\Missions\MissionEvaluatorRegistry;
use FaraCart\Missions\MissionResult;

defined( 'ABSPATH' ) || exit;

/**
 * Class CompositeEvaluator
 *
 * Evaluates composite missions: AND/OR combinations of child missions, each child
 * defined as a Mission::from_array() payload and evaluated against the same
 * CartContext through the registry.
 *
 * Combination semantics (documented in docs/mission-engine.md):
 *  - AND: progress is the weakest child (min percentage); completed only
 *         when every child is complete. current/target are the sums of the
 *         children's values. An ineligible child keeps the mission incomplete.
 *  - OR:  progress is the best child (max percentage); completed as soon as
 *         any child completes. current/target mirror the best child.
 */
class CompositeEvaluator implements MissionEvaluator {

	/**
	 * Evaluator registry for resolving child missions.
	 *
	 * @var MissionEvaluatorRegistry
	 */
	protected $registry;

	/**
	 * Constructor.
	 *
	 * @param MissionEvaluatorRegistry $registry Registry used for children.
	 */
	public function __construct( MissionEvaluatorRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Mission::TYPE_COMPOSITE === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Mission $mission, CartContext $context ) {
		$children = $mission->children();

		if ( empty( $children ) ) {
			return MissionResult::ineligible( $mission, MissionResult::REASON_NO_MATCHING_ITEMS );
		}

		$results   = array();
		$reasons   = array();

		foreach ( $children as $child_data ) {
			if ( ! is_array( $child_data ) ) {
				continue;
			}

			// Children inherit the parent's status unless they override it.
			if ( ! isset( $child_data['status'] ) ) {
				$child_data['status'] = $mission->status();
			}

			$child = new Mission( $child_data );

			// Children are held to the same eligibility rules as top-level
			// missions (status, schedule, target validity).
			$child_reason = MissionEngine::eligibility_reason( $child );

			if ( MissionResult::REASON_NONE !== $child_reason ) {
				$reasons[] = $child_reason;
				continue;
			}

			// Unsupported child types are treated as ineligible rather than
			// throwing, mirroring MissionEngine's own unknown-type handling.
			if ( ! $this->registry->supports( $child->type() ) ) {
				$reasons[] = MissionResult::REASON_UNKNOWN_TYPE;
				continue;
			}

			$result = $this->registry->evaluator( $child->type() )->evaluate( $child, $context );

			if ( $result->eligible() ) {
				$results[] = $result;
			} else {
				$reasons[] = $result->reason();
			}
		}

		if ( empty( $results ) ) {
			return MissionResult::ineligible(
				$mission,
				! empty( $reasons ) ? $reasons[0] : MissionResult::REASON_NO_MATCHING_ITEMS
			);
		}

		if ( $mission->is_operator_or() ) {
			// OR: the best child drives progress and completion.
			$best = $results[0];
			foreach ( $results as $result ) {
				if ( $result->percentage() > $best->percentage() ) {
					$best = $result;
				}
			}

			return MissionResult::build( $mission, $best->current(), $best->target(), $best->percentage(), $best->completed() );
		}

		// AND: weakest child drives progress; every child must complete.
		// An ineligible child keeps the mission incomplete (per the docs).
		$weakest     = $results[0];
		$current_sum = 0.0;
		$target_sum  = 0.0;
		$all_done    = empty( $reasons );

		foreach ( $results as $result ) {
			$current_sum += $result->current();
			$target_sum  += $result->target();

			if ( $result->percentage() < $weakest->percentage() ) {
				$weakest = $result;
			}

			if ( ! $result->completed() ) {
				$all_done = false;
			}
		}

		return MissionResult::build( $mission, $current_sum, $target_sum, $weakest->percentage(), $all_done );
	}
}
