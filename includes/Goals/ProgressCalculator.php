<?php
/**
 * Shared progress math for the FaraCart engine.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProgressCalculator
 *
 * Pure, static math shared by every evaluator so progress is always
 * computed the same way (P04-T02 architecture step 4).
 *
 * Rules:
 *  - remaining is target - current, never negative
 *  - percentage is capped at 100 and never below 0
 *  - a non-positive target is trivially completed (100%) — negative targets
 *    are rejected earlier by GoalEngine as invalid, so only zero reaches here
 */
final class ProgressCalculator {

	/**
	 * Remaining = max( 0, target - current ).
	 *
	 * @param float $current Current value.
	 * @param float $target  Target value.
	 * @return float
	 */
	public static function remaining( $current, $target ) {
		return max( 0.0, (float) $target - (float) $current );
	}

	/**
	 * Percentage 0-100, capped at 100.
	 *
	 * @param float $current Current value.
	 * @param float $target  Target value.
	 * @return float
	 */
	public static function percentage( $current, $target ) {
		$current = max( 0.0, (float) $current );
		$target  = (float) $target;

		if ( $target <= 0 ) {
			return 100.0;
		}

		return round( min( 100.0, ( $current / $target ) * 100.0 ), 2 );
	}

	/**
	 * Whether the target has been reached.
	 *
	 * @param float $current Current value.
	 * @param float $target  Target value.
	 * @return bool
	 */
	public static function completed( $current, $target ) {
		$target = (float) $target;

		if ( $target <= 0 ) {
			return true;
		}

		return (float) $current >= $target;
	}
}
