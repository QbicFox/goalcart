<?php
/**
 * Conflict & priority resolution for the FaraCart engine.
 *
 * @package FaraCart
 */

namespace FaraCart\Missions;

use FaraCart\Rewards\Reward;
use FaraCart\Rewards\RewardResult;
use FaraCart\Rewards\RewardSafety;

defined( 'ABSPATH' ) || exit;

/**
 * Class ConflictResolver
 *
 * Conflict & Priority Engine: the single deterministic rule for
 * what happens when multiple missions/campaigns are active at the same time.
 *
 * The resolver is pure and stateless — it takes the ACTIVE missions in their
 * deterministic order (campaign priority → mission priority → id, produced by
 * MissionRepository::active_missions()) plus the MissionResult of every COMPLETED
 * mission that carries a reward, and answers one question per mission:
 *
 *   does this mission get to grant its reward, and if not, why?
 *
 * Resolution modes (store-wide `conflict_resolution` setting):
 *
 *  - cumulative — every completed mission grants (subject to the per-reward
 *    stacking rules in RewardSafety). The default, preserving the
 *    previous behavior.
 *  - best       — only the completed mission with the most valuable reward
 *    grants. "Value" is the reward's computed discount amount on the
 *    current cart when the caller provides it (RewardEngine pass), else a
 *    deterministic static score (fixed/percentage value; free shipping,
 *    gifts and coupons count as equal-value offers). Ties break by
 *    priority order, then id.
 *  - first      — only the first completed mission in priority order grants
 *    ("first matching mission"); every later completed mission is suppressed.
 *
 * Mutually exclusive missions: a mission marked `exclusive` that is COMPLETED
 * suppresses every lower-priority completed mission in every mode. Priority
 * is respected — missions ABOVE the exclusive mission are unaffected, so an
 * exclusive mission is "I win over everything below me".
 *
 * Suppressed missions receive a machine-readable reason (REASON_* constants)
 * that flows into the RewardResult and the progress payload so both the
 * reward engine and the admin/storefront UI communicate the same behavior.
 */
final class ConflictResolver {

	/**
	 * Resolution modes.
	 */
	const MODE_CUMULATIVE = 'cumulative';
	const MODE_BEST       = 'best';
	const MODE_FIRST      = 'first';

	/**
	 * Suppression reasons. REASON_NONE means the mission wins (grants).
	 * REASON_STACKING is the same string as RewardResult::REASON_STACKING
	 * so every payload reason is defined here on the resolver.
	 */
	const REASON_NONE           = '';
	const REASON_LOWER_PRIORITY = 'lower_priority';
	const REASON_EXCLUSIVE      = 'exclusive';
	const REASON_NOT_BEST       = 'not_best';
	const REASON_NOT_FIRST      = 'not_first';
	const REASON_STACKING       = 'stacking';

	/**
	 * The whitelist of resolution modes (REST schema enum + settings UI).
	 *
	 * @return string[]
	 */
	public static function modes() {
		return array( self::MODE_CUMULATIVE, self::MODE_BEST, self::MODE_FIRST );
	}

	/**
	 * Resolve which completed missions may grant their rewards.
	 *
	 * Contract: `$missions` must be the active missions in the deterministic
	 * conflict order (campaign priority → mission priority → id) and
	 * `$results` must contain ONLY eligible, completed missions that carry a
	 * reward — callers (RewardEngine, FrontendController, PreviewController)
	 * filter before calling. Missions not present in `$results` are simply
	 * ignored (they grant nothing regardless).
	 *
	 * @param Mission[]                   $missions   Ordered active missions.
	 * @param array<int, MissionResult>   $results mission_id => completed MissionResult.
	 * @param string                   $mode    MODE_* constant.
	 * @param array<int, float>        $scores  Optional mission_id => computed
	 *                                          reward amount (RewardEngine
	 *                                          pass) for 'best' comparisons.
	 * @return array<int, string> mission_id => REASON_* ('' = grants).
	 */
	public function resolve( array $missions, array $results, $mode = self::MODE_CUMULATIVE, array $scores = array() ) {
		$resolution = array();
		$ids        = array();
		$mission_map   = array();

		foreach ( $missions as $mission ) {
			$id = (int) $mission->id();

			$mission_map[ $id ] = $mission;

			// Only completed, rewarded missions participate in resolution.
			if ( isset( $results[ $id ] ) ) {
				$ids[]             = $id;
				$resolution[ $id ] = self::REASON_NONE;
			}
		}

		// Mutually exclusive missions are resolved FIRST, in priority order:
		// a completed exclusive mission suppresses every lower-priority
		// completed mission, and the exclusive mission itself is guaranteed to
		// win — mode selection below only ever competes the remaining
		// (non-suppressed) missions, so an exclusive mission can never be
		// undone by 'best'.
		$winners        = $ids;
		$exclusive_seen = false;

		foreach ( $ids as $index => $id ) {
			if ( $exclusive_seen ) {
				$resolution[ $id ] = self::REASON_EXCLUSIVE;
				unset( $winners[ $index ] );
				continue;
			}

			$mission = isset( $mission_map[ $id ] ) ? $mission_map[ $id ] : null;

			if ( $mission && $mission->is_exclusive() ) {
				$exclusive_seen = true;
			}
		}

		$winners = array_values( $winners );

		if ( self::MODE_FIRST === $mode ) {
			// First matching mission wins; every later completed mission loses.
			foreach ( array_slice( $winners, 1 ) as $id ) {
				$resolution[ $id ] = self::REASON_NOT_FIRST;
			}
		} elseif ( self::MODE_BEST === $mode ) {
			$best_id = $this->best_mission_id( $winners, $results, $scores );

			foreach ( $winners as $id ) {
				if ( $id !== $best_id ) {
					$resolution[ $id ] = self::REASON_NOT_BEST;
				}
			}
		}

		return $resolution;
	}

	/**
	 * Apply the per-reward stacking safety to a resolution, in priority order.
	 *
	 * Mirrors RewardEngine::sync_cart() pass 2 exactly: winners (REASON_NONE)
	 * grant in mission order, a non-stacking reward may only be the first of its
	 * type (later same-type winners are blocked with the 'stacking' reason),
	 * and reward-less / not-applicable winners never consume a type slot. The
	 * display paths (FrontendController, PreviewController) call this with the
	 * same evaluated RewardResults the engine uses, so the payload's conflict
	 * reasons are always what the live cart grants — including in cumulative
	 * mode, where two same-type non-stacking rewards never both show as won.
	 *
	 * @param Mission[]                 $missions          Missions in deterministic order.
	 * @param array<int, string>     $resolution     mission_id => REASON_*.
	 * @param array<int, RewardResult> $reward_results mission_id => evaluated
	 *                                               RewardResult (empty
	 *                                               already_applied pass).
	 * @return array<int, string> Updated resolution (stacking reasons added).
	 */
	public function apply_stacking( array $missions, array $resolution, array $reward_results ) {
		$already_applied = array();

		foreach ( $missions as $mission ) {
			$id = (int) $mission->id();

			if ( ! isset( $resolution[ $id ] ) || self::REASON_NONE !== $resolution[ $id ] ) {
				// Not a winner (incomplete, reward-less, or conflict-suppressed).
				continue;
			}

			$reward_result = isset( $reward_results[ $id ] ) ? $reward_results[ $id ] : null;

			if ( ! $reward_result || RewardResult::STATE_NOT_APPLICABLE === $reward_result->state() ) {
				continue;
			}

			// A winner whose reward is itself blocked (e.g. invalid coupon)
			// but whose type was already consumed by an earlier winner is
			// reported 'stacking' here; it never consumes a slot, matching
			// the engine's pass 2 (the engine keeps its real reason — an
			// ultra corner case, accepted for a shared display contract).
			if ( ! RewardSafety::stacking_allows( $reward_result->reward(), $already_applied ) ) {
				$resolution[ $id ] = self::REASON_STACKING;
				continue;
			}

			if ( RewardResult::STATE_AVAILABLE === $reward_result->state() ) {
				$already_applied[] = $reward_result->type();
			}
		}

		return $resolution;
	}

	/**
	 * The deterministic reward score for 'best' mode.
	 *
	 * Uses the caller-provided computed amount (the RewardEngine pass, where
	 * percentage discounts are resolved to their actual cart value) when it
	 * is positive; otherwise falls back to a static score from the mission's
	 * reward configuration. Free shipping, gifts and coupons are treated as
	 * equal-value offers (score 0) — ties resolve by priority order.
	 *
	 * @param MissionResult $result Mission evaluation result (completed).
	 * @param float|null $amount Computed reward amount, when available.
	 * @return float
	 */
	public static function reward_score( MissionResult $result, $amount = null ) {
		$score = null !== $amount ? (float) $amount : 0.0;

		if ( $score > 0 ) {
			return $score;
		}

		$mission = $result->mission();

		switch ( $mission->reward_type() ) {
			case Reward::TYPE_FIXED_DISCOUNT:
			case Reward::TYPE_PERCENT_DISCOUNT:
				return (float) $mission->reward_value();

			default:
				return 0.0;
		}
	}

	/**
	 * Pick the id of the completed mission with the best reward.
	 *
	 * Deterministic: highest score wins; equal scores go to the earlier
	 * mission in priority order (the first id in `$ids`).
	 *
	 * @param int[]                  $ids     Completed mission ids in priority order.
	 * @param array<int, MissionResult> $results mission_id => MissionResult.
	 * @param array<int, float>      $scores  Computed reward amounts.
	 * @return int|null
	 */
	protected function best_mission_id( array $ids, array $results, array $scores ) {
		$best_id    = null;
		$best_score = -INF;

		foreach ( $ids as $id ) {
			$score = self::reward_score(
				$results[ $id ],
				isset( $scores[ $id ] ) ? $scores[ $id ] : null
			);

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_id    = $id;
			}
		}

		return $best_id;
	}
}
