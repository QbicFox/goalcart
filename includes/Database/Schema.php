<?php
/**
 * Database schema definitions for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Schema
 *
 * Central definition of every database table used by the plugin.
 * Table names are prefixed with `{$wpdb->prefix}goalcart_` so they work
 * with any WordPress table prefix.
 *
 * Mirrors the reference plugin (WooInsights\Database\Schema): tables are
 * declared here as dbDelta CREATE TABLE statements plus foreign-key
 * definitions that the Installer applies with ALTER TABLE (dbDelta cannot
 * manage foreign keys).
 *
 * Phase 3 (Database & Domain Model) defines three entities:
 *
 *  - `goals`            — one row per goal (amount / quantity / category …).
 *                         The MVP reward is embedded on the goal (type,
 *                         value, max value, extra meta) because MVP ships
 *                         exactly one reward per goal; a standalone
 *                         `rewards` table can be extracted later without
 *                         breaking this schema.
 *  - `campaigns`        — one row per campaign (scheduled, prioritized).
 *                         Goals join to a campaign through
 *                         `goals.campaign_id` + `goals.menu_order`, which
 *                         expresses milestone ordering (Phase 10).
 *  - `analytics_events` — append-only event log (impressions, progress,
 *                         completions, reward activations, suggestion
 *                         events) feeding the analytics phases (16–17).
 *
 * Settings intentionally live in a single WordPress option
 * (`goalcart_settings`, see Settings) rather than a table: the reference
 * plugin declares a settings table that its Settings service never uses,
 * so the option-only pattern was adopted to avoid duplicated storage.
 */
class Schema {

	/**
	 * Table prefix used by the plugin (after the WordPress prefix).
	 *
	 * @var string
	 */
	const TABLE_PREFIX = 'goalcart_';

	/**
	 * Get the fully qualified table name for a plugin table.
	 *
	 * @param string $name Table name without the plugin prefix.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_PREFIX . $name;
	}

	/**
	 * List of all table names managed by the plugin (without prefix).
	 *
	 * Phase 33 (Revenue Optimization) adds the five attribution tables:
	 * revenue_events (the raw attribution event log), revenue_daily (daily
	 * aggregates), goal_attribution (per-order attribution), upsell_events
	 * (raw upsell interaction log) and upsell_stats (per-product upsell
	 * aggregates).
	 *
	 * @return string[]
	 */
	public static function tables() {
		return array(
			'campaigns',
			'goals',
			'analytics_events',
			'revenue_events',
			'revenue_daily',
			'goal_attribution',
			'upsell_events',
			'upsell_stats',
		);
	}

	/**
	 * CREATE TABLE statements for every plugin table, formatted for dbDelta().
	 *
	 * @return string[] Table name => CREATE TABLE statement.
	 */
	public static function create_statements() {
		global $wpdb;

		$collate   = $wpdb->get_charset_collate();
		$goals     = self::table( 'goals' );
		$campaigns = self::table( 'campaigns' );
		$events    = self::table( 'analytics_events' );

		// Phase 33 (Revenue Optimization): the five attribution tables.
		$revenue_events = self::table( 'revenue_events' );
		$revenue_daily  = self::table( 'revenue_daily' );
		$goal_attrib    = self::table( 'goal_attribution' );
		$upsell_events  = self::table( 'upsell_events' );
		$upsell_stats   = self::table( 'upsell_stats' );
		return array(
			$campaigns => "CREATE TABLE {$campaigns} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				description text,
				status varchar(20) NOT NULL DEFAULT 'active',
				starts_at datetime DEFAULT NULL,
				ends_at datetime DEFAULT NULL,
				priority int(10) unsigned NOT NULL DEFAULT 10,
				display_rules longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY starts_at (starts_at),
				KEY ends_at (ends_at)
			) ENGINE=InnoDB {$collate};",

			$goals => "CREATE TABLE {$goals} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				description text,
				status varchar(20) NOT NULL DEFAULT 'active',
				type varchar(20) NOT NULL,
				target decimal(19,4) NOT NULL DEFAULT 0,
				calculation_mode varchar(20) NOT NULL DEFAULT 'subtotal',
				reward_type varchar(20) DEFAULT NULL,
				reward_value decimal(19,4) DEFAULT NULL,
				reward_max_value decimal(19,4) DEFAULT NULL,
				reward_meta longtext,
				conditions longtext,
				display_settings longtext,
				priority int(10) unsigned NOT NULL DEFAULT 10,
				exclusive tinyint(1) NOT NULL DEFAULT 0,
				campaign_id bigint(20) unsigned DEFAULT NULL,
				menu_order int(10) unsigned NOT NULL DEFAULT 0,
				starts_at datetime DEFAULT NULL,
				ends_at datetime DEFAULT NULL,
				limits longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY type (type),
				KEY campaign_id (campaign_id),
				KEY priority (priority),
				KEY starts_at (starts_at),
				KEY ends_at (ends_at)
			) ENGINE=InnoDB {$collate};",

			$events => "CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				goal_id bigint(20) unsigned DEFAULT NULL,
				campaign_id bigint(20) unsigned DEFAULT NULL,
				event_type varchar(40) NOT NULL,
				session_id varchar(32) DEFAULT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				product_id bigint(20) unsigned DEFAULT NULL,
				order_id bigint(20) unsigned DEFAULT NULL,
				cart_value decimal(19,4) DEFAULT NULL,
				meta longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY goal_id (goal_id),
				KEY campaign_id (campaign_id),
				KEY event_type (event_type),
				KEY session_id (session_id),
				KEY product_id (product_id),
				KEY order_id (order_id),
				KEY created_at (created_at),
				KEY goal_event (goal_id, event_type),
				KEY campaign_event (campaign_id, event_type)
			) ENGINE=InnoDB {$collate};",

			// Phase 33 (Revenue Attribution): the raw revenue-optimization
			// event log. Deliberately separate from analytics_events: those
			// rows are the lightweight Phase 16 dashboard counters, while
			// revenue_events carries the attribution fields (goal_target,
			// incremental_value) plus order ids and is only written when the
			// revenue tracking gate passes (RevenueTracker::tracking_enabled
			// — master + analytics toggles plus the
			// goalcart_revenue_tracking_enabled filter).
			$revenue_events => "CREATE TABLE {$revenue_events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_type varchar(40) NOT NULL,
				goal_id bigint(20) unsigned DEFAULT NULL,
				campaign_id bigint(20) unsigned DEFAULT NULL,
				product_id bigint(20) unsigned DEFAULT NULL,
				order_id bigint(20) unsigned DEFAULT NULL,
				session_id varchar(32) DEFAULT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				cart_value decimal(19,4) DEFAULT NULL,
				goal_target decimal(19,4) DEFAULT NULL,
				incremental_value decimal(19,4) DEFAULT NULL,
				meta longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event_type (event_type),
				KEY goal_id (goal_id),
				KEY campaign_id (campaign_id),
				KEY product_id (product_id),
				KEY order_id (order_id),
				KEY session_id (session_id),
				KEY user_id (user_id),
				KEY created_at (created_at),
				KEY goal_event (goal_id, event_type),
				KEY order_event (order_id, event_type),
				UNIQUE KEY order_dedup (event_type, order_id)
			) ENGINE=InnoDB {$collate};",

			// Phase 33 (Aggregation): one row per goal per day — the
			// pre-aggregated revenue metrics the dashboard reads instead of
			// scanning the raw event log on every admin request.
			$revenue_daily => "CREATE TABLE {$revenue_daily} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				report_date date NOT NULL,
				goal_id bigint(20) unsigned DEFAULT NULL,
				views int(10) unsigned NOT NULL DEFAULT 0,
				progressions int(10) unsigned NOT NULL DEFAULT 0,
				completions int(10) unsigned NOT NULL DEFAULT 0,
				conversions int(10) unsigned NOT NULL DEFAULT 0,
				revenue decimal(19,4) NOT NULL DEFAULT 0,
				incremental_revenue decimal(19,4) NOT NULL DEFAULT 0,
				reward_cost decimal(19,4) NOT NULL DEFAULT 0,
				estimated_profit decimal(19,4) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY goal_id (goal_id),
				KEY report_date (report_date),
				KEY goal_date (goal_id, report_date)
			) ENGINE=InnoDB {$collate};",

			// Phase 33 (Order attribution): one row per goal per order — the
			// deterministic link between an order and the goal(s) that
			// influenced it, with the attribution model (direct vs assisted)
			// and the incremental value attributed to the goal.
			$goal_attrib => "CREATE TABLE {$goal_attrib} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				goal_id bigint(20) unsigned DEFAULT NULL,
				session_id varchar(32) DEFAULT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				model varchar(20) NOT NULL DEFAULT 'direct',
				order_total decimal(19,4) NOT NULL DEFAULT 0,
				incremental_value decimal(19,4) NOT NULL DEFAULT 0,
				goal_completed tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY goal_id (goal_id),
				KEY session_id (session_id),
				KEY model (model),
				KEY created_at (created_at),
				UNIQUE KEY order_goal_model (order_id, goal_id, model)
			) ENGINE=InnoDB {$collate};",

			// Phase 33 (Smart Upsell): raw upsell interaction events —
			// impression / clicked / added / order per product per session.
			$upsell_events => "CREATE TABLE {$upsell_events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_type varchar(40) NOT NULL,
				goal_id bigint(20) unsigned DEFAULT NULL,
				product_id bigint(20) unsigned DEFAULT NULL,
				order_id bigint(20) unsigned DEFAULT NULL,
				session_id varchar(32) DEFAULT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				cart_value decimal(19,4) DEFAULT NULL,
				meta longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event_type (event_type),
				KEY goal_id (goal_id),
				KEY product_id (product_id),
				KEY order_id (order_id),
				KEY session_id (session_id),
				KEY created_at (created_at),
				KEY product_event (product_id, event_type),
				UNIQUE KEY order_dedup (event_type, order_id)
			) ENGINE=InnoDB {$collate};",

			// Phase 33 (Historical Learning): per-product upsell aggregates
			// rebuilt by the daily aggregator — the conversion signal the
			// ranking engine reads (impressions, clicks, adds, orders,
			// revenue, conversion rate).
			$upsell_stats => "CREATE TABLE {$upsell_stats} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_id bigint(20) unsigned NOT NULL,
				impressions int(10) unsigned NOT NULL DEFAULT 0,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				adds int(10) unsigned NOT NULL DEFAULT 0,
				orders int(10) unsigned NOT NULL DEFAULT 0,
				revenue decimal(19,4) NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY product_id (product_id)
			) ENGINE=InnoDB {$collate};",
		);
	}

	/**
	 * Index definitions for every plugin table.
	 *
	 * dbDelta() creates indexes on NEW tables but CANNOT add an index to an
	 * existing table, so any index declared after a table first shipped
	 * (e.g. the analytics composite keys) must be applied by the installer
	 * with ALTER TABLE — the same pattern as foreign_keys(). The set below
	 * mirrors the CREATE TABLE statements exactly, so fresh installs get
	 * their indexes from dbDelta and upgrades get the missing ones from
	 * Installer::maybe_add_indexes().
	 *
	 * Note: this list and the KEY lines in create_statements() are two
	 * views of the same index set — keep them in sync when adding or
	 * removing an index (the installer skips keys that already exist by
	 * name, so a divergence only surfaces as a missing index on upgraded
	 * installs).
	 *
	 * P22 hardening (Database → indexed queries): every query path in the
	 * repositories and the analytics layer is covered by an index (status /
	 * type / campaign_id on goals, event_type / session_id / created_at on
	 * the append-only event log, plus the goal_event and campaign_event
	 * composite keys the dashboard aggregations group by).
	 *
	 * @return array<string, array<string, string[]>> Table => index name => columns.
	 */
	public static function indexes() {
		$campaigns      = self::table( 'campaigns' );
		$goals          = self::table( 'goals' );
		$events         = self::table( 'analytics_events' );
		$revenue_events = self::table( 'revenue_events' );
		$revenue_daily  = self::table( 'revenue_daily' );
		$goal_attrib    = self::table( 'goal_attribution' );
		$upsell_events  = self::table( 'upsell_events' );
		$upsell_stats   = self::table( 'upsell_stats' );

		return array(
			$campaigns => array(
				'status'    => array( 'status' ),
				'starts_at' => array( 'starts_at' ),
				'ends_at'   => array( 'ends_at' ),
			),
			$goals => array(
				'status'      => array( 'status' ),
				'type'        => array( 'type' ),
				'campaign_id' => array( 'campaign_id' ),
				'priority'    => array( 'priority' ),
				'starts_at'   => array( 'starts_at' ),
				'ends_at'     => array( 'ends_at' ),
			),
			$events => array(
				'goal_id'        => array( 'goal_id' ),
				'campaign_id'    => array( 'campaign_id' ),
				'event_type'     => array( 'event_type' ),
				'session_id'     => array( 'session_id' ),
				'product_id'     => array( 'product_id' ),
				'order_id'       => array( 'order_id' ),
				'created_at'     => array( 'created_at' ),
				'goal_event'     => array( 'goal_id', 'event_type' ),
				'campaign_event' => array( 'campaign_id', 'event_type' ),
			),
			$revenue_events => array(
				'event_type'  => array( 'event_type' ),
				'goal_id'     => array( 'goal_id' ),
				'campaign_id' => array( 'campaign_id' ),
				'product_id'  => array( 'product_id' ),
				'order_id'    => array( 'order_id' ),
				'session_id'  => array( 'session_id' ),
				'user_id'     => array( 'user_id' ),
				'created_at'  => array( 'created_at' ),
				'goal_event'  => array( 'goal_id', 'event_type' ),
				'order_event' => array( 'order_id', 'event_type' ),
			),
			$revenue_daily => array(
				'goal_id'     => array( 'goal_id' ),
				'report_date' => array( 'report_date' ),
				'goal_date'   => array( 'goal_id', 'report_date' ),
			),
			$goal_attrib => array(
				'order_id'  => array( 'order_id' ),
				'goal_id'   => array( 'goal_id' ),
				'session_id' => array( 'session_id' ),
				'model'     => array( 'model' ),
				'created_at' => array( 'created_at' ),
			),
			$upsell_events => array(
				'event_type'   => array( 'event_type' ),
				'goal_id'      => array( 'goal_id' ),
				'product_id'   => array( 'product_id' ),
				'order_id'     => array( 'order_id' ),
				'session_id'   => array( 'session_id' ),
				'created_at'   => array( 'created_at' ),
				'product_event' => array( 'product_id', 'event_type' ),
			),
			$upsell_stats => array(
				'product_id' => array( 'product_id' ),
			),
		);
	}

	/**
	 * Unique key definitions that dbDelta applies only to NEW tables.
	 *
	 * Same contract as indexes(): the CREATE TABLE statements above declare
	 * these inline, and the installer re-applies any that are missing on
	 * upgraded installs with ALTER TABLE (ADD UNIQUE KEY).
	 *
	 * `order_dedup` enforces the "an order is attributed exactly once"
	 * contract of RevenueTracker at the database level: the SELECT-based
	 * dedup in the tracker closes the common re-report paths, while the
	 * unique key is the final guard against a concurrent double-report of
	 * the same order (the same hardening rationale as the
	 * order_goal_model unique key on goal_attribution). MySQL permits
	 * multiple NULL order_ids in a unique key, so rows without an order
	 * (views, progress, cart snapshots, impressions) are unaffected.
	 *
	 * @return array<string, array<string, string[]>> Table => key name => columns.
	 */
	public static function unique_keys() {
		$revenue_events = self::table( 'revenue_events' );
		$upsell_events  = self::table( 'upsell_events' );

		return array(
			$revenue_events => array(
				'order_dedup' => array( 'event_type', 'order_id' ),
			),
			$upsell_events => array(
				'order_dedup' => array( 'event_type', 'order_id' ),
			),
		);
	}

	/**
	 * Foreign key definitions.
	 *
	 * dbDelta() cannot create foreign keys, so the installer adds them with
	 * ALTER TABLE statements based on this definition.
	 *
	 * Only plugin-owned tables get foreign keys (campaigns, goals, the
	 * analytics event log, and the Phase 33 revenue/upsell tables that
	 * reference plugin goals/campaigns), always ON DELETE SET NULL so
	 * analytics history and standalone goals survive deletion.
	 *
	 * WooCommerce data (product_id, order_id) is deliberately referenced
	 * WITHOUT foreign keys: since WC 8.2 orders live in the High-Performance
	 * Order Storage tables, not wp_posts, and an FK into a WC table would
	 * either block WooCommerce's own deletion flows or silently
	 * cascade-delete analytics history. They stay plain indexed columns,
	 * mirroring the reference plugin's convention.
	 *
	 * @return array[] Each entry: table, name, column, references, referenced_column, on_delete.
	 */
	public static function foreign_keys() {
		$goals           = self::table( 'goals' );
		$campaigns       = self::table( 'campaigns' );
		$events          = self::table( 'analytics_events' );
		$revenue_events  = self::table( 'revenue_events' );
		$revenue_daily   = self::table( 'revenue_daily' );
		$goal_attrib     = self::table( 'goal_attribution' );
		$upsell_events   = self::table( 'upsell_events' );

		return array(
			array(
				'table'             => $goals,
				'name'              => 'fk_goalcart_goals_campaign',
				'column'            => 'campaign_id',
				'references'        => $campaigns,
				'referenced_column' => 'id',
				'on_delete'         => 'SET NULL',
			),
			array(
				'table'             => $events,
				'name'              => 'fk_goalcart_analytics_goal',
				'column'            => 'goal_id',
				'references'        => $goals,
				'referenced_column' => 'id',
				'on_delete'         => 'SET NULL',
			),
			array(
				'table'             => $events,
				'name'              => 'fk_goalcart_analytics_campaign',
				'column'            => 'campaign_id',
				'references'        => $campaigns,
				'referenced_column' => 'id',
				'on_delete'         => 'SET NULL',
			),
			// Phase 33.1: the revenue/upsell tables reference plugin goals
			// and campaigns, so they follow the same SET NULL convention as
			// analytics_events — deleting a goal/campaign never orphans or
			// cascades away its attribution history.
			array(
				'table'             => $revenue_events,
				'name'              => 'fk_goalcart_revenue_goal',
				'column'            => 'goal_id',
				'references'        => $goals,
				'referenced_column' => 'id',
				'on_delete'         => 'SET NULL',
			),
			array(
				'table'             => $revenue_events,
				'name'              => 'fk_goalcart_revenue_campaign',
				'column'            => 'campaign_id',
				'references'        => $campaigns,
				'referenced_column' => 'id',
				'on_delete'         => 'SET NULL',
			),
			array(
				'table'             => $revenue_daily,
				'name'              => 'fk_goalcart_daily_goal',
				'column'            => 'goal_id',
				'references'        => $goals,
				'referenced_column' => 'id',
				'on_delete'         => 'SET NULL',
			),
			array(
				'table'             => $goal_attrib,
				'name'              => 'fk_goalcart_attribution_goal',
				'column'            => 'goal_id',
				'references'        => $goals,
				'referenced_column' => 'id',
				'on_delete'         => 'SET NULL',
			),
			array(
				'table'             => $upsell_events,
				'name'              => 'fk_goalcart_upsell_goal',
				'column'            => 'goal_id',
				'references'        => $goals,
				'referenced_column' => 'id',
				'on_delete'         => 'SET NULL',
			),
		);
	}
}
