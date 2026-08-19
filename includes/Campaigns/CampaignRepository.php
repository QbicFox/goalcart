<?php
/**
 * Campaign repository for the FaraCart REST layer.
 *
 * @package FaraCart
 */

namespace FaraCart\Campaigns;

use FaraCart\Database\Schema;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Class CampaignRepository
 *
 * shipped read-only campaign access; (Campaign Builder)
 * extends the same table and repository with the full CRUD surface:
 *
 *  - `create()` / `update()` / `delete()` / `duplicate()`
 *  - milestone ordering: campaigns own missions through `missions.campaign_id`
 *    + `missions.menu_order`; `sync_missions()` assigns an ordered mission list and
 *    detaches missions that were removed
 *  - reads carry `mission_count` (list page) and `missions` (detail/builder)
 *
 * Deleting a campaign detaches its missions (explicitly, and via the
 * `fk_faracart_missions_campaign` ON DELETE SET NULL foreign key), so mission
 * definitions survive and can be reused by other campaigns.
 */
final class CampaignRepository {

	/**
	 * All campaigns, newest first, each with its milestone count.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		global $wpdb;

		$table = Schema::table( 'campaigns' );
		$missions = Schema::table( 'missions' );

		$rows = $wpdb->get_results(
			"SELECT c.id, c.name, c.description, c.status, c.starts_at, c.ends_at, c.priority, c.display_rules, c.created_at, c.updated_at,
			        (SELECT COUNT(*) FROM {$missions} g WHERE g.campaign_id = c.id) AS mission_count
			 FROM {$table} c
			 ORDER BY c.priority ASC, c.id ASC",
			ARRAY_A
		);

		$rows = $this->normalize_rows( (array) $rows );

		foreach ( $rows as &$row ) {
			$row['mission_count'] = (int) $row['mission_count'];
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Get a single campaign with its ordered milestones.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string, mixed>|null Null when the campaign does not exist.
	 */
	public function get( $campaign_id ) {
		global $wpdb;

		$table = Schema::table( 'campaigns' );

		$row = $wpdb->get_row(
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
		$row  = $rows[0];
		$row['missions']      = $this->missions( (int) $row['id'] );
		$row['mission_count'] = count( $row['missions'] );

		return $row;
	}

	/**
	 * Create a campaign from a validated payload.
	 *
	 * Accepts a superset of the campaigns table columns plus an optional
	 * ordered `missions` array of mission ids (milestone ordering).
	 *
	 * @param array<string, mixed> $data Campaign payload.
	 * @return int New campaign id, 0 on failure.
	 */
	public function create( array $data ) {
		global $wpdb;

		$row = $this->map_columns( $data );

		if ( empty( $row ) ) {
			return 0;
		}

		$row['created_at'] = current_time( 'mysql' );
		$row['updated_at'] = $row['created_at'];

		$inserted = $wpdb->insert( Schema::table( 'campaigns' ), $row );

		if ( ! $inserted ) {
			return 0;
		}

		$campaign_id = (int) $wpdb->insert_id;

		if ( isset( $data['missions'] ) ) {
			$this->sync_missions( $campaign_id, $data['missions'] );
		}

		return $campaign_id;
	}

	/**
	 * Update a campaign from a validated partial payload.
	 *
	 * Only the keys present in $data are written. An optional `missions`
	 * array replaces the campaign's milestone membership.
	 *
	 * @param int                    $campaign_id Campaign id.
	 * @param array<string, mixed>   $data        Partial campaign payload.
	 * @return bool
	 */
	public function update( $campaign_id, array $data ) {
		global $wpdb;

		$row = $this->map_columns( $data );

		if ( ! empty( $row ) ) {
			$row['updated_at'] = current_time( 'mysql' );

			$updated = $wpdb->update(
				Schema::table( 'campaigns' ),
				$row,
				array( 'id' => (int) $campaign_id ),
				null,
				array( '%d' )
			);

			if ( false === $updated ) {
				return false;
			}
		}

		if ( isset( $data['missions'] ) ) {
			$this->sync_missions( (int) $campaign_id, $data['missions'] );
		}

		return true;
	}

	/**
	 * Delete a campaign.
	 *
	 * Missions are detached (campaign_id → null, menu_order → 0) so they can
	 * be reused; analytics history survives via ON DELETE SET NULL.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return bool
	 */
	public function delete( $campaign_id ) {
		global $wpdb;

		$missions = Schema::table( 'missions' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$missions} SET campaign_id = NULL, menu_order = 0 WHERE campaign_id = %d",
				(int) $campaign_id
			)
		);

		$deleted = $wpdb->delete(
			Schema::table( 'campaigns' ),
			array( 'id' => (int) $campaign_id ),
			array( '%d' )
		);

		return false !== $deleted;
	}

	/**
	 * Duplicate a campaign (copy with a ' (copy)' name suffix).
	 *
	 * The copy starts INACTIVE so it never runs alongside the original
	 * until the admin explicitly activates it. Its milestones are copied
	 * as new mission rows (each named ' (copy)') preserving menu_order, so
	 * the duplicate is a fully editable milestone set.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return int New campaign id, 0 when the source does not exist or the
	 *             insert failed.
	 */
	public function duplicate( $campaign_id ) {
		$campaign = $this->get( (int) $campaign_id );

		if ( null === $campaign ) {
			return 0;
		}

		$missions = $campaign['missions'];

		$data = array(
			'name'          => sprintf(
				/* translators: %s: original campaign name. */
				__( '%s (copy)', 'faracart' ),
				$campaign['name']
			),
			'description'   => $campaign['description'],
			'status'        => Mission::STATUS_INACTIVE,
			'starts_at'     => $campaign['starts_at'],
			'ends_at'       => $campaign['ends_at'],
			'priority'      => $campaign['priority'],
			'display_rules' => $campaign['display_rules'],
		);

		$copy_id = $this->create( $data );

		if ( ! $copy_id ) {
			return 0;
		}

		// Copy each milestone as a new mission bound to the copy.
		$mission_repo = new MissionRepository();

		foreach ( $missions as $mission ) {
			$new_mission_id = $mission_repo->duplicate( (int) $mission['id'] );

			if ( ! $new_mission_id ) {
				continue;
			}

			$this->assign_mission( $copy_id, $new_mission_id, (int) $mission['menu_order'] );
		}

		return $copy_id;
	}

	/**
	 * Ordered milestones of a campaign (engine display order).
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<int, array<string, mixed>> Each: id, name, type,
	 *                                         target, reward_type, menu_order.
	 */
	public function missions( $campaign_id ) {
		global $wpdb;

		$missions = Schema::table( 'missions' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, type, target, reward_type, menu_order
				 FROM {$missions}
				 WHERE campaign_id = %d
				 ORDER BY menu_order ASC, id ASC",
				(int) $campaign_id
			),
			ARRAY_A
		);

		$items = array();

		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'          => (int) $row['id'],
				'name'        => (string) $row['name'],
				'type'        => (string) $row['type'],
				'target'      => (float) $row['target'],
				'reward_type' => ! empty( $row['reward_type'] ) ? (string) $row['reward_type'] : null,
				'menu_order'  => (int) $row['menu_order'],
			);
		}

		return $items;
	}

	/**
	 * Replace a campaign's milestone membership with an ordered mission list.
	 *
	 * The given mission ids (in milestone order) become the campaign's missions
	 * with menu_order 1..N; missions previously in this campaign that are no
	 * longer listed are detached.
	 *
	 * @param int   $campaign_id Campaign id.
	 * @param mixed $mission_ids    Ordered mission ids (validated ints).
	 * @return void
	 */
	protected function sync_missions( $campaign_id, $mission_ids ) {
		global $wpdb;

		$missions = Schema::table( 'missions' );

		$keep = $this->positive_ints( $mission_ids );

		// Detach missions that were in this campaign but are no longer listed.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$missions} SET campaign_id = NULL, menu_order = 0 WHERE campaign_id = %d",
				$campaign_id
			)
		);

		foreach ( array_values( $keep ) as $index => $mission_id ) {
			$this->assign_mission( $campaign_id, $mission_id, $index + 1 );
		}
	}

	/**
	 * Bind a single mission to a campaign at a milestone position.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $mission_id     Mission id.
	 * @param int $menu_order  Milestone position (1-based).
	 * @return void
	 */
	protected function assign_mission( $campaign_id, $mission_id, $menu_order ) {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'missions' ),
			array(
				'campaign_id' => $campaign_id,
				'menu_order'  => $menu_order,
			),
			array( 'id' => (int) $mission_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Map payload keys onto campaigns table columns.
	 *
	 * Only keys present in the payload are written (partial updates).
	 *
	 * @param array<string, mixed> $data Campaign payload.
	 * @return array<string, mixed>
	 */
	protected function map_columns( array $data ) {
		$row = array();

		foreach ( array(
			'name'         => 'text',
			'description'  => 'textarea',
			'status'       => 'key',
			'starts_at'    => 'datetime_nullable',
			'ends_at'      => 'datetime_nullable',
			'priority'     => 'int',
		) as $column => $type ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			$row[ $column ] = $this->sanitize_column( $type, $data[ $column ] );
		}

		if ( array_key_exists( 'display_rules', $data ) ) {
			$value = $data['display_rules'];

			$row['display_rules'] = is_array( $value ) && ! empty( $value )
				? wp_json_encode( $value )
				: null;
		}

		return $row;
	}

	/**
	 * Sanitize a single column value by type.
	 *
	 * @param string $type  Column type key.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	protected function sanitize_column( $type, $value ) {
		switch ( $type ) {
			case 'text':
				return sanitize_text_field( (string) $value );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'key':
				return sanitize_key( (string) $value );

			case 'int':
				return max( 0, (int) $value );

			case 'datetime_nullable':
				$value = (string) $value;

				return '' === $value ? null : $value;
		}

		return $value;
	}

	/**
	 * Cast a mixed value to a list of positive ints.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	protected function positive_ints( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', $value ), function ( $id ) {
			return $id > 0;
		} ) );
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

			if ( ! isset( $row['display_rules'] ) || ! is_array( $row['display_rules'] ) ) {
				$row['display_rules'] = array();
			}
		}

		unset( $row );

		return $rows;
	}
}
