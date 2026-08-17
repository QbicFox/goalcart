import { __ } from '@wordpress/i18n';
import type { FloatingPosition } from '../../types';

export type { FloatingPosition };

/**
 * Shared floating-widget model: defaults, position presets and the
 * device resolution the admin form and the live preview both use.
 *
 * The position axes are PHYSICAL sides (left/right × top/center/bottom) —
 * the admin's chosen side must keep its visual result in RTL, so the
 * storefront (and the preview) always position with physical left/right,
 * never logical start/end.
 */

/** Defaults — keep in sync with PHP `Settings::defaults()` (floating_*). */
export const FLOATING_DEFAULTS = {
  enabled: false,
  desktop: { horizontal: 'right', vertical: 'bottom', offset_x: 20, offset_y: 80 },
  mobile: { horizontal: 'right', vertical: 'bottom', offset_x: 16, offset_y: 100 },
  mobileUseDesktop: true,
  showDesktop: true,
  showMobile: true,
  buttonSize: 56,
  animation: true,
  drawerDirection: 'auto',
  icon: '',
  label: '',
} as const;

/** The button size bounds (same clamp as the PHP sanitizer). */
export const FLOATING_BUTTON_SIZE_MIN = 32;
export const FLOATING_BUTTON_SIZE_MAX = 96;

/** The offset bounds (same clamp as the PHP sanitizer). */
export const FLOATING_OFFSET_MAX = 200;

/** One selectable position preset. */
export interface FloatingPreset {
  /** Stable machine id ('' = the current position is not a named preset). */
  value:
    '' | 'bottom-right' | 'bottom-left' | 'center-right' | 'center-left' | 'top-right' | 'top-left';
  label: string;
  horizontal: 'left' | 'right';
  vertical: 'top' | 'center' | 'bottom';
}

/** The six predefined position presets (Section 13 of the spec). */
export const FLOATING_PRESETS: FloatingPreset[] = [
  {
    value: 'bottom-right',
    label: __('Bottom right', 'faracart'),
    horizontal: 'right',
    vertical: 'bottom',
  },
  {
    value: 'bottom-left',
    label: __('Bottom left', 'faracart'),
    horizontal: 'left',
    vertical: 'bottom',
  },
  {
    value: 'center-right',
    label: __('Center right', 'faracart'),
    horizontal: 'right',
    vertical: 'center',
  },
  {
    value: 'center-left',
    label: __('Center left', 'faracart'),
    horizontal: 'left',
    vertical: 'center',
  },
  { value: 'top-right', label: __('Top right', 'faracart'), horizontal: 'right', vertical: 'top' },
  { value: 'top-left', label: __('Top left', 'faracart'), horizontal: 'left', vertical: 'top' },
];

/** The device scopes the position settings cover. */
export type FloatingDevice = 'desktop' | 'mobile';

/**
 * The floating-widget form/persisted shape (the `floating_*` settings
 * keys). Partial — the preview and helpers always normalize missing
 * values to the documented defaults.
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
  floating_drawer_direction?: string;
  floating_icon?: string;
  floating_label?: string;
}

/**
 * Normalize a raw position object (server-served or form state) so a
 * missing/malformed value can never reach the preview: enums fall back to
 * the documented defaults, offsets are clamped to the PHP bounds.
 */
export function normalizeFloatingPosition(
  value: Partial<FloatingPosition> | undefined,
  fallback: FloatingPosition
): FloatingPosition {
  const horizontal =
    value?.horizontal === 'left' || value?.horizontal === 'right'
      ? value.horizontal
      : fallback.horizontal;
  const vertical =
    value?.vertical === 'top' || value?.vertical === 'center' || value?.vertical === 'bottom'
      ? value.vertical
      : fallback.vertical;

  return {
    horizontal,
    vertical,
    offset_x: clampOffset(value?.offset_x, fallback.offset_x),
    offset_y: clampOffset(value?.offset_y, fallback.offset_y),
  };
}

function clampOffset(value: unknown, fallback: number): number {
  const number = Number(value);
  return Number.isFinite(number)
    ? Math.min(FLOATING_OFFSET_MAX, Math.max(0, Math.round(number)))
    : fallback;
}

/** The named preset matching a position, or '' when it is a custom position. */
export function presetForPosition(position: FloatingPosition): FloatingPreset['value'] {
  const preset = FLOATING_PRESETS.find(
    (candidate) =>
      candidate.horizontal === position.horizontal && candidate.vertical === position.vertical
  );

  return preset ? preset.value : '';
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

/**
 * The resolved display options for a floating-widget draft, with safe
 * defaults so the preview can never read undefined.
 */
export function resolveFloatingDisplay(draft: FloatingDraft): {
  enabled: boolean;
  showDesktop: boolean;
  showMobile: boolean;
  buttonSize: number;
  animation: boolean;
  drawerDirection: 'auto' | 'left' | 'right' | 'up' | 'down';
  icon: string;
  label: string;
} {
  const size = Math.round(Number(draft.floating_button_size) || FLOATING_DEFAULTS.buttonSize);

  const direction: 'auto' | 'left' | 'right' | 'up' | 'down' =
    draft.floating_drawer_direction === 'left' ||
    draft.floating_drawer_direction === 'right' ||
    draft.floating_drawer_direction === 'up' ||
    draft.floating_drawer_direction === 'down'
      ? draft.floating_drawer_direction
      : 'auto';

  return {
    enabled: Boolean(draft.floating_enabled),
    showDesktop: draft.floating_show_desktop !== false,
    showMobile: draft.floating_show_mobile !== false,
    buttonSize: Math.min(FLOATING_BUTTON_SIZE_MAX, Math.max(FLOATING_BUTTON_SIZE_MIN, size)),
    animation: draft.floating_animation !== false,
    drawerDirection: direction,
    icon: String(draft.floating_icon ?? ''),
    label: String(draft.floating_label ?? ''),
  };
}

/** The default button glyph when no custom icon is configured. */
export const FLOATING_DEFAULT_ICON = '🛒';

/**
 * The drawer opening direction the storefront resolves for a button
 * anchor (physical rect in px). 'auto' opens toward the screen center
 * horizontally when the panel fits — it never points off-screen — and an
 * explicit direction that has no room flips to the side with the most
 * free space (the opposite side first, then the vertical axis). This is
 * the safe-positioning guard that keeps the drawer inside the viewport.
 */
export function resolveDrawerDirection(
  rect: { left: number; top: number; right: number; bottom: number },
  requested: 'auto' | 'left' | 'right' | 'up' | 'down',
  viewport: { width: number; height: number },
  options: { minWidth?: number; minHeight?: number } = {}
): 'left' | 'right' | 'up' | 'down' {
  const { width, height } = viewport;
  const minWidth = options.minWidth ?? 280;
  const minHeight = options.minHeight ?? 240;

  const free: Record<'left' | 'right' | 'up' | 'down', number> = {
    left: rect.left,
    right: width - rect.right,
    up: rect.top,
    down: height - rect.bottom,
  };

  function best() {
    let direction: 'left' | 'right' | 'up' | 'down' = 'left';
    let space = -1;

    for (const candidate of ['left', 'right', 'up', 'down'] as const) {
      if (free[candidate] > space) {
        direction = candidate;
        space = free[candidate];
      }
    }

    return direction;
  }

  if (requested === 'left' || requested === 'right') {
    if (free[requested] >= minWidth) {
      return requested;
    }

    const opposite: 'left' | 'right' = requested === 'left' ? 'right' : 'left';
    if (free[opposite] >= minWidth) {
      return opposite;
    }

    return free.up >= free.down ? 'up' : 'down';
  }

  if (requested === 'up' || requested === 'down') {
    if (free[requested] >= minHeight) {
      return requested;
    }

    const opposite: 'up' | 'down' = requested === 'up' ? 'down' : 'up';
    if (free[opposite] >= minHeight) {
      return opposite;
    }

    return free.left >= free.right ? 'left' : 'right';
  }

  // auto: toward the screen center horizontally when the panel fits,
  // otherwise the vertical side with the most room, otherwise the single
  // largest free side.
  const center = rect.left + (rect.right - rect.left) / 2;
  const towardCenter: 'left' | 'right' = center < width / 2 ? 'right' : 'left';

  if (free[towardCenter] >= minWidth) {
    return towardCenter;
  }

  if (free.up >= minHeight || free.down >= minHeight) {
    return free.up >= free.down ? 'up' : 'down';
  }

  return best();
}
