import { __ } from '@wordpress/i18n';

import { getBootData } from '../boot';

/**
 * Calendar helpers for the wheel date pickers.
 *
 * The admin app stores and exchanges dates as Gregorian `Y-m-d` strings
 * everywhere (REST payloads, URL params, `boot.currentDate`). When the
 * WordPress admin language is Persian (`fa_*`), the pickers display those
 * same instants in the Jalali (Persian) calendar and convert back on
 * selection — the stored value never changes, only its presentation.
 *
 * Conversions rely on the runtime's built-in Intl Persian-calendar support
 * (ICU), so no calendar library is needed: Gregorian → Jalali is a single
 * `formatToParts()` call, and Jalali → Gregorian is a short binary search
 * over the monotonic mapping (~10 formatting calls per conversion).
 */

export interface CalendarDate {
  year: number;
  /** 1–12. */
  month: number;
  day: number;
}

/** Whether the admin is running in a Persian (Jalali) locale. */
export function isJalaliLocale(): boolean {
  return getBootData().locale.toLowerCase().startsWith('fa');
}

/** Persian-calendar formatter using Latin digits (matches the rest of the admin). */
const PERSIAN_FMT = new Intl.DateTimeFormat('en-US-u-ca-persian', {
  year: 'numeric',
  month: 'numeric',
  day: 'numeric',
});

/** Convert a Gregorian Date to its Jalali calendar date. */
export function toJalali(date: Date): CalendarDate {
  let year = 0;
  let month = 0;
  let day = 0;

  for (const part of PERSIAN_FMT.formatToParts(date)) {
    if (part.type === 'year') {
      year = Number(part.value);
    } else if (part.type === 'month') {
      month = Number(part.value);
    } else if (part.type === 'day') {
      day = Number(part.value);
    }
  }

  return { year, month, day };
}

/** Order two calendar dates (Jalali or Gregorian) for the binary search. */
function compareDate(a: CalendarDate, b: CalendarDate): number {
  return a.year - b.year || a.month - b.month || a.day - b.day;
}

const DAY_MS = 86_400_000;

/** A fixed local-noon anchor used to convert dates to whole-day indices. */
const ANCHOR_MS = new Date(2020, 0, 1, 12).getTime();

/** Whole-day index of a local date (rounding absorbs DST offset shifts). */
function dayIndex(date: Date): number {
  return Math.round((date.getTime() - ANCHOR_MS) / DAY_MS);
}

/** Local instant for a whole-day index (may drift ±1h across DST shifts). */
function fromDayIndex(index: number): Date {
  return new Date(ANCHOR_MS + index * DAY_MS);
}

/**
 * Convert a Jalali date to a Gregorian Date (local noon, like the rest of
 * the app's date math).
 *
 * The Persian new year (Nowruz) lands around March 21, so a Jalali year
 * spans two Gregorian years (jy + 621 and jy + 622). A binary search over
 * the monotonic Gregorian → Jalali mapping pins the exact day.
 *
 * The search runs over whole-day indices anchored to a fixed local noon
 * instead of raw timestamps: midpoint arithmetic on timestamps drifts off
 * noon whenever the window spans a DST/offset transition, which breaks the
 * day-stepping. Day indices stay exact under any offset change.
 */
export function jalaliToDate(year: number, month: number, day: number): Date {
  // Conservative window around Nowruz for any possible leap offset.
  let lo = dayIndex(new Date(year + 620, 11, 21, 12));
  let hi = dayIndex(new Date(year + 623, 2, 21, 12));
  const target: CalendarDate = { year, month, day };

  while (lo < hi) {
    const mid = (lo + hi) >> 1;

    if (compareDate(toJalali(fromDayIndex(mid)), target) < 0) {
      lo = mid + 1;
    } else {
      hi = mid;
    }
  }

  const found = fromDayIndex(lo);

  // Re-anchor to local noon of the found calendar day.
  return new Date(found.getFullYear(), found.getMonth(), found.getDate(), 12);
}

/** Number of days in a Jalali month (leap-aware, via conversion). */
export function daysInJalaliMonth(year: number, month: number): number {
  const first = jalaliToDate(year, month, 1);
  const next = jalaliToDate(month === 12 ? year + 1 : year, month === 12 ? 1 : month + 1, 1);

  return Math.round((next.getTime() - first.getTime()) / DAY_MS);
}

/**
 * Jalali month names (1–12), translatable so the fa_IR catalog renders
 * them in Persian script (Farvardin … Esfand).
 */
export const JALALI_MONTH_NAMES: string[] = [
  __('Farvardin', 'faracart'),
  __('Ordibehesht', 'faracart'),
  __('Khordad', 'faracart'),
  __('Tir', 'faracart'),
  __('Mordad', 'faracart'),
  __('Shahrivar', 'faracart'),
  __('Mehr', 'faracart'),
  __('Aban', 'faracart'),
  __('Azar', 'faracart'),
  __('Dey', 'faracart'),
  __('Bahman', 'faracart'),
  __('Esfand', 'faracart'),
];

/** Gregorian month name (1–12) localized to the admin language. */
export function gregorianMonthName(month: number): string {
  const boot = getBootData();

  try {
    return new Intl.DateTimeFormat(boot.locale.replace('_', '-'), { month: 'long' }).format(
      new Date(2000, month - 1, 1)
    );
  } catch {
    return String(month);
  }
}

/** Days in a Gregorian month (1–12), leap-aware. */
export function daysInGregorianMonth(year: number, month: number): number {
  return new Date(year, month, 0).getDate();
}

/**
 * Site-local "now" in the app's `Y-m-d H:i` shape — boot's current date
 * (the site's today, matching the rest of the app's date math) plus the
 * admin's local clock.
 */
export function nowDateTime(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${getBootData().currentDate} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

/**
 * Format a stored `Y-m-d H:i:s` (or `Y-m-d`) datetime for display in
 * the admin language.
 *
 * `fa_*` locales render the Jalali date exactly like the wheel pickers
 * present it (translated month name + Latin digits); everything else
 * uses the locale's Gregorian format. The 24-hour time is appended when
 * present.
 */
export function formatDateTime(value: string): string {
  const boot = getBootData();
  const [datePart, timePart] = value.split(' ');
  const time = (timePart ?? '').slice(0, 5);
  const date = new Date(`${datePart || value}T12:00:00`);

  let dateLabel: string;

  if (isJalaliLocale()) {
    if (!Number.isNaN(date.getTime())) {
      const { year, month, day } = toJalali(date);

      dateLabel = `${day} ${JALALI_MONTH_NAMES[month - 1]} ${year}`;
    } else {
      dateLabel = datePart || value;
    }
  } else {
    try {
      dateLabel = new Intl.DateTimeFormat(boot.locale.replace('_', '-'), {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      }).format(date);
    } catch {
      dateLabel = datePart || value;
    }
  }

  return time ? `${dateLabel} ${time}` : dateLabel;
}
