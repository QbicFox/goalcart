<?php
/**
 * Smart upsell ranking engine for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Analytics;

use FaraCart\Database\Schema;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionRepository;
use FaraCart\Hooks\HookManager;
use FaraCart\Settings\Settings;
use FaraCart\Utils\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Class UpsellRanker
 *
 * Smart Upsell — the deterministic ranking engine that
 * answers "which products should be recommended to help this customer
 * reach the mission?" with a fully transparent, weighted composite score.
 * No LLM/AI: every component is computed from the store's product data,
 * the live cart/mission context and the historical upsell funnel
 * (P33-25 → P33-36).
 *
 * Pipeline (P33.5):
 *
 *  1. Candidate collection (P33-26) — from the mission's own products, the
 *     mission's categories, the cart items' WooCommerce-endorsed sources
 *     (upsells / cross-sells / related), products sharing a category or
 *     tag with the cart, best sellers, and products historically
 *     recommended for the mission. Out-of-stock / private / draft /
 *     already-in-cart / mission-excluded products never reach scoring.
 *  2. Six normalized (0–100) component scores with filterable weights
 *     (`faracart_upsell_weights`, defaults per P33-33):
 *
 *       price_gap  25% — how well the price fits the remaining gap
 *                         (tolerates small overshoots, P33-27/36)
 *       relevance  25% — category/tag/WC-source/mission-eligibility overlap
 *                         with the cart (P33-28)
 *       popularity 15% — units sold + rating, bounded (P33-30)
 *       inventory  10% — healthy stock preferred (P33-29)
 *       margin     15% — higher margin → higher score, only when the
 *                         store provides cost data (P33-31)
 *       conversion 10% — the product's historical upsell performance,
 *                         impressions-weighted (P33-32)
 *
 *  3. Ranking (P33-34) — every product exposes its composite `score`,
 *     the `components` breakdown, the raw `factors`, the historical
 *     `conversion` stats and plain-English `reasons` — so the admin UI
 *     and the storefront can always show *why* a product was chosen.
 *
 * Historical learning (P33-35): upsell impressions/clicks/adds are
 * reported by the storefront through the public
 * `POST /faracart/v1/upsell/track` endpoint (UpsellController) into the
 * upsell_events log; `upsell_order` events are attributed
 * server-side when a paid order completes (a product that was shown /
 * clicked / added in the ordering session gets the order's upsell_order
 * event — the "purchased after recommendation" signal). The * DailyAggregator rebuilds upsell_stats from that log, and the
 * conversion scorer reads the aggregates — deterministic historical
 * scoring, no black-box model (P33-35).
 *
 * Graceful degradation (P33-51): no margin data → margin neutral 50 and
 * profit excluded; no historical data → conversion neutral 50; no
 * remaining gap (mission reached / not a money mission) → price gap neutral 50;
 * no candidates → unavailable with a reason — never a fabricated list.
 *
 * Extensibility (P33-60): the public rank() contract is the frontend
 * contract — a future MLUpsellRanker can replace this class behind the
 * same payload shape without touching the REST layer or admin UI.
 */
final class UpsellRanker {

	/**
	 * Composite scoring weights (P33-33 defaults). Filterable per call
	 * through faracart_upsell_weights.
	 *
	 * @var array<string, float>
	 */
	const SCORE_WEIGHTS = array(
		'price_gap'  => 0.25,
		'relevance'  => 0.25,
		'popularity' => 0.15,
		'inventory'  => 0.10,
		'margin'     => 0.15,
		'conversion' => 0.10,
	);

	/**
	 * Default maximum ranked products returned.
	 *
	 * @var int
	 */
	const MAX_RESULTS = 4;

	/**
	 * Hard cap on candidates collected before scoring (bounds queries).
	 *
	 * @var int
	 */
	const MAX_CANDIDATES = 60;

	/**
	 * Price-gap sweet band around the remaining amount (ratio). Products
	 * priced within [0.75×, 1.30×] the gap are near-perfect fits; small
	 * overshoots are tolerated (P33-27 — no exact match required).
	 *
	 * @var float
	 */
	const GAP_SWEET_MIN = 0.75;

	/**
	 * @var float
	 */
	const GAP_SWEET_MAX = 1.30;

	/**
	 * Gap ratio above which the price-gap score reaches zero.
	 *
	 * @var float
	 */
	const GAP_HARD_MAX = 3.0;

	/**
	 * Source keys (primary source is the first collected, deterministic).
	 */
	const SOURCE_MANUAL       = 'manual';
	const SOURCE_HISTORICAL   = 'historical';
	const SOURCE_CATEGORY     = 'category';
	const SOURCE_UPSELL       = 'upsell';
	const SOURCE_CROSS_SELL   = 'cross_sell';
	const SOURCE_RELATED      = 'related';
	const SOURCE_CATEGORY_MATCH = 'category_match';
	const SOURCE_TAG_MATCH    = 'tag_match';
	const SOURCE_POPULAR      = 'popular';

	/**
	 * Attribution window for the order → upsell_order association (seconds).
	 *
	 * @var int
	 */
	const ATTRIBUTION_WINDOW = 30 * DAY_IN_SECONDS;

	/**
	 * Revenue event tracker (upsell_order attribution on payment).
	 *
	 * @var RevenueTracker
	 */
	protected $tracker;

	/**
	 * Reward cost / margin estimator (margin scorer + profit).
	 *
	 * @var RewardCostEstimator
	 */
	protected $costs;

	/**
	 * Mission repository (mission lookup for eligibility/reward context).
	 *
	 * @var MissionRepository
	 */
	protected $repository;

	/**
	 * Plugin settings (enabled gates).
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Per-request product cache (id => WC_Product).
	 *
	 * @var array<int, \WC_Product>
	 */
	protected $loaded = array();

	/**
	 * Per-request upsell_stats cache (product_id => row).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $stats_cache = array();

	/**
	 * Per-request order cache (id => WC_Order|null).
	 *
	 * @var array<int, \WC_Order|null>
	 */
	protected $order_cache = array();

	/**
	 * Constructor.
	 *
	 * @param RevenueTracker      $tracker    Revenue event tracker.
	 * @param RewardCostEstimator $costs      Reward cost / margin estimator.
	 * @param MissionRepository      $repository Mission repository.
	 * @param Settings|null       $settings   Settings instance.
	 */
	public function __construct( RevenueTracker $tracker, RewardCostEstimator $costs, MissionRepository $repository, ?Settings $settings = null ) {
		$this->tracker    = $tracker;
		$this->costs      = $costs;
		$this->repository = $repository;
		$this->settings   = $settings;
	}

	/**
	 * Register hooks: server-side upsell_order attribution on paid orders.
	 *
	 * The upsell funnel is recorded per session by the storefront; when an
	 * order becomes revenue-producing, every product that was recommended
	 * (impression / clicked / added) in the ordering session receives an
	 * upsell_order event — the "purchased after recommendation" signal the
	 * historical conversion score reads. Runs at a later priority than the
	 * AttributionEngine so the order_paid event (its session anchor) is
	 * already recorded; both paths are idempotent.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( 'woocommerce_payment_complete', array( $this, 'handle_order_paid' ), 20, 1 );
		$hooks->add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_paid' ), 20, 1 );
	}

	/**
	 * Whether smart upsell ranking is enabled.
	 *
	 * @return bool
	 */
	public function enabled() {
		if ( null !== $this->settings && ( ! $this->settings->get( 'enabled', true ) || ! $this->settings->get( 'analytics_enabled', true ) ) ) {
			return false;
		}

		/**
		 * Filter whether smart upsell ranking is on.
		 *
		 * @param bool         $enabled Whether smart upsells are enabled.
		 * @param UpsellRanker $ranker  Ranker instance.
		 */
		return (bool) apply_filters( 'faracart_upsells_enabled', true, $this );
	}

	/**
	 * Rank products that help close the remaining mission gap.
	 *
	 * Deterministic: the same cart + mission + catalog always yields the same
	 * ranking. Never writes anything — the historical events are recorded
	 * by the tracker endpoint and the order hooks, never here.
	 *
	 * @param array<string, mixed> $args Optional: mission_id, mission (an already
	 *                                   loaded Mission instance), cart_value,
	 *                                   remaining, cart (product ids),
	 *                                   limit, exclude.
	 * @return array<string, mixed> The ranking payload.
	 */
	public function rank( array $args = array() ) {
		$unavailable = function ( $reason ) use ( $args ) {
			return array(
				'available'   => false,
				'status'      => 'unavailable',
				'reason'      => $reason,
				'context'     => $this->context( $args ),
				'candidates'  => 0,
				'weights'     => $this->score_weights(),
				'recommendations' => array(),
				'generated_at' => current_time( 'mysql' ),
			);
		};

		if ( ! $this->enabled() ) {
			return $unavailable( __( 'Smart upsells are disabled.', 'faracart' ) );
		}

		// Prefer an already-loaded mission (the unified engine hands its
		// in-memory Mission over so unpersisted/fresh missions rank correctly);
		// fall back to resolving from the repository by id for the REST
		// endpoint and other id-only callers.
		$mission = ( isset( $args['mission'] ) && $args['mission'] instanceof Mission )
			? $args['mission']
			: $this->resolve_mission( $args );
		$remaining = $this->remaining( $args, $mission );

		// No measurable money gap → nothing to close with priced products.
		if ( null === $remaining || $remaining <= 0 ) {
			return $unavailable(
				null === $remaining
					? __( 'A mission target or an explicit remaining amount is required to rank upsells.', 'faracart' )
					: __( 'The mission gap is already closed — no upsells needed.', 'faracart' )
			);
		}

		$cart    = $this->cart_ids( $args );
		$exclude = $this->exclude_ids( $args, $mission );

		// 1. Candidate products (bounded, deduped, source-annotated).
		$candidates = $this->candidates( $mission, $cart );

		/**
		 * Filters the candidate product ids before scoring.
		 *
		 * @param int[]              $candidates Candidate product ids.
		 * @param array<string, mixed> $args       Original request args.
		 * @param UpsellRanker       $ranker     Ranker instance.
		 */
		$candidates = (array) apply_filters( 'faracart_upsell_candidates', $candidates, $args, $this );

		if ( empty( $candidates ) ) {
			return $unavailable( __( 'No candidate products could be collected for this cart and mission.', 'faracart' ) );
		}

		// 2. Score every candidate.
		$scored = array();

		foreach ( $this->load_products( array_keys( array_slice( $candidates, 0, self::MAX_CANDIDATES, true ) ) ) as $id => $product ) {
			if ( isset( $exclude[ $id ] ) || in_array( $id, $cart, true ) ) {
				continue;
			}

			if ( ! $this->is_rankable( $product ) ) {
				continue;
			}

			$scored[] = $this->score_product( $product, $candidates[ $id ], $remaining, $cart, $mission );
		}

		// 3. Rank: score desc; ties → lower price first, then id (deterministic).
		usort( $scored, function ( $a, $b ) {
			if ( abs( $a['score'] - $b['score'] ) > 0.0001 ) {
				return $a['score'] > $b['score'] ? -1 : 1;
			}

			$a_price = isset( $a['factors']['price'] ) ? (float) $a['factors']['price'] : 0.0;
			$b_price = isset( $b['factors']['price'] ) ? (float) $b['factors']['price'] : 0.0;

			if ( abs( $a_price - $b_price ) > 0.0001 ) {
				return $a_price < $b_price ? -1 : 1;
			}

			return (int) $a['product_id'] <=> (int) $b['product_id'];
		} );

		$limit = max( 1, min( 10, isset( $args['limit'] ) ? (int) $args['limit'] : self::MAX_RESULTS ) );

		$payload = array(
			'available'   => true,
			'status'      => 'available',
			'reason'      => null,
			'context'     => $this->context( $args, $remaining, $mission ),
			'candidates'  => count( $candidates ),
			'weights'     => $this->score_weights(),
			'recommendations' => array_slice( $scored, 0, $limit ),
			'generated_at' => current_time( 'mysql' ),
		);

		/**
		 * Filters the full upsell ranking payload.
		 *
		 * @param array<string, mixed> $payload Ranking payload.
		 * @param array<string, mixed> $args    Original request args.
		 * @param UpsellRanker         $ranker  Ranker instance.
		 */
		return (array) apply_filters( 'faracart_upsells', $payload, $args, $this );
	}

	/**
	 * Score a single product (used by rank() and the per-product endpoint).
	 *
	 * Public so the admin analytics can reuse the exact same component
	 * math for a product without a cart context (price gap / relevance
	 * become neutral 50 when no gap or cart is available).
	 *
	 * @param \WC_Product             $product   Product.
	 * @param string                  $source    Primary source key.
	 * @param float|null              $remaining Remaining gap (null = neutral price gap).
	 * @param int[]                   $cart      Product ids in the cart.
	 * @param Mission|null               $mission      Mission (optional).
	 * @return array<string, mixed>
	 */
	public function score_product( \WC_Product $product, $source = self::SOURCE_POPULAR, $remaining = null, array $cart = array(), $mission = null ) {
		$id    = (int) $product->get_id();
		$stats = $this->product_stats( $id );
		$margin = $this->costs->product_margin( $id );

		$price = '' !== $product->get_price() ? (float) $product->get_price() : null;

		$price_gap = $this->price_gap_score( $price, $remaining );
		$relevance = $this->relevance_score( $product, $source, $cart, $mission );
		$popularity = $this->popularity_score( $product, $stats );
		$inventory = $this->inventory_score( $product );
		$margin_score = $this->margin_score( $margin );
		$conversion = $this->conversion_score( $stats );

		$weights = $this->score_weights();

		// Clamp to 0–100: a partial faracart_upsell_weights filter
		// normalizes only its provided keys (defaults keep their values),
		// so the weight sum can exceed 1 — the exposed score must stay a
		// bounded percentage regardless of the filter.
		$score = min( 100.0, ( $price_gap * $weights['price_gap'] )
			+ ( $relevance * $weights['relevance'] )
			+ ( $popularity * $weights['popularity'] )
			+ ( $inventory * $weights['inventory'] )
			+ ( $margin_score * $weights['margin'] )
			+ ( $conversion * $weights['conversion'] ) );

		$components = array(
			'price_gap'  => round( $price_gap, 2 ),
			'relevance'  => round( $relevance, 2 ),
			'popularity' => round( $popularity, 2 ),
			'inventory'  => round( $inventory, 2 ),
			'margin'     => round( $margin_score, 2 ),
			'conversion' => round( $conversion, 2 ),
		);

		// Per-unit estimated profit only when the store provides cost data
		// (never invented — the margin scorer degrades gracefully).
		$profit_available = null !== $margin;
		$estimated_profit = $profit_available ? (float) $margin['margin'] : null;

		return array(
			'product_id'        => $id,
			'name'              => $product->get_name(),
			'permalink'         => $product->get_permalink(),
			'price'             => null !== $price ? round( $price, 4 ) : null,
			'price_html'        => $this->price_html( $product ),
			'image'             => $this->image_url( $product ),
			'stock_status'      => $product->get_stock_status(),
			'source'            => (string) $source,
			'score'             => round( $score, 2 ),
			'components'        => $components,
			'conversion'        => $this->conversion_payload( $stats ),
			'estimated_profit'  => null !== $estimated_profit ? round( $estimated_profit, 4 ) : null,
			'profit_available'  => $profit_available,
			'reasons'           => $this->reasons(
				$product,
				$source,
				$remaining,
				$price,
				$price_gap,
				$relevance,
				$popularity,
				$inventory,
				$margin,
				$stats,
				$cart
			),
			'factors'           => array(
				'price'           => null !== $price ? round( $price, 4 ) : null,
				'gap_ratio'       => null !== $price && null !== $remaining && $remaining > 0
					? round( $price / $remaining, 4 )
					: null,
				'gap'             => null !== $remaining ? round( (float) $remaining, 4 ) : null,
				'sales'           => (int) $product->get_total_sales(),
				'rating'          => method_exists( $product, 'get_average_rating' )
					? round( (float) $product->get_average_rating(), 2 )
					: 0.0,
				'margin_pct'      => null !== $margin ? (float) $margin['margin_pct'] : null,
				'stock_quantity'  => $this->stock_quantity( $product ),
				'components'      => $components,
			),
		);
	}

	/**
	 * WooCommerce hook handler: attribute upsell_order events on payment.
	 *
	 * @param int $order_id Order id.
	 * @return int Number of upsell_order events recorded.
	 */
	public function handle_order_paid( $order_id ) {
		if ( ! $this->enabled() || ! $this->tracker->tracking_enabled() ) {
			return 0;
		}

		return $this->attribute_order( (int) $order_id );
	}

	/**
	 * One product's upsell score breakdown + historical stats.
	 *
	 * The per-product endpoint's data source: the product is scored through
	 * the same component math as rank() (in the given cart/mission context, or
	 * standalone when no context args are passed) and the historical
	 * upsell_stats row is attached. Null when the product does not exist or
	 * cannot be ranked (private / draft / out of stock).
	 *
	 * @param int                  $product_id Product id.
	 * @param array<string, mixed> $args       Optional: mission_id, cart_value,
	 *                                         remaining, cart.
	 * @return array<string, mixed>|null
	 */
	public function product_detail( $product_id, array $args = array() ) {
		$product_id = (int) $product_id;
		$product    = $this->get( $product_id );

		if ( ! $product || ! $this->is_rankable( $product ) ) {
			return null;
		}

		$mission      = $this->resolve_mission( $args );
		$remaining = $this->remaining( $args, $mission );
		$cart      = $this->cart_ids( $args );

		return $this->score_product( $product, self::SOURCE_POPULAR, $remaining, $cart, $mission );
	}

	/**
	 * Attribute upsell_order events for an order.
	 *
	 * Resolves the ordering session (the order_paid event's session — the
	 * AttributionEngine records it at priority 10 — then the live cookie,
	 * then the logged-in user's recent revenue session), finds the
	 * products recommended in that session (impression / clicked / added
	 * within the attribution window before the order) and records one
	 * upsell_order event per product with the order's total as cart value.
	 * Exactly-once per order via the tracker's order dedup.
	 *
	 * @param int                   $order_id Order id.
	 * @param \WC_Order|array|null  $order    Order object or data array.
	 * @return int Number of upsell_order events recorded.
	 */
	public function attribute_order( $order_id, $order = null ) {
		$order_id = (int) $order_id;

		if ( $order_id < 1 ) {
			return 0;
		}

		if ( null === $order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}

		$total = 0.0;
		$date  = current_time( 'mysql' );

		if ( is_object( $order ) && method_exists( $order, 'get_total' ) ) {
			$total = (float) $order->get_total();
			$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$date = $created ? $created->date( 'Y-m-d H:i:s' ) : $date;
		} elseif ( is_array( $order ) ) {
			$total = isset( $order['total'] ) ? (float) $order['total'] : 0.0;
			$date  = isset( $order['date'] ) ? (string) $order['date'] : $date;
		}

		$session_id = $this->resolve_session( $order_id );

		if ( ! Session::is_valid( $session_id ) ) {
			return 0;
		}

		global $wpdb;

		$events = Schema::table( 'upsell_events' );
		$cutoff = date( 'Y-m-d H:i:s', strtotime( $date ) - self::ATTRIBUTION_WINDOW );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, MAX(mission_id) AS mission_id FROM {$events}
				 WHERE session_id = %s AND event_type IN (%s, %s, %s)
				   AND product_id IS NOT NULL AND created_at >= %s AND created_at <= %s
				 GROUP BY product_id",
				$session_id,
				RevenueTracker::EVENT_UPSELL_IMPRESSION,
				RevenueTracker::EVENT_UPSELL_CLICKED,
				RevenueTracker::EVENT_UPSELL_ADDED,
				$cutoff,
				$date
			),
			ARRAY_A
		);

		$written = 0;

		foreach ( (array) $rows as $row ) {
			$product_id = (int) $row['product_id'];

			if ( $product_id < 1 ) {
				continue;
			}

			// Carry the mission the recommendation funnel belonged to, so
			// mission-scoped upsell analytics count the order too.
			$mission_id = (int) $row['mission_id'];

			$written += $this->tracker->record_upsell(
				RevenueTracker::EVENT_UPSELL_ORDER,
				array(
					'order_id'   => $order_id,
					'product_id' => $product_id,
					'mission_id'    => $mission_id > 0 ? $mission_id : 0,
					'session_id' => $session_id,
					'cart_value' => $total,
				)
			);
		}

		return $written;
	}

	/**
	 * The ranking context echo (transparency for the UI).
	 *
	 * @param array<string, mixed> $args      Original args.
	 * @param float|null           $remaining Resolved remaining gap.
	 * @param Mission|null            $mission      Resolved mission.
	 * @return array<string, mixed>
	 */
	protected function context( array $args, $remaining = null, $mission = null ) {
		return array(
			'mission_id'    => isset( $args['mission_id'] ) ? (int) $args['mission_id'] : 0,
			'cart_value' => isset( $args['cart_value'] ) ? round( (float) $args['cart_value'], 4 ) : 0.0,
			'remaining'  => null !== $remaining ? round( (float) $remaining, 4 ) : null,
			'cart'       => $this->cart_ids( $args ),
			'limit'      => isset( $args['limit'] ) ? max( 1, min( 10, (int) $args['limit'] ) ) : self::MAX_RESULTS,
			'mission_name'  => null !== $mission ? $mission->name() : '',
		);
	}

	/**
	 * Resolve the mission for a request.
	 *
	 * @param array<string, mixed> $args Request args.
	 * @return Mission|null
	 */
	protected function resolve_mission( array $args ) {
		if ( empty( $args['mission_id'] ) ) {
			return null;
		}

		return $this->repository->find( (int) $args['mission_id'] );
	}

	/**
	 * The remaining money gap to close.
	 *
	 * Priority: explicit `remaining` arg → money mission target − cart value
	 * → null (no gap computable). Non-money missions (quantity / weight /
	 * distinct-quantity) have no money gap, so the price-gap component
	 * degrades to neutral.
	 *
	 * @param array<string, mixed> $args Request args.
	 * @param Mission|null            $mission Resolved mission.
	 * @return float|null
	 */
	protected function remaining( array $args, $mission ) {
		if ( isset( $args['remaining'] ) && is_numeric( $args['remaining'] ) ) {
			return max( 0.0, (float) $args['remaining'] );
		}

		if ( null !== $mission && $mission->is_money_mission() ) {
			$cart_value = isset( $args['cart_value'] ) ? (float) $args['cart_value'] : 0.0;

			return max( 0.0, $mission->target() - $cart_value );
		}

		return null;
	}

	/**
	 * Product ids currently in the cart (never recommended again).
	 *
	 * @param array<string, mixed> $args Request args.
	 * @return int[]
	 */
	protected function cart_ids( array $args ) {
		$cart = isset( $args['cart'] ) && is_array( $args['cart'] ) ? $args['cart'] : array();

		return array_values( array_filter( array_map( 'intval', $cart ), function ( $id ) {
			return $id > 0;
		} ) );
	}

	/**
	 * Product ids to exclude (explicit + mission-excluded).
	 *
	 * @param array<string, mixed> $args Request args.
	 * @param Mission|null            $mission Resolved mission.
	 * @return array<int, true>
	 */
	protected function exclude_ids( array $args, $mission ) {
		$exclude = array();

		foreach ( (array) ( isset( $args['exclude'] ) && is_array( $args['exclude'] ) ? $args['exclude'] : array() ) as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$exclude[ $id ] = true;
			}
		}

		if ( null !== $mission ) {
			foreach ( $mission->excluded_products() as $id ) {
				$exclude[ (int) $id ] = true;
			}
		}

		return $exclude;
	}

	/**
	 * Collect candidate product ids from every source (P33-26), deduped.
	 *
	 * Each id keeps its first (highest-priority) source for the badge and
	 * the relevance signal. Sources, in priority order:
	 *
	 *  1. manual          — the mission's own products (they count toward it)
	 *  2. historical      — products previously recommended for this mission
	 *                       (upsell_events funnel for the mission)
	 *  3. category        — products inside the mission's categories
	 *  4. upsell          — the cart items' _upsell_ids
	 *  5. cross_sell      — the cart items' _crosssell_ids
	 *  6. related         — wc_get_related_products() of the cart items
	 *  7. category_match  — products sharing a category with the cart
	 *  8. tag_match       — products sharing a tag with the cart
	 *  9. popular         — best sellers (low-priority filler)
	 *
	 * @param Mission|null $mission Mission (optional).
	 * @param int[]     $cart Product ids in the cart.
	 * @return array<int, string> Product id => source key.
	 */
	protected function candidates( $mission, array $cart ) {
		$candidates = array();

		$add = function ( array $ids, $source ) use ( &$candidates ) {
			foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
				if ( $id > 0 && ! isset( $candidates[ $id ] ) ) {
					$candidates[ $id ] = $source;
				}
			}
		};

		// 1. Manual: the mission's own products count toward it.
		if ( null !== $mission ) {
			$add( $mission->products(), self::SOURCE_MANUAL );
		}

		// 2. Historical: products the store already recommended for this
		// mission (its upsell funnel — the P33-35 learning signal).
		if ( null !== $mission && $mission->id() > 0 ) {
			$add( $this->historical_ids( $mission->id() ), self::SOURCE_HISTORICAL );
		}

		// 3. Category: products inside the mission's categories.
		if ( null !== $mission && ! empty( $mission->categories() ) ) {
			$add( $this->taxonomy_product_ids( 'product_cat', $mission->categories() ), self::SOURCE_CATEGORY );
		}

		// 4–6. Cart items' WooCommerce-endorsed sources.
		foreach ( $cart as $cart_id ) {
			$product = $this->get( $cart_id );

			if ( ! $product ) {
				continue;
			}

			$add( $product->get_upsell_ids(), self::SOURCE_UPSELL );
			$add( $product->get_cross_sell_ids(), self::SOURCE_CROSS_SELL );

			if ( function_exists( 'wc_get_related_products' ) ) {
				$add( wc_get_related_products( $product->get_id(), 5 ), self::SOURCE_RELATED );
			}
		}

		// 7–8. Category / tag overlap with the cart (context-aware).
		if ( ! empty( $cart ) ) {
			$category_ids = $this->cart_taxonomy_ids( $cart, 'product_cat' );
			$tag_ids      = $this->cart_taxonomy_ids( $cart, 'product_tag' );

			if ( ! empty( $category_ids ) ) {
				$add( $this->taxonomy_product_ids( 'product_cat', $category_ids ), self::SOURCE_CATEGORY_MATCH );
			}

			if ( ! empty( $tag_ids ) ) {
				$add( $this->taxonomy_product_ids( 'product_tag', $tag_ids ), self::SOURCE_TAG_MATCH );
			}
		}

		// 9. Best sellers — low-scoring fallback filler.
		$add( $this->best_seller_ids(), self::SOURCE_POPULAR );

		return array_slice( $candidates, 0, self::MAX_CANDIDATES, true );
	}

	/**
	 * Products historically recommended for a mission (its upsell funnel).
	 *
	 * Reads the raw upsell_events log for the mission — impressions, clicks
	 * and adds are all "shown before" signals — newest first, bounded.
	 *
	 * @param int $mission_id Mission id.
	 * @return int[]
	 */
	protected function historical_ids( $mission_id ) {
		global $wpdb;

		$events = Schema::table( 'upsell_events' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id FROM {$events}
				 WHERE mission_id = %d AND event_type IN (%s, %s, %s) AND product_id IS NOT NULL
				 ORDER BY id DESC
				 LIMIT 20",
				(int) $mission_id,
				RevenueTracker::EVENT_UPSELL_IMPRESSION,
				RevenueTracker::EVENT_UPSELL_CLICKED,
				RevenueTracker::EVENT_UPSELL_ADDED
			),
			ARRAY_A
		);

		return array_map( 'intval', wp_list_pluck( (array) $rows, 'product_id' ) );
	}

	/**
	 * Taxonomy term ids (categories / tags) present across the cart items.
	 *
	 * @param int[]  $cart      Product ids in the cart.
	 * @param string $taxonomy  Taxonomy name.
	 * @return int[]
	 */
	protected function cart_taxonomy_ids( array $cart, $taxonomy ) {
		$terms = array();

		foreach ( $cart as $cart_id ) {
			$product = $this->get( $cart_id );

			if ( ! $product ) {
				continue;
			}

			if ( 'product_cat' === $taxonomy ) {
				$terms = array_merge( $terms, array_map( 'intval', $product->get_category_ids() ) );
			} elseif ( method_exists( $product, 'get_tag_ids' ) ) {
				$terms = array_merge( $terms, array_map( 'intval', $product->get_tag_ids() ) );
			}
		}

		return array_values( array_unique( array_filter( $terms, function ( $id ) {
			return $id > 0;
		} ) ) );
	}

	/**
	 * Product ids inside the given taxonomy terms (bounded).
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int[]  $term_ids Term ids.
	 * @return int[]
	 */
	protected function taxonomy_product_ids( $taxonomy, array $term_ids ) {
		if ( empty( $term_ids ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 15,
				'no_found_rows'  => true,
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => array_map( 'intval', $term_ids ),
					),
				),
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Best-selling product ids (fallback filler).
	 *
	 * @return int[]
	 */
	protected function best_seller_ids() {
		if ( ! class_exists( '\WC_Product_Query' ) ) {
			return array();
		}

		$query = new \WC_Product_Query(
			array(
				'status'  => 'publish',
				'limit'   => 10,
				'orderby' => 'popularity',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);

		return $query->get_products();
	}

	/**
	 * Whether a product can be ranked at all.
	 *
	 * Published, priced, and not sold out — private/draft products never
	 * reach the storefront, and out-of-stock items are excluded by the
	 * spec (P33-26/29). Backordered products with a price are rankable
	 * but score low on inventory.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	protected function is_rankable( \WC_Product $product ) {
		if ( 'publish' !== $product->get_status() ) {
			return false;
		}

		if ( 'outofstock' === $product->get_stock_status() ) {
			return false;
		}

		return '' !== $product->get_price();
	}

	/**
	 * Price gap score (P33-27/36): how well the price fits the gap.
	 *
	 * ratio = price / gap. The sweet band [0.75×, 1.30×] scores 100 — a
	 * small overshoot is explicitly tolerated (no exact match required).
	 * Below the band the score falls off linearly (too cheap still helps
	 * a little), above it hard (a 3× gap product is useless). Without a
	 * price or gap the score is a neutral 50.
	 *
	 * @param float|null $price     Product price.
	 * @param float|null $remaining Remaining gap.
	 * @return float 0–100.
	 */
	protected function price_gap_score( $price, $remaining ) {
		if ( null === $price || null === $remaining || $remaining <= 0 || $price <= 0 ) {
			return 50.0;
		}

		$ratio = $price / (float) $remaining;

		if ( $ratio >= self::GAP_SWEET_MIN && $ratio <= self::GAP_SWEET_MAX ) {
			return 100.0;
		}

		if ( $ratio < self::GAP_SWEET_MIN ) {
			return max( 0.0, 100.0 * ( $ratio / self::GAP_SWEET_MIN ) );
		}

		// Above the sweet band: decay to 0 at GAP_HARD_MAX.
		$span = self::GAP_HARD_MAX - self::GAP_SWEET_MAX;

		if ( $ratio >= self::GAP_HARD_MAX ) {
			return 0.0;
		}

		return max( 0.0, 100.0 * ( 1.0 - ( ( $ratio - self::GAP_SWEET_MAX ) / $span ) ) );
	}

	/**
	 * Relevance score (P33-28): how related the product is to the mission
	 * and the cart contents.
	 *
	 * Signals (additive, capped at 100): mission eligibility (manual
	 * products +55, counts-toward-mission via its categories +35),
	 * category overlap with the cart (+30), tag overlap (+20), and the
	 * WooCommerce-endorsed source trust bonus (upsell/cross-sell/related
	 * +15). No signals → a low baseline (the product is still a possible
	 * gap-closer, scored on the other components).
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $source  Primary source key.
	 * @param int[]       $cart    Cart product ids.
	 * @param Mission|null   $mission    Mission.
	 * @return float 0–100.
	 */
	protected function relevance_score( \WC_Product $product, $source, array $cart, $mission ) {
		$score = 0.0;
		$id    = (int) $product->get_id();

		// Mission eligibility — the strongest relevance signal.
		if ( null !== $mission && in_array( $id, $mission->products(), true ) ) {
			$score += 55.0;
		} elseif ( null !== $mission && $this->counts_toward_mission( $product, $mission ) ) {
			$score += 35.0;
		}

		// Cart-content overlap.
		if ( ! empty( $cart ) ) {
			if ( $this->shares_cart_taxonomy( $product, $cart, 'product_cat' ) ) {
				$score += 30.0;
			}

			if ( $this->shares_cart_taxonomy( $product, $cart, 'product_tag' ) ) {
				$score += 20.0;
			}
		}

		// WooCommerce-endorsed sources carry a small trust bonus.
		if ( in_array( $source, array( self::SOURCE_UPSELL, self::SOURCE_CROSS_SELL, self::SOURCE_RELATED ), true ) ) {
			$score += 15.0;
		}

		return min( 100.0, $score );
	}

	/**
	 * Inventory score (P33-29): healthy stock preferred.
	 *
	 * stock > 20 → 100, 5–20 → 70, 1–4 → 40; stock-managed but empty and
	 * on backorder → 20; not managing stock → 70 (unknowable, neutral);
	 * out of stock is excluded earlier (is_rankable).
	 *
	 * @param \WC_Product $product Product.
	 * @return float 0–100.
	 */
	protected function inventory_score( \WC_Product $product ) {
		if ( ! $product->managing_stock() ) {
			return 70.0;
		}

		$stock = $this->stock_quantity( $product );

		if ( null === $stock ) {
			return 70.0;
		}

		if ( $stock > 20 ) {
			return 100.0;
		}

		if ( $stock >= 5 ) {
			return 70.0;
		}

		if ( $stock >= 1 ) {
			return 40.0;
		}

		// 0 managed stock but still rankable → backorder.
		return 20.0;
	}

	/**
	 * Popularity score (P33-30): units sold + average rating, bounded.
	 *
	 * Sales normalize to a max at 100 units (one huge seller cannot
	 * dominate); rating contributes up to 30. The "recent performance"
	 * weight lives in the conversion score, which reads the
	 * retention-bounded upsell funnel (recent by construction).
	 *
	 * @param \WC_Product           $product Product.
	 * @param array<string, mixed>  $stats   Upsell stats row (unused here —
	 *                                       kept for a future velocity blend).
	 * @return float 0–100.
	 */
	protected function popularity_score( \WC_Product $product, array $stats ) {
		$sales  = min( 1.0, (float) $product->get_total_sales() / 100.0 );
		$rating = method_exists( $product, 'get_average_rating' )
			? min( 1.0, (float) $product->get_average_rating() / 5.0 )
			: 0.0;

		return ( $sales * 70.0 ) + ( $rating * 30.0 );
	}

	/**
	 * Margin score (P33-31): higher margin → higher score, only when cost
	 * data exists (never invented). Neutral 50 without margin data — and
	 * capped so margin can never dominate relevance.
	 *
	 * @param array<string, mixed>|null $margin product_margin() result.
	 * @return float 0–100.
	 */
	protected function margin_score( $margin ) {
		if ( null === $margin || (float) $margin['margin_pct'] <= 0 ) {
			return 50.0;
		}

		// 50% margin → 100; linear below, capped so the component is
		// never a free 100 for extreme margins.
		return min( 100.0, 50.0 + ( (float) $margin['margin_pct'] * 100.0 ) );
	}

	/**
	 * Conversion score (P33-32): the product's historical upsell funnel.
	 *
	 * Base conversion rate (orders / impressions) normalized against a
	 * 5% target, blended toward the neutral 50 by an impressions
	 * confidence factor — a product with 2 impressions and 1 order must
	 * not be crowned a 100-conversion champion. No data → neutral 50.
	 *
	 * @param array<string, mixed> $stats Upsell stats row.
	 * @return float 0–100.
	 */
	protected function conversion_score( array $stats ) {
		$impressions = (int) $stats['impressions'];
		$orders      = (int) $stats['orders'];

		if ( $impressions < 1 ) {
			return 50.0;
		}

		$rate   = $orders / $impressions;
		$signal = min( 100.0, ( $rate / 0.05 ) * 100.0 );
		$weight = min( 1.0, $impressions / 50.0 );

		return 50.0 + ( ( $signal - 50.0 ) * $weight );
	}

	/**
	 * The historical conversion payload for a ranked product.
	 *
	 * @param array<string, mixed> $stats Upsell stats row.
	 * @return array<string, mixed>
	 */
	protected function conversion_payload( array $stats ) {
		$impressions = (int) $stats['impressions'];

		return array(
			'impressions'     => $impressions,
			'clicks'          => (int) $stats['clicks'],
			'adds'            => (int) $stats['adds'],
			'orders'          => (int) $stats['orders'],
			'revenue'         => round( (float) $stats['revenue'], 4 ),
			'conversion_rate' => $impressions > 0 ? round( (int) $stats['orders'] / $impressions, 4 ) : 0.0,
			'available'       => $impressions > 0,
		);
	}

	/**
	 * Plain-English explanation bullets for a product (P33-34/59).
	 *
	 * Every bullet is derived from the actual computed factors — no
	 * hard-coded claims. Strings are intentionally plain (structured data
	 * rendered by the admin UI / storefront).
	 *
	 * @param \WC_Product             $product    Product.
	 * @param string                  $source     Primary source key.
	 * @param float|null              $remaining  Remaining gap.
	 * @param float|null              $price      Product price.
	 * @param float                   $price_gap  Price-gap score.
	 * @param float                   $relevance  Relevance score.
	 * @param float                   $popularity Popularity score.
	 * @param float                   $inventory  Inventory score.
	 * @param array<string, mixed>|null $margin    Margin stats.
	 * @param array<string, mixed>    $stats      Upsell stats row.
	 * @param int[]                   $cart       Cart product ids.
	 * @return string[]
	 */
	protected function reasons( \WC_Product $product, $source, $remaining, $price, $price_gap, $relevance, $popularity, $inventory, $margin, array $stats, array $cart ) {
		$reasons = array();

		if ( null !== $price && null !== $remaining && $remaining > 0 ) {
			$reasons[] = sprintf(
				/* translators: 1: product price, 2: remaining gap amount, 3: how well the price fits. */
				__( 'Price %s fits the remaining %s %s.', 'faracart' ),
				$this->fmt_amount( $price ),
				$this->fmt_amount( (float) $remaining ),
				$price_gap >= 90.0 ? __( 'almost exactly', 'faracart' ) : __( 'partially', 'faracart' )
			);
		}

		$source_label = $this->source_label( $source );

		if ( '' !== $source_label ) {
			$reasons[] = sprintf( /* translators: 1: product source description. */ __( 'Source: %s.', 'faracart' ), $source_label );
		}

		if ( $relevance >= 80.0 ) {
			$reasons[] = __( 'Highly relevant to the mission and the current cart contents.', 'faracart' );
		} elseif ( $relevance >= 40.0 ) {
			$reasons[] = __( 'Relevant to the mission or the current cart contents.', 'faracart' );
		}

		if ( (float) $product->get_total_sales() > 0 ) {
			$reasons[] = sprintf(
				/* translators: 1: units sold, 2: average rating. */
				__( 'Popular product (%d units sold, %s rating).', 'faracart' ),
				(int) $product->get_total_sales(),
				$this->fmt_decimal( method_exists( $product, 'get_average_rating' ) ? (float) $product->get_average_rating() : 0.0 )
			);
		}

		if ( $inventory >= 70.0 ) {
			$reasons[] = __( 'Healthy stock levels.', 'faracart' );
		} elseif ( $inventory < 50.0 ) {
			$reasons[] = __( 'Limited stock remaining.', 'faracart' );
		}

		if ( null !== $margin ) {
			$reasons[] = sprintf(
				/* translators: 1: estimated margin percentage. */
				__( 'Estimated margin %s%% on the product price.', 'faracart' ),
				$this->fmt_pct( (float) $margin['margin_pct'] * 100.0 )
			);
		} else {
			$reasons[] = __( 'Product margin data is not available — profitability scored neutral.', 'faracart' );
		}

		if ( (int) $stats['impressions'] > 0 ) {
			$reasons[] = sprintf(
				/* translators: 1: impressions, 2: orders, 3: conversion rate. */
				__( 'Historical upsell performance: %d impressions, %d orders (%s conversion).', 'faracart' ),
				(int) $stats['impressions'],
				(int) $stats['orders'],
				$this->fmt_pct( (float) $this->conversion_payload( $stats )['conversion_rate'] * 100.0 )
			);
		} else {
			$reasons[] = __( 'No historical upsell performance data yet — conversion scored neutral.', 'faracart' );
		}

		if ( empty( $cart ) ) {
			$reasons[] = __( 'No cart contents provided — relevance scored from the mission only.', 'faracart' );
		}

		return $reasons;
	}

	/**
	 * Human-readable source label for the reason bullets.
	 *
	 * @param string $source Source key.
	 * @return string
	 */
	protected function source_label( $source ) {
		$labels = array(
			self::SOURCE_MANUAL         => __( 'manually selected for this mission', 'faracart' ),
			self::SOURCE_HISTORICAL     => __( 'previously recommended for this mission', 'faracart' ),
			self::SOURCE_CATEGORY       => __( "inside the mission's categories", 'faracart' ),
			self::SOURCE_UPSELL         => __( 'WooCommerce upsell of a cart item', 'faracart' ),
			self::SOURCE_CROSS_SELL     => __( 'WooCommerce cross-sell of a cart item', 'faracart' ),
			self::SOURCE_RELATED        => __( 'related to a cart item', 'faracart' ),
			self::SOURCE_CATEGORY_MATCH => __( 'shares a category with the cart', 'faracart' ),
			self::SOURCE_TAG_MATCH      => __( 'shares a tag with the cart', 'faracart' ),
			self::SOURCE_POPULAR        => __( 'best seller', 'faracart' ),
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : '';
	}

	/**
	 * Whether the product counts toward the mission (category/product missions).
	 *
	 * @param \WC_Product $product Product.
	 * @param Mission        $mission    Mission.
	 * @return bool
	 */
	protected function counts_toward_mission( \WC_Product $product, Mission $mission ) {
		if ( in_array( (int) $product->get_id(), $mission->products(), true ) ) {
			return true;
		}

		if ( empty( $mission->categories() ) ) {
			return false;
		}

		return count( array_intersect( array_map( 'intval', $product->get_category_ids() ), $mission->categories() ) ) > 0;
	}

	/**
	 * Whether the product shares a taxonomy term with any cart item.
	 *
	 * @param \WC_Product $product  Product.
	 * @param int[]       $cart     Cart product ids.
	 * @param string      $taxonomy Taxonomy name.
	 * @return bool
	 */
	protected function shares_cart_taxonomy( \WC_Product $product, array $cart, $taxonomy ) {
		$cart_terms = $this->cart_taxonomy_ids( $cart, $taxonomy );

		if ( empty( $cart_terms ) ) {
			return false;
		}

		$product_terms = 'product_cat' === $taxonomy
			? array_map( 'intval', $product->get_category_ids() )
			: ( method_exists( $product, 'get_tag_ids' ) ? array_map( 'intval', $product->get_tag_ids() ) : array() );

		return count( array_intersect( $product_terms, $cart_terms ) ) > 0;
	}

	/**
	 * The stock quantity (null when not stock-managed).
	 *
	 * @param \WC_Product $product Product.
	 * @return int|null
	 */
	protected function stock_quantity( \WC_Product $product ) {
		if ( ! $product->managing_stock() ) {
			return null;
		}

		$stock = $product->get_stock_quantity();

		return null !== $stock ? (int) $stock : null;
	}

	/**
	 * The scoring weights, filterable.
	 *
	 * @return array<string, float>
	 */
	protected function score_weights() {
		$weights = (array) apply_filters( 'faracart_upsell_weights', self::SCORE_WEIGHTS );

		// Fall back per key so a partial filter cannot zero a component:
		// keys the filter omitted keep their defaults unchanged, while the
		// explicitly provided keys are normalized among themselves (so a
		// filter returning only price_gap=50/relevance=50 yields 0.5/0.5
		// and the untouched defaults — conversion stays 0.10).
		$provided = array();

		foreach ( self::SCORE_WEIGHTS as $key => $default ) {
			if ( isset( $weights[ $key ] ) && is_numeric( $weights[ $key ] ) ) {
				$value = max( 0.0, (float) $weights[ $key ] );
				$weights[ $key ] = $value;
				$provided[ $key ] = $value;
			} else {
				$weights[ $key ] = $default;
			}
		}

		$total = array_sum( $provided );

		if ( $total <= 0 ) {
			return $weights;
		}

		foreach ( $provided as $key => $value ) {
			$weights[ $key ] = $value / $total;
		}

		return $weights;
	}

	/**
	 * The product's upsell_stats row (memoized per request).
	 *
	 * @param int $product_id Product id.
	 * @return array{impressions: int, clicks: int, adds: int, orders: int, revenue: float}
	 */
	protected function product_stats( $product_id ) {
		$product_id = (int) $product_id;

		if ( isset( $this->stats_cache[ $product_id ] ) ) {
			return $this->stats_cache[ $product_id ];
		}

		$empty = array( 'impressions' => 0, 'clicks' => 0, 'adds' => 0, 'orders' => 0, 'revenue' => 0.0 );

		global $wpdb;

		$stats = Schema::table( 'upsell_stats' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT impressions, clicks, adds, orders, revenue FROM {$stats} WHERE product_id = %d",
				$product_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			$this->stats_cache[ $product_id ] = $empty;

			return $empty;
		}

		$this->stats_cache[ $product_id ] = array(
			'impressions' => (int) $row['impressions'],
			'clicks'      => (int) $row['clicks'],
			'adds'        => (int) $row['adds'],
			'orders'      => (int) $row['orders'],
			'revenue'     => (float) $row['revenue'],
		);

		return $this->stats_cache[ $product_id ];
	}

	/**
	 * Resolve the ordering session for upsell order attribution.
	 *
	 * Priority: the session recorded on the order_paid event (recorded by
	 * the AttributionEngine at priority 10) → the live cookie session →
	 * the most recent revenue session of a logged-in user.
	 *
	 * @param int $order_id Order id.
	 * @return string Anonymous session id ('' when unresolvable).
	 */
	protected function resolve_session( $order_id ) {
		global $wpdb;

		$revenue = Schema::table( 'revenue_events' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT session_id FROM {$revenue} WHERE event_type = %s AND order_id = %d ORDER BY id DESC LIMIT 1",
				RevenueTracker::EVENT_ORDER_PAID,
				$order_id
			),
			ARRAY_A
		);

		if ( is_array( $row ) && Session::is_valid( (string) $row['session_id'] ) ) {
			return (string) $row['session_id'];
		}

		$cookie = $this->tracker->get_session_id();

		if ( Session::is_valid( $cookie ) ) {
			return $cookie;
		}

		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT session_id FROM {$revenue}
					 WHERE user_id = %d AND event_type IN (%s, %s, %s, %s)
					 ORDER BY created_at DESC, id DESC LIMIT 1",
					$user_id,
					RevenueTracker::EVENT_MISSION_VIEW,
					RevenueTracker::EVENT_MISSION_PROGRESS,
					RevenueTracker::EVENT_MISSION_COMPLETED,
					RevenueTracker::EVENT_CART_VALUE
				),
				ARRAY_A
			);

			if ( is_array( $row ) && Session::is_valid( (string) $row['session_id'] ) ) {
				return (string) $row['session_id'];
			}
		}

		return '';
	}

	/**
	 * Memoized wc_get_order().
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|null
	 */
	protected function wc_order( $order_id ) {
		if ( ! isset( $this->order_cache[ $order_id ] ) ) {
			$this->order_cache[ $order_id ] = function_exists( 'wc_get_order' )
				? wc_get_order( (int) $order_id )
				: null;
		}

		return $this->order_cache[ $order_id ] ? $this->order_cache[ $order_id ] : null;
	}

	/**
	 * Load products by id in one batched query, memoized per request.
	 *
	 * @param int[] $ids Product ids.
	 * @return array<int, \WC_Product>
	 */
	protected function load_products( array $ids ) {
		$result  = array();
		$missing = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( isset( $this->loaded[ $id ] ) ) {
				$result[ $id ] = $this->loaded[ $id ];
			} elseif ( $id > 0 ) {
				$missing[] = $id;
			}
		}

		if ( ! empty( $missing ) ) {
			$found = array();

			foreach ( $this->query_products( array( 'include' => $missing ) ) as $product ) {
				$found[ $product->get_id() ] = $product;
			}

			foreach ( $missing as $id ) {
				if ( isset( $found[ $id ] ) ) {
					continue;
				}

				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;

				if ( ! $product || 'publish' !== $product->get_status() ) {
					continue;
				}

				$found[ $id ] = $product;
			}

			foreach ( $found as $product ) {
				$this->loaded[ $product->get_id() ] = $product;
				$result[ $product->get_id() ]       = $product;
			}
		}

		return $result;
	}

	/**
	 * Get a single product through the request cache.
	 *
	 * @param int $id Product id.
	 * @return \WC_Product|null
	 */
	protected function get( $id ) {
		$id = (int) $id;

		if ( isset( $this->loaded[ $id ] ) ) {
			return $this->loaded[ $id ];
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;

		if ( ! $product ) {
			return null;
		}

		$this->loaded[ $id ] = $product;

		return $product;
	}

	/**
	 * Run a WC_Product_Query and normalize the result to objects.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return \WC_Product[]
	 */
	protected function query_products( array $args ) {
		if ( ! class_exists( '\WC_Product_Query' ) ) {
			return array();
		}

		$args['status'] = 'publish';
		$args['return'] = 'objects';

		$query = new \WC_Product_Query( $args );

		return $query->get_products();
	}

	/**
	 * The product's stripped price label (text-only, entity-decoded).
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	protected function price_html( \WC_Product $product ) {
		$price = $product->get_price();

		if ( '' === $price || ! function_exists( 'wc_price' ) ) {
			return '';
		}

		return Currency::price( (float) $price );
	}

	/**
	 * The product's gallery thumbnail URL ('' when none).
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	protected function image_url( \WC_Product $product ) {
		$image_id = $product->get_image_id();

		if ( ! $image_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );

		return $url ? $url : '';
	}

	/**
	 * Format a percentage for the reason strings.
	 *
	 * @param float $pct Percentage value.
	 * @return string
	 */
	protected function fmt_pct( $pct ) {
		return rtrim( rtrim( number_format( (float) $pct, 1, '.', ',' ), '0' ), '.' ) . '%';
	}

	/**
	 * Format an amount for the reason strings.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	protected function fmt_amount( $amount ) {
		return Currency::price( (float) $amount );
	}

	/**
	 * Format a non-monetary decimal for a recommendation reason.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	protected function fmt_decimal( $value ) {
		return (string) number_format_i18n( (float) $value, 1 );
	}
}
