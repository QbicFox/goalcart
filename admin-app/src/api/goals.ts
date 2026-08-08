import { apiFetch } from './client';
import type { ApiEnvelope, Goal, GoalInput } from '../types';

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
  const envelope = await apiFetch<ApiEnvelope<Goal[]>>(`/goals${qs ? `?${qs}` : ''}`, {}, false);

  // The list endpoint returns the goals directly in `data` (a plain
  // array, not an `{ items }` wrapper) — see GoalsController::handle_index.
  const items = envelope.data;

  return {
    items,
    total: envelope.pagination?.total ?? items.length,
    page: envelope.pagination?.page ?? params.page ?? 1,
    per_page: envelope.pagination?.per_page ?? params.per_page ?? 20,
  };
}

/** Fetch a single goal via `GET /goalcart/v1/goals/{id}`. */
export async function fetchGoal(id: number): Promise<Goal> {
  return apiFetch<Goal>(`/goals/${id}`);
}

/** Create a goal via `POST /goalcart/v1/goals`. */
export async function createGoal(input: GoalInput): Promise<Goal> {
  return apiFetch<Goal>('/goals', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

/** Update a goal (partial) via `PUT /goalcart/v1/goals/{id}`. */
export async function updateGoal(id: number, input: Partial<GoalInput>): Promise<Goal> {
  return apiFetch<Goal>(`/goals/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  });
}

/** Delete a goal via `DELETE /goalcart/v1/goals/{id}`. */
export async function deleteGoal(id: number): Promise<void> {
  await apiFetch<{ deleted: boolean }>(`/goals/${id}`, { method: 'DELETE' });
}

/** Duplicate a goal via `POST /goalcart/v1/goals/{id}/duplicate`. */
export async function duplicateGoal(id: number): Promise<Goal> {
  return apiFetch<Goal>(`/goals/${id}/duplicate`, { method: 'POST' });
}
