import type { TemplateSettingsValue } from '../types';

/**
 * Typed readers for a resolved template settings map. The backend always
 * returns schema-conformant values, but a map can still miss a key (e.g.
 * during an in-progress form edit), so every reader falls back to the
 * caller-provided default.
 */

export function str(
  settings: TemplateSettingsValue | undefined,
  key: string,
  fallback: string
): string {
  const value = settings?.[key];
  return typeof value === 'string' && value !== '' ? value : fallback;
}

export function num(
  settings: TemplateSettingsValue | undefined,
  key: string,
  fallback: number
): number {
  const value = settings?.[key];
  return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

export function bool(
  settings: TemplateSettingsValue | undefined,
  key: string,
  fallback: boolean
): boolean {
  const value = settings?.[key];
  return typeof value === 'boolean' ? value : fallback;
}
