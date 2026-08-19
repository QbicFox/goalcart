import { apiFetch } from './client';
import type {
  CostCoveragePayload,
  MissionPerformancePayload,
  MissionRecommendationsPayload,
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
  mission_id?: number;
}

/** Params for the upsell analytics list (analytics mode). */
export interface UpsellAnalyticsParams extends RevenueWindowParams {
  limit?: number;
}

/** Params for `GET /faracart/v1/revenue/mission-recommendations`. */
export interface MissionRecommendationsParams {
  mission_id?: number;
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
 * Revenue Overview payload from `GET /faracart/v1/revenue/overview`
 *: attribution summary + incremental cart value + AOV +
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
 * Attribution Dashboard payload from `GET /faracart/v1/revenue/attribution`
 *: the overview minus the trend series.
 */
export async function fetchRevenueAttribution(
  params: RevenueWindowParams = {}
): Promise<RevenueAttributionPayload> {
  return apiFetch<RevenueAttributionPayload>(
    `/revenue/attribution${toQuery(params)}`
  );
}

/**
 * Mission Performance rows from `GET /faracart/v1/revenue/missions`
 *: per-mission funnel + revenue metrics.
 */
export async function fetchMissionPerformance(
  params: RevenueWindowParams = {}
): Promise<MissionPerformancePayload> {
  return apiFetch<MissionPerformancePayload>(`/revenue/missions${toQuery(params)}`);
}

/**
 * Top-products upsell analytics table from
 * `GET /faracart/v1/revenue/upsells?analytics=1`.
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
 * `GET /faracart/v1/revenue/upsells/{product_id}`.
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
 * Smart mission recommendation payload from
 * `GET /faracart/v1/revenue/mission-recommendations`.
 */
export async function fetchMissionRecommendations(
  params: MissionRecommendationsParams = {}
): Promise<MissionRecommendationsPayload> {
  return apiFetch<MissionRecommendationsPayload>(
    `/revenue/mission-recommendations${toQuery(params)}`
  );
}

/**
 * Apply a recommended threshold to a mission (UPSELL_REFACTOR §10/§41) via
 * `POST /faracart/v1/revenue/mission-recommendations/apply`. This is the only
 * write path for Recommendations — it changes only the mission's target,
 * records the feedback-loop event and invalidates the revenue caches.
 */
export async function applyMissionRecommendation(
  missionId: number,
  threshold: number
): Promise<RecommendationApplyResult> {
  return apiFetch<RecommendationApplyResult>(
    '/revenue/mission-recommendations/apply',
    {
      method: 'POST',
      body: JSON.stringify({ mission_id: missionId, threshold }),
    }
  );
}

/**
 * Product-cost coverage from
 * `GET /faracart/v1/revenue/cost-coverage` (UPSELL_REFACTOR §25/§46):
 * how much of the catalog carries cost data, so the UI can explain why
 * profit estimates are unavailable and how to fix it.
 */
export async function fetchCostCoverage(): Promise<CostCoveragePayload> {
  return apiFetch<CostCoveragePayload>('/revenue/cost-coverage');
}
