import { __ } from '@wordpress/i18n';
import type { FloatingPosition } from '../../types';

export type { FloatingPosition };

/**
 * Shared floating-widget model: defaults, position presets and the
 * device resolution the admin form uses.
 *
 * The position preset is the ONLY position control — it picks a physical
 * side/edge (the storefront always positions with physical left/right,
 * never logical start/end, so the admin's choice keeps its visual result
 * in RTL). The drawer always opens toward the screen center from the
 * chosen preset; there is no separate direction setting.
 */

/** Defaults — keep in sync with PHP `Settings::defaults()` (floating_*). */
export const FLOATING_DEFAULTS = {
  enabled: false,
  desktop: { preset: 'bottom-right', offset_x: 20, offset_y: 80 },
  mobile: { preset: 'bottom-right', offset_x: 16, offset_y: 100 },
  mobileUseDesktop: true,
  showDesktop: true,
  showMobile: true,
  buttonSize: 56,
  animation: true,
  icon: '',
  label: '',
} as const;

/** The offset bounds (same clamp as the PHP sanitizer). */
export const FLOATING_OFFSET_MAX = 200;

/** One selectable position preset. */
export interface FloatingPreset {
  /** Stable machine id. */
  value: FloatingPosition['preset'];
  label: string;
}

/** The six predefined position presets (the only position control). */
export const FLOATING_PRESETS: FloatingPreset[] = [
  { value: 'bottom-right', label: __('Bottom right', 'faracart') },
  { value: 'bottom-left', label: __('Bottom left', 'faracart') },
  { value: 'center-right', label: __('Center right', 'faracart') },
  { value: 'center-left', label: __('Center left', 'faracart') },
  { value: 'top-right', label: __('Top right', 'faracart') },
  { value: 'top-left', label: __('Top left', 'faracart') },
];

/** The device scopes the position settings cover. */
export type FloatingDevice = 'desktop' | 'mobile';

/**
 * The floating-widget form/persisted shape (the `floating_*` settings
 * keys). Partial — helpers always normalize missing values to the
 * documented defaults.
 */
export interface FloatingDraft {
  floating_enabled?: boolean;
  floating_desktop?: Partial<FloatingPosition>;
  floating_mobile?: Partial<FloatingPosition>;
  floating_mobile_use_desktop?: boolean;
  floating_show_desktop?: boolean;
  floating_show_mobile?: boolean;
  floating_button_size?: number;
  floating_animation?: boolean;
  floating_icon?: string;
  floating_label?: string;
}

/**
 * Normalize a raw position object (server-served or form state) so a
 * missing/malformed value can never reach the storefront: the preset
 * falls back to the documented default, offsets are clamped to the PHP
 * bounds.
 */
export function normalizeFloatingPosition(
  value: Partial<FloatingPosition> | undefined,
  fallback: FloatingPosition
): FloatingPosition {
  return {
    preset: isPreset(value?.preset) ? value.preset : fallback.preset,
    offset_x: clampOffset(value?.offset_x, fallback.offset_x),
    offset_y: clampOffset(value?.offset_y, fallback.offset_y),
  };
}

function isPreset(value: unknown): value is FloatingPosition['preset'] {
  return (
    value === 'top-left' ||
    value === 'top-right' ||
    value === 'center-left' ||
    value === 'center-right' ||
    value === 'bottom-left' ||
    value === 'bottom-right'
  );
}

function clampOffset(value: unknown, fallback: number): number {
  const number = Number(value);
  return Number.isFinite(number)
    ? Math.min(FLOATING_OFFSET_MAX, Math.max(0, Math.round(number)))
    : fallback;
}

/**
 * The resolved device position for a floating-widget draft: mobile reuses
 * the desktop position unless floating_mobile_use_desktop is off.
 */
export function resolveFloatingPosition(draft: FloatingDraft): {
  desktop: FloatingPosition;
  mobile: FloatingPosition;
} {
  const desktop = normalizeFloatingPosition(draft.floating_desktop, FLOATING_DEFAULTS.desktop);
  const mobile = draft.floating_mobile_use_desktop
    ? desktop
    : normalizeFloatingPosition(draft.floating_mobile, FLOATING_DEFAULTS.mobile);

  return { desktop, mobile };
}

