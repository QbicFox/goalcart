import { apiFetch } from './client';
import type { ApiEnvelope, Mission, MissionInput } from '../types';

export interface FetchMissionsParams {
  page?: number;
  per_page?: number;
  status?: '' | 'active' | 'inactive';
  search?: string;
}

export interface MissionsResult {
  items: Mission[];
  total: number;
  page: number;
  per_page: number;
}

/**
 * Fetch a paginated mission list from `GET /faracart/v1/missions`.
 *
 * Reads the full envelope (unwrap=false) so the pagination totals are
 * available to the caller.
 */
export async function fetchMissions(params: FetchMissionsParams = {}): Promise<MissionsResult> {
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
  const envelope = await apiFetch<ApiEnvelope<Mission[]>>(`/missions${qs ? `?${qs}` : ''}`, {}, false);

  // The list endpoint returns the missions directly in `data` (a plain
  // array, not an `{ items }` wrapper) — see MissionsController::handle_index.
  const items = envelope.data;

  return {
    items,
    total: envelope.pagination?.total ?? items.length,
    page: envelope.pagination?.page ?? params.page ?? 1,
    per_page: envelope.pagination?.per_page ?? params.per_page ?? 20,
  };
}

/** Fetch a single mission via `GET /faracart/v1/missions/{id}`. */
export async function fetchMission(id: number): Promise<Mission> {
  return apiFetch<Mission>(`/missions/${id}`);
}

/** Create a mission via `POST /faracart/v1/missions`. */
export async function createMission(input: MissionInput): Promise<Mission> {
  return apiFetch<Mission>('/missions', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

/** Update a mission (partial) via `PUT /faracart/v1/missions/{id}`. */
export async function updateMission(id: number, input: Partial<MissionInput>): Promise<Mission> {
  return apiFetch<Mission>(`/missions/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  });
}

/** Delete a mission via `DELETE /faracart/v1/missions/{id}`. */
export async function deleteMission(id: number): Promise<void> {
  await apiFetch<{ deleted: boolean }>(`/missions/${id}`, { method: 'DELETE' });
}

/** Duplicate a mission via `POST /faracart/v1/missions/{id}/duplicate`. */
export async function duplicateMission(id: number): Promise<Mission> {
  return apiFetch<Mission>(`/missions/${id}/duplicate`, { method: 'POST' });
}
