<?php
/**
 * Analytics metrics repository for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Analytics;

use FaraCart\Database\Schema;
use FaraCart\Rewards\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Class AnalyticsRepository
 *
 * Phase 16 (Analytics Foundation — Metrics) — the read layer over the
 * append-only `analytics_events` table, computing the dashboard metrics:
 *
 *  - impressions                    mission_impression event count
 *  - completions                    mission_completed + reward_activated count
 *  - completion rate                completions / impressions
 *  - average cart value             AVG(cart_value) at mission impression
 *  - revenue on completed missions     SUM(cart_value) at completion events
 *  - suggestion CTR                 suggestion_clicked / suggestion_impression
 *  - suggestion add-to-cart rate    suggested_product_added / suggestion_clicked
 *
 * Phase 17 (Analytics Dashboard) adds the dashboard-shaped queries:
 *
 *  - trend()                        daily impression/completion/revenue
 *                                   buckets over a (default 30-day) window
 *  - top_campaigns()                per-campaign impressions, completions,
 *                                   revenue and completion rate
 *  - top_missions()                    per-mission impressions, completions,
 *                                   revenue and completion rate
 *  - top_suggested_products()       per-product suggestion impressions,
 *                                   clicks and conversions + derived rates
 *
 * Every query accepts the same filter set (date range, campaign, mission,
 * mission ids, reward type, product) so the dashboard can slice any metric
 * without new SQL. Event types and reward types are always whitelisted,
 * filter values are bound through $wpdb->prepare, and table/column names
 * are plugin constants — no user input ever reaches the SQL string.
 *
 * Division-by-zero is guarded: rates return 0.0 when the denominator is
 * empty. All values are pure aggregates over anonymous sessions; no row
 * content is exposed.
 */
final class AnalyticsRepository {

	/**
	 * Count events of a whitelisted type, optionally filtered.
	 *
	 * @param string               $event_type One of Tracker::EVENT_*.
	 * @param array<string, mixed> $filters    Optional from/to, campaign_id,
	 *                                         mission_id, mission_ids, product_id,
	 *                                         reward_type.
	 * @return int
	 */
	public function count( $event_type, array $filters = array() ) {
		if ( ! Tracker::is_event_type( $event_type ) ) {
			return 0;
		}

		global $wpdb;

		$where = $this->where( $event_type, $filters );
		$sql   = 'SELECT COUNT(*) FROM ' . Schema::table( 'analytics_events' ) . ' ' . $where['sql'];

		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$where['values'] ) );

		return (int) $count;
	}

	/**
	 * Mission impressions.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function impressions( array $filters = array() ) {
		return $this->count( Tracker::EVENT_MISSION_IMPRESSION, $filters );
	}

	/**
	 * Completed missions (with or without a reward).
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function completions( array $filters = array() ) {
		return $this->count( Tracker::EVENT_MISSION_COMPLETED, $filters )
			+ $this->count( Tracker::EVENT_REWARD_ACTIVATED, $filters );
	}

	/**
	 * Completion rate: completions / impressions.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return float 0–1, or 0 when there are no impressions.
	 */
	public function completion_rate( array $filters = array() ) {
		$impressions = $this->impressions( $filters );

		if ( $impressions < 1 ) {
			return 0.0;
		}

		return round( $this->completions( $filters ) / $impressions, 4 );
	}

	/**
	 * Average cart value at mission impression.
	 *
	 * The average cart_value recorded when mission widgets were shown — the
	 * store's typical engaged-cart value. Only impressions of carts with a
	 * positive value count (empty carts and legacy rows without one are
	 * excluded rather than skewing the mean down).
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return float
	 */
	public function average_cart_value( array $filters = array() ) {
		global $wpdb;

		$where = $this->where( Tracker::EVENT_MISSION_IMPRESSION, $filters );
		$sql   = 'SELECT AVG(cart_value) FROM ' . Schema::table( 'analytics_events' )
			. ' ' . $where['sql'] . ' AND cart_value > 0';

		$avg = $wpdb->get_var( $wpdb->prepare( $sql, ...$where['values'] ) );

		return null === $avg ? 0.0 : round( (float) $avg, 4 );
	}

	/**
	 * Revenue associated with completed missions.
	 *
	 * The sum of the cart values captured when missions were completed (the
	 * cart was worth this much at the moment the mission was met), i.e. the
	 * revenue the mission system can claim credit toward.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return float
	 */
	public function revenue_associated_with_completed_missions( array $filters = array() ) {
		global $wpdb;

		$parts  = $this->clauses( null, $filters );
		$parts['clauses'][] = 'event_type IN (%s, %s)';
		$parts['clauses'][] = 'cart_value IS NOT NULL';
		$parts['values'][]  = Tracker::EVENT_MISSION_COMPLETED;
		$parts['values'][]  = Tracker::EVENT_REWARD_ACTIVATED;

		$sql = 'SELECT SUM(cart_value) FROM ' . Schema::table( 'analytics_events' )
			. ( empty( $parts['clauses'] ) ? '' : ' WHERE ' . implode( ' AND ', $parts['clauses'] ) );

		$sum = $wpdb->get_var( $wpdb->prepare( $sql, ...$parts['values'] ) );

		return null === $sum ? 0.0 : round( (float) $sum, 4 );
	}

	/**
	 * Suggestion click-through rate: clicks / impressions.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return float 0–1, or 0 when no suggestions were shown.
	 */
	public function suggestion_ctr( array $filters = array() ) {
		$impressions = $this->count( Tracker::EVENT_SUGGESTION_IMPRESSION, $filters );

		if ( $impressions < 1 ) {
			return 0.0;
		}

		$clicks = $this->count( Tracker::EVENT_SUGGESTION_CLICKED, $filters );

		return round( $clicks / $impressions, 4 );
	}

	/**
	 * Suggestion add-to-cart rate: conversions / clicks.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return float 0–1, or 0 when no suggestions were clicked.
	 */
	public function suggestion_add_to_cart_rate( array $filters = array() ) {
		$clicks = $this->count( Tracker::EVENT_SUGGESTION_CLICKED, $filters );

		if ( $clicks < 1 ) {
			return 0.0;
		}

		$added = $this->count( Tracker::EVENT_SUGGESTED_PRODUCT_ADDED, $filters );

		return round( $added / $clicks, 4 );
	}

	/**
	 * Daily trend buckets (Phase 17).
	 *
	 * One point per day of the (default 30-day) window — days without
	 * events are filled with zeros so the chart is continuous. Each point
	 * carries the day's impressions, completions and completion revenue.
	 *
	 * @param array<string, mixed> $filters Filters (date range optional —
	 *                                      defaults to the last 30 days).
	 * @return array<int, array{date: string, impressions: int, completions: int, revenue: float}>
	 */
	public function trend( array $filters = array() ) {
		global $wpdb;

		$filters = $this->default_range( $filters );
		$from    = $this->day_bounds_start( $filters['from'] );
		$to      = $this->day_bounds_end( $filters['to'] );

		// The window bounds are handled by this method (day-granularity
		// to/from), so the shared clauses builder must not add its own.
		unset( $filters['from'], $filters['to'] );

		$parts = $this->clauses( null, $filters, 'e' );
		$parts['clauses'][] = 'e.created_at >= %s';
		$parts['values'][]  = $from;
		$parts['clauses'][] = 'e.created_at <= %s';
		$parts['values'][]  = $to;

		$table = Schema::table( 'analytics_events' );

		// SELECT placeholders precede the WHERE placeholders in the SQL, so
		// their values lead the bound-value array (prepare() substitutes in
		// order of appearance).
		$sql = "SELECT DATE(e.created_at) AS day,
			SUM( CASE WHEN e.event_type = %s THEN 1 ELSE 0 END ) AS impressions,
			SUM( CASE WHEN e.event_type IN (%s, %s) THEN 1 ELSE 0 END ) AS completions,
			SUM( CASE WHEN e.event_type IN (%s, %s) THEN e.cart_value ELSE 0 END ) AS revenue
			FROM {$table} e
			WHERE " . implode( ' AND ', $parts['clauses'] ) . '
			GROUP BY DATE(e.created_at)
			ORDER BY day ASC';

		$values = array_merge(
			array(
				Tracker::EVENT_MISSION_IMPRESSION,
				Tracker::EVENT_MISSION_COMPLETED,
				Tracker::EVENT_REWARD_ACTIVATED,
				Tracker::EVENT_MISSION_COMPLETED,
				Tracker::EVENT_REWARD_ACTIVATED,
			),
			$parts['values']
		);

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

		$by_day = array();

		foreach ( (array) $rows as $row ) {
			$by_day[ $row['day'] ] = $row;
		}

		// Fill the whole window with zero days so the chart is continuous.
		$trend  = array();
		$cursor = strtotime( date( 'Y-m-d', strtotime( $from ) ) );
		$end    = strtotime( date( 'Y-m-d', strtotime( $to ) ) );

		while ( $cursor <= $end ) {
			$day = date( 'Y-m-d', $cursor );
			$row = isset( $by_day[ $day ] ) ? $by_day[ $day ] : array(
				'impressions' => 0,
				'completions' => 0,
				'revenue'     => 0,
			);

			$trend[] = array(
				'date'        => $day,
				'impressions' => (int) $row['impressions'],
				'completions' => (int) $row['completions'],
				'revenue'     => round( (float) $row['revenue'], 4 ),
			);

			$cursor = strtotime( '+1 day', $cursor );
		}

		return $trend;
	}

	/**
	 * Top campaigns by completion volume (Phase 17).
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param int                  $limit   Max entries (1–20).
	 * @return array<int, array{id: int, name: string, impressions: int, completions: int, revenue: float, completion_rate: float}>
	 */
	public function top_campaigns( array $filters = array(), $limit = 5 ) {
		global $wpdb;

		$limit = max( 1, min( 20, (int) $limit ) );

		$parts = $this->clauses( null, $filters, 'e' );
		$parts['clauses'][] = 'e.campaign_id IS NOT NULL';

		$events    = Schema::table( 'analytics_events' );
		$campaigns = Schema::table( 'campaigns' );

		$sql = "SELECT e.campaign_id AS id, c.name AS name,
			SUM( CASE WHEN e.event_type = %s THEN 1 ELSE 0 END ) AS impressions,
			SUM( CASE WHEN e.event_type IN (%s, %s) THEN 1 ELSE 0 END ) AS completions,
			SUM( CASE WHEN e.event_type IN (%s, %s) THEN e.cart_value ELSE 0 END ) AS revenue
			FROM {$events} e
			INNER JOIN {$campaigns} c ON c.id = e.campaign_id
			WHERE " . implode( ' AND ', $parts['clauses'] ) . '
			GROUP BY e.campaign_id, c.name
			ORDER BY completions DESC, impressions DESC
			LIMIT %d';

		$values = array_merge(
			array(
				Tracker::EVENT_MISSION_IMPRESSION,
				Tracker::EVENT_MISSION_COMPLETED,
				Tracker::EVENT_REWARD_ACTIVATED,
				Tracker::EVENT_MISSION_COMPLETED,
				Tracker::EVENT_REWARD_ACTIVATED,
			),
			$parts['values'],
			array( $limit )
		);

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

		return $this->shape_tops( (array) $rows );
	}

	/**
	 * Top missions by completion volume (Phase 17).
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param int                  $limit   Max entries (1–20).
	 * @return array<int, array{id: int, name: string, impressions: int, completions: int, revenue: float, completion_rate: float}>
	 */
	public function top_missions( array $filters = array(), $limit = 5 ) {
		global $wpdb;

		$limit = max( 1, min( 20, (int) $limit ) );

		$parts = $this->clauses( null, $filters, 'e' );
		$parts['clauses'][] = 'e.mission_id IS NOT NULL';

		$events = Schema::table( 'analytics_events' );
		$missions  = Schema::table( 'missions' );

		$sql = "SELECT e.mission_id AS id, g.name AS name,
			SUM( CASE WHEN e.event_type = %s THEN 1 ELSE 0 END ) AS impressions,
			SUM( CASE WHEN e.event_type IN (%s, %s) THEN 1 ELSE 0 END ) AS completions,
			SUM( CASE WHEN e.event_type IN (%s, %s) THEN e.cart_value ELSE 0 END ) AS revenue
			FROM {$events} e
			INNER JOIN {$missions} g ON g.id = e.mission_id
			WHERE " . implode( ' AND ', $parts['clauses'] ) . '
			GROUP BY e.mission_id, g.name
			ORDER BY completions DESC, impressions DESC
			LIMIT %d';

		$values = array_merge(
			array(
				Tracker::EVENT_MISSION_IMPRESSION,
				Tracker::EVENT_MISSION_COMPLETED,
				Tracker::EVENT_REWARD_ACTIVATED,
				Tracker::EVENT_MISSION_COMPLETED,
				Tracker::EVENT_REWARD_ACTIVATED,
			),
			$parts['values'],
			array( $limit )
		);

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

		return $this->shape_tops( (array) $rows );
	}

	/**
	 * Top suggested products by conversions (Phase 17).
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param int                  $limit   Max entries (1–20).
	 * @return array<int, array{product_id: int, name: string, impressions: int, clicks: int, added: int, ctr: float, add_to_cart_rate: float}>
	 */
	public function top_suggested_products( array $filters = array(), $limit = 5 ) {
		global $wpdb;

		$limit = max( 1, min( 20, (int) $limit ) );

		$impressions = Tracker::EVENT_SUGGESTION_IMPRESSION;
		$clicked     = Tracker::EVENT_SUGGESTION_CLICKED;
		$added       = Tracker::EVENT_SUGGESTED_PRODUCT_ADDED;

		$parts = $this->clauses( null, $filters, 'e' );
		$parts['clauses'][] = 'e.product_id IS NOT NULL';
		$parts['clauses'][] = 'e.event_type IN (%s, %s, %s)';
		$parts['values'][]  = $impressions;
		$parts['values'][]  = $clicked;
		$parts['values'][]  = $added;

		$events = Schema::table( 'analytics_events' );
		$posts  = $wpdb->posts;

		$sql = "SELECT e.product_id AS product_id, p.post_title AS name,
			SUM( CASE WHEN e.event_type = %s THEN 1 ELSE 0 END ) AS impressions,
			SUM( CASE WHEN e.event_type = %s THEN 1 ELSE 0 END ) AS clicks,
			SUM( CASE WHEN e.event_type = %s THEN 1 ELSE 0 END ) AS added
			FROM {$events} e
			INNER JOIN {$posts} p ON p.ID = e.product_id
			WHERE " . implode( ' AND ', $parts['clauses'] ) . '
			GROUP BY e.product_id, p.post_title
			ORDER BY added DESC, clicks DESC, impressions DESC
			LIMIT %d';

		$values = array_merge(
			array( $impressions, $clicked, $added ),
			$parts['values'],
			array( $limit )
		);

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

		$items = array();

		foreach ( (array) $rows as $row ) {
			$impressions = (int) $row['impressions'];
			$clicks      = (int) $row['clicks'];
			$added       = (int) $row['added'];

			$items[] = array(
				'product_id'       => (int) $row['product_id'],
				'name'             => (string) $row['name'],
				'impressions'      => $impressions,
				'clicks'           => $clicks,
				'added'            => $added,
				'ctr'              => $impressions > 0 ? round( $clicks / $impressions, 4 ) : 0.0,
				'add_to_cart_rate' => $clicks > 0 ? round( $added / $clicks, 4 ) : 0.0,
			);
		}

		return $items;
	}

	/**
	 * Normalize campaign/mission top rows into the shared payload shape.
	 *
	 * @param array<int, array<string, mixed>> $rows Query rows.
	 * @return array<int, array<string, mixed>>
	 */
	protected function shape_tops( array $rows ) {
		$items = array();

		foreach ( $rows as $row ) {
			$impressions = (int) $row['impressions'];
			$completions = (int) $row['completions'];

			$items[] = array(
				'id'              => (int) $row['id'],
				'name'            => (string) $row['name'],
				'impressions'     => $impressions,
				'completions'     => $completions,
				'revenue'         => round( (float) $row['revenue'], 4 ),
				'completion_rate' => $impressions > 0 ? round( $completions / $impressions, 4 ) : 0.0,
			);
		}

		return $items;
	}

	/**
	 * Build the WHERE clause + bound values for a filtered metrics query.
	 *
	 * Event types are whitelisted constants baked into the clause list;
	 * filter values are placeholders bound through prepare in order.
	 *
	 * @param string               $event_type Whitelisted event type, or
	 *                                         null when the caller adds its
	 *                                         own event clause.
	 * @param array<string, mixed> $filters    Optional from/to, campaign_id,
	 *                                         mission_id, mission_ids, product_id,
	 *                                         reward_type.
	 * @param string               $alias      Optional table alias to qualify
	 *                                         columns with (join queries).
	 * @return array{sql: string, values: array<int, mixed>}
	 */
	protected function where( $event_type, array $filters, $alias = '' ) {
		$parts = $this->clauses( $event_type, $filters, $alias );

		return array(
			'sql'    => empty( $parts['clauses'] ) ? '' : 'WHERE ' . implode( ' AND ', $parts['clauses'] ),
			'values' => $parts['values'],
		);
	}

	/**
	 * Build the ordered clause + value lists for a filtered query.
	 *
	 * @param string|null          $event_type Whitelisted event type, or
	 *                                         null for none.
	 * @param array<string, mixed> $filters    Optional filters.
	 * @param string               $alias      Optional table alias to qualify
	 *                                         columns with (join queries).
	 * @return array{clauses: string[], values: array<int, mixed>}
	 */
	protected function clauses( $event_type, array $filters, $alias = '' ) {
		$clauses = array();
		$values  = array();

		$col = function ( $name ) use ( $alias ) {
			return '' === $alias ? $name : $alias . '.' . $name;
		};

		if ( null !== $event_type ) {
			$clauses[] = $col( 'event_type' ) . ' = %s';
			$values[]  = $event_type;
		}

		// Date-only bounds are widened to the full day (00:00:00 / 23:59:59)
		// so the `to` day is inclusive: a bare 'YYYY-MM-DD' would be cast by
		// MySQL to midnight, silently dropping every event recorded on the
		// `to` day (the dashboard's default "last 30 days" ends today).
		// trend() applies the same widening via day_bounds_start/end.
		if ( ! empty( $filters['from'] ) && $this->valid_datetime( $filters['from'] ) ) {
			$clauses[] = $col( 'created_at' ) . ' >= %s';
			$values[]  = $this->day_bounds_start( $filters['from'] );
		}

		if ( ! empty( $filters['to'] ) && $this->valid_datetime( $filters['to'] ) ) {
			$clauses[] = $col( 'created_at' ) . ' <= %s';
			$values[]  = $this->day_bounds_end( $filters['to'] );
		}

		if ( isset( $filters['campaign_id'] ) && (int) $filters['campaign_id'] > 0 ) {
			$clauses[] = $col( 'campaign_id' ) . ' = %d';
			$values[]  = (int) $filters['campaign_id'];
		}

		if ( isset( $filters['mission_id'] ) && (int) $filters['mission_id'] > 0 ) {
			$clauses[] = $col( 'mission_id' ) . ' = %d';
			$values[]  = (int) $filters['mission_id'];
		}

		if ( ! empty( $filters['mission_ids'] ) && is_array( $filters['mission_ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', $filters['mission_ids'] ), function ( $id ) {
				return $id > 0;
			} ) );

			if ( ! empty( $ids ) ) {
				$clauses[] = $col( 'mission_id' ) . ' IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$values    = array_merge( $values, $ids );
			}
		}

		if ( isset( $filters['product_id'] ) && (int) $filters['product_id'] > 0 ) {
			$clauses[] = $col( 'product_id' ) . ' = %d';
			$values[]  = (int) $filters['product_id'];
		}

		// Reward filter: restrict to events whose mission carries the reward
		// type. Whitelisted against Reward::types() and expressed as a
		// subquery so every query (with or without joins) can use it.
		if ( ! empty( $filters['reward_type'] ) && in_array( $filters['reward_type'], Reward::types(), true ) ) {
			$missions_table = Schema::table( 'missions' );
			$clauses[] = $col( 'mission_id' ) . " IN (SELECT id FROM {$missions_table} WHERE reward_type = %s)";
			$values[]  = $filters['reward_type'];
		}

		return array(
			'clauses' => $clauses,
			'values'  => $values,
		);
	}

	/**
	 * Maximum spread between the from/to bounds, in seconds.
	 *
	 * P22 hardening: the dashboard trend loops day-by-day over the window,
	 * so an unbounded admin-supplied range (e.g. 1990 → 2100) would loop
	 * tens of thousands of times. The default_range() clamp below keeps
	 * every trend query to at most one year of daily buckets (366 days).
	 *
	 * @var int
	 */
	const MAX_RANGE_SECONDS = 366 * DAY_IN_SECONDS;

	/**
	 * Fill in the default window: the last 30 days when no valid bounds
	 * were supplied (mirrors the reference dashboard's default range), and
	 * cap the span at MAX_RANGE_SECONDS so a request can never force a
	 * pathological day-by-day loop in trend().
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, mixed>
	 */
	protected function default_range( array $filters ) {
		$now = strtotime( current_time( 'mysql' ) );

		if ( empty( $filters['from'] ) || ! $this->valid_datetime( $filters['from'] ) ) {
			$filters['from'] = date( 'Y-m-d', strtotime( $now ) - 29 * DAY_IN_SECONDS );
		}

		if ( empty( $filters['to'] ) || ! $this->valid_datetime( $filters['to'] ) ) {
			$filters['to'] = current_time( 'Y-m-d' );
		}

		$from_ts = strtotime( (string) $filters['from'] );
		$to_ts   = strtotime( (string) $filters['to'] );

		if ( false === $from_ts || false === $to_ts ) {
			return $filters;
		}

		if ( $to_ts < $from_ts ) {
			// Backwards range: swap so the query always returns a valid,
			// ordered window instead of an empty/pathological result.
			$filters['from'] = date( 'Y-m-d', $to_ts );
			$filters['to']   = date( 'Y-m-d', $from_ts );
			$from_ts         = $to_ts;
			$to_ts           = strtotime( $filters['from'] );
		}

		if ( ( $to_ts - $from_ts ) > self::MAX_RANGE_SECONDS ) {
			// Clamp by pushing `from` forward, keeping the requested end
			// date (the dashboard's natural read: "give me up to here").
			$filters['from'] = date( 'Y-m-d', $to_ts - self::MAX_RANGE_SECONDS );
		}

		return $filters;
	}

	/**
	 * Normalize a date-only bound to the first instant of the day.
	 *
	 * @param mixed $value Y-m-d or Y-m-d H:i:s.
	 * @return string
	 */
	protected function day_bounds_start( $value ) {
		$value = (string) $value;

		return 10 === strlen( $value ) ? $value . ' 00:00:00' : $value;
	}

	/**
	 * Normalize a date-only bound to the last instant of the day.
	 *
	 * @param mixed $value Y-m-d or Y-m-d H:i:s.
	 * @return string
	 */
	protected function day_bounds_end( $value ) {
		$value = (string) $value;

		return 10 === strlen( $value ) ? $value . ' 23:59:59' : $value;
	}

	/**
	 * Whether a datetime string is a valid Y-m-d or Y-m-d H:i:s value.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	protected function valid_datetime( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value ) ) {
			return false;
		}

		return false !== strtotime( $value );
	}
}
