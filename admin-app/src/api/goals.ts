import { apiFetch } from './client';
import type { ApiEnvelope, Goal, GoalsList } from '../types';

export interface FetchGoalsParams {
  page?: number;
  per_page?: number;
  status?: '' | 'active' | 'inactive';
  search?: string;
}

export interface GoalsResult {
  items: Goal[];
  total: number;
  page: number;
  per_page: number;
}

/**
 * Fetch a paginated goal list from `GET /goalcart/v1/goals`.
 *
 * Reads the full envelope (unwrap=false) so the pagination totals are
 * available to the caller.
 */
export async function fetchGoals(params: FetchGoalsParams = {}): Promise<GoalsResult> {
  const query = new URLSearchParams();

  if (params.page && params.page > 1) {
    query.set('page', String(params.page));
  }
  if (params.per_page) {
    query.set('per_page', String(params.per_page));
  }
  if (params.status) {
    query.set('status', params.status);
  }
  if (params.search) {
    query.set('search', params.search);
  }

  const qs = query.toString();
  const envelope = await apiFetch<ApiEnvelope<GoalsList>>(`/goals${qs ? `?${qs}` : ''}`, {}, false);

  return {
    items: envelope.data.items,
    total: envelope.pagination?.total ?? envelope.data.items.length,
    page: envelope.pagination?.page ?? params.page ?? 1,
    per_page: envelope.pagination?.per_page ?? params.per_page ?? 20,
  };
}

/** Delete a goal via `DELETE /goalcart/v1/goals/{id}`. */
export async function deleteGoal(id: number): Promise<void> {
  await apiFetch<{ deleted: boolean }>(`/goals/${id}`, { method: 'DELETE' });
}
