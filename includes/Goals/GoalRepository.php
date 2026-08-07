<?php
/**
 * Goal repository for the Goal Cart engine.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals;

use GoalCart\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Class GoalRepository
 *
 * Loads goal definitions from the `goalcart_goals` table and turns them
 * into Goal value objects. Phase 5 (Reward Engine) is the first consumer:
 * the RewardEngine reads the active goals once per request to decide which
 * rewards apply to the live cart. Later phases (REST admin, campaigns)
 * extend the same repository.
 *
 * The engine itself stays database-free (Phase 4 contract); persistence
 * concerns live here and in the Schema/Installer pair.
 */
final class GoalRepository {

	/**
	 * Per-request cache of active goals.
	 *
	 * @var Goal[]|null
	 */
	protected $cache;

	/**
	 * All goals currently eligible to run (status active, campaign active).
	 *
	 * Ordered by priority (ascending — lower number wins) then id, which is
	 * the deterministic conflict-resolution order the RewardEngine relies
	 * on. Results are cached per request so multiple evaluations during one
	 * page load run a single query.
	 *
	 * @return Goal[]
	 */
	public function active_goals() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		global $wpdb;

		$goals     = Schema::table( 'goals' );
		$campaigns = Schema::table( 'campaigns' );

		// The LEFT JOIN folds campaign gating (status + schedule) into the
		// goal so a goal inside an inactive or out-of-schedule campaign can
		// never grant rewards.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.*, c.status AS campaign_status, c.starts_at AS campaign_starts_at, c.ends_at AS campaign_ends_at
				 FROM {$goals} g
				 LEFT JOIN {$campaigns} c ON c.id = g.campaign_id
				 WHERE g.status = %s
				 ORDER BY g.priority ASC, g.id ASC",
				Goal::STATUS_ACTIVE
			),
			ARRAY_A
		);

		$this->cache = array();

		foreach ( (array) $rows as $row ) {
			$goal = new Goal( $this->normalize( $row ) );

			if ( $goal->is_active() ) {
				$this->cache[] = $goal;
			}
		}

		return $this->cache;
	}

	/**
	 * Find a single goal by id.
	 *
	 * @param int $goal_id Goal id.
	 * @return Goal|null Null when the goal does not exist.
	 */
	public function find( $goal_id ) {
		global $wpdb;

		$goals = Schema::table( 'goals' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$goals} WHERE id = %d", (int) $goal_id ),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return null;
		}

		return new Goal( $this->normalize( $row ) );
	}

	/**
	 * Normalize a raw database row into a Goal constructor payload.
	 *
	 * Decodes the JSON columns and folds campaign schedule/status onto the
	 * goal (a goal's own dates take precedence; otherwise the campaign
	 * window applies).
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	protected function normalize( array $row ) {
		foreach ( array( 'reward_meta', 'conditions', 'display_settings', 'limits' ) as $json_col ) {
			if ( isset( $row[ $json_col ] ) && is_string( $row[ $json_col ] ) && '' !== $row[ $json_col ] ) {
				$decoded = json_decode( $row[ $json_col ], true );
				$row[ $json_col ] = is_array( $decoded ) ? $decoded : array();
			}
		}

		if ( ! empty( $row['campaign_id'] ) ) {
			if ( ! empty( $row['campaign_status'] ) && Goal::STATUS_ACTIVE !== $row['campaign_status'] ) {
				$row['status'] = Goal::STATUS_INACTIVE;
			}

			$campaign_starts = ! empty( $row['campaign_starts_at'] ) ? (string) $row['campaign_starts_at'] : null;
			$campaign_ends   = ! empty( $row['campaign_ends_at'] ) ? (string) $row['campaign_ends_at'] : null;

			if ( $campaign_starts ) {
				$row['starts_at'] = ( ! empty( $row['starts_at'] ) && $row['starts_at'] > $campaign_starts ) ? $row['starts_at'] : $campaign_starts;
			}

			if ( $campaign_ends ) {
				$row['ends_at'] = ( ! empty( $row['ends_at'] ) && $row['ends_at'] < $campaign_ends ) ? $row['ends_at'] : $campaign_ends;
			}
		}

		unset( $row['campaign_status'], $row['campaign_starts_at'], $row['campaign_ends_at'] );

		return $row;
	}
}
