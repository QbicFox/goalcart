import { apiFetch } from './client';
import type {
  CostCoveragePayload,
  GoalPerformancePayload,
  GoalRecommendationsPayload,
  RecommendationApplyResult,
  RevenueAttributionPayload,
  RevenueOverviewPayload,
  UpsellAnalyticsRow,
  UpsellRecommendation,
} from '../types';

/** Shared window filters accepted by the revenue routes. */
export interface RevenueWindowParams {
  from?: string;
  to?: string;
  goal_id?: number;
}

/** Params for the upsell analytics list (Phase 33.5 analytics mode). */
export interface UpsellAnalyticsParams extends RevenueWindowParams {
  limit?: number;
}

/** Params for `GET /goalcart/v1/revenue/goal-recommendations`. */
export interface GoalRecommendationsParams {
  goal_id?: number;
  reward_type?: string;
  reward_value?: number;
  reward_max_value?: number;
  window_days?: number;
  from?: string;
  to?: string;
}

/** Build a query string from the present params (mirrors analytics.ts). */
function toQuery(params: object): string {
  const query = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== '' && Number(value) !== 0) {
      query.set(key, String(value));
    }
  });

  const qs = query.toString();

  return qs ? `?${qs}` : '';
}

/**
 * Revenue Overview payload from `GET /goalcart/v1/revenue/overview`
 * (Phase 33.6): attribution summary + incremental cart value + AOV +
 * shipping + the daily trend series over the window.
 */
export async function fetchRevenueOverview(
  params: RevenueWindowParams = {}
): Promise<RevenueOverviewPayload> {
  return apiFetch<RevenueOverviewPayload>(
    `/revenue/overview${toQuery(params)}`
  );
}

/**
 * Attribution Dashboard payload from `GET /goalcart/v1/revenue/attribution`
 * (Phase 33.6): the overview minus the trend series.
 */
export async function fetchRevenueAttribution(
  params: RevenueWindowParams = {}
): Promise<RevenueAttributionPayload> {
  return apiFetch<RevenueAttributionPayload>(
    `/revenue/attribution${toQuery(params)}`
  );
}

/**
 * Goal Performance rows from `GET /goalcart/v1/revenue/goals`
 * (Phase 33.6): per-goal funnel + revenue metrics.
 */
export async function fetchGoalPerformance(
  params: RevenueWindowParams = {}
): Promise<GoalPerformancePayload> {
  return apiFetch<GoalPerformancePayload>(`/revenue/goals${toQuery(params)}`);
}

/**
 * Top-products upsell analytics table from
 * `GET /goalcart/v1/revenue/upsells?analytics=1` (Phase 33.5/33.6).
 */
export async function fetchUpsellAnalytics(
  params: UpsellAnalyticsParams = {}
): Promise<UpsellAnalyticsRow[]> {
  return apiFetch<UpsellAnalyticsRow[]>(
    `/revenue/upsells${toQuery({ ...params, analytics: 1 })}`
  );
}

/**
 * One product's upsell score breakdown + historical stats from
 * `GET /goalcart/v1/revenue/upsells/{product_id}` (Phase 33.5/33.6).
 */
export async function fetchUpsellProduct(
  productId: number,
  params: RevenueWindowParams = {}
): Promise<UpsellRecommendation | null> {
  return apiFetch<UpsellRecommendation | null>(
    `/revenue/upsells/${productId}${toQuery(params)}`
  );
}

/**
 * Smart goal recommendation payload from
 * `GET /goalcart/v1/revenue/goal-recommendations` (Phase 33.4/33.6).
 */
export async function fetchGoalRecommendations(
  params: GoalRecommendationsParams = {}
): Promise<GoalRecommendationsPayload> {
  return apiFetch<GoalRecommendationsPayload>(
    `/revenue/goal-recommendations${toQuery(params)}`
  );
}

/**
 * Apply a recommended threshold to a goal (UPSELL_REFACTOR §10/§41) via
 * `POST /goalcart/v1/revenue/goal-recommendations/apply`. This is the only
 * write path for Recommendations — it changes only the goal's target,
 * records the feedback-loop event and invalidates the revenue caches.
 */
export async function applyGoalRecommendation(
  goalId: number,
  threshold: number
): Promise<RecommendationApplyResult> {
  return apiFetch<RecommendationApplyResult>(
    '/revenue/goal-recommendations/apply',
    {
      method: 'POST',
      body: JSON.stringify({ goal_id: goalId, threshold }),
    }
  );
}

/**
 * Product-cost coverage from
 * `GET /goalcart/v1/revenue/cost-coverage` (UPSELL_REFACTOR §25/§46):
 * how much of the catalog carries cost data, so the UI can explain why
 * profit estimates are unavailable and how to fix it.
 */
export async function fetchCostCoverage(): Promise<CostCoveragePayload> {
  return apiFetch<CostCoveragePayload>('/revenue/cost-coverage');
}
