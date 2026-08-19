<?php
/**
 * Plugin bootstrap class for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart;

use FaraCart\Admin\Admin;
use FaraCart\Admin\AssetLoader;
use FaraCart\Admin\ProductCostField;
use FaraCart\Analytics\AnalyticsRepository;
use FaraCart\Analytics\OrderCostSnapshot;
use FaraCart\Analytics\AttributionEngine;
use FaraCart\Analytics\DailyAggregator;
use FaraCart\Analytics\MissionRecommendationEngine;
use FaraCart\Analytics\RevenueRepository;
use FaraCart\Analytics\RevenueTracker;
use FaraCart\Analytics\RewardCostEstimator;
use FaraCart\Analytics\UpsellRanker;
use FaraCart\Analytics\Session;
use FaraCart\Analytics\Tracker;
use FaraCart\Campaigns\CampaignRepository;
use FaraCart\Cart\CartIntegration;
use FaraCart\Compatibility;
use FaraCart\Database\Installer;
use FaraCart\Frontend\ProgressUI;
use FaraCart\Missions\CompletionService;
use FaraCart\Missions\MissionEngine;
use FaraCart\Missions\MissionRepository;
use FaraCart\Missions\MessageEngine;
use FaraCart\Hooks\HookManager;
use FaraCart\REST\AnalyticsController;
use FaraCart\REST\CampaignsController;
use FaraCart\REST\FrontendController;
use FaraCart\REST\GiftController;
use FaraCart\REST\MissionsController;
use FaraCart\REST\PreviewController;
use FaraCart\REST\RecommendationsController;
use FaraCart\REST\RevenueController;
use FaraCart\REST\SearchController;
use FaraCart\REST\SettingsController;
use FaraCart\REST\TemplatesController;
use FaraCart\REST\TrackController;
use FaraCart\REST\UpsellController;
use FaraCart\Recommendations\ProductRecommendationEngine;
use FaraCart\Rewards\RewardEngine;
use FaraCart\Settings\Settings;
use FaraCart\Suggestions\SuggestionEngine;
use FaraCart\Templates\TemplateEngine;
use FaraCart\Templates\TemplateRegistry;

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
		register_activation_hook( FARACART_FILE, array( Installer::class, 'activate' ) );
		register_deactivation_hook( FARACART_FILE, array( Installer::class, 'deactivate' ) );

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

		// Declare WooCommerce feature compatibility (HPOS). FaraCart
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
		// records the attribution funnel (mission_view → progress → completed
		// → order_paid) and the upsell funnel into their dedicated logs,
		// with idempotent dedup and the weekly retention cleanup cron.
		$this->hooks()->register( $this->container->get( RevenueTracker::class ) );

		// Revenue attribution (Phase 33.2): associates paid orders with the
		// missions that influenced their session (direct/assisted models) and
		// exposes the revenue metrics — incremental cart value, mission-driven
		// / assisted revenue, AOV, reward cost and profit impact.
		$this->hooks()->register( $this->container->get( AttributionEngine::class ) );

		// Per-user mission completion limit (Phase 36): the order-lifecycle
		// hooks — stamping the anonymous session on checkout and recording
		// one completion cycle per met mission when the order is paid — keep
		// the server-side completion history in sync with every order
		// (idempotent via the order_mission unique key).
		$this->hooks()->register( $this->container->get( CompletionService::class ) );

		// Aggregation & performance (Phase 33.3): the daily aggregator
		// pre-computes revenue_daily + upsell_stats on a bounded cron job, and
		// the revenue repository serves the cached summaries (overview, mission
		// performance, daily trend, product stats, mission recommendations) with
		// generation-versioned invalidation wired to order/mission/product changes
		// and aggregation runs.
		$this->hooks()->register( $this->container->get( DailyAggregator::class ) );
		$this->hooks()->register( $this->container->get( RevenueRepository::class ) );

		// Smart mission recommendation (Phase 33.4): the deterministic
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
		// attribution / mission-performance endpoints serving the React Admin
		// Revenue section through the cached repository layer.
		$this->hooks()->register( $this->container->get( RevenueController::class ) );

		// Product cost (UPSELL_REFACTOR §18–§22): the WooCommerce product
		// editor gains FaraCart's own cost field (simple + variations), and
		// order creation snapshots each line's unit cost so historical
		// profit never changes when a product cost is edited later.
		$this->hooks()->register( $this->container->get( ProductCostField::class ) );
		$this->hooks()->register( $this->container->get( OrderCostSnapshot::class ) );

		// Storefront progress UI (Phase 11): shortcode, display-location
		// injection, floating widget and frontend assets.
		$this->hooks()->register( $this->container->get( ProgressUI::class ) );

		// REST controllers (Phase 7): each registers its routes on
		// rest_api_init through the same HookManager.
		$this->hooks()->register( $this->container->get( MissionsController::class ) );
		$this->hooks()->register( $this->container->get( SettingsController::class ) );
		$this->hooks()->register( $this->container->get( GiftController::class ) );
		$this->hooks()->register( $this->container->get( SearchController::class ) );
		$this->hooks()->register( $this->container->get( CampaignsController::class ) );
		$this->hooks()->register( $this->container->get( FrontendController::class ) );
		$this->hooks()->register( $this->container->get( PreviewController::class ) );
		$this->hooks()->register( $this->container->get( TrackController::class ) );
		$this->hooks()->register( $this->container->get( AnalyticsController::class ) );

		// Template registry (pluggable progress templates): the engine
		// resolves which template + settings render for each mission/campaign;
		// the REST endpoint lists the registered templates + schemas.
		$this->hooks()->register( $this->container->get( TemplatesController::class ) );

		// Apply everything to WordPress.
		$this->hooks()->run();

		/**
		 * Fires once the plugin has been fully bootstrapped.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'faracart_loaded', $this );
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

		// Mission engine (Phase 4): stateless calculation engine, resolved
		// lazily on first use by the frontend integration / REST layers.
		$this->container->singleton( MissionEngine::class, function () {
			return new MissionEngine();
		} );

		// Mission repository (Phase 5): loads active missions from the database so
		// the reward engine can decide rewards on the live cart.
		$this->container->singleton( MissionRepository::class, function () {
			return new MissionRepository();
		} );

		// Per-user mission completion limit (Phase 36): the authoritative
		// completion service — per-user counts over the mission_completions
		// history, the can_complete eligibility rule, and the
		// transactional order-time recording — consumed by the reward
		// engine (exhausted missions grant nothing), the storefront payload
		// (completion status per mission) and the order hooks (recording).
		$this->container->singleton( CompletionService::class, function ( Container $container ) {
			return new CompletionService(
				$container->get( Settings::class ),
				$container->get( MissionRepository::class ),
				$container->get( MissionEngine::class ),
				$container->get( Session::class )
			);
		} );

		// Message engine (Phase 13): stateless dynamic-message template
		// engine — state detection, variable substitution and localized
		// per-state copy, consumed by the frontend REST layer. The resolved
		// display currency rides along so server-rendered amounts are
		// labelled with the same unit the storefront widgets use.
		$this->container->singleton( MessageEngine::class, function ( Container $container ) {
			return new MessageEngine( $container->get( Settings::class )->currency() );
		} );

		// Suggestion engine (Phase 14): product recommendations that close
		// the mission gap — six sources, stock filter, relevance + price
		// proximity ranking — consumed by the frontend REST layer. Phase 32
		// (advanced upsell ranking) reads the suggestions_ranking mode.
		$this->container->singleton( SuggestionEngine::class, function ( Container $container ) {
			return new SuggestionEngine( $container->get( Settings::class ) );
		} );

		// Unified product recommendation engine: merges the Suggestion
		// engine and the Upsell ranker into ONE customer-facing ranked,
		// deduplicated list (both internal strategies preserved, scored on
		// the same 0–100 scale) — consumed by the storefront progress
		// payload through FrontendController.
		$this->container->singleton( ProductRecommendationEngine::class, function ( Container $container ) {
			return new ProductRecommendationEngine(
				$container->get( SuggestionEngine::class ),
				$container->get( UpsellRanker::class )
			);
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
		// payment, direct/assisted attribution into mission_attribution, and
		// the SQL-aggregated metric reads (funnel, incremental cart value,
		// mission-driven/assisted revenue, AOV, shipping stats).
		$this->container->singleton( AttributionEngine::class, function ( Container $container ) {
			return new AttributionEngine(
				$container->get( RevenueTracker::class ),
				$container->get( Session::class ),
				$container->get( Settings::class ),
				$container->get( RewardCostEstimator::class ),
				$container->get( MissionRepository::class )
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

		// Smart mission recommendation engine (Phase 33.4): the deterministic
		// threshold recommender — store-order analysis (AOV, median,
		// distribution), shipping/margin analyzers, candidate generation +
		// weighted scoring, confidence and plain-English explanations. Pure
		// computation: caching and invalidation live in RevenueRepository.
		$this->container->singleton( MissionRecommendationEngine::class, function ( Container $container ) {
			return new MissionRecommendationEngine(
				$container->get( AttributionEngine::class ),
				$container->get( RewardCostEstimator::class ),
				$container->get( MissionRepository::class )
			);
		} );

		// Cached revenue summaries (Phase 33.3/33.4): overview / mission
		// performance / daily trend / product stats / mission recommendations
		// with generation-versioned transients and invalidation on order,
		// mission, product and aggregation changes.
		// Smart upsell ranking engine (Phase 33.5): the deterministic
		// product-ranking engine — candidate collection from the mission/cart
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
				$container->get( MissionRepository::class ),
				$container->get( Settings::class )
			);
		} );

		// Cached revenue summaries (Phase 33.3/33.4/33.5): overview / mission
		// performance / daily trend / product stats / mission recommendations
		// / upsell ranking + analytics with generation-versioned transients
		// and invalidation on order, mission, product and aggregation changes.
		$this->container->singleton( RevenueRepository::class, function ( Container $container ) {
			return new RevenueRepository(
				$container->get( AttributionEngine::class ),
				$container->get( MissionRepository::class ),
				$container->get( MissionRecommendationEngine::class ),
				$container->get( UpsellRanker::class )
			);
		} );

		// Cart integration (Phase 6): the single source of the live-cart
		// snapshot — memoized, lifecycle-aware, with batched category
		// preloading — consumed by the reward engine (and later REST/frontend).
		$this->container->singleton( CartIntegration::class, function ( Container $container ) {
			// Phase 18 (Mission Calculation): the snapshot service applies the
			// tax / discount / shipping / sale / virtual inclusion settings.
			return new CartIntegration( $container->get( Settings::class ) );
		} );

		// Reward engine (Phase 5): decoupled from mission calculation — consumes
		// MissionResult objects, applies rewards on the WooCommerce cart.
		$this->container->singleton( RewardEngine::class, function ( Container $container ) {
			return new RewardEngine(
				$container->get( MissionEngine::class ),
				$container->get( MissionRepository::class ),
				$container->get( Settings::class ),
				$container->get( CartIntegration::class ),
				null,
				$container->get( CompletionService::class )
			);
		} );

		// Campaign repository (Phase 7): read-only campaign access for the
		// REST layer; extended with CRUD by Phase 10 (Campaign Builder).
		$this->container->singleton( CampaignRepository::class, function () {
			return new CampaignRepository();
		} );

		// Template engine (pluggable progress templates): the registry of
		// built-in Mission/Campaign templates plus the resolution/validation
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
		$this->container->singleton( MissionsController::class, function ( Container $container ) {
			return new MissionsController(
				$container->get( MissionRepository::class ),
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
			// Phase 18 (Settings): mission behavior, the suggestions gate and
			// the optional progress cache all read the settings service.
			// Phase 26 (Conflict & Priority Engine): the reward engine is
			// injected so the payload resolves 'best' with real computed
			// amounts and mirrors stacking suppression — the display is
			// always what the live cart grants.
			return new FrontendController(
				$container->get( MissionEngine::class ),
				$container->get( MissionRepository::class ),
				$container->get( CartIntegration::class ),
				$container->get( MessageEngine::class ),
				$container->get( ProductRecommendationEngine::class ),
				$container->get( Settings::class ),
				$container->get( RewardEngine::class ),
				$container->get( TemplateEngine::class ),
				$container->get( CompletionService::class )
			);
		} );

		// Admin preview system (Phase 15): evaluates a mission/campaign against
		// a SIMULATED cart through the real engine, reusing the frontend
		// controller's shared payload shape — never touches the live cart.
		$this->container->singleton( PreviewController::class, function ( Container $container ) {
			// Phase 26 (Conflict & Priority Engine): the preview resolves
			// conflicts across completed milestones with the store's
			// configured resolution mode — including computed 'best' scores
			// and stacking suppression via the reward engine — so admins
			// see the exact behavior before publishing.
			return new PreviewController(
				$container->get( MissionEngine::class ),
				$container->get( MissionRepository::class ),
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
		// campaigns / missions / suggested products lists, all filterable.
		$this->container->singleton( AnalyticsController::class, function ( Container $container ) {
			// RevenueRepository powers the Phase 2 purchase/profit fields of
			// the /analytics summary (same cached attribution layer as the
			// revenue endpoints).
			return new AnalyticsController(
				$container->get( AnalyticsRepository::class ),
				$container->get( RevenueRepository::class ),
				$container->get( DailyAggregator::class )
			);
		} );

		// Template registry endpoint (pluggable engine): lists every
		// registered template + schema, grouped by scope, for the admin UI.
		$this->container->singleton( TemplatesController::class, function ( Container $container ) {
			return new TemplatesController( $container->get( TemplateEngine::class ) );
		} );

		// Mission recommendation endpoints (Phase 33.4 + UPSELL_REFACTOR §41):
		// the read-only deterministic threshold recommendation served
		// through the cached revenue repository, plus the apply write path
		// (explicit admin confirmation → mission target update + the
		// recommendation_applied feedback-loop event + cache
		// invalidation).
		$this->container->singleton( RecommendationsController::class, function ( Container $container ) {
			return new RecommendationsController(
				$container->get( RevenueRepository::class ),
				$container->get( MissionRepository::class ),
				$container->get( RevenueTracker::class )
			);
		} );

		// Upsell endpoints (Phase 33.5/33.7): the public nonce-guarded
		// upsell event tracking route (impression/clicked/added into the
		// Phase 33.1 upsell_events log — upsell_order is attributed
		// server-side on payment), the Phase 33.7 public storefront rank
		// route (live-cart mission gap + deterministic ranking — the
		// injected ranker/cart/engine/missions serve it directly, no
		// per-cart transient churn) plus the admin ranking + analytics
		// reads served through the cached revenue repository.
		$this->container->singleton( UpsellController::class, function ( Container $container ) {
			return new UpsellController(
				$container->get( RevenueRepository::class ),
				$container->get( UpsellRanker::class ),
				$container->get( CartIntegration::class ),
				$container->get( MissionEngine::class ),
				$container->get( MissionRepository::class )
			);
		} );

		// Revenue optimization admin reads (Phase 33.6 + UPSELL_REFACTOR
		// §25): the overview / attribution / mission-performance endpoints
		// serving the React Admin Revenue section through the cached
		// revenue repository, plus the product-cost coverage read.
		$this->container->singleton( RevenueController::class, function ( Container $container ) {
			return new RevenueController(
				$container->get( RevenueRepository::class ),
				$container->get( RewardCostEstimator::class )
			);
		} );

		$this->container->singleton( AssetLoader::class, function ( Container $container ) {
			return new AssetLoader( $container->get( Settings::class ) );
		} );

		$this->container->singleton( Admin::class, function ( Container $container ) {
			return new Admin( $container->get( Settings::class ), $container->get( AssetLoader::class ) );
		} );

		// WooCommerce product-cost field (UPSELL_REFACTOR §19/§20): adds
		// the `_faracart_product_cost` input to simple products and
		// variations, saved with the product-edit screen's own capability
		// guard.
		$this->container->singleton( ProductCostField::class, function () {
			return new ProductCostField();
		} );

		// Order cost snapshot (UPSELL_REFACTOR §21/§22): stamps each line
		// item with the unit cost used at order creation so historical
		// profit is stable when product costs change.
		$this->container->singleton( OrderCostSnapshot::class, function ( Container $container ) {
			return new OrderCostSnapshot( $container->get( RewardCostEstimator::class ) );
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
	 * Get the mission engine (Phase 4).
	 *
	 * @return MissionEngine
	 */
	public function mission_engine() {
		return $this->container->get( MissionEngine::class );
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
	 * feature. FaraCart reads orders through wc_get_order() and runs its
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
			FARACART_FILE,
			true
		);
	}

	/**
	 * Load the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'faracart', false, dirname( FARACART_BASENAME ) . '/languages' );
	}
}
