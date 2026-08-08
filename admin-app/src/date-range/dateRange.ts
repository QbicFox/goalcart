import { __ } from '@wordpress/i18n';

import { getBootData } from '../boot';
import type { DateRange, FixedRangePreset } from './types';

/** Format a Date as `Y-m-d` in the local timezone. */
export function toYmd(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

/**
 * Shift a `Y-m-d` string by a number of days. Noon-local avoids DST edge
 * cases (e.g. spring-forward/fall-back nights).
 */
export function shiftDate(dateStr: string, days: number): string {
  const date = new Date(`${dateStr}T12:00:00`);
  date.setDate(date.getDate() + days);

  return toYmd(date);
}

/** Number of days between two `Y-m-d` dates (inclusive). */
export function daysBetween(from: string, to: string): number {
  const start = new Date(`${from}T00:00:00`).getTime();
  const end = new Date(`${to}T00:00:00`).getTime();

  return Math.round((end - start) / 86_400_000) + 1;
}

/** Whether a string looks like a `Y-m-d` date. */
export function isYmd(value: unknown): value is string {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
}

/**
 * Resolve a fixed preset to its concrete inclusive `Y-m-d` bounds,
 * anchored to the site-local "today" from boot data.
 */
export function presetRange(preset: FixedRangePreset, today: string): { from: string; to: string } {
  switch (preset) {
    case 'today':
      return { from: today, to: today };
    case 'yesterday':
      return { from: shiftDate(today, -1), to: shiftDate(today, -1) };
    case 'last7':
      return { from: shiftDate(today, -6), to: today };
    case 'last30':
    default:
      return { from: shiftDate(today, -29), to: today };
  }
}

/**
 * The previous period of equal length that immediately precedes `range`
 * — used for the "vs previous period" comparison the filter caption
 * surfaces.
 */
export function comparisonRange(range: DateRange): { from: string; to: string } {
  const length = daysBetween(range.from, range.to);
  const to = shiftDate(range.from, -1);
  const from = shiftDate(to, -(length - 1));

  return { from, to };
}

/** Human-readable label for the filter button (e.g. "Last 30 days"). */
export function presetLabel(preset: FixedRangePreset): string {
  switch (preset) {
    case 'today':
      return __('Today', 'goalcart');
    case 'yesterday':
      return __('Yesterday', 'goalcart');
    case 'last7':
      return __('Last 7 days', 'goalcart');
    case 'last30':
    default:
      return __('Last 30 days', 'goalcart');
  }
}

/** Sort a custom from/to pair so `from <= to` (swaps if reversed). */
export function normalizeBounds(from: string, to: string): { from: string; to: string } {
  if (!isYmd(from) || !isYmd(to)) {
    throw new Error('Invalid custom date range bounds.');
  }

  return from <= to ? { from, to } : { from: to, to: from };
}

/** Format a `Y-m-d` date for display in the site locale. */
export function formatDay(dateStr: string): string {
  const boot = getBootData();

  try {
    return new Intl.DateTimeFormat(boot.locale.replace('_', '-'), {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    }).format(new Date(`${dateStr}T12:00:00`));
  } catch {
    return dateStr;
  }
}

/**
 * Compact label for the filter button: the preset name for fixed
 * presets, or the locale-formatted bounds for custom ranges.
 */
export function formatRangeLabel(range: DateRange): string {
  if (range.preset !== 'custom') {
    return presetLabel(range.preset);
  }

  return `${formatDay(range.from)} – ${formatDay(range.to)}`;
}
