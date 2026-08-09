import { apiFetch } from './client';
import type { GoalCartSettings, SettingsMeta } from '../types';

/**
 * Fetch settings plus the settings-page meta (developer-hooks reference,
 * debug log path) via `GET /goalcart/v1/settings`.
 *
 * This is the ONLY settings fetch: the admin app caches the envelope
 * shape `{ data, meta }` under the `['settings']` query key, and every
 * consumer (Settings, Appearance, preview dialogs) reads `data` off it.
 * A raw-settings variant was removed because mixing shapes under one
 * query key made the Settings page read `data?.data` as undefined after
 * an Appearance save and fall back to defaults.
 */
export async function fetchSettingsEnvelope(): Promise<{
  data: GoalCartSettings;
  meta: SettingsMeta;
}> {
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
