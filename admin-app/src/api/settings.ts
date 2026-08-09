import { apiFetch } from './client';
import type { GoalCartSettings, SettingsMeta } from '../types';

/** Fetch the persisted settings via `GET /goalcart/v1/settings`. */
export async function fetchSettings(): Promise<GoalCartSettings> {
  return apiFetch<GoalCartSettings>('/settings');
}

/**
 * Fetch settings plus the settings-page meta (developer-hooks reference,
 * debug log path) — the Settings page needs both (Phase 18 Advanced).
 */
export async function fetchSettingsEnvelope(): Promise<{ data: GoalCartSettings; meta: SettingsMeta }> {
  const payload = await apiFetch<{ data: GoalCartSettings; meta: SettingsMeta }>(
    '/settings',
    {},
    false
  );

  return {
    data: payload.data,
    meta: payload.meta ?? {},
  };
}

/** Save a partial settings object via `POST /goalcart/v1/settings`. */
export async function saveSettings(values: Partial<GoalCartSettings>): Promise<GoalCartSettings> {
  return apiFetch<GoalCartSettings>('/settings', {
    method: 'POST',
    body: JSON.stringify(values),
  });
}
