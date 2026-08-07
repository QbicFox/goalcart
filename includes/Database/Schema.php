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
 * The reference plugin (WooInsights\Database\Schema) defines its tables
 * here as dbDelta statements plus foreign-key definitions applied by the
 * Installer. Goal Cart keeps the exact same framework; the concrete
 * tables (goals, campaigns, analytics events, …) are designed in
 * Phase 3 and filled into tables()/create_statements()/foreign_keys()
 * at that point, so activation/deactivation/uninstall stay table-driven
 * from day one.
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
	 * Populated in Phase 3 (Database & Domain Model).
	 *
	 * @return string[]
	 */
	public static function tables() {
		return array();
	}

	/**
	 * CREATE TABLE statements for every plugin table, formatted for dbDelta().
	 *
	 * Populated in Phase 3 (Database & Domain Model).
	 *
	 * @return string[] Table name => CREATE TABLE statement.
	 */
	public static function create_statements() {
		return array();
	}

	/**
	 * Foreign key definitions.
	 *
	 * dbDelta() cannot create foreign keys, so the installer adds them with
	 * ALTER TABLE statements based on this definition.
	 *
	 * Populated in Phase 3 (Database & Domain Model).
	 *
	 * @return array[]
	 */
	public static function foreign_keys() {
		return array();
	}
}
