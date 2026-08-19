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
 * schema itself is defined in Schema (empty until Phase 3), so every
 * method here is already table-driven and needs no changes when the
 * tables land.
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
	 * Phase 33.1 (Analytics Foundation) adds the weekly revenue-event
	 * cleanup job (RevenueTracker::CLEANUP_EVENT); Phase 33.3 (Aggregation &
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

		// 0.7.0: Goal → Mission terminology migration. Runs before the
		// schema create/upgrade pass so the renamed tables exist before
		// dbDelta / index / foreign-key statements reference them.
		if ( version_compare( $installed, '0.7.0', '<' ) ) {
			self::maybe_migrate_goal_to_mission();
		}

		// 0.7.1: repair stored settings that older versions could persist
		// but the current REST schema rejects. One-time, version-gated —
		// never a permanent runtime check (see the method docblock).
		if ( version_compare( $installed, '0.7.1', '<' ) ) {
			self::maybe_migrate_settings_option();
		}

		// 0.7.2: rewrite the legacy Goal analytics event names to Mission in
		// the stored event_type values (the 0.7.0 migration renamed the
		// tables/columns, not the data values). One-time, version-gated.
		if ( version_compare( $installed, '0.7.2', '<' ) ) {
			self::maybe_migrate_goal_event_names();
		}

		self::maybe_create_tables();
		self::maybe_schedule_events();
		update_option( self::DB_VERSION_OPTION, FARACART_DB_VERSION, false );
	}

	/**
	 * Migrate the Goal terminology in the database to Mission (0.7.0).
	 *
	 * Renames the tables/columns/indexes/foreign keys in place, preserving
	 * every row. The legacy `goals` tables are renamed to `missions` only
	 * when they exist (fresh installs skip straight to the new schema), and
	 * every statement is guarded by an INFORMATION_SCHEMA existence check so
	 * a partial/interrupted migration is safe to re-run on the next upgrade.
	 *
	 * Renames performed (all in the plugin's own prefix):
	 *   - tables:     goals → missions, goal_attribution → mission_attribution,
	 *                 goal_completions → mission_completions
	 *   - columns:    goal_id → mission_id (on 6 tables), goal_target →
	 *                 mission_target (revenue_events), goal_completed →
	 *                 mission_completed (mission_attribution)
	 *   - indexes:    goal_id → mission_id, goal_event → mission_event,
	 *                 goal_date → mission_date, goal_user → mission_user,
	 *                 goal_session → mission_session, order_goal →
	 *                 order_mission, order_goal_model → order_mission_model
	 *   - foreign keys: fk_faracart_*_goal → fk_faracart_*_mission
	 *
	 * Foreign keys are dropped first (MySQL cannot rename a constraint in
	 * place) and re-added by the standard maybe_add_foreign_keys() pass that
	 * runs right after this method in maybe_upgrade().
	 *
	 * @return void
	 */
	protected static function maybe_migrate_goal_to_mission() {
		global $wpdb;

		$prefix = $wpdb->prefix . Schema::TABLE_PREFIX;

		// --- 1. Drop the legacy foreign keys (renamed/readded below). ---
		$legacy_fks = array(
			$prefix . 'goals'              => array( 'fk_faracart_goals_campaign' ),
			$prefix . 'analytics_events'   => array( 'fk_faracart_analytics_goal' ),
			$prefix . 'revenue_events'     => array( 'fk_faracart_revenue_goal' ),
			$prefix . 'revenue_daily'      => array( 'fk_faracart_daily_goal' ),
			$prefix . 'goal_attribution'   => array( 'fk_faracart_attribution_goal' ),
			$prefix . 'upsell_events'      => array( 'fk_faracart_upsell_goal' ),
			$prefix . 'goal_completions'   => array( 'fk_faracart_completions_goal' ),
		);

		foreach ( $legacy_fks as $table => $names ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			foreach ( $names as $name ) {
				if ( self::constraint_exists( $table, $name ) ) {
					$wpdb->query( "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}
		}

		// --- 2. Rename the three tables (data preserved). ---
		$table_renames = array(
			'goals'            => 'missions',
			'goal_attribution' => 'mission_attribution',
			'goal_completions' => 'mission_completions',
		);

		foreach ( $table_renames as $old => $new ) {
			$old_table = $prefix . $old;
			$new_table = $prefix . $new;

			if ( self::table_exists( $old_table ) && ! self::table_exists( $new_table ) ) {
				$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		// --- 3. Rename the goal_* columns. ---
		// Each entry carries the column definition so CHANGE COLUMN
		// preserves the exact type (goal_target is decimal, goal_completed
		// is tinyint — a generic definition would corrupt them).
		$column_renames = array(
			$prefix . 'analytics_events'      => array(
				'goal_id' => array( 'mission_id', 'bigint(20) unsigned DEFAULT NULL' ),
			),
			$prefix . 'revenue_events'        => array(
				'goal_id'     => array( 'mission_id', 'bigint(20) unsigned DEFAULT NULL' ),
				'goal_target' => array( 'mission_target', 'decimal(19,4) DEFAULT NULL' ),
			),
			$prefix . 'revenue_daily'         => array(
				'goal_id' => array( 'mission_id', 'bigint(20) unsigned DEFAULT NULL' ),
			),
			$prefix . 'mission_attribution'   => array(
				'goal_id'         => array( 'mission_id', 'bigint(20) unsigned DEFAULT NULL' ),
				'goal_completed'  => array( 'mission_completed', 'tinyint(1) NOT NULL DEFAULT 0' ),
			),
			$prefix . 'upsell_events'         => array(
				'goal_id' => array( 'mission_id', 'bigint(20) unsigned DEFAULT NULL' ),
			),
			$prefix . 'mission_completions'   => array(
				'goal_id' => array( 'mission_id', 'bigint(20) unsigned DEFAULT NULL' ),
			),
		);

		foreach ( $column_renames as $table => $columns ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			foreach ( $columns as $old => $column ) {
				list( $new, $definition ) = $column;

				if ( self::column_exists( $table, $old ) && ! self::column_exists( $table, $new ) ) {
					$wpdb->query( "ALTER TABLE `{$table}` CHANGE COLUMN `{$old}` `{$new}` {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}
		}

		// --- 4. Rename the goal_* indexes. ---
		$index_renames = array(
			$prefix . 'analytics_events'      => array(
				'goal_id'    => 'mission_id',
				'goal_event' => 'mission_event',
			),
			$prefix . 'revenue_events'        => array(
				'goal_id'    => 'mission_id',
				'goal_event' => 'mission_event',
			),
			$prefix . 'revenue_daily'         => array(
				'goal_id'    => 'mission_id',
				'goal_date'  => 'mission_date',
			),
			$prefix . 'mission_attribution'   => array(
				'goal_id'            => 'mission_id',
				'order_goal_model'   => 'order_mission_model',
			),
			$prefix . 'upsell_events'         => array(
				'goal_id'    => 'mission_id',
			),
			$prefix . 'mission_completions'   => array(
				'goal_id'      => 'mission_id',
				'order_goal'   => 'order_mission',
				'goal_user'    => 'mission_user',
				'goal_session' => 'mission_session',
			),
		);

		foreach ( $index_renames as $table => $indexes ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			foreach ( $indexes as $old => $new ) {
				if ( self::index_exists( $table, $old ) && ! self::index_exists( $table, $new ) ) {
					$wpdb->query( "ALTER TABLE `{$table}` RENAME INDEX `{$old}` TO `{$new}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}
		}
	}

	/**
	 * Repair the stored settings option (0.7.1).
	 *
	 * Older versions could persist values that the current REST schema
	 * rejects (the Settings page echoes the served values back on save, so
	 * an out-of-schema stored value fails the next save with a 400):
	 *
	 *  - a `frontend_template` outside the six design ids (a pre-engine
	 *    back-sync wrote retired ids such as 'card'/'ring' into it),
	 *  - the retired 'sticky' display location,
	 *  - pre-preset horizontal/vertical floating positions,
	 *  - the pre-rename (Goal → Mission) 'goal' scope keys and
	 *    `default_goal_behavior`.
	 *
	 * The repair runs exactly once per store during the upgrade to 0.7.1
	 * and is idempotent, so re-runs and fresh installs (no stored option)
	 * are no-ops. This replaces the former read-time self-healing in
	 * Settings::all() — the sanitization is a migration, not a permanent
	 * runtime check.
	 *
	 * @return void
	 */
	protected static function maybe_migrate_settings_option() {
		$option = get_option( \FaraCart\Settings\Settings::OPTION_NAME, null );

		if ( ! is_array( $option ) ) {
			return;
		}

		$settings = new \FaraCart\Settings\Settings();
		$defaults = $settings->defaults();
		$changed  = false;

		// Retired / unknown frontend_template ids fall back to the default.
		if ( isset( $option['frontend_template'] ) && ! in_array( (string) $option['frontend_template'], \FaraCart\Settings\Settings::MISSION_TEMPLATES, true ) ) {
			$option['frontend_template'] = $defaults['frontend_template'];
			$changed                     = true;
		}

		// Retired / unknown display locations are dropped (e.g. 'sticky').
		if ( isset( $option['frontend_locations'] ) && is_array( $option['frontend_locations'] ) ) {
			$locations = array_values( array_unique( array_intersect(
				\FaraCart\Settings\Settings::DISPLAY_LOCATIONS,
				array_map( 'strval', $option['frontend_locations'] )
			) ) );

			if ( $locations !== $option['frontend_locations'] ) {
				$option['frontend_locations'] = $locations;
				$changed                      = true;
			}
		}

		// Pre-preset floating positions (horizontal × vertical axes) are
		// migrated to the matching preset through the shared normalizer.
		foreach ( array( 'floating_desktop', 'floating_mobile' ) as $key ) {
			if ( ! isset( $option[ $key ] ) ) {
				continue;
			}

			$normalized = \FaraCart\Settings\Settings::normalize_floating_position( $option[ $key ], $defaults[ $key ] );

			if ( $normalized !== $option[ $key ] ) {
				$option[ $key ] = $normalized;
				$changed        = true;
			}
		}

		// Pre-rename (Goal → Mission) settings keys are migrated to the
		// canonical names and dropped.
		if ( array_key_exists( 'default_goal_behavior', $option ) ) {
			if ( ! array_key_exists( 'default_mission_behavior', $option ) ) {
				$option['default_mission_behavior'] = $option['default_goal_behavior'];
			}

			unset( $option['default_goal_behavior'] );
			$changed = true;
		}

		foreach ( array( 'template_defaults', 'template_settings', 'template_versions' ) as $group ) {
			if ( isset( $option[ $group ] ) && is_array( $option[ $group ] ) && array_key_exists( 'goal', $option[ $group ] ) ) {
				if ( ! array_key_exists( 'mission', $option[ $group ] ) ) {
					$option[ $group ]['mission'] = $option[ $group ]['goal'];
				}

				unset( $option[ $group ]['goal'] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( \FaraCart\Settings\Settings::OPTION_NAME, $option, false );
		}
	}

	/**
	 * Rewrite the legacy Goal analytics event names to Mission (0.7.2).
	 *
	 * The 0.7.0 migration renamed the tables/columns/indexes but left the
	 * stored `event_type` values (`goal_view`, `goal_impression`,
	 * `goal_progress`, `goal_completed`) untouched. This pass rewrites those
	 * values in place so the application layer can use the canonical Mission
	 * event names without losing the historical rows. Idempotent (each
	 * UPDATE only matches the old value) and guarded by a table-existence
	 * check so a fresh install skips it entirely.
	 *
	 * @return void
	 */
	protected static function maybe_migrate_goal_event_names() {
		global $wpdb;

		$analytics_table = Schema::table( 'analytics_events' );
		$revenue_table   = Schema::table( 'revenue_events' );

		$renames = array(
			'goal_impression' => 'mission_impression',
			'goal_view'       => 'mission_view',
			'goal_progress'   => 'mission_progress',
			'goal_completed'  => 'mission_completed',
		);

		foreach ( array( $analytics_table, $revenue_table ) as $table ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			foreach ( $renames as $old => $new ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `{$table}` SET event_type = %s WHERE event_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$new,
						$old
					)
				);
			}
		}
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
	 * Unique keys (Schema::unique_keys(), e.g. the Phase 33 order_dedup
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
	 * Remove the coupons generated by coupon rewards (Phase 5).
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
