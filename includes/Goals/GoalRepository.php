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
	 * The campaign-priority a standalone goal competes at.
	 *
	 * Goals inside a campaign sort by their campaign's priority; goals
	 * without a campaign behave as if their campaign priority were the
	 * schema default (10), so standalone goals interleave with campaigns
	 * deterministically (Phase 26).
	 *
	 * @var int
	 */
	const DEFAULT_CAMPAIGN_PRIORITY = 10;

	/**
	 * Per-request cache of active goals.
	 *
	 * @var Goal[]|null
	 */
	protected $cache;

	/**
	 * All goals currently eligible to run (status active, campaign active).
	 *
	 * Ordered by campaign priority (ascending — lower number wins;
	 * standalone goals compete at DEFAULT_CAMPAIGN_PRIORITY), then goal
	 * priority, then id — the deterministic Phase 26 conflict-resolution
	 * order the RewardEngine and the storefront payload rely on. Results
	 * are cached per request so multiple evaluations during one page load
	 * run a single query.
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
		// never grant rewards. Campaign priority is the primary sort key so
		// a higher-priority campaign wins conflicts over a lower-priority
		// one regardless of the individual goals' priorities.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.*, c.name AS campaign_name, c.status AS campaign_status, c.starts_at AS campaign_starts_at, c.ends_at AS campaign_ends_at, c.display_rules AS campaign_display_rules
				 FROM {$goals} g
				 LEFT JOIN {$campaigns} c ON c.id = g.campaign_id
				 WHERE g.status = %s
				 ORDER BY COALESCE(c.priority, %d) ASC, g.priority ASC, g.id ASC",
				Goal::STATUS_ACTIVE,
				self::DEFAULT_CAMPAIGN_PRIORITY
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
	}	/**
	 * Find a single goal by id (engine path: campaign folding applied).
	 *
	 * Runs the campaign join (unlike get(), which returns stored values for
	 * the admin CRUD), so the returned Goal reflects the effective
	 * campaign gating exactly like active_goals().
	 *
	 * @param int $goal_id Goal id.
	 * @return Goal|null Null when the goal does not exist.
	 */
	public function find( $goal_id ) {
		global $wpdb;

		$goals     = Schema::table( 'goals' );
		$campaigns = Schema::table( 'campaigns' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT g.*, c.name AS campaign_name, c.status AS campaign_status, c.starts_at AS campaign_starts_at, c.ends_at AS campaign_ends_at, c.display_rules AS campaign_display_rules
				 FROM {$goals} g
				 LEFT JOIN {$campaigns} c ON c.id = g.campaign_id
				 WHERE g.id = %d",
				(int) $goal_id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return null;
		}

		return new Goal( $this->normalize( $row ) );
	}

/**
 * All goals for the admin list, paginated (Phase 7 REST layer).
 *
 * @param array<string, mixed> $args Optional: page, per_page, status,
 *                                   search (name LIKE).
 * @return array{items: array<int, array<string, mixed>>, total: int}
 */
public function all( array $args = array() ) {
	global $wpdb;

	$table    = Schema::table( 'goals' );
	$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
	$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
	$offset   = ( $page - 1 ) * $per_page;

	$where  = '1=1';
	$params = array();

	if ( ! empty( $args['status'] ) ) {
		$where .= ' AND status = %s';
		$params[] = (string) $args['status'];
	}

	if ( ! empty( $args['search'] ) ) {
		$where .= ' AND name LIKE %s';
		$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
	}

	// wpdb::prepare() requires at least one placeholder (WP 6.2+), so the
	// no-filter case runs the same queries without a WHERE clause.
	if ( empty( $params ) ) {
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY priority ASC, id ASC LIMIT %d OFFSET %d",
				array( $per_page, $offset )
			),
			ARRAY_A
		);
	} else {
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params )
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY priority ASC, id ASC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
	}

	$items = array();

	// Admin list shows the STORED goal values (stored status/dates), so
	// the CRUD UI never sees campaign-folded state; the engine applies the
	// campaign gating itself via active_goals()/find().
	foreach ( (array) $rows as $row ) {
		$items[] = $this->decode_row( $row );
	}

	return array(
		'items' => $items,
		'total' => $total,
	);
}

/**
 * Get a single goal as a normalized array (REST detail payload).
 *
 * @param int $goal_id Goal id.
 * @return array<string, mixed>|null Null when the goal does not exist.
 */
public function get( $goal_id ) {
	global $wpdb;

	$goals = Schema::table( 'goals' );
	$row   = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$goals} WHERE id = %d", (int) $goal_id ),
		ARRAY_A
	);

	if ( empty( $row ) ) {
		return null;
	}

	// Stored values (no campaign folding) — the admin detail payload.
	return $this->decode_row( $row );
}

/**
 * Create a goal from a validated payload.
 *
 * Maps the REST payload keys onto the goals table columns (conditions
 * keys packed into the conditions JSON). Returns the new goal id, or 0
 * when the insert failed.
 *
 * @param array<string, mixed> $data Goal payload (superset of columns).
 * @return int New goal id, 0 on failure.
 */
public function create( array $data ) {
	global $wpdb;

	$row = $this->map_columns( $data );

	if ( empty( $row ) ) {
		return 0;
	}

	$row['conditions'] = $this->pack_conditions( $data );
	$row['created_at'] = current_time( 'mysql' );
	$row['updated_at'] = $row['created_at'];

	$inserted = $wpdb->insert( Schema::table( 'goals' ), $row );

	if ( $inserted ) {
		/**
		 * Fires after a goal is created.
		 *
		 * @param int $goal_id New goal id.
		 */
		do_action( 'goalcart_goals_changed', (int) $wpdb->insert_id );
	}

	return $inserted ? (int) $wpdb->insert_id : 0;
}

/**
 * Update a goal from a validated partial payload.
 *
 * Only the keys present in $data are written. Returns true when the
 * update succeeded or had nothing to change (idempotent).
 *
 * @param int                    $goal_id Goal id.
 * @param array<string, mixed>   $data    Partial goal payload.
 * @return bool
 */
public function update( $goal_id, array $data ) {
	global $wpdb;

	$row = $this->map_columns( $data );
	$conditions = $this->pack_conditions( $data );

	if ( null !== $conditions ) {
		$row['conditions'] = $conditions;
	}

	if ( empty( $row ) ) {
		return true; // Nothing to change.
	}

	$row['updated_at'] = current_time( 'mysql' );

	$updated = $wpdb->update(
		Schema::table( 'goals' ),
		$row,
		array( 'id' => (int) $goal_id ),
		null,
		array( '%d' )
	);

	if ( false !== $updated ) {
		/**
		 * Fires after a goal is updated.
		 *
		 * @param int $goal_id Goal id.
		 */
		do_action( 'goalcart_goals_changed', (int) $goal_id );
	}

	return false !== $updated;
}

/**
 * Delete a goal.
 *
 * Hard delete — analytics history survives because the event log's goal
 * foreign key is ON DELETE SET NULL.
 *
 * @param int $goal_id Goal id.
 * @return bool
 */
public function delete( $goal_id ) {
	global $wpdb;

	$deleted = $wpdb->delete(
		Schema::table( 'goals' ),
		array( 'id' => (int) $goal_id ),
		array( '%d' )
	);

	if ( false !== $deleted ) {
		/**
		 * Fires after a goal is deleted.
		 *
		 * @param int $goal_id Goal id.
		 */
		do_action( 'goalcart_goals_changed', (int) $goal_id );
	}

	return false !== $deleted;
}	/**
	 * Duplicate a goal (copy with a ' (copy)' name suffix).
	 *
	 * Copies the STORED values (stored status/dates) — the copy never
	 * inherits the source's campaign-folded engine state.
	 *
	 * @param int $goal_id Goal id.
	 * @return int New goal id, 0 when the source goal does not exist or the
	 *             insert failed.
	 */
	public function duplicate( $goal_id ) {
		$row = $this->get( (int) $goal_id );

	if ( null === $row ) {
		return 0;
	}

	$row['name'] = sprintf(
		/* translators: %s: original goal name. */
		__( '%s (copy)', 'goalcart' ),
		$row['name']
	);

	return $this->create( $row );
}

/**
 * Map payload keys onto goals table columns.
 *
 * Only keys present in the payload are written (partial updates). Values
 * are sanitized/cast per column; campaign_id 0 is normalized to null so
 * the foreign key is never pointed at a non-existent campaign.
 *
 * @param array<string, mixed> $data Goal payload.
 * @return array<string, mixed>
 */
protected function map_columns( array $data ) {
	$row = array();

	foreach ( array(
		'name'              => 'text',
		'description'       => 'textarea',
		'status'            => 'key',
		'type'              => 'key',
		'target'            => 'float',
		'calculation_mode'  => 'key',
		'reward_type'       => 'key_nullable',
		'reward_value'      => 'float_nullable',
		'reward_max_value'  => 'float_nullable',
		'priority'          => 'int',
		'exclusive'         => 'bool',
		'campaign_id'       => 'campaign',
		'menu_order'        => 'int',
		'starts_at'         => 'datetime_nullable',
		'ends_at'           => 'datetime_nullable',
	) as $column => $type ) {
		if ( ! array_key_exists( $column, $data ) ) {
			continue;
		}

		$row[ $column ] = $this->sanitize_column( $type, $data[ $column ] );
	}

	foreach ( array( 'reward_meta', 'display_settings', 'limits' ) as $json_col ) {
		if ( ! array_key_exists( $json_col, $data ) ) {
			continue;
		}

		$row[ $json_col ] = $this->encode_json_column( $data[ $json_col ] );
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

		case 'key_nullable':
			$value = (string) $value;

			return '' === $value ? null : sanitize_key( $value );

		case 'float':
			return (float) $value;

		case 'float_nullable':
			return null === $value || '' === $value ? null : (float) $value;

		case 'int':
			return (int) $value;

		case 'bool':
			return (bool) $value;

		case 'campaign':
			$campaign_id = (int) $value;

			return $campaign_id > 0 ? $campaign_id : null;

		case 'datetime_nullable':
			$value = (string) $value;

			return '' === $value ? null : $value;
	}

	return $value;
}	/**
	 * Encode an array column to its JSON string (null when empty).
	 *
	 * Note: an explicitly provided empty array (e.g. `reward_meta: {}` in an
	 * update) is stored as NULL — a deliberate "clear the column" semantic,
	 * distinct from omitting the key (which leaves the stored value
	 * untouched).
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	protected function encode_json_column( $value ) {
	if ( ! is_array( $value ) || empty( $value ) ) {
		return null;
	}

	return wp_json_encode( $value );
}	/**
	 * Pack the condition/composite keys into the conditions JSON.
	 *
	 * The Goal model reads categories, products, excluded_products, operator,
	 * children and the Phase 32 condition keys as first-class properties;
	 * they are persisted inside the conditions column. Returns null when
	 * none of those keys is present in the payload (nothing to write).
	 *
	 * @param array<string, mixed> $data Goal payload.
	 * @return string|null
	 */
	protected function pack_conditions( array $data ) {
		$keys = array(
			'categories',
			'products',
			'excluded_products',
			'operator',
			'children',
			// Phase 32 (Advanced V2 conditions).
			'tags',
			'attributes',
			'customer_roles',
			'customer_state',
			'first_order',
			'vip',
			'vip_min_spend',
			'vip_min_orders',
			'shipping_zones',
			'cart_coupons',
			'cart_min_items',
			'schedule_days',
			'schedule_start_time',
			'schedule_end_time',
		);
		$packed = array();
		$found  = false;

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$found = true;

			if ( 'children' === $key ) {
				$packed[ $key ] = is_array( $data[ $key ] ) ? $data[ $key ] : array();
				continue;
			}

			$packed[ $key ] = $data[ $key ];
		}

		if ( ! $found ) {
			return null;
		}

		return wp_json_encode( $packed );
	}

	/**
	 * Decode a raw database row: JSON columns + condition-key spread.
	 *
	 * No campaign folding — the stored values are returned as-is, which is
	 * what the admin CRUD layer wants.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	protected function decode_row( array $row ) {
		foreach ( array( 'reward_meta', 'conditions', 'display_settings', 'limits' ) as $json_col ) {
			if ( isset( $row[ $json_col ] ) && is_string( $row[ $json_col ] ) && '' !== $row[ $json_col ] ) {
				$decoded = json_decode( $row[ $json_col ], true );
				$row[ $json_col ] = is_array( $decoded ) ? $decoded : array();
			}
		}

		// The conditions JSON stores the condition/composite keys the Goal
		// model reads as first-class properties; spread them onto the row so
		// persisted category/product/composite/tag/attribute/brand goals and
		// the Phase 32 customer/cart/shipping conditions evaluate correctly.
		if ( isset( $row['conditions'] ) && is_array( $row['conditions'] ) ) {
			foreach ( array(
				'categories',
				'products',
				'excluded_products',
				'operator',
				'children',
				'tags',
				'attributes',
				'customer_roles',
				'customer_state',
				'first_order',
				'vip',
				'vip_min_spend',
				'vip_min_orders',
				'shipping_zones',
				'cart_coupons',
				'cart_min_items',
				'schedule_days',
				'schedule_start_time',
				'schedule_end_time',
			) as $key ) {
				if ( array_key_exists( $key, $row['conditions'] ) ) {
					$row[ $key ] = $row['conditions'][ $key ];
				}
			}
		}

		return $row;
	}

	/**
	 * Fold the campaign status/schedule onto a decoded goal row.
	 *
	 * Engine path: a goal's own dates take precedence; otherwise the
	 * campaign window applies, and an inactive/out-of-window campaign makes
	 * the goal inactive.
	 *
	 * Phase 32 (advanced scheduled campaigns): a campaign may carry its own
	 * recurring day/time rules inside display_rules; a goal without its own
	 * rules inherits them, so one campaign window schedules every milestone.
	 *
	 * @param array<string, mixed> $row Decoded goal row.
	 * @return array<string, mixed>
	 */
	protected function fold_campaign( array $row ) {
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

			// Phase 32 recurring rules: inherit the campaign's day/time
			// window unless the goal pins its own.
			$rules = $this->decode_campaign_display_rules( $row );

			foreach ( array( 'schedule_days', 'schedule_start_time', 'schedule_end_time' ) as $key ) {
				if ( empty( $row[ $key ] ) && isset( $rules[ $key ] ) && ! empty( $rules[ $key ] ) ) {
					$row[ $key ] = $rules[ $key ];
				}
			}
		}

		unset( $row['campaign_status'], $row['campaign_starts_at'], $row['campaign_ends_at'] );

		return $row;
	}

	/**
	 * Decode a campaign's display_rules for schedule folding.
	 *
	 * @param array<string, mixed> $row Raw row (campaign_display_rules may
	 *                                  be a JSON string or an array).
	 * @return array<string, mixed>
	 */
	protected function decode_campaign_display_rules( array $row ) {
		if ( ! isset( $row['campaign_display_rules'] ) ) {
			return array();
		}

		if ( is_array( $row['campaign_display_rules'] ) ) {
			return $row['campaign_display_rules'];
		}

		$decoded = json_decode( (string) $row['campaign_display_rules'], true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Normalize a raw database row into a Goal constructor payload.
	 *
	 * Decode + campaign folding — the engine evaluation path
	 * (active_goals()).
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	protected function normalize( array $row ) {
		return $this->fold_campaign( $this->decode_row( $row ) );
	}
}
