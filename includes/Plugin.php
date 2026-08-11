<?php
/**
 * Plugin bootstrap class for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart;

use GoalCart\Admin\Admin;
use GoalCart\Admin\AssetLoader;
use GoalCart\Analytics\AnalyticsRepository;
use GoalCart\Analytics\AttributionEngine;
use GoalCart\Analytics\DailyAggregator;
use GoalCart\Analytics\GoalRecommendationEngine;
use GoalCart\Analytics\RevenueRepository;
use GoalCart\Analytics\RevenueTracker;
use GoalCart\Analytics\RewardCostEstimator;
use GoalCart\Analytics\UpsellRanker;
use GoalCart\Analytics\Session;
use GoalCart\Analytics\Tracker;
use GoalCart\Campaigns\CampaignRepository;
use GoalCart\Cart\CartIntegration;
use GoalCart\Compatibility;
use GoalCart\Database\Installer;
use GoalCart\Frontend\ProgressUI;
use GoalCart\Goals\GoalEngine;
use GoalCart\Goals\GoalRepository;
use GoalCart\Goals\MessageEngine;
use GoalCart\Hooks\HookManager;
use GoalCart\REST\AnalyticsController;
use GoalCart\REST\CampaignsController;
use GoalCart\REST\FrontendController;
use GoalCart\REST\GiftController;
use GoalCart\REST\GoalsController;
use GoalCart\REST\PreviewController;
use GoalCart\REST\RecommendationsController;
use GoalCart\REST\RevenueController;
use GoalCart\REST\SearchController;
use GoalCart\REST\SettingsController;
use GoalCart\REST\TemplatesController;
use GoalCart\REST\TrackController;
use GoalCart\REST\UpsellController;
use GoalCart\Rewards\RewardEngine;
use GoalCart\Settings\Settings;
use GoalCart\Suggestions\SuggestionEngine;
use GoalCart\Templates\TemplateEngine;
use GoalCart\Templates\TemplateRegistry;

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
		$this->hooks()->register( $this->cart_integration() );
		$this->hooks()->register( $this->reward_engine() );
		$this->hooks()->register( $this->admin() );

		// Analytics (Phase 16): session cookie, event recording, the
		// frontend config print and the server-side suggested-product
		// attribution all register through the Tracker.
		$this->hooks()->register( $this->container->get( Tracker::class ) );

		// Revenue optimization (Phase 33.1): the revenue event tracker
		// records the attribution funnel (goal_view → progress → completed
		// → order_paid) and the upsell funnel into their dedicated logs,
		// with idempotent dedup and the weekly retention cleanup cron.
		$this->hooks()->register( $this->container->get( RevenueTracker::class ) );

		// Revenue attribution (Phase 33.2): associates paid orders with the
		// goals that influenced their session (direct/assisted models) and
		// exposes the revenue metrics — incremental cart value, goal-driven
		// / assisted revenue, AOV, reward cost and profit impact.
		$this->hooks()->register( $this->container->get( AttributionEngine::class ) );

		// Aggregation & performance (Phase 33.3): the daily aggregator
		// pre-computes revenue_daily + upsell_stats on a bounded cron job, and
		// the revenue repository serves the cached summaries (overview, goal
		// performance, daily trend, product stats, goal recommendations) with
		// generation-versioned invalidation wired to order/goal/product changes
		// and aggregation runs.
		$this->hooks()->register( $this->container->get( DailyAggregator::class ) );
		$this->hooks()->register( $this->container->get( RevenueRepository::class ) );

		// Smart goal recommendation (Phase 33.4): the deterministic
		// threshold recommendation engine — AOV/median/distribution/shipping
		// /margin analyzers, candidate scoring, confidence and explanations —
		// served read-only through the cached revenue repository.
		$this->hooks()->register( $this->container->get( RecommendationsController::class ) );

		// Smart upsell (Phase 33.5): the deterministic product-ranking
		// engine (candidate collection, price-gap/relevance/inventory
		// /popularity/margin/conversion scorers, composite weighted score)
		// attributes upsell_order events server-side when a paid order
		// completes, and the controller exposes the public upsell tracking
		// endpoint plus the admin ranking/analytics reads.
		$this->hooks()->register( $this->container->get( UpsellRanker::class ) );
		$this->hooks()->register( $this->container->get( UpsellController::class ) );

		// Revenue optimization admin reads (Phase 33.6): the overview /
		// attribution / goal-performance endpoints serving the React Admin
		// Revenue section through the cached repository layer.
		$this->hooks()->register( $this->container->get( RevenueController::class ) );

		// Storefront progress UI (Phase 11): shortcode, display-location
		// injection, sticky bar and frontend assets.
		$this->hooks()->register( $this->container->get( ProgressUI::class ) );

		// REST controllers (Phase 7): each registers its routes on
		// rest_api_init through the same HookManager.
		$this->hooks()->register( $this->container->get( GoalsController::class ) );
		$this->hooks()->register( $this->container->get( SettingsController::class ) );
		$this->hooks()->register( $this->container->get( GiftController::class ) );
		$this->hooks()->register( $this->container->get( SearchController::class ) );
		$this->hooks()->register( $this->container->get( CampaignsController::class ) );
		$this->hooks()->register( $this->container->get( FrontendController::class ) );
		$this->hooks()->register( $this->container->get( PreviewController::class ) );
		$this->hooks()->register( $this->container->get( TrackController::class ) );
		$this->hooks()->register( $this->container->get( AnalyticsController::class ) );

		// Template registry (pluggable progress templates): the engine
		// resolves which template + settings render for each goal/campaign;
		// the REST endpoint lists the registered templates + schemas.
		$this->hooks()->register( $this->container->get( TemplatesController::class ) );

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

		// Goal repository (Phase 5): loads active goals from the database so
		// the reward engine can decide rewards on the live cart.
		$this->container->singleton( GoalRepository::class, function () {
			return new GoalRepository();
		} );

		// Message engine (Phase 13): stateless dynamic-message template
		// engine — state detection, variable substitution and localized
		// per-state copy, consumed by the frontend REST layer.
		$this->container->singleton( MessageEngine::class, function () {
			return new MessageEngine();
		} );

		// Suggestion engine (Phase 14): product recommendations that close
		// the goal gap — six sources, stock filter, relevance + price
		// proximity ranking — consumed by the frontend REST layer. Phase 32
		// (advanced upsell ranking) reads the suggestions_ranking mode.
		$this->container->singleton( SuggestionEngine::class, function ( Container $container ) {
			return new SuggestionEngine( $container->get( Settings::class ) );
		} );

		// Analytics (Phase 16): anonymous session, event recorder, and the
		// metrics repository consumed by the Phase 17 dashboard. The
		// Tracker registers the session cookie, the frontend config print
		// and the add-to-cart attribution hook.
		$this->container->singleton( Session::class, function () {
			return new Session();
		} );

		$this->container->singleton( Tracker::class, function ( Container $container ) {
			return new Tracker(
				$container->get( Settings::class ),
				$container->get( Session::class )
			);
		} );

		$this->container->singleton( AnalyticsRepository::class, function () {
			return new AnalyticsRepository();
		} );

		// Revenue event tracker (Phase 33.1): owns the revenue_events and
		// upsell_events logs — whitelisted, deduped, privacy-safe recording
		// plus the weekly retention cleanup cron.
		$this->container->singleton( RevenueTracker::class, function ( Container $container ) {
			return new RevenueTracker(
				$container->get( Settings::class ),
				$container->get( Session::class )
			);
		} );

		// Reward cost / profit impact estimator (Phase 33.2): deterministic
		// reward-cost models per type, product margin detection (only when
		// the store provides cost data) and profit impact with graceful
		// degradation to revenue-only analytics.
		$this->container->singleton( RewardCostEstimator::class, function () {
			return new RewardCostEstimator();
		} );

		// Revenue attribution engine (Phase 33.2): order association on
		// payment, direct/assisted attribution into goal_attribution, and
		// the SQL-aggregated metric reads (funnel, incremental cart value,
		// goal-driven/assisted revenue, AOV, shipping stats).
		$this->container->singleton( AttributionEngine::class, function ( Container $container ) {
			return new AttributionEngine(
				$container->get( RevenueTracker::class ),
				$container->get( Session::class ),
				$container->get( Settings::class ),
				$container->get( RewardCostEstimator::class ),
				$container->get( GoalRepository::class )
			);
		} );

		// Daily revenue aggregation (Phase 33.3): the bounded cron job that
		// pre-computes revenue_daily + upsell_stats from the raw logs through
		// the attribution engine's daily_metrics (same definitions as the
		// live reads, so the aggregated history never drifts).
		$this->container->singleton( DailyAggregator::class, function ( Container $container ) {
			return new DailyAggregator(
				$container->get( AttributionEngine::class ),
				$container->get( RevenueTracker::class )
			);
		} );

		// Smart goal recommendation engine (Phase 33.4): the deterministic
		// threshold recommender — store-order analysis (AOV, median,
		// distribution), shipping/margin analyzers, candidate generation +
		// weighted scoring, confidence and plain-English explanations. Pure
		// computation: caching and invalidation live in RevenueRepository.
		$this->container->singleton( GoalRecommendationEngine::class, function ( Container $container ) {
			return new GoalRecommendationEngine(
				$container->get( AttributionEngine::class ),
				$container->get( RewardCostEstimator::class ),
				$container->get( GoalRepository::class )
			);
		} );

		// Cached revenue summaries (Phase 33.3/33.4): overview / goal
		// performance / daily trend / product stats / goal recommendations
		// with generation-versioned transients and invalidation on order,
		// goal, product and aggregation changes.
		// Smart upsell ranking engine (Phase 33.5): the deterministic
		// product-ranking engine — candidate collection from the goal/cart
		// context (manual, historical, category, WC-endorsed, taxonomy
		// overlap, best sellers), six normalized component scorers
		// (price gap / relevance / popularity / inventory / margin /
		// conversion) with filterable weights, transparent score
		// breakdowns + reasons, and the server-side upsell_order
		// attribution on paid orders (historical learning). Pure
		// computation: caching lives in RevenueRepository.
		$this->container->singleton( UpsellRanker::class, function ( Container $container ) {
			return new UpsellRanker(
				$container->get( RevenueTracker::class ),
				$container->get( RewardCostEstimator::class ),
				$container->get( GoalRepository::class ),
				$container->get( Settings::class )
			);
		} );

		// Cached revenue summaries (Phase 33.3/33.4/33.5): overview / goal
		// performance / daily trend / product stats / goal recommendations
		// / upsell ranking + analytics with generation-versioned transients
		// and invalidation on order, goal, product and aggregation changes.
		$this->container->singleton( RevenueRepository::class, function ( Container $container ) {
			return new RevenueRepository(
				$container->get( AttributionEngine::class ),
				$container->get( GoalRepository::class ),
				$container->get( GoalRecommendationEngine::class ),
				$container->get( UpsellRanker::class )
			);
		} );

		// Cart integration (Phase 6): the single source of the live-cart
		// snapshot — memoized, lifecycle-aware, with batched category
		// preloading — consumed by the reward engine (and later REST/frontend).
		$this->container->singleton( CartIntegration::class, function ( Container $container ) {
			// Phase 18 (Goal Calculation): the snapshot service applies the
			// tax / discount / shipping / sale / virtual inclusion settings.
			return new CartIntegration( $container->get( Settings::class ) );
		} );

		// Reward engine (Phase 5): decoupled from goal calculation — consumes
		// GoalResult objects, applies rewards on the WooCommerce cart.
		$this->container->singleton( RewardEngine::class, function ( Container $container ) {
			return new RewardEngine(
				$container->get( GoalEngine::class ),
				$container->get( GoalRepository::class ),
				$container->get( Settings::class ),
				$container->get( CartIntegration::class )
			);
		} );

		// Campaign repository (Phase 7): read-only campaign access for the
		// REST layer; extended with CRUD by Phase 10 (Campaign Builder).
		$this->container->singleton( CampaignRepository::class, function () {
			return new CampaignRepository();
		} );

		// Template engine (pluggable progress templates): the registry of
		// built-in Goal/Campaign templates plus the resolution/validation
		// service every display layer (settings, builders, REST, preview)
		// shares.
		$this->container->singleton( TemplateRegistry::class, function () {
			return new TemplateRegistry();
		} );

		$this->container->singleton( TemplateEngine::class, function ( Container $container ) {
			return new TemplateEngine(
				$container->get( TemplateRegistry::class ),
				$container->get( Settings::class )
			);
		} );

		// REST controllers (Phase 7: REST API / AJAX Layer) — admin CRUD,
		// settings, search, campaigns and the public cart-progress endpoint.
		$this->container->singleton( GoalsController::class, function ( Container $container ) {
			return new GoalsController(
				$container->get( GoalRepository::class ),
				$container->get( TemplateEngine::class )
			);
		} );

		$this->container->singleton( SettingsController::class, function ( Container $container ) {
			return new SettingsController(
				$container->get( Settings::class ),
				$container->get( TemplateEngine::class )
			);
		} );

		$this->container->singleton( SearchController::class, function () {
			return new SearchController();
		} );

		$this->container->singleton( CampaignsController::class, function ( Container $container ) {
			return new CampaignsController(
				$container->get( CampaignRepository::class ),
				$container->get( TemplateEngine::class )
			);
		} );

		$this->container->singleton( FrontendController::class, function ( Container $container ) {
			// Phase 18 (Settings): goal behavior, the suggestions gate and
			// the optional progress cache all read the settings service.
			// Phase 26 (Conflict & Priority Engine): the reward engine is
			// injected so the payload resolves 'best' with real computed
			// amounts and mirrors stacking suppression — the display is
			// always what the live cart grants.
			return new FrontendController(
				$container->get( GoalEngine::class ),
				$container->get( GoalRepository::class ),
				$container->get( CartIntegration::class ),
				$container->get( MessageEngine::class ),
				$container->get( SuggestionEngine::class ),
				$container->get( Settings::class ),
				$container->get( RewardEngine::class ),
				$container->get( TemplateEngine::class )
			);
		} );

		// Admin preview system (Phase 15): evaluates a goal/campaign against
		// a SIMULATED cart through the real engine, reusing the frontend
		// controller's shared payload shape — never touches the live cart.
		$this->container->singleton( PreviewController::class, function ( Container $container ) {
			// Phase 26 (Conflict & Priority Engine): the preview resolves
			// conflicts across completed milestones with the store's
			// configured resolution mode — including computed 'best' scores
			// and stacking suppression via the reward engine — so admins
			// see the exact behavior before publishing.
			return new PreviewController(
				$container->get( GoalEngine::class ),
				$container->get( GoalRepository::class ),
				$container->get( CampaignRepository::class ),
				$container->get( FrontendController::class ),
				$container->get( Settings::class ),
				$container->get( RewardEngine::class ),
				$container->get( TemplateEngine::class )
			);
		} );

		// Public track endpoint (Phase 16): nonce-guarded, per-IP rate
		// limited — the storefront JS reports events through it.
		$this->container->singleton( TrackController::class, function ( Container $container ) {
			return new TrackController( $container->get( Tracker::class ) );
		} );

		// Public gift-selection endpoint (Phase 32): nonce-guarded, per-IP
		// rate limited — the storefront gift picker claims a chosen free
		// gift through the reward engine.
		$this->container->singleton( GiftController::class, function ( Container $container ) {
			return new GiftController(
				$container->get( RewardEngine::class ),
				$container->get( CartIntegration::class )
			);
		} );

		// Analytics dashboard endpoint (Phase 17): admin-only read of the
		// Phase 16 metrics repository — summary, daily trend and the top
		// campaigns / goals / suggested products lists, all filterable.
		$this->container->singleton( AnalyticsController::class, function ( Container $container ) {
			return new AnalyticsController( $container->get( AnalyticsRepository::class ) );
		} );

		// Template registry endpoint (pluggable engine): lists every
		// registered template + schema, grouped by scope, for the admin UI.
		$this->container->singleton( TemplatesController::class, function ( Container $container ) {
			return new TemplatesController( $container->get( TemplateEngine::class ) );
		} );

		// Goal recommendation endpoint (Phase 33.4): admin-only read of the
		// deterministic threshold recommendation, served through the cached
		// revenue repository (recommendations are cached and invalidated
		// like the other revenue summaries).
		$this->container->singleton( RecommendationsController::class, function ( Container $container ) {
			return new RecommendationsController( $container->get( RevenueRepository::class ) );
		} );

		// Upsell endpoints (Phase 33.5): the public nonce-guarded
		// upsell event tracking route (impression/clicked/added into the
		// Phase 33.1 upsell_events log — upsell_order is attributed
		// server-side on payment) plus the admin ranking + analytics reads
		// served through the cached revenue repository.
		$this->container->singleton( UpsellController::class, function ( Container $container ) {
			return new UpsellController( $container->get( RevenueRepository::class ) );
		} );

		// Revenue optimization admin reads (Phase 33.6): the overview /
		// attribution / goal-performance endpoints serving the React Admin
		// Revenue section, all through the cached revenue repository.
		$this->container->singleton( RevenueController::class, function ( Container $container ) {
			return new RevenueController( $container->get( RevenueRepository::class ) );
		} );

		$this->container->singleton( AssetLoader::class, function ( Container $container ) {
			return new AssetLoader( $container->get( Settings::class ) );
		} );

		$this->container->singleton( Admin::class, function ( Container $container ) {
			return new Admin( $container->get( Settings::class ), $container->get( AssetLoader::class ) );
		} );

		// Storefront progress UI (Phase 11): renders widget containers and
		// enqueues the vanilla frontend JS/CSS (assets/js, assets/css).
		$this->container->singleton( ProgressUI::class, function ( Container $container ) {
			return new ProgressUI( $container->get( Settings::class ) );
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
	 * Get the reward engine (Phase 5).
	 *
	 * @return RewardEngine
	 */
	public function reward_engine() {
		return $this->container->get( RewardEngine::class );
	}

	/**
	 * Get the cart integration service (Phase 6).
	 *
	 * @return CartIntegration
	 */
	public function cart_integration() {
		return $this->container->get( CartIntegration::class );
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
