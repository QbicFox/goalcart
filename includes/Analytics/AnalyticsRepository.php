<?php
/**
 * Analytics metrics repository for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Class AnalyticsRepository
 *
 * Phase 16 (Analytics Foundation — Metrics) — the read layer over the
 * append-only `analytics_events` table, computing the dashboard metrics:
 *
 *  - impressions                    goal_impression event count
 *  - completions                    goal_completed + reward_activated count
 *  - completion rate                completions / impressions
 *  - average cart value             AVG(cart_value) at goal impression
 *  - revenue on completed goals     SUM(cart_value) at completion events
 *  - suggestion CTR                 suggestion_clicked / suggestion_impression
 *  - suggestion add-to-cart rate    suggested_product_added / suggestion_clicked
 *
 * Every query accepts the same filter set (date range, campaign, goal)
 * so the Phase 17 dashboard can slice any metric without new SQL. Event
 * types are always whitelisted and every value is bound with
 * $wpdb->prepare — no user input ever reaches the SQL string.
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
	 *                                         goal_id.
	 * @return int
	 */
	public function count( $event_type, array $filters = array() ) {
		if ( ! Tracker::is_event_type( $event_type ) ) {
			return 0;
		}

		global $wpdb;

		$where = $this->where( $event_type, $filters );
		$sql   = "SELECT COUNT(*) FROM " . \GoalCart\Database\Schema::table( 'analytics_events' ) . ' ' . $where['sql'];

		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$where['values'] ) );

		return (int) $count;
	}

	/**
	 * Goal impressions.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function impressions( array $filters = array() ) {
		return $this->count( Tracker::EVENT_GOAL_IMPRESSION, $filters );
	}

	/**
	 * Completed goals (with or without a reward).
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function completions( array $filters = array() ) {
		return $this->count( Tracker::EVENT_GOAL_COMPLETED, $filters )
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
	 * Average cart value at goal impression.
	 *
	 * The average cart_value recorded when goal widgets were shown — the
	 * store's typical engaged-cart value. Only impressions of carts with a
	 * positive value count (empty carts and legacy rows without one are
	 * excluded rather than skewing the mean down).
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return float
	 */
	public function average_cart_value( array $filters = array() ) {
		global $wpdb;

		$where = $this->where( Tracker::EVENT_GOAL_IMPRESSION, $filters );
		$sql   = "SELECT AVG(cart_value) FROM " . \GoalCart\Database\Schema::table( 'analytics_events' )
			. ' ' . $where['sql'] . ' AND cart_value > 0';

		$avg = $wpdb->get_var( $wpdb->prepare( $sql, ...$where['values'] ) );

		return null === $avg ? 0.0 : round( (float) $avg, 4 );
	}

	/**
	 * Revenue associated with completed goals.
	 *
	 * The sum of the cart values captured when goals were completed (the
	 * cart was worth this much at the moment the goal was met), i.e. the
	 * revenue the goal system can claim credit toward.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return float
	 */
	public function revenue_associated_with_completed_goals( array $filters = array() ) {
		global $wpdb;

		$parts  = $this->clauses( null, $filters );
		$parts['clauses'][] = 'event_type IN (%s, %s)';
		$parts['clauses'][] = 'cart_value IS NOT NULL';
		$parts['values'][]  = Tracker::EVENT_GOAL_COMPLETED;
		$parts['values'][]  = Tracker::EVENT_REWARD_ACTIVATED;

		$sql = "SELECT SUM(cart_value) FROM " . \GoalCart\Database\Schema::table( 'analytics_events' )
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
	 * Build the WHERE clause + bound values for a filtered metrics query.
	 *
	 * Event types are whitelisted constants baked into the clause list;
	 * filter values are placeholders bound through prepare in order.
	 *
	 * @param string               $event_type Whitelisted event type, or
	 *                                         null when the caller adds its
	 *                                         own event clause.
	 * @param array<string, mixed> $filters    Optional from/to, campaign_id,
	 *                                         goal_id.
	 * @return array{sql: string, values: array<int, mixed>}
	 */
	protected function where( $event_type, array $filters ) {
		$parts = $this->clauses( $event_type, $filters );

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
	 * @return array{clauses: string[], values: array<int, mixed>}
	 */
	protected function clauses( $event_type, array $filters ) {
		$clauses = array();
		$values  = array();

		if ( null !== $event_type ) {
			$clauses[] = 'event_type = %s';
			$values[]  = $event_type;
		}

		if ( ! empty( $filters['from'] ) && $this->valid_datetime( $filters['from'] ) ) {
			$clauses[] = 'created_at >= %s';
			$values[]  = $filters['from'];
		}

		if ( ! empty( $filters['to'] ) && $this->valid_datetime( $filters['to'] ) ) {
			$clauses[] = 'created_at <= %s';
			$values[]  = $filters['to'];
		}

		if ( isset( $filters['campaign_id'] ) && (int) $filters['campaign_id'] > 0 ) {
			$clauses[] = 'campaign_id = %d';
			$values[]  = (int) $filters['campaign_id'];
		}

		if ( isset( $filters['goal_id'] ) && (int) $filters['goal_id'] > 0 ) {
			$clauses[] = 'goal_id = %d';
			$values[]  = (int) $filters['goal_id'];
		}

		return array(
			'clauses' => $clauses,
			'values'  => $values,
		);
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
