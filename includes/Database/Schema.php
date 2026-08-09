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
	 * @return string[]
	 */
	public static function tables() {
		return array(
			'campaigns',
			'goals',
			'analytics_events',
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
		);
	}

	/**
	 * Foreign key definitions.
	 *
	 * dbDelta() cannot create foreign keys, so the installer adds them with
	 * ALTER TABLE statements based on this definition.
	 *
	 * Only plugin-owned tables get foreign keys (campaigns, goals, and the
	 * analytics event log), always ON DELETE SET NULL so analytics history
	 * and standalone goals survive deletion.
	 *
	 * WooCommerce data (product_id, order_id in analytics_events) is
	 * deliberately referenced WITHOUT foreign keys: since WC 8.2 orders live
	 * in the High-Performance Order Storage tables, not wp_posts, and an FK
	 * into a WC table would either block WooCommerce's own deletion flows
	 * or silently cascade-delete analytics history. They stay plain indexed
	 * columns, mirroring the reference plugin's convention.
	 *
	 * @return array[] Each entry: table, name, column, references, referenced_column, on_delete.
	 */
	public static function foreign_keys() {
		$goals     = self::table( 'goals' );
		$campaigns = self::table( 'campaigns' );
		$events    = self::table( 'analytics_events' );

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
		);
	}
}
