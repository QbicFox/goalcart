import { apiFetch } from './client';
import type { GoalCartSettings } from '../types';

/** Fetch the persisted settings via `GET /goalcart/v1/settings`. */
export async function fetchSettings(): Promise<GoalCartSettings> {
  return apiFetch<GoalCartSettings>('/settings');
}

/** Save a partial settings object via `POST /goalcart/v1/settings`. */
export async function saveSettings(values: Partial<GoalCartSettings>): Promise<GoalCartSettings> {
  return apiFetch<GoalCartSettings>('/settings', {
    method: 'POST',
    body: JSON.stringify(values),
  });
}
