<?php
/**
 * Cached revenue summaries for Goal Cart (Phase 33.3 — Aggregation & Performance).
 *
 * @package GoalCart
 */

namespace GoalCart\Analytics;

use GoalCart\Database\Schema;
use GoalCart\Goals\GoalRepository;
use GoalCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class RevenueRepository
 *
 * Phase 33.3 (Aggregation & Performance) — the cached read layer over the
 * Phase 33.2 attribution engine and the Phase 33.3 aggregated tables, so
 * admin/dashboard requests never re-run the bounded live queries on every
 * page load:
 *
 *  - overview()          — one cached payload merging the attribution
 *                          summary, incremental cart value, AOV analysis and
 *                          shipping stats (the Revenue Optimization KPIs).
 *  - goal_performance()  — per-goal metrics (all goals or one).
 *  - daily_trend()       — the daily revenue series read from revenue_daily
 *                          (the aggregated table), zero-filled over the
 *                          window, with today's still-live data merged from
 *                          the engine until the next aggregation tick.
 *  - product_stats()     — per-product upsell aggregates read from
 *                          upsell_stats (rebuilt by DailyAggregator).
 *  - upsell_ranking()    — the Phase 33.5 Smart Upsell ranked products for
 *                          a cart + goal context (cached, deterministic).
 *  - upsell_analytics()  — the top-products upsell analytics table
 *                          (impressions/clicks/adds/orders/revenue/profit/
 *                          score) over a window.
 *  - upsell_product_detail() — one product's score breakdown + stats.
 *
 * Caching (P33.3): every read is memoized in a transient whose key embeds a
 * generation counter (goalcart_revenue_cache_version). invalidate() bumps the
 * counter, so stale entries are never served and no key enumeration is
 * needed; old transients expire through their TTL. Invalidation is wired to
 * the events that change the underlying data — order payment/status changes,
 * goal CRUD (goalcart_goals_changed), product saves (upsell stats), and the
 * daily aggregation run (goalcart_revenue_aggregated).
 *
 * The whole layer is gated by goalcart_revenue_cache_enabled (default on)
 * with a filterable TTL (goalcart_revenue_cache_ttl), so stores can tune or
 * disable caching without changing reads.
 */
final class RevenueRepository {

	/**
	 * Transient prefix shared by every revenue cache key.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'goalcart_rev_';

	/**
	 * Option holding the cache generation counter.
	 *
	 * @var string
	 */
	const CACHE_VERSION_OPTION = 'goalcart_revenue_cache_version';

	/**
	 * Default cache TTL (seconds) — filterable with goalcart_revenue_cache_ttl.
	 *
	 * @var int
	 */
	const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Default TTL for product stats (rebuilt daily by the aggregator).
	 *
	 * @var int
	 */
	const PRODUCTS_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Default TTL for goal recommendations (filterable with
	 * goalcart_recommendation_cache_ttl).
	 *
	 * @var int
	 */
	const RECS_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Attribution engine (live reads behind the cache).
	 *
	 * @var AttributionEngine
	 */
	protected $engine;

	/**
	 * Goal repository (goal list for the performance report).
	 *
	 * @var GoalRepository
	 */
	protected $repository;

	/**
	 * Smart goal recommendation engine (Phase 33.4).
	 *
	 * @var GoalRecommendationEngine
	 */
	protected $recommendations;

	/**
	 * Smart upsell ranking engine (Phase 33.5).
	 *
	 * @var UpsellRanker
	 */
	protected $upsells;

	/**
	 * Constructor.
	 *
	 * @param AttributionEngine        $engine         Revenue attribution engine.
	 * @param GoalRepository           $repository     Goal repository.
	 * @param GoalRecommendationEngine $recommendations Goal recommendation engine.
	 * @param UpsellRanker|null        $upsells        Upsell ranking engine (Phase 33.5).
	 */
	public function __construct( AttributionEngine $engine, GoalRepository $repository, GoalRecommendationEngine $recommendations, ?UpsellRanker $upsells = null ) {
		$this->engine         = $engine;
		$this->repository     = $repository;
		$this->recommendations = $recommendations;
		$this->upsells        = $upsells;
	}

	/**
	 * Register the cache-invalidation hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		// Order data changes every revenue summary (attribution, AOV,
		// shipping, reward cost) — invalidate on payment + status changes.
		$hooks->add_action( 'woocommerce_payment_complete', array( $this, 'invalidate' ) );
		$hooks->add_action( 'woocommerce_order_status_completed', array( $this, 'invalidate' ) );
		$hooks->add_action( 'woocommerce_order_status_changed', array( $this, 'invalidate' ) );

		// Goal CRUD changes reward config, funnel targets and names.
		$hooks->add_action( 'goalcart_goals_changed', array( $this, 'invalidate' ) );

		// Product saves change names/prices behind the upsell stats.
		$hooks->add_action( 'save_post_product', array( $this, 'invalidate' ) );

		// The daily aggregation refreshes revenue_daily + upsell_stats.
		$hooks->add_action( 'goalcart_revenue_aggregated', array( $this, 'invalidate' ) );
	}

	/**
	 * Invalidate every revenue cache by bumping the generation counter.
	 *
	 * Cheap (one option update, no key enumeration); old transients are
	 * orphaned and expire through their TTL.
	 *
	 * @return void
	 */
	public function invalidate() {
		$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
		update_option( self::CACHE_VERSION_OPTION, $version + 1, false );
	}

	/**
	 * Revenue Optimization overview — the KPI payload, cached.
	 *
	 * @param array<string, mixed> $args Optional: goal_id, from, to.
	 * @return array<string, mixed>
	 */
	public function overview( array $args = array() ) {
		return $this->cached(
			'overview',
			$args,
			self::CACHE_TTL,
			function () use ( $args ) {
				return array(
					'summary'             => $this->engine->attribution_summary( $args ),
					'incremental_cart_value' => $this->engine->incremental_cart_value( $args ),
					'aov'                 => $this->engine->aov_analysis( $args ),
					'shipping'            => $this->engine->shipping_stats( $args ),
					'generated_at'        => current_time( 'mysql' ),
				);
			}
		);
	}

	/**
	 * Goal Performance rows — every goal (or one), cached.
	 *
	 * @param array<string, mixed> $args Optional: goal_id, from, to.
	 * @return array<int, array<string, mixed>>
	 */
	public function goal_performance( array $args = array() ) {
		return $this->cached(
			'goals',
			$args,
			self::CACHE_TTL,
			function () use ( $args ) {
				$items = array();

				if ( ! empty( $args['goal_id'] ) ) {
					$metrics = $this->engine->goal_metrics( (int) $args['goal_id'], $args );

					if ( null !== $metrics ) {
						$items[] = $metrics;
					}

					return $items;
				}

				// All goals, paged — never silently truncated by a fixed cap.
				$page = 1;

				while ( true ) {
					$result = $this->repository->all(
						array( 'page' => $page, 'per_page' => 100 )
					);

					foreach ( $result['items'] as $row ) {
						$metrics = $this->engine->goal_metrics( (int) $row['id'], $args );

						if ( null !== $metrics ) {
							$items[] = $metrics;
						}
					}

					$page++;

					if ( $page > (int) ceil( $result['total'] / 100 ) ) {
						break;
					}
				}

				return $items;
			}
		);
	}

	/**
	 * Daily revenue trend from the aggregated revenue_daily table.
	 *
	 * One point per day of the window, zero-filled so charts stay continuous
	 * (mirrors AnalyticsRepository::trend). Today — the only day the
	 * aggregator cannot have captured yet (it rolls up through yesterday) —
	 * is merged live from the engine, so the report's latest point is never
	 * blank while waiting for the next tick. Any past day the aggregator has
	 * not reached (cron lag) reads as zeros until the next run, and days
	 * beyond today in a custom range are clamped to today (there is no data
	 * for the future).
	 *
	 * @param array<string, mixed> $args Optional: goal_id, from, to.
	 * @return array<int, array{date: string, views: int, progressions: int, completions: int, conversions: int, revenue: float, incremental_revenue: float, reward_cost: float, estimated_profit: float}>
	 */
	public function daily_trend( array $args = array() ) {
		return $this->cached(
			'trend',
			$args,
			self::CACHE_TTL,
			function () use ( $args ) {
				global $wpdb;

				$daily = Schema::table( 'revenue_daily' );

				$from = ! empty( $args['from'] ) ? date( 'Y-m-d', strtotime( (string) $args['from'] ) ) : date( 'Y-m-d', strtotime( '-29 days' ) );
				$to   = ! empty( $args['to'] ) ? date( 'Y-m-d', strtotime( (string) $args['to'] ) ) : date( 'Y-m-d' );

				// A future 'to' would produce days with no data and would
				// duplicate today's live bucket across them — clamp to today.
				$today = date( 'Y-m-d', current_time( 'timestamp' ) );

				if ( $to > $today ) {
					$to = $today;
				}

				// Clamp the window to the MAX_RANGE pattern used elsewhere.
				if ( strtotime( $to ) - strtotime( $from ) > 366 * DAY_IN_SECONDS ) {
					$from = date( 'Y-m-d', strtotime( $to . ' -366 days' ) );
				}

				$where  = 'report_date >= %s AND report_date <= %s';
				$params = array( $from, $to );

				if ( ! empty( $args['goal_id'] ) ) {
					$where   .= ' AND goal_id = %d';
					$params[] = (int) $args['goal_id'];
				}

				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT report_date,
							SUM(views) AS views,
							SUM(progressions) AS progressions,
							SUM(completions) AS completions,
							SUM(conversions) AS conversions,
							SUM(revenue) AS revenue,
							SUM(incremental_revenue) AS incremental_revenue,
							SUM(reward_cost) AS reward_cost,
							SUM(estimated_profit) AS estimated_profit
						 FROM {$daily}
						 WHERE {$where}
						 GROUP BY report_date
						 ORDER BY report_date ASC",
						$params
					),
					ARRAY_A
				);

				$by_day = array();

				foreach ( (array) $rows as $row ) {
					$by_day[ $row['report_date'] ] = $row;
				}

				// Today's live bucket — merged only on the exact today date (a
				// window ending before today never reaches it).
				$today_live = null;

				$trend  = array();
				$cursor = strtotime( $from );
				$end    = strtotime( $to );

				while ( $cursor <= $end ) {
					$day = date( 'Y-m-d', $cursor );

					if ( $day === $today && null === $today_live ) {
						$today_live = $this->live_day( $args, $today );
					}

					$row = isset( $by_day[ $day ] ) ? $by_day[ $day ] : array(
						'views' => 0, 'progressions' => 0, 'completions' => 0,
						'conversions' => 0, 'revenue' => 0, 'incremental_revenue' => 0,
						'reward_cost' => 0, 'estimated_profit' => 0,
					);

					if ( $day === $today && null !== $today_live ) {
						$row = array_merge( $row, $today_live );
					}

					$trend[] = array(
						'date'                => $day,
						'views'               => (int) $row['views'],
						'progressions'        => (int) $row['progressions'],
						'completions'         => (int) $row['completions'],
						'conversions'         => (int) $row['conversions'],
						'revenue'             => round( (float) $row['revenue'], 4 ),
						'incremental_revenue' => round( (float) $row['incremental_revenue'], 4 ),
						'reward_cost'         => round( (float) $row['reward_cost'], 4 ),
						'estimated_profit'    => round( (float) $row['estimated_profit'], 4 ),
					);

					$cursor = strtotime( '+1 day', $cursor );
				}

				return $trend;
			}
		);
	}

	/**
	 * Purchase-focused attribution summary for the legacy Analytics
	 * endpoint (Phase 2 — Backend/Data Layer).
	 *
	 * Maps the Phase 17 analytics filters onto the attribution layer and
	 * returns the fields the redesigned Analytics UI needs (purchased
	 * orders, purchase rate, attributed sales, estimated profit + reason /
	 * coverage), served through the same cached layer as every other
	 * revenue read — never recomputed per request.
	 *
	 * Filter mapping (Improvement.md §36/§37 — extend, don't duplicate):
	 *
	 *  - from / to          → the attribution window (the same date range)
	 *  - goal_id / goal_ids → the goal(s) directly
	 *  - campaign_id        → resolved to the campaign's goal ids
	 *  - reward_type        → resolved to the goals carrying that reward
	 *  - product_id         → unsupported in attribution (goal_attribution
	 *                         has no product dimension) → null, so the UI
	 *                         never shows a misleading number for a
	 *                         dimension the engine does not track
	 *
	 * @param array<string, mixed> $filters Optional from/to, campaign_id,
	 *                                      goal_id, goal_ids, product_id,
	 *                                      reward_type.
	 * @return array<string, mixed>|null The attribution summary shape, or
	 *                                  null when the filters cannot be
	 *                                  expressed in attribution.
	 */
	public function purchase_summary( array $filters = array() ) {
		// product_id cannot be expressed in attribution — never fabricate a
		// number for a dimension the engine does not track.
		if ( ! empty( $filters['product_id'] ) ) {
			return null;
		}

		$args = array(
			'from' => isset( $filters['from'] ) ? (string) $filters['from'] : '',
			'to'   => isset( $filters['to'] ) ? (string) $filters['to'] : '',
		);

		$goal_ids = array();

		if ( ! empty( $filters['goal_id'] ) ) {
			$goal_ids[] = (int) $filters['goal_id'];
		} elseif ( ! empty( $filters['goal_ids'] ) && is_array( $filters['goal_ids'] ) ) {
			$goal_ids = array_values( array_filter( array_map( 'absint', $filters['goal_ids'] ), function ( $id ) {
				return $id > 0;
			} ) );
		} elseif ( ! empty( $filters['campaign_id'] ) ) {
			$goal_ids = $this->repository->ids_by_campaign( (int) $filters['campaign_id'] );
		} elseif ( ! empty( $filters['reward_type'] ) ) {
			$goal_ids = $this->repository->ids_by_reward_type( (string) $filters['reward_type'] );
		}

		// A goal/campaign/reward filter that resolves to no goals is a
		// genuine empty window — return the zeroed summary rather than
		// silently reporting store-wide data for the wrong filter.
		$filter_active = ! empty( $filters['goal_id'] )
			|| ! empty( $filters['goal_ids'] )
			|| ! empty( $filters['campaign_id'] )
			|| ! empty( $filters['reward_type'] );

		if ( $filter_active && empty( $goal_ids ) ) {
			return $this->empty_purchase_summary();
		}

		if ( ! empty( $goal_ids ) ) {
			$args['goal_ids'] = $goal_ids;
		}

		return $this->cached(
			'purchase_summary',
			$args,
			self::CACHE_TTL,
			function () use ( $args ) {
				return $this->engine->attribution_summary( $args );
			}
		);
	}

	/**
	 * The zeroed attribution-summary shape for empty filter windows.
	 *
	 * Keeps the purchase metrics well-formed so the analytics UI can render
	 * an honest empty state. IMPORTANT: this must stay key-for-key in sync
	 * with AttributionEngine::attribution_summary() — when the engine adds
	 * a summary field, mirror it here (and in the engine's zero-data path).
	 *
	 * @return array<string, mixed>
	 */
	protected function empty_purchase_summary() {
		return array(
			'goal_driven_revenue'    => 0.0,
			'goal_assisted_revenue'  => 0.0,
			'goal_influenced_revenue'=> 0.0,
			'orders'                 => 0,
			'reward_cost'            => 0.0,
			'reward_cost_available'  => true,
			'profit_impact'          => null,
			'profit_available'       => false,
			'profit_reason'          => null,
			'profit_reason_code'     => 'insufficient_data',
			'profit_details'         => array(
				'incremental_revenue' => 0.0,
				'margin_pct'          => null,
				'reward_cost'         => 0.0,
				'shipping_cost'       => null,
			),
			'cost_coverage'          => array(
				'attributed_orders'     => 0,
				'orders_with_cost_data' => 0,
				'coverage_pct'          => null,
				'available'             => false,
			),
			'funnel'                 => array(
				'views'           => 0,
				'progressed'      => 0,
				'completed'       => 0,
				'converted'       => 0,
				'completion_rate' => null,
				'conversion_rate' => null,
			),
		);
	}

	/**
	 * Smart goal recommendations, cached with the same generation-versioned
	 * transient system as the other revenue reads.
	 *
	 * The recommendation engine is deterministic and pure (no writes), so
	 * caching only skips recomputation; the existing invalidation — order
	 * payment/status changes, goal CRUD, product saves, aggregation runs —
	 * already covers every event that could change a recommendation.
	 *
	 * @param array<string, mixed> $args Optional: goal_id, reward_type,
	 *                                   reward_value, reward_max_value,
	 *                                   reward_meta, window_days, from, to.
	 * @return array<string, mixed>
	 */
	public function goal_recommendations( array $args = array() ) {
		return $this->cached(
			'goal_recs',
			$args,
			(int) apply_filters( 'goalcart_recommendation_cache_ttl', self::RECS_CACHE_TTL ),
			function () use ( $args ) {
				return $this->recommendations->recommend( $args );
			}
		);
	}

	/**
	 * Product upsell statistics from the aggregated upsell_stats table.
	 *
	 * @param array<string, mixed> $args Optional: limit (1–100).
	 * @return array<int, array{product_id: int, name: string, impressions: int, clicks: int, adds: int, orders: int, revenue: float, conversion_rate: float}>
	 */
	public function product_stats( array $args = array() ) {
		return $this->cached(
			'products',
			$args,
			self::PRODUCTS_CACHE_TTL,
			function () use ( $args ) {
				global $wpdb;

				$stats = Schema::table( 'upsell_stats' );
				$posts = $wpdb->posts;
				$limit = max( 1, min( 100, isset( $args['limit'] ) ? (int) $args['limit'] : 20 ) );

				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT s.product_id AS product_id, p.post_title AS name,
							s.impressions AS impressions, s.clicks AS clicks,
							s.adds AS adds, s.orders AS orders, s.revenue AS revenue
						 FROM {$stats} s
						 LEFT JOIN {$posts} p ON p.ID = s.product_id
						 ORDER BY s.orders DESC, s.revenue DESC, s.impressions DESC
						 LIMIT %d",
						$limit
					),
					ARRAY_A
				);

				$items = array();

				foreach ( (array) $rows as $row ) {
					$impressions = (int) $row['impressions'];
					$items[] = array(
						'product_id'      => (int) $row['product_id'],
						'name'            => (string) $row['name'],
						'impressions'     => $impressions,
						'clicks'          => (int) $row['clicks'],
						'adds'            => (int) $row['adds'],
						'orders'          => (int) $row['orders'],
						'revenue'         => round( (float) $row['revenue'], 4 ),
						'conversion_rate' => $impressions > 0 ? round( (int) $row['orders'] / $impressions, 4 ) : 0.0,
					);
				}

				return $items;
			}
		);
	}

	/**
	 * Smart upsell ranking (Phase 33.5), cached.
	 *
	 * The UpsellRanker is deterministic and pure (no writes), so caching
	 * only skips recomputation; the existing invalidation — order payment/
	 * status changes (upsell funnel), goal CRUD (eligibility), product
	 * saves (prices/stock/margin), aggregation runs (upsell_stats) —
	 * already covers every event that could change a ranking.
	 *
	 * @param array<string, mixed> $args Optional: goal_id, cart_value,
	 *                                   remaining, cart, limit, exclude.
	 * @return array<string, mixed>
	 */
	public function upsell_ranking( array $args = array() ) {
		if ( null === $this->upsells ) {
			return $this->upsells_unavailable( __( 'Smart upsell ranking is not available.', 'goalcart' ) );
		}

		return $this->cached(
			'upsell_rank',
			$args,
			self::RECS_CACHE_TTL,
			function () use ( $args ) {
				return $this->upsells->rank( $args );
			}
		);
	}

	/**
	 * Top-products upsell analytics table (Phase 33.5), cached.
	 *
	 * Unlike product_stats() (which reads the pre-aggregated upsell_stats
	 * table), this read supports the admin analytics window (from/to) and
	 * goal filtering by scanning the retention-bounded upsell_events log
	 * — grouped per product, bounded by the same pagination caps as the
	 * other revenue reads. Each row is enriched with the product's margin
	 * (when the store provides cost data) and its upsell score computed
	 * through the same Phase 33.5 component math.
	 *
	 * @param array<string, mixed> $args Optional: from, to, goal_id, limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function upsell_analytics( array $args = array() ) {
		return $this->cached(
			'upsell_analytics',
			$args,
			self::PRODUCTS_CACHE_TTL,
			function () use ( $args ) {
				return $this->build_upsell_analytics( $args );
			}
		);
	}

	/**
	 * One product's upsell score breakdown + historical stats (Phase 33.5),
	 * cached.
	 *
	 * @param int                  $product_id Product id.
	 * @param array<string, mixed> $args       Optional: goal_id, cart_value,
	 *                                         remaining, cart.
	 * @return array<string, mixed>|null Null when the product is not found
	 *                                   or not rankable.
	 */
	public function upsell_product_detail( $product_id, array $args = array() ) {
		if ( null === $this->upsells ) {
			return null;
		}

		return $this->cached(
			'upsell_product',
			array_merge( $args, array( 'product_id' => (int) $product_id ) ),
			self::PRODUCTS_CACHE_TTL,
			function () use ( $product_id, $args ) {
				return $this->upsells->product_detail( (int) $product_id, $args );
			}
		);
	}

	/**
	 * Build the top-products upsell analytics rows.
	 *
	 * @param array<string, mixed> $args Optional: from, to, goal_id, limit.
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_upsell_analytics( array $args ) {
		global $wpdb;

		$events = Schema::table( 'upsell_events' );
		$posts  = $wpdb->posts;
		$limit  = max( 1, min( 100, isset( $args['limit'] ) ? (int) $args['limit'] : 20 ) );

		$where  = '1=1';
		$params = array();

		if ( ! empty( $args['goal_id'] ) ) {
			$where .= ' AND goal_id = %d';
			$params[] = (int) $args['goal_id'];
		}

		if ( ! empty( $args['from'] ) ) {
			$where .= ' AND created_at >= %s';
			$params[] = date( 'Y-m-d 00:00:00', strtotime( (string) $args['from'] ) );
		}

		if ( ! empty( $args['to'] ) ) {
			$where .= ' AND created_at <= %s';
			$params[] = date( 'Y-m-d 23:59:59', strtotime( (string) $args['to'] ) );
		}

		$impression = RevenueTracker::EVENT_UPSELL_IMPRESSION;
		$clicked    = RevenueTracker::EVENT_UPSELL_CLICKED;
		$added      = RevenueTracker::EVENT_UPSELL_ADDED;
		$order      = RevenueTracker::EVENT_UPSELL_ORDER;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id,
					SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ) AS impressions,
					SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ) AS clicks,
					SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ) AS adds,
					COUNT( DISTINCT CASE WHEN event_type = %s THEN order_id END ) AS orders,
					SUM( CASE WHEN event_type = %s THEN COALESCE(cart_value, 0) ELSE 0 END ) AS revenue
				 FROM {$events}
				 WHERE product_id IS NOT NULL AND {$where}
				 GROUP BY product_id
				 ORDER BY orders DESC, revenue DESC, impressions DESC
				 LIMIT %d",
				array_merge(
					array( $impression, $clicked, $added, $order, $order ),
					$params,
					array( $limit )
				)
			),
			ARRAY_A
		);

		$items = array();

		foreach ( (array) $rows as $row ) {
			$product_id = (int) $row['product_id'];
			$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

			if ( ! $product ) {
				continue;
			}

			$impressions = (int) $row['impressions'];
			$stats       = array(
				'impressions' => $impressions,
				'clicks'      => (int) $row['clicks'],
				'adds'        => (int) $row['adds'],
				'orders'      => (int) $row['orders'],
				'revenue'     => (float) $row['revenue'],
			);

			// Scored standalone (no cart context) through the same Phase
			// 33.5 component math — margin/profit included when the store
			// provides cost data, neutral otherwise.
			$score = null !== $this->upsells ? $this->upsells->score_product( $product ) : null;

			$items[] = array(
				'product_id'      => $product_id,
				'name'            => $product->get_name(),
				'impressions'     => $impressions,
				'clicks'          => $stats['clicks'],
				'adds'            => $stats['adds'],
				'orders'          => $stats['orders'],
				'revenue'         => round( $stats['revenue'], 4 ),
				'conversion_rate' => $impressions > 0 ? round( $stats['orders'] / $impressions, 4 ) : 0.0,
				'upsell_score'    => $score ? (float) $score['score'] : 0.0,
				// Per-unit estimated margin/profit — null when the store
				// stores no product costs (never invented, P33-31).
				'estimated_profit' => $score ? $score['estimated_profit'] : null,
				'profit_available' => $score ? (bool) $score['profit_available'] : false,
				'margin_pct'      => $score && isset( $score['factors']['margin_pct'] )
					? $score['factors']['margin_pct']
					: null,
			);
		}

		return $items;
	}

	/**
	 * The unavailable ranking payload shape (graceful degradation).
	 *
	 * @param string $reason Human-readable reason.
	 * @return array<string, mixed>
	 */
	protected function upsells_unavailable( $reason ) {
		return array(
			'available'   => false,
			'status'      => 'unavailable',
			'reason'      => $reason,
			'context'     => array(),
			'candidates'  => 0,
			'weights'     => array(),
			'recommendations' => array(),
			'generated_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Memoized read with a generation-versioned transient.
	 *
	 * @param string   $method  Cache bucket (part of the key).
	 * @param array    $args    Args (part of the key).
	 * @param int      $ttl     TTL in seconds.
	 * @param callable $compute Fresh-data callback.
	 * @return mixed
	 */
	protected function cached( $method, array $args, $ttl, callable $compute ) {
		if ( ! (bool) apply_filters( 'goalcart_revenue_cache_enabled', true ) ) {
			return $compute();
		}

		$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
		$key     = self::CACHE_PREFIX . $version . '_' . $method . '_' . md5( wp_json_encode( $args ) );

		$cached = get_transient( $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$data = $compute();

		set_transient( $key, $data, (int) apply_filters( 'goalcart_revenue_cache_ttl', $ttl ) );

		return $data;
	}

	/**
	 * The live single-day bucket for the trend's un-aggregated tail.
	 *
	 * Reuses the engine's daily_metrics() for a goal-scoped trend, or the
	 * store-wide funnel + summary when no goal is requested, then normalizes
	 * to the trend row shape.
	 *
	 * @param array<string, mixed> $args  Original trend args.
	 * @param string               $day   Date 'Y-m-d'.
	 * @return array<string, mixed>
	 */
	protected function live_day( array $args, $day ) {
		$scoped = array_merge( $args, array( 'from' => $day, 'to' => $day ) );

		if ( ! empty( $args['goal_id'] ) ) {
			$m = $this->engine->daily_metrics( (int) $args['goal_id'], $scoped );

			return array(
				'views'               => $m['views'],
				'progressions'        => $m['progressions'],
				'completions'         => $m['completions'],
				'conversions'         => $m['conversions'],
				'revenue'             => $m['revenue'],
				'incremental_revenue' => $m['incremental_revenue'],
				'reward_cost'         => $m['reward_cost'],
				'estimated_profit'    => null !== $m['estimated_profit'] ? $m['estimated_profit'] : 0.0,
			);
		}

		$summary = $this->engine->attribution_summary( $scoped );
		$funnel  = $summary['funnel'];

		return array(
			'views'               => $funnel['views'],
			'progressions'        => $funnel['progressed'],
			'completions'         => $funnel['completed'],
			'conversions'         => $funnel['converted'],
			'revenue'             => $summary['goal_influenced_revenue'],
			'incremental_revenue' => $summary['goal_driven_revenue'],
			'reward_cost'         => $summary['reward_cost'],
			'estimated_profit'    => null !== $summary['profit_impact'] ? $summary['profit_impact'] : 0.0,
		);
	}
}
