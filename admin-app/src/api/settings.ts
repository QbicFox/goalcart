import { apiFetch } from './client';
import type { FaraCartSettings, SettingsMeta } from '../types';

/**
 * Fetch settings plus the settings-page meta (developer-hooks reference,
 * debug log path) via `GET /faracart/v1/settings`.
 *
 * This is the ONLY settings fetch: the admin app caches the envelope
 * shape `{ data, meta }` under the `['settings']` query key, and every
 * consumer (Settings, Appearance, preview dialogs) reads `data` off it.
 * A raw-settings variant was removed because mixing shapes under one
 * query key made the Settings page read `data?.data` as undefined after
 * an Appearance save and fall back to defaults.
 */
export async function fetchSettingsEnvelope(): Promise<{
  data: FaraCartSettings;
  meta: SettingsMeta;
}> {
  const payload = await apiFetch<{ data: FaraCartSettings; meta: SettingsMeta }>(
    '/settings',
    {},
    false
  );

  return {
    data: payload.data,
    meta: payload.meta ?? {},
  };
}

/** Save a partial settings object via `POST /faracart/v1/settings`. */
export async function saveSettings(values: Partial<FaraCartSettings>): Promise<FaraCartSettings> {
  return apiFetch<FaraCartSettings>('/settings', {
    method: 'POST',
    body: JSON.stringify(values),
  });
}
