<?php
/**
 * Smart mission recommendation engine for FaraCart (Phase 33.4).
 *
 * @package FaraCart
 */

namespace FaraCart\Analytics;

use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionRepository;
use FaraCart\Rewards\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Class MissionRecommendationEngine
 *
 * Phase 33.4 (Smart Mission Recommendation) — answers "what threshold should
 * this store use?" with a fully deterministic, explainable analysis of the
 * store's own order data. No LLM/AI in this phase: every number is computed
 * from the store's historical orders, shipping stats and (when present)
 * product margins.
 *
 * Inputs (P33-16; the admin page requires mission_id, everything else is
 * optional except the order window):
 *
 *  - AOV, median order value and the order-value distribution over a
 *    configurable window (7–180 days, default 90 — the spec's preferred
 *    30/90-day stability)
 *  - shipping cost (average + free share, from AttributionEngine)
 *  - product margins (sampled from the newest catalog products when the
 *    store stores cost data — never invented)
 *  - current mission performance (completion/conversion rates via the
 *    attribution funnel for the selected mission — the recommendation is
 *    always scoped to that mission, never "all missions")
 *
 * Algorithm (P33-22): candidate thresholds are generated around the
 * current AOV (0.9×–1.5× per the spec's candidate inputs) plus
 * shipping-aware additions (AOV + average shipping, median + average
 * shipping for free-shipping missions). Each candidate is scored on four
 * deterministic components, each normalized 0–100 and weighted:
 *
 *  - reachability 30% — share of orders within 30% below the threshold
 *                       (the orders that can plausibly close the gap)
 *  - distance     25% — how far the threshold stretches above the median
 *                       and AOV (too easy adds nothing, too far is unreachable)
 *  - economics    30% — reward cost vs the incremental margin the threshold
 *                       earns (neutral 50 when margin/reward data is missing)
 *  - history      15% — the store's existing mission completion performance
 *                       (neutral 50 without mission history)
 *
 * Every candidate carries a confidence score (data volume tier, order-value
 * consistency, margin/shipping availability), the raw scoring factors and a
 * plain-English reasons list — so the admin UI can always show *why* a
 * threshold was chosen (P33-24, P33-59) without trusting a black box.
 *
 * Graceful degradation (P33-51/52): fewer than the minimum orders
 * (default 50, filterable) → no recommendation, only a reason; no margin
 * data → profit estimates excluded and economics scored neutral (revenue
 * analytics still work); no shipping data → free-shipping economics scored
 * neutral with reduced confidence. The engine never changes a mission — the
 * admin must explicitly approve an application (P33-53).
 *
 * Extensibility (P33-60): the public recommend() contract is the frontend
 * contract — a future MLMissionRecommendationEngine can replace this class
 * behind the same payload shape without touching the REST layer or admin UI.
 */
final class MissionRecommendationEngine {

	/**
	 * Default analysis window (days) — the spec prefers 30/90-day data for
	 * stable recommendations.
	 *
	 * @var int
	 */
	const DEFAULT_WINDOW_DAYS = 90;

	/**
	 * Clamp bounds for the window.
	 *
	 * @var int
	 */
	const MIN_WINDOW_DAYS = 7;
	const MAX_WINDOW_DAYS = 180;

	/**
	 * Minimum orders for any recommendation (P33-52, filterable via
	 * faracart_recommendation_min_orders).
	 *
	 * @var int
	 */
	const MIN_ORDERS = 50;

	/**
	 * Candidate threshold multipliers around the current AOV (P33-22 —
	 * candidate generation inputs, not a hard-coded answer).
	 *
	 * @var float[]
	 */
	const CANDIDATE_MULTIPLIERS = array( 0.9, 1.0, 1.1, 1.2, 1.3, 1.4, 1.5 );

	/**
	 * Composite scoring weights (P33-22). Filterable per call through
	 * faracart_recommendation_weights.
	 *
	 * @var array<string, float>
	 */
	const SCORE_WEIGHTS = array(
		'reachability' => 0.30,
		'distance'     => 0.25,
		'economics'    => 0.30,
		'history'      => 0.15,
	);

	/**
	 * Number of newest products sampled for the margin analyzer.
	 *
	 * Newest-catalog products are a deterministic, representative sample of
	 * current store pricing; bounded so the analysis is one cheap query.
	 *
	 * @var int
	 */
	const MARGIN_SAMPLE_PRODUCTS = 20;

	/**
	 * Reach band width: orders within this fraction below the threshold are
	 * considered "within reach" (they would need to add less than that
	 * share of the threshold to complete the mission).
	 *
	 * @var float
	 */
	const REACH_BAND = 0.30;

	/**
	 * Volume tiers for confidence (P33-52 heuristics).
	 *
	 * @var array<string, int>
	 */
	const CONFIDENCE_TIERS = array(
		'basic'           => 55,
		'reliable'        => 75,
		'high_confidence' => 88,
	);

	/**
	 * Attribution engine (order values, shipping stats, mission funnel).
	 *
	 * @var AttributionEngine
	 */
	protected $engine;

	/**
	 * Reward cost / profit estimator (economics scoring).
	 *
	 * @var RewardCostEstimator
	 */
	protected $costs;

	/**
	 * Mission repository (mission lookup for reward type + history).
	 *
	 * @var MissionRepository
	 */
	protected $repository;

	/**
	 * Constructor.
	 *
	 * @param AttributionEngine   $engine     Attribution engine.
	 * @param RewardCostEstimator $costs      Reward cost estimator.
	 * @param MissionRepository      $repository Mission repository.
	 */
	public function __construct( AttributionEngine $engine, RewardCostEstimator $costs, MissionRepository $repository ) {
		$this->engine     = $engine;
		$this->costs      = $costs;
		$this->repository = $repository;
	}

	/**
	 * Whether smart mission recommendations are enabled.
	 *
	 * @return bool
	 */
	public function enabled() {
		/**
		 * Filter whether smart mission recommendations are on.
		 *
		 * @param bool                     $enabled Whether recommendations are enabled.
		 * @param MissionRecommendationEngine $engine  Engine instance.
		 */
		return (bool) apply_filters( 'faracart_recommendations_enabled', true, $this );
	}

	/**
	 * Recommend a mission threshold from the store's order data.
	 *
	 * Deterministic: the same store data always yields the same ranked
	 * candidates. Never modifies a mission — the caller must apply explicitly.
	 *
	 * @param array<string, mixed> $args Optional: mission_id (required by the
	 *                                   admin page — a supplied mission_id must
	 *                                   resolve, otherwise the payload is
	 *                                   unavailable), reward_type,
	 *                                   reward_value, reward_max_value,
	 *                                   reward_meta, window_days, from, to.
	 * @return array<string, mixed> The recommendation payload.
	 */
	public function recommend( array $args = array() ) {
		$unavailable = function ( $reason ) use ( $args ) {
			return array(
				'available'         => false,
				'mission_id'           => ! empty( $args['mission_id'] ) ? (int) $args['mission_id'] : null,
				'status'            => 'unavailable',
				'insufficient_reason' => $reason,
				'window_days'       => $this->window_days( $args ),
				'orders'            => 0,
				'data'              => null,
				'candidates'        => array(),
				'recommendation'    => null,
				'generated_at'      => current_time( 'mysql' ),
			);
		};

		if ( ! $this->enabled() ) {
			return $unavailable( __( 'Smart mission recommendations are disabled.', 'faracart' ) );
		}

		// Mission context (required by the admin Recommendations page): a
		// supplied mission_id must resolve to an existing mission — never
		// recommend for a mission that was deleted or archived after
		// selection, and never fall back to a store-wide "all missions"
		// context. Resolved early so an invalid mission fails before any
		// order analysis runs.
		$mission = $this->resolve_mission( $args );

		if ( ! empty( $args['mission_id'] ) && null === $mission ) {
			return $unavailable( __( 'The selected mission could not be found.', 'faracart' ) );
		}

		// Resolve the analysis window (explicit range wins over window_days).
		$from = ! empty( $args['from'] ) ? date( 'Y-m-d', strtotime( (string) $args['from'] ) ) : '';
		$to   = ! empty( $args['to'] ) ? date( 'Y-m-d', strtotime( (string) $args['to'] ) ) : '';

		if ( '' === $from && '' === $to ) {
			$days = $this->window_days( $args );
			$to   = date( 'Y-m-d', current_time( 'timestamp' ) );
			$from = date( 'Y-m-d', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );
		}

		$window = array( 'from' => $from, 'to' => $to );

		// --- Analyzers (each bounded; see method docs). ---
		$orders = $this->engine->store_order_values( $window );

		if ( ! $orders['available'] ) {
			return $unavailable( __( 'WooCommerce order data is not available — no recommendation can be computed.', 'faracart' ) );
		}

		$min_orders = (int) apply_filters( 'faracart_recommendation_min_orders', self::MIN_ORDERS );

		if ( $orders['count'] < max( 1, $min_orders ) ) {
			return $unavailable(
				sprintf(
					/* translators: 1: order count in the window, 2: minimum orders required. */
					__( 'Not enough order data for a reliable recommendation (%d orders in the window; at least %d required).', 'faracart' ),
					(int) $orders['count'],
					(int) $min_orders
				)
			);
		}

		$stats   = $this->order_statistics( $orders['totals'] );
		$shipping = $this->engine->shipping_stats( $window );
		$margin  = $this->margin_analyzer();
		$history = null !== $mission ? $this->mission_history( $mission->id(), $window ) : null;

		// Reward config for economics: the mission's own when supplied, else a
		// synthetic mission built from the request args.
		$reward_type = $this->reward_type( $args, $mission );
		$reward_mission = null !== $mission ? $mission : $this->synthetic_mission( $args, $reward_type );

		// --- Candidate thresholds (filterable generation inputs). ---
		$candidates = $this->candidate_thresholds( $stats['aov'], $stats['median'], $reward_type, $shipping );

		/**
		 * Filters the candidate thresholds before scoring.
		 *
		 * @param float[]               $candidates Threshold values.
		 * @param array<string, mixed>  $stats      aov, median, count.
		 * @param string|null           $reward_type Reward type being recommended for.
		 * @param array<string, mixed>  $shipping   Shipping stats.
		 */
		$candidates = (array) apply_filters( 'faracart_recommendation_candidates', $candidates, $stats, $reward_type, $shipping );

		if ( empty( $candidates ) ) {
			return $unavailable( __( 'No candidate thresholds could be generated from the order data.', 'faracart' ) );
		}

		// --- Score every candidate. ---
		$scored = array();

		foreach ( array_unique( $candidates ) as $threshold ) {
			$threshold = (float) $threshold;

			if ( $threshold <= 0 ) {
				continue;
			}

			$scored[] = $this->score_candidate(
				$threshold,
				$stats,
				$orders['totals'],
				$reward_type,
				$reward_mission,
				$shipping,
				$margin,
				$history
			);
		}

		// Rank: score desc; ties → lower threshold first (deterministic).
		usort( $scored, function ( $a, $b ) {
			if ( abs( $a['score'] - $b['score'] ) > 0.0001 ) {
				return $a['score'] > $b['score'] ? -1 : 1;
			}

			return $a['threshold'] <=> $b['threshold'];
		} );

		$payload = array(
			'available'         => true,
			'mission_id'           => null !== $mission ? (int) $mission->id() : null,
			'status'            => $this->data_tier( $stats['count'] ),
			'insufficient_reason' => null,
			'window_days'       => $this->window_days( $args ),
			'from'              => $from,
			'to'                => $to,
			'orders'            => $stats['count'],
			'data'              => array(
				'aov'          => $stats['aov'],
				'median'       => $stats['median'],
				'coefficient_of_variation' => $stats['cv'],
				'distribution' => $this->distribution( $orders['totals'], $stats['aov'] ),
				'shipping'     => array(
					'available'        => $shipping['available'],
					'average_shipping' => $shipping['available'] ? $shipping['average_shipping'] : null,
					'free_share'       => $shipping['available'] && $shipping['orders'] > 0
						? round( $shipping['free_shipping_orders'] / $shipping['orders'], 4 )
						: null,
				),
				'margin'       => $margin,
				'mission_history' => $history,
				'reward_type'  => $reward_type,
			),
			'candidates'        => $scored,
			'recommendation'    => ! empty( $scored ) ? $scored[0] : null,
			'generated_at'      => current_time( 'mysql' ),
		);

		/**
		 * Filters the full recommendation payload.
		 *
		 * @param array<string, mixed>   $payload Recommendation payload.
		 * @param array<string, mixed>   $args    Original request args.
		 * @param MissionRecommendationEngine $engine Engine instance.
		 */
		return (array) apply_filters( 'faracart_recommendations', $payload, $args, $this );
	}

	/**
	 * Score a single candidate threshold.
	 *
	 * @param float                $threshold   Candidate threshold.
	 * @param array<string, mixed> $stats       aov, median, count, cv.
	 * @param float[]              $totals      Store order totals.
	 * @param string|null          $reward_type Reward type.
	 * @param Mission|null            $reward_mission Mission carrying the reward config.
	 * @param array<string, mixed> $shipping    Shipping stats.
	 * @param array<string, mixed> $margin      Margin analysis.
	 * @param array<string, mixed>|null $history Mission funnel history.
	 * @return array<string, mixed> The scored candidate.
	 */
	protected function score_candidate( $threshold, array $stats, array $totals, $reward_type, $reward_mission, array $shipping, array $margin, $history ) {
		$aov    = (float) $stats['aov'];
		$median = (float) $stats['median'];

		// --- Reach analysis: shares of orders below/at/above the band. ---
		$already = 0;
		$reach   = 0;
		$below   = 0;

		$band_min = $threshold * ( 1.0 - self::REACH_BAND );

		foreach ( $totals as $total ) {
			if ( $total >= $threshold ) {
				$already++;
			} elseif ( $total >= $band_min ) {
				$reach++;
			} else {
				$below++;
			}
		}

		$count         = count( $totals );
		$reach_share   = $count > 0 ? $reach / $count : 0.0;
		$already_share = $count > 0 ? $already / $count : 0.0;

		// --- Component scores (each 0–100). ---
		$reachability = $this->reachability_score( $reach_share );
		$distance     = $this->distance_score( $threshold, $aov, $median );

		// --- Economics: reward cost vs incremental margin at the threshold. ---
		$reward_cost    = 0.0;
		$cost_available = true;

		if ( null !== $reward_mission && null !== $reward_type ) {
			$cost = $this->costs->estimate_reward_cost(
				$reward_mission,
				$threshold,
				array(
					'shipping_total' => $shipping['available'] ? $shipping['average_shipping'] : null,
				)
			);

			$reward_cost    = (float) $cost['estimated_cost'];
			$cost_available = (bool) $cost['available'];

			// Discount-type rewards without a configured value cannot be
			// costed (a 0 value would read as a free reward).
			if ( $this->reward_needs_value( $reward_type ) && null === $reward_mission->reward_value() ) {
				$cost_available = false;
			}
		} else {
			$cost_available = false;
		}

		$economics = $this->economics_score( $threshold, $aov, $reward_type, $reward_cost, $cost_available, $margin );
		$history_score = $this->history_score( $history );

		$weights = $this->score_weights();
		$score   = ( $reachability * $weights['reachability'] )
			+ ( $distance * $weights['distance'] )
			+ ( $economics * $weights['economics'] )
			+ ( $history_score * $weights['history'] );

		// --- Expected impact (derived from the real data, labeled estimated). ---
		$gap_pct        = $aov > 0 ? max( 0.0, ( $threshold - $aov ) / $aov ) : 0.0;
		$aov_uplift_pct = $gap_pct * $reach_share * 100.0;

		$completion_factor = null !== $history && $history['views'] > 0 && $history['completion_rate'] > 0
			? min( 1.2, $history['completion_rate'] / 0.25 )
			: 0.6;

		$expected_completion = min( 0.85, max( 0.05, $reach_share * $completion_factor ) );

		$profit = $this->costs->profit_impact(
			array(
				'incremental_revenue' => $reach_share * max( 0.0, $threshold - $aov ),
				'margin_pct'          => $margin['available'] ? $margin['average_margin_pct'] : null,
				'reward_cost'         => $cost_available ? $reward_cost : 0.0,
				'shipping_cost'       => null,
			)
		);

		// --- Transparency: raw factors + plain-English reasons. ---
		$factors = array(
			'threshold'            => round( $threshold, 4 ),
			'aov_ratio'            => $aov > 0 ? round( $threshold / $aov, 4 ) : null,
			'median_ratio'         => $median > 0 ? round( $threshold / $median, 4 ) : null,
			'reach_share'          => round( $reach_share, 4 ),
			'already_at_share'     => round( $already_share, 4 ),
			'reachability_score'   => round( $reachability, 2 ),
			'distance_score'       => round( $distance, 2 ),
			'economics_score'      => round( $economics, 2 ),
			'history_score'        => round( $history_score, 2 ),
			'reward_cost'          => $cost_available ? round( $reward_cost, 4 ) : null,
			'reward_cost_available'=> $cost_available,
			'margin_pct'           => $margin['available'] ? $margin['average_margin_pct'] : null,
		);

		return array(
			'threshold'              => round( $threshold, 4 ),
			'score'                  => round( $score, 2 ),
			'confidence'             => $this->confidence( $stats, $shipping, $margin, $history, $cost_available, $economics ),
			'expected_aov_impact'    => array(
				'low'     => round( max( 0.0, $aov_uplift_pct * 0.75 ), 2 ),
				'high'    => round( $aov_uplift_pct * 1.25, 2 ),
			),
			'expected_completion_rate' => round( $expected_completion, 4 ),
			'expected_profit'        => $profit['estimated_profit'],
			'expected_profit_available' => $profit['available'],
			'reachable_orders_pct'   => round( $reach_share * 100.0, 2 ),
			'reward_cost'            => $cost_available ? round( $reward_cost, 4 ) : null,
			'reasons'                => $this->reasons(
				$threshold,
				$stats,
				$reach_share,
				$reward_type,
				$reward_cost,
				$cost_available,
				$shipping,
				$margin,
				$history,
				$economics,
				$profit
			),
			'factors'                => $factors,
		);
	}

	/**
	 * Reachability score: peak when ~30% of orders sit within the reach
	 * band below the threshold; too few (nothing moves) or too many (the
	 * band is the bulk of the store) both score lower.
	 *
	 * @param float $reach_share Share of orders within the reach band.
	 * @return float 0–100.
	 */
	protected function reachability_score( $reach_share ) {
		$peak = 0.30;

		if ( $reach_share <= $peak ) {
			return $peak > 0 ? ( $reach_share / $peak ) * 100.0 : 0.0;
		}

		// Linear decay to zero at 2× the peak share (0.60).
		return max( 0.0, 100.0 - ( ( $reach_share - $peak ) / $peak ) * 100.0 );
	}

	/**
	 * Distance score: the threshold should be a plausible stretch above
	 * both the median order value and the AOV — too easy adds no revenue,
	 * too far is unreachable (P33-19/22).
	 *
	 * @param float $threshold Candidate threshold.
	 * @param float $aov       Store AOV.
	 * @param float $median    Store median order value.
	 * @return float 0–100.
	 */
	protected function distance_score( $threshold, $aov, $median ) {
		$band = function ( $ratio ) {
			if ( $ratio < 0.9 ) {
				return 20.0;
			}
			if ( $ratio < 1.1 ) {
				return 50.0;
			}
			if ( $ratio < 1.5 ) {
				return 100.0;
			}
			if ( $ratio < 1.8 ) {
				return 65.0;
			}
			if ( $ratio < 2.0 ) {
				return 40.0;
			}

			return 20.0;
		};

		$aov_score = $aov > 0 ? $band( $threshold / $aov ) : 40.0;
		$med_score = $median > 0 ? $band( $threshold / $median ) : $aov_score;

		return ( $aov_score + $med_score ) / 2.0;
	}

	/**
	 * Economics score: is the reward worth granting at this threshold?
	 *
	 * incremental margin at the threshold = (threshold − AOV) × margin%.
	 * When margin data or a costable reward is missing, the score is a
	 * neutral 50 (the engine scores what it can and lowers confidence) —
	 * never a guessed number.
	 *
	 * @param float                $threshold      Candidate threshold.
	 * @param float                $aov            Store AOV.
	 * @param string|null          $reward_type    Reward type.
	 * @param float                $reward_cost    Estimated reward cost.
	 * @param bool                 $cost_available Whether the reward cost model had its data.
	 * @param array<string, mixed> $margin         Margin analysis.
	 * @return float 0–100.
	 */
	protected function economics_score( $threshold, $aov, $reward_type, $reward_cost, $cost_available, array $margin ) {
		if ( ! $cost_available || ! $margin['available'] ) {
			return 50.0;
		}

		$incremental_margin = max( 0.0, $threshold - $aov ) * (float) $margin['average_margin_pct'];
		$net                = $incremental_margin - $reward_cost;

		if ( $reward_cost > 0 ) {
			if ( $net >= 2 * $reward_cost ) {
				return 100.0;
			}
			if ( $net >= $reward_cost ) {
				return 80.0;
			}
			if ( $net >= 0 ) {
				return 60.0;
			}
			if ( $net >= -$reward_cost ) {
				return 35.0;
			}

			return 15.0;
		}

		return $net >= 0 ? 80.0 : 40.0;
	}

	/**
	 * History score: the store's existing mission completion performance shows
	 * how attainable missions of this stretch are.
	 *
	 * @param array<string, mixed>|null $history Mission funnel history.
	 * @return float 0–100.
	 */
	protected function history_score( $history ) {
		if ( null === $history || (int) $history['views'] < 10 ) {
			return 50.0;
		}

		return min( 100.0, ( (float) $history['completion_rate'] / 0.35 ) * 100.0 );
	}

	/**
	 * Confidence score for a candidate (0–100).
	 *
	 * Base from the data-volume tier (P33-52), adjusted by the consistency
	 * of the order distribution (CV), the availability of margin/shipping
	 * data and the depth of mission history — clamped so it never presents
	 * product-level heuristics as statistical certainty.
	 *
	 * @param array<string, mixed> $stats          aov, median, count, cv.
	 * @param array<string, mixed> $shipping       Shipping stats.
	 * @param array<string, mixed> $margin         Margin analysis.
	 * @param array<string, mixed>|null $history   Mission funnel history.
	 * @param bool                 $cost_available Whether the reward cost was costable.
	 * @param float                $economics      Economics score (neutral 50 → data limited).
	 * @return int
	 */
	protected function confidence( array $stats, array $shipping, array $margin, $history, $cost_available, $economics ) {
		$base = self::CONFIDENCE_TIERS[ $this->data_tier( $stats['count'] ) ];

		if ( $margin['available'] ) {
			$base += 4;
		}

		if ( $shipping['available'] ) {
			$base += 4;
		}

		if ( null !== $history && (int) $history['views'] >= 50 ) {
			$base += 4;
		}

		if ( (float) $stats['cv'] <= 0.5 ) {
			$base += 6;
		} elseif ( (float) $stats['cv'] <= 1.0 ) {
			$base += 3;
		}

		// Neutral economics (missing margin/reward data) lowers certainty.
		if ( ! $cost_available || ! $margin['available'] || $economics <= 50.0001 ) {
			$base -= 5;
		}

		return max( 40, min( 95, $base ) );
	}

	/**
	 * Plain-English explanation bullets for a candidate.
	 *
 * Every bullet is derived from the actual computed factors — no
 * hard-coded claims. The bullet strings are translatable (the faracart
 * text domain) because the admin UI renders them directly.
 *
	 * @param float                $threshold      Candidate threshold.
	 * @param array<string, mixed> $stats          aov, median, count.
	 * @param float                $reach_share    Share of orders in reach.
	 * @param string|null          $reward_type    Reward type.
	 * @param float                $reward_cost    Estimated reward cost.
	 * @param bool                 $cost_available Whether the cost was costable.
	 * @param array<string, mixed> $shipping       Shipping stats.
	 * @param array<string, mixed> $margin         Margin analysis.
	 * @param array<string, mixed>|null $history   Mission funnel history.
	 * @param float                $economics      Economics score.
	 * @param array<string, mixed> $profit         Profit impact result.
	 * @return string[]
	 */
	protected function reasons( $threshold, array $stats, $reach_share, $reward_type, $reward_cost, $cost_available, array $shipping, array $margin, $history, $economics, array $profit ) {
		$reasons = array();

		if ( (float) $stats['median'] > 0 ) {
			$reasons[] = sprintf(
				/* translators: 1: percentage above the median, 2: formatted median order value. */
				__( 'This threshold is %s above the median order value (%s).', 'faracart' ),
				$this->fmt_pct( ( $threshold / (float) $stats['median'] ) * 100.0 - 100.0 ),
				$this->fmt_amount( (float) $stats['median'] )
			);
		}

		$reasons[] = sprintf(
			/* translators: 1: percentage of orders in reach, 2: reach band percentage. */
			__( '%s of existing orders are within reach of this threshold (below it by up to %s%%).', 'faracart' ),
			$this->fmt_pct( $reach_share * 100.0 ),
			(int) round( self::REACH_BAND * 100.0 )
		);

		$reasons[] = sprintf(
			/* translators: 1: formatted average order value, 2: order count. */
			__( 'Average order value is %s over %d orders.', 'faracart' ),
			$this->fmt_amount( (float) $stats['aov'] ),
			(int) $stats['count']
		);

		if ( Reward::TYPE_FREE_SHIPPING === $reward_type && $shipping['available'] ) {
			$reasons[] = sprintf(
				/* translators: 1: formatted average shipping cost. */
				__( 'Average shipping cost is %s — a free-shipping mission absorbs this cost.', 'faracart' ),
				$this->fmt_amount( (float) $shipping['average_shipping'] )
			);
		}

		if ( $cost_available ) {
			$reasons[] = sprintf(
				/* translators: 1: formatted estimated reward cost. */
				__( 'Estimated reward cost at this threshold is %s.', 'faracart' ),
				$this->fmt_amount( $reward_cost )
			);

			if ( $margin['available'] ) {
				$reasons[] = $economics >= 60.0
					? __( 'Incremental margin at this threshold covers the reward cost.', 'faracart' )
					: __( 'Incremental margin at this threshold does not fully cover the reward cost.', 'faracart' );
			}
		} elseif ( null !== $reward_type ) {
			$reasons[] = __( 'Reward cost cannot be estimated from the available data — economics scored neutral.', 'faracart' );
		}

		if ( ! $margin['available'] ) {
			$reasons[] = __( 'Product margin data is not available — profit estimates are excluded.', 'faracart' );
		} elseif ( ! $profit['available'] ) {
			$reasons[] = __( 'Profit impact could not be estimated for this threshold.', 'faracart' );
		}

		if ( null !== $history && (int) $history['views'] > 0 ) {
			$reasons[] = sprintf(
				/* translators: 1: historical completion rate. */
				__( 'Historical mission completion rate is %s.', 'faracart' ),
				$this->fmt_pct( (float) $history['completion_rate'] * 100.0 )
			);
		}

		return $reasons;
	}

	/**
	 * AOV / median / CV over the order totals (bounded in-memory math over
	 * the paginated scan — no extra queries).
	 *
	 * @param float[] $totals Order totals.
	 * @return array{aov: float, median: float, count: int, cv: float}
	 */
	protected function order_statistics( array $totals ) {
		$count = count( $totals );

		if ( 0 === $count ) {
			return array( 'aov' => 0.0, 'median' => 0.0, 'count' => 0, 'cv' => 0.0 );
		}

		$sum  = array_sum( $totals );
		$aov  = $sum / $count;

		$sorted = $totals;
		sort( $sorted, SORT_NUMERIC );

		$mid = (int) floor( $count / 2 );

		if ( 0 === $count % 2 ) {
			$median = ( $sorted[ $mid - 1 ] + $sorted[ $mid ] ) / 2.0;
		} else {
			$median = $sorted[ $mid ];
		}

		// Coefficient of variation (spread consistency).
		$variance = 0.0;

		foreach ( $totals as $total ) {
			$variance += pow( $total - $aov, 2 );
		}

		$variance = $count > 1 ? $variance / ( $count - 1 ) : 0.0;
		$cv       = $aov > 0 ? sqrt( $variance ) / $aov : 0.0;

		return array(
			'aov'    => round( $aov, 4 ),
			'median' => round( $median, 4 ),
			'count'  => $count,
			'cv'     => round( $cv, 6 ),
		);
	}

	/**
	 * Order-value distribution buckets relative to the AOV (currency
	 * agnostic, P33-19). Used to avoid recommending thresholds far beyond
	 * normal purchasing behavior.
	 *
	 * @param float[] $totals Order totals.
	 * @param float   $aov    Store AOV.
	 * @return array<int, array<string, mixed>>
	 */
	protected function distribution( array $totals, $aov ) {
		$count = count( $totals );

		if ( $count < 1 || $aov <= 0 ) {
			return array();
		}

		$buckets = array(
			array( 'label' => __( '< 0.5× AOV', 'faracart' ), 'min' => null, 'max' => 0.5, 'count' => 0 ),
			array( 'label' => __( '0.5–0.75× AOV', 'faracart' ), 'min' => 0.5, 'max' => 0.75, 'count' => 0 ),
			array( 'label' => __( '0.75–1.0× AOV', 'faracart' ), 'min' => 0.75, 'max' => 1.0, 'count' => 0 ),
			array( 'label' => __( '1.0–1.5× AOV', 'faracart' ), 'min' => 1.0, 'max' => 1.5, 'count' => 0 ),
			array( 'label' => __( '> 1.5× AOV', 'faracart' ), 'min' => 1.5, 'max' => null, 'count' => 0 ),
		);

		foreach ( $totals as $total ) {
			$ratio = $total / $aov;
			$slot  = 0;

			foreach ( $buckets as $index => $bucket ) {
				if ( null !== $bucket['min'] && $ratio < $bucket['min'] ) {
					break;
				}

				$slot = $index;
			}

			$buckets[ $slot ]['count']++;
		}

		foreach ( $buckets as $index => $bucket ) {
			$buckets[ $index ]['share'] = round( $bucket['count'] / $count, 4 );
			$buckets[ $index ]['count'] = (int) $bucket['count'];

			if ( null !== $bucket['min'] ) {
				$buckets[ $index ]['min'] = round( $bucket['min'] * $aov, 4 );
			}

			if ( null !== $bucket['max'] ) {
				$buckets[ $index ]['max'] = round( $bucket['max'] * $aov, 4 );
			}
		}

		return $buckets;
	}

	/**
	 * Margin analyzer: average margin % over the newest catalog products
	 * that carry cost data (P33-21). Unavailable when the store does not
	 * store product costs — never invented.
	 *
	 * @return array{available: bool, sampled: int, with_cost: int, average_margin_pct: float|null}
	 */
	protected function margin_analyzer() {
		$result = array(
			'available'          => false,
			'sampled'            => 0,
			'with_cost'          => 0,
			'average_margin_pct' => null,
		);

		if ( ! class_exists( '\\WC_Product_Query' ) ) {
			return $result;
		}

		$sample = (int) apply_filters( 'faracart_recommendation_margin_products', self::MARGIN_SAMPLE_PRODUCTS );

		$query = new \WC_Product_Query(
			array(
				'status' => 'publish',
				'limit'  => max( 1, $sample ),
				'orderby' => 'date',
				'order'  => 'DESC',
				'return' => 'ids',
			)
		);

		$ids = $query->get_products();

		if ( empty( $ids ) ) {
			return $result;
		}

		$result['sampled'] = count( $ids );
		$margins           = array();

		foreach ( $ids as $id ) {
			$margin = $this->costs->product_margin( (int) $id );

			if ( null === $margin ) {
				continue;
			}

			$margins[] = (float) $margin['margin_pct'];
		}

		$result['with_cost'] = count( $margins );

		if ( ! empty( $margins ) ) {
			$result['available']          = true;
			$result['average_margin_pct'] = round( array_sum( $margins ) / count( $margins ), 6 );
		}

		return $result;
	}

	/**
	 * The mission's current performance over the window — the "current mission"
	 * block of the recommendation detail (UPSELL_REFACTOR §9).
	 *
	 * Built from the same mission_metrics() the Mission Performance page reads,
	 * so the recommendation's "current" numbers always agree with the
	 * analytics: funnel counts + rates, current threshold, reward type,
	 * attributed/influenced sales, estimated profit and the upsell-assisted
	 * completions linkage. Null when the mission cannot be resolved.
	 *
	 * @param int                  $mission_id Mission id.
	 * @param array<string, mixed> $window  from/to.
	 * @return array<string, mixed>|null
	 */
	protected function mission_history( $mission_id, array $window ) {
		$metrics = $this->engine->mission_metrics( (int) $mission_id, $window );

		if ( null === $metrics ) {
			return null;
		}

		return array(
			'views'             => (int) $metrics['views'],
			'progressed'        => (int) $metrics['progressed'],
			'completed'         => (int) $metrics['completed'],
			'converted'         => (int) $metrics['converted'],
			'completion_rate'   => $metrics['completion_rate'],
			'conversion_rate'   => $metrics['conversion_rate'],
			'purchase_rate'     => $metrics['conversion_rate'],
			'current_target'    => (float) $metrics['target'],
			'reward_type'       => $metrics['reward_type'],
			'attributed_sales'  => (float) $metrics['attributed_revenue'],
			'influenced_sales'  => (float) $metrics['influenced_revenue'],
			'estimated_profit'  => $metrics['profit_impact'],
			'profit_available'  => (bool) $metrics['profit_available'],
			'upsell_assisted'   => isset( $metrics['upsell_assisted'] ) ? (int) $metrics['upsell_assisted'] : 0,
		);
	}

	/**
	 * Generate candidate thresholds (P33-22 candidate inputs).
	 *
	 * Base: AOV × the multiplier list. Shipping-aware additions for
	 * free-shipping missions (P33-20): AOV + average shipping and median +
	 * average shipping — the "the reward should cover the typical shipping
	 * cost" break-even points. Deduplicated and kept positive.
	 *
	 * @param float                $aov         Store AOV.
	 * @param float                $median      Store median.
	 * @param string|null          $reward_type Reward type.
	 * @param array<string, mixed> $shipping    Shipping stats.
	 * @return float[]
	 */
	protected function candidate_thresholds( $aov, $median, $reward_type, array $shipping ) {
		$candidates = array();

		if ( $aov <= 0 ) {
			return $candidates;
		}

		foreach ( self::CANDIDATE_MULTIPLIERS as $multiplier ) {
			$candidates[] = round( $aov * (float) $multiplier, 2 );
		}

		if ( Reward::TYPE_FREE_SHIPPING === $reward_type && $shipping['available'] && $shipping['average_shipping'] > 0 ) {
			$candidates[] = round( $aov + (float) $shipping['average_shipping'], 2 );

			if ( $median > 0 ) {
				$candidates[] = round( $median + (float) $shipping['average_shipping'], 2 );
			}
		}

		$candidates = array_values( array_unique( array_filter( $candidates, function ( $value ) {
			return $value > 0;
		} ) ) );

		sort( $candidates, SORT_NUMERIC );

		return $candidates;
	}

	/**
	 * Resolve the mission for a request (reward config + history source).
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
	 * The reward type to recommend for: explicit arg > the mission's own.
	 *
	 * @param array<string, mixed> $args Request args.
	 * @param Mission|null            $mission Resolved mission.
	 * @return string|null
	 */
	protected function reward_type( array $args, $mission ) {
		if ( ! empty( $args['reward_type'] ) ) {
			return (string) $args['reward_type'];
		}

		return null !== $mission ? $mission->reward_type() : null;
	}

	/**
	 * A synthetic mission carrying the request's reward config, for
	 * recommendations made without an existing mission.
	 *
	 * @param array<string, mixed> $args        Request args.
	 * @param string|null          $reward_type Reward type.
	 * @return Mission|null
	 */
	protected function synthetic_mission( array $args, $reward_type ) {
		if ( null === $reward_type ) {
			return null;
		}

		return new Mission(
			array(
				'reward_type'      => $reward_type,
				'reward_value'     => isset( $args['reward_value'] ) ? $args['reward_value'] : null,
				'reward_max_value' => isset( $args['reward_max_value'] ) ? $args['reward_max_value'] : null,
				'reward_meta'      => isset( $args['reward_meta'] ) && is_array( $args['reward_meta'] ) ? $args['reward_meta'] : array(),
			)
		);
	}

	/**
	 * Whether the reward type requires a configured value to be costable.
	 *
	 * @param string|null $reward_type Reward type.
	 * @return bool
	 */
	protected function reward_needs_value( $reward_type ) {
		return in_array(
			(string) $reward_type,
			array( Reward::TYPE_PERCENT_DISCOUNT, Reward::TYPE_FIXED_DISCOUNT, Reward::TYPE_COUPON ),
			true
		);
	}

	/**
	 * The scoring weights, filterable.
	 *
	 * @return array<string, float>
	 */
	protected function score_weights() {
		$weights = (array) apply_filters( 'faracart_recommendation_weights', self::SCORE_WEIGHTS );

		// Fall back per-key so a partial filter cannot zero a component.
		foreach ( self::SCORE_WEIGHTS as $key => $default ) {
			if ( ! isset( $weights[ $key ] ) || ! is_numeric( $weights[ $key ] ) ) {
				$weights[ $key ] = $default;
			} else {
				$weights[ $key ] = max( 0.0, (float) $weights[ $key ] );
			}
		}

		$total = array_sum( $weights );

		if ( $total <= 0 ) {
			return self::SCORE_WEIGHTS;
		}

		foreach ( $weights as $key => $value ) {
			$weights[ $key ] = $value / $total;
		}

		return $weights;
	}

	/**
	 * The data-sufficiency tier for an order count (P33-52, product-level
	 * heuristics — not statistical certainty).
	 *
	 * @param int $count Order count.
	 * @return string basic|reliable|high_confidence
	 */
	protected function data_tier( $count ) {
		if ( $count >= 1000 ) {
			return 'high_confidence';
		}

		if ( $count >= 200 ) {
			return 'reliable';
		}

		return 'basic';
	}

	/**
	 * Resolve + clamp the requested window length.
	 *
	 * @param array<string, mixed> $args Request args.
	 * @return int
	 */
	protected function window_days( array $args ) {
		$days = isset( $args['window_days'] ) ? (int) $args['window_days'] : self::DEFAULT_WINDOW_DAYS;

		return max( self::MIN_WINDOW_DAYS, min( self::MAX_WINDOW_DAYS, $days ) );
	}

	/**
	 * Format a percentage (with sign handling for the reason strings).
	 *
	 * @param float $pct Percentage value.
	 * @return string
	 */
	protected function fmt_pct( $pct ) {
		$pct = round( (float) $pct, 1 );
		$prefix = $pct > 0 ? '+' : '';

		return $prefix . number_format( $pct, 1, '.', ',' ) . '%';
	}

	/**
	 * Format an amount for the reason strings.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	protected function fmt_amount( $amount ) {
		return number_format( round( (float) $amount, 0 ), 0, '.', ',' );
	}
}
