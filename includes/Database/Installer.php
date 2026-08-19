<?php
/**
 * Database installer and migrator for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Installer
 *
 * Creates, upgrades, and removes the plugin database tables.
 * Wired to the WordPress activation/deactivation hooks and to
 * admin_init for version-driven upgrades.
 *
 * Mirrors the reference plugin (WooInsights\Database\Installer). The
 * schema itself is defined in Schema, so every method here is already
 * table-driven and needs no changes when the tables land.
 */
class Installer {

	/**
	 * Option name storing the installed database schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'faracart_db_version';

	/**
	 * Run on plugin activation: create all tables and schedule crons.
	 *
	 * @return void
	 */
	public static function activate() {
		self::maybe_create_tables();
		self::maybe_schedule_events();
		update_option( self::DB_VERSION_OPTION, FARACART_DB_VERSION, false );
	}

	/**
	 * Schedule every cron event owned by the plugin, idempotently.
	 *
	 * Each event is scheduled with `wp_schedule_event()` only when no
	 * pending occurrence exists (wp_next_scheduled()), so re-running
	 * activation/upgrade never stacks duplicate schedules. Every event uses
	 * its own interval from cron_intervals() (the weekly interval is
	 * registered through cron_schedules() on every request; the daily
	 * aggregation uses core's 'daily').
	 *
	 * @return void
	 */
	public static function maybe_schedule_events() {
		$intervals = self::cron_intervals();

		foreach ( self::cron_events() as $event ) {
			if ( wp_next_scheduled( $event ) ) {
				continue;
			}

			$interval = isset( $intervals[ $event ] ) ? $intervals[ $event ] : 'faracart_weekly';

			wp_schedule_event( time() + HOUR_IN_SECONDS, $interval, $event );
		}
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * Data is intentionally preserved; only transient/runtime state is
	 * cleared (scheduled events, etc.). Destructive cleanup happens on
	 * uninstall.
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( self::cron_events() as $event ) {
			wp_clear_scheduled_hook( $event );
		}
	}

	/**
	 * Scheduled event names owned by the plugin.
	 *
	 * Analytics Foundation adds the weekly revenue-event
	 * cleanup job (RevenueTracker::CLEANUP_EVENT); (Aggregation &
	 * Performance) adds the daily aggregation job
	 * (DailyAggregator::AGGREGATE_EVENT). The schedule is registered in
	 * activate() and cleared in deactivate() through this list.
	 *
	 * @return string[]
	 */
	public static function cron_events() {
		return array(
			\FaraCart\Analytics\RevenueTracker::CLEANUP_EVENT,
			\FaraCart\Analytics\DailyAggregator::AGGREGATE_EVENT,
		);
	}

	/**
	 * Cron interval per scheduled event.
	 *
	 * @return array<string, string> Event name => WP cron interval key.
	 */
	public static function cron_intervals() {
		return array(
			\FaraCart\Analytics\RevenueTracker::CLEANUP_EVENT  => 'faracart_weekly',
			\FaraCart\Analytics\DailyAggregator::AGGREGATE_EVENT => 'daily',
		);
	}

	/**
	 * Check the installed schema version and run pending migrations.
	 *
	 * Hooked to plugins_loaded and admin_init so upgrades also run for
	 * users who update the plugin files without going through the normal
	 * update flow.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		if ( version_compare( $installed, FARACART_DB_VERSION, '>=' ) ) {
			// Even on an up-to-date install, make sure the plugin's cron
			// events are scheduled (they may have been cleared by an
			// interrupted deactivate, or a manual wp-cron cleanup). The
			// call is idempotent (wp_next_scheduled guard).
			self::maybe_schedule_events();
			return;
		}

		self::maybe_create_tables();
		self::maybe_schedule_events();
		update_option( self::DB_VERSION_OPTION, FARACART_DB_VERSION, false );
	}

	/**
	 * Whether a database table exists.
	 *
	 * @param string $table Fully qualified table name.
	 * @return bool
	 */
	protected static function table_exists( $table ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);
	}

	/**
	 * Whether a constraint exists on a table.
	 *
	 * @param string $table Fully qualified table name.
	 * @param string $name  Constraint name.
	 * @return bool
	 */
	protected static function constraint_exists( $table, $name ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s',
				$wpdb->dbname,
				$table,
				$name
			)
		);
	}

	/**
	 * Whether a column exists on a table.
	 *
	 * @param string $table  Fully qualified table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	protected static function column_exists( $table, $column ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$wpdb->dbname,
				$table,
				$column
			)
		);
	}

	/**
	 * Whether an index exists on a table.
	 *
	 * @param string $table Fully qualified table name.
	 * @param string $name  Index name.
	 * @return bool
	 */
	protected static function index_exists( $table, $name ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$wpdb->dbname,
				$table,
				$name
			)
		);
	}

	/**
	 * Register a weekly cron interval.
	 *
	 * WordPress core only ships hourly/twicedaily/daily, so the plugin
	 * adds its own weekly interval for recurring jobs (analytics
	 * roll-ups in later phases).
	 *
	 * @param array<string, array<string, mixed>> $schedules Cron schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['faracart_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'faracart' ),
		);

		return $schedules;
	}

	/**
	 * Create or update all plugin tables (idempotent via dbDelta).
	 *
	 * @return void
	 */
	public static function maybe_create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::create_statements() as $statement ) {
			dbDelta( $statement );
		}

		self::maybe_add_indexes();
		self::maybe_add_foreign_keys();
	}

	/**
	 * Add indexes (and unique keys) that dbDelta cannot manage on
	 * existing tables.
	 *
	 * dbDelta() creates indexes only on NEW tables — it never adds an
	 * index to a table that already exists (P22 hardening: the analytics
	 * composite keys mission_event / campaign_event shipped after the table
	 * first deployed, so upgrades would silently miss them). Each missing
	 * index is added with ALTER TABLE after an INFORMATION_SCHEMA check,
	 * making the operation safe to re-run on every activation/upgrade.
	 *
	 * Unique keys (Schema::unique_keys(), e.g. the order_dedup
	 * anti-double-attribution guard) flow through the same path with
	 * ADD UNIQUE KEY, and are skipped by name once present.
	 *
	 * @return void
	 */
	protected static function maybe_add_indexes() {
		self::apply_indexes( Schema::indexes(), false );
		self::apply_indexes( Schema::unique_keys(), true );
	}

	/**
	 * Apply a set of index/unique-key definitions idempotently.
	 *
	 * Idempotency is name-based (same as maybe_add_foreign_keys): an
	 * index whose name already exists is assumed to be the one we want.
	 * Index names are plugin constants, so no two schema keys share a
	 * name with different column sets.
	 *
	 * @param array<string, array<string, string[]>> $indexes Table => name => columns.
	 * @param bool                                    $unique  Whether to add as UNIQUE KEY.
	 * @return void
	 */
	protected static function apply_indexes( array $indexes, $unique ) {
		global $wpdb;

		$kind = $unique ? 'UNIQUE KEY' : 'KEY';

		foreach ( $indexes as $table => $defs ) {
			$existing = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					$wpdb->dbname,
					$table
				)
			);

			foreach ( $defs as $name => $columns ) {
				if ( in_array( $name, $existing, true ) ) {
					continue;
				}

				$columns_sql = implode(
					', ',
					array_map(
						function ( $column ) {
							return '`' . $column . '`';
						},
						$columns
					)
				);

				$wpdb->query( "ALTER TABLE `{$table}` ADD {$kind} `{$name}` ({$columns_sql})" );

				if ( ! empty( $wpdb->last_error ) ) {
					// Log the failure without blocking activation; missing
					// indexes can be retried on the next upgrade.
					error_log( 'FaraCart: failed to add index ' . $name . ' on ' . $table . ': ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}
	}

	/**
	 * Add foreign keys that dbDelta cannot manage.
	 *
	 * Uses INFORMATION_SCHEMA to skip constraints that already exist, making
	 * the operation safe to re-run on every activation/upgrade.
	 *
	 * @return void
	 */
	protected static function maybe_add_foreign_keys() {
		global $wpdb;

		foreach ( Schema::foreign_keys() as $fk ) {
			$table = $fk['table'];

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s',
					$wpdb->dbname,
					$table,
					$fk['name']
				)
			);

			if ( $exists > 0 ) {
				continue;
			}

			$wpdb->query(
				"ALTER TABLE `{$table}` ADD CONSTRAINT `{$fk['name']}` " .
				"FOREIGN KEY (`{$fk['column']}`) REFERENCES `{$fk['references']}` (`{$fk['referenced_column']}`) " .
				"ON DELETE {$fk['on_delete']}"
			);

			if ( ! empty( $wpdb->last_error ) ) {
				// Log the failure without blocking activation; missing FKs
				// can be retried on the next upgrade.
				error_log( 'FaraCart: failed to add foreign key ' . $fk['name'] . ': ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Remove every plugin table and option. Called from uninstall.php.
	 *
	 * Foreign keys are disabled around the drops: child tables
	 * (analytics_events -> missions/campaigns, missions -> campaigns) reference
	 * their parents, and MySQL refuses to DROP a parent table while a
	 * child FK still references it. Wrapping the drops in
	 * FOREIGN_KEY_CHECKS=0 makes uninstall order-independent and safe to
	 * re-run. (Improvement over the reference installer, which drops in
	 * dependency order and can leave parent tables behind.)
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( Schema::tables() as $table_name ) {
			$table = Schema::table( $table_name );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		delete_option( self::DB_VERSION_OPTION );

		self::cleanup_generated_coupons();
	}

	/**
	 * Remove the coupons generated by coupon rewards.
	 *
	 * Deletes every coupon created by the CouponApplicator plus the option
	 * that tracks them. WooCommerce may not be loaded during uninstall, so
	 * the option is always removed and the coupon posts are deleted only
	 * when WC is available.
	 *
	 * @return void
	 */
	protected static function cleanup_generated_coupons() {
		$generated = get_option( 'faracart_generated_coupons', array() );
		$generated = is_array( $generated ) ? $generated : array();

		if ( class_exists( 'WC_Coupon' ) && function_exists( 'wc_get_coupon_id_by_code' ) ) {
			foreach ( $generated as $code ) {
				$coupon_id = wc_get_coupon_id_by_code( (string) $code );

				if ( $coupon_id ) {
					wp_delete_post( $coupon_id, true );
				}
			}
		}

		delete_option( 'faracart_generated_coupons' );
	}
}
