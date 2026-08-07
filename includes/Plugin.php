<?php
/**
 * Plugin bootstrap class for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart;

use GoalCart\Admin\Admin;
use GoalCart\Admin\AssetLoader;
use GoalCart\Compatibility;
use GoalCart\Database\Installer;
use GoalCart\Goals\GoalEngine;
use GoalCart\Hooks\HookManager;
use GoalCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 *
 * Singleton that bootstraps the whole plugin: builds the dependency
 * injection container, resolves the core services, and applies every
 * registered action/filter hook through the HookManager.
 *
 * Service set is intentionally minimal during the foundation phase; the
 * Tracker, REST controllers and report services are added by later
 * phases through the same register_services() wiring.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Dependency injection container.
	 *
	 * @var Container
	 */
	protected $container;

	/**
	 * Hook manager.
	 *
	 * @var HookManager
	 */
	protected $hooks;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	protected $booted = false;

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {
		$this->container = new Container();
		$this->hooks     = new HookManager();

		$this->register_services();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Get the plugin singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin: build services and apply all hooks.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		// Activation / deactivation hooks (Phase 2: Plugin Foundation).
		register_activation_hook( GOALCART_FILE, array( Installer::class, 'activate' ) );
		register_deactivation_hook( GOALCART_FILE, array( Installer::class, 'deactivate' ) );

		// WooCommerce dependency + version gate. Runs at priority 5 on
		// plugins_loaded — after every plugin is loaded — so the WC checks
		// are not load-order dependent (see Compatibility::gate()).
		$this->hooks()->add_action( 'plugins_loaded', array( Compatibility::class, 'gate' ), 5 );

		// Schema migrations on every load (plugins_loaded) and admin_init,
		// mirroring the reference installer so upgrades run for users who
		// update the plugin files without going through activation.
		$this->hooks()->add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		$this->hooks()->add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );

		// Load the text domain (Phase 2: translation loading).
		$this->hooks()->add_action( 'init', array( $this, 'load_textdomain' ) );

		// Custom cron intervals must be registered on every request
		// (including wp-cron.php) so scheduled events keep rescheduling
		// themselves after each run.
		$this->hooks()->add_filter( 'cron_schedules', array( Installer::class, 'cron_schedules' ) );

		// Declare WooCommerce feature compatibility (HPOS). Goal Cart
		// reads orders through wc_get_order() and never writes to
		// WooCommerce's order tables, so HPOS is fully supported. Without
		// this declaration WooCommerce flags the plugin as incompatible
		// whenever the custom order tables feature is enabled.
		$this->hooks()->add_action( 'before_woocommerce_init', array( $this, 'declare_feature_compatibility' ) );

		// Register hooks from every core service.
		$this->hooks()->register( $this->settings() );
		$this->hooks()->register( $this->admin() );

		// Apply everything to WordPress.
		$this->hooks()->run();

		/**
		 * Fires once the plugin has been fully bootstrapped.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'goalcart_loaded', $this );
	}

	/**
	 * Register core services in the container.
	 *
	 * @return void
	 */
	protected function register_services() {
		$this->container->singleton( Settings::class, function () {
			return new Settings();
		} );

		// Goal engine (Phase 4): stateless calculation engine, resolved
		// lazily on first use by the frontend integration / REST layers.
		$this->container->singleton( GoalEngine::class, function () {
			return new GoalEngine();
		} );

		$this->container->singleton( AssetLoader::class, function ( Container $container ) {
			return new AssetLoader( $container->get( Settings::class ) );
		} );

		$this->container->singleton( Admin::class, function ( Container $container ) {
			return new Admin( $container->get( Settings::class ), $container->get( AssetLoader::class ) );
		} );
	}

	/**
	 * Get the dependency injection container.
	 *
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/**
	 * Get the hook manager.
	 *
	 * @return HookManager
	 */
	public function hooks() {
		return $this->hooks;
	}

	/**
	 * Get the settings service.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->container->get( Settings::class );
	}

	/**
	 * Get the goal engine (Phase 4).
	 *
	 * @return GoalEngine
	 */
	public function goal_engine() {
		return $this->container->get( GoalEngine::class );
	}

	/**
	 * Get the admin service.
	 *
	 * @return Admin
	 */
	public function admin() {
		return $this->container->get( Admin::class );
	}

	/**
	 * Declare compatibility with WooCommerce features (HPOS).
	 *
	 * Hooked to 'before_woocommerce_init'. WooCommerce shows its
	 * "incompatible plugins" notice for any plugin that has not declared
	 * compatibility with an enabled feature whose default compatibility
	 * is 'incompatible' — most notably the custom order tables (HPOS)
	 * feature. Goal Cart reads orders through wc_get_order() and runs its
	 * own analytics against its own tables, so declaring compatibility is
	 * accurate.
	 *
	 * @return void
	 */
	public function declare_feature_compatibility() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			GOALCART_FILE,
			true
		);
	}

	/**
	 * Load the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'goalcart', false, dirname( GOALCART_BASENAME ) . '/languages' );
	}
}
