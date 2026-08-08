import { apiFetch } from './client';
import type { AnalyticsPayload } from '../types';

/** Filters accepted by `GET /goalcart/v1/analytics` (Phase 17). */
export interface AnalyticsParams {
  from?: string;
  to?: string;
  campaign_id?: number;
  goal_id?: number;
  reward?: string;
  product_id?: number;
  limit?: number;
}

/**
 * Fetch the analytics dashboard payload from `GET /goalcart/v1/analytics`.
 *
 * Sends the active date range plus any campaign / goal / reward / product
 * filters; the backend responds with the summary KPIs, the daily trend
 * and the top campaigns / goals / suggested products lists.
 */
export async function fetchAnalytics(params: AnalyticsParams = {}): Promise<AnalyticsPayload> {
  const query = new URLSearchParams();

  if (params.from) {
    query.set('from', params.from);
  }
  if (params.to) {
    query.set('to', params.to);
  }
  if (params.campaign_id && params.campaign_id > 0) {
    query.set('campaign_id', String(params.campaign_id));
  }
  if (params.goal_id && params.goal_id > 0) {
    query.set('goal_id', String(params.goal_id));
  }
  if (params.reward) {
    query.set('reward', params.reward);
  }
  if (params.product_id && params.product_id > 0) {
    query.set('product_id', String(params.product_id));
  }
  if (params.limit) {
    query.set('limit', String(params.limit));
  }

  const qs = query.toString();

  return apiFetch<AnalyticsPayload>(`/analytics${qs ? `?${qs}` : ''}`);
}
