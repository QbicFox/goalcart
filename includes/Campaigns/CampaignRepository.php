<?php
/**
 * Campaign repository for the Goal Cart REST layer.
 *
 * @package GoalCart
 */

namespace GoalCart\Campaigns;

use GoalCart\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Class CampaignRepository
 *
 * Read-only campaign access for Phase 7 (REST API / AJAX Layer): the goal
 * builder needs the campaign list (and a single campaign lookup) to assign
 * goals to campaigns. The full campaign CRUD, milestone ordering and
 * scheduling surface is implemented by Phase 10 (Campaign Builder) on top
 * of the same table and repository.
 */
final class CampaignRepository {

	/**
	 * All campaigns, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		global $wpdb;

		$table = Schema::table( 'campaigns' );
		$rows  = $wpdb->get_results(
			"SELECT id, name, description, status, starts_at, ends_at, priority, display_rules, created_at, updated_at
			 FROM {$table}
			 ORDER BY priority ASC, id ASC",
			ARRAY_A
		);

		return $this->normalize_rows( (array) $rows );
	}

	/**
	 * Get a single campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string, mixed>|null Null when the campaign does not exist.
	 */
	public function get( $campaign_id ) {
		global $wpdb;

		$table = Schema::table( 'campaigns' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, description, status, starts_at, ends_at, priority, display_rules, created_at, updated_at
				 FROM {$table}
				 WHERE id = %d",
				(int) $campaign_id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return null;
		}

		$rows = $this->normalize_rows( array( $row ) );

		return $rows[0];
	}

	/**
	 * Decode the display_rules JSON column on every row.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw rows.
	 * @return array<int, array<string, mixed>>
	 */
	protected function normalize_rows( array $rows ) {
		foreach ( $rows as &$row ) {
			if ( isset( $row['display_rules'] ) && is_string( $row['display_rules'] ) && '' !== $row['display_rules'] ) {
				$decoded = json_decode( $row['display_rules'], true );
				$row['display_rules'] = is_array( $decoded ) ? $decoded : array();
			}
		}

		unset( $row );

		return $rows;
	}
}
