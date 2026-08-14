import { getBootData } from '../boot';

/**
 * Format a number as store currency using the site locale + currency from
 * the boot data (Phase 12: currency-aware formatting). Falls back to a
 * plain "1,234" when Intl cannot handle the locale/currency pair.
 */
export function formatCurrency(value: number, currency?: string): string {
  const boot = getBootData();

  try {
    return new Intl.NumberFormat(boot.locale.replace('_', '-'), {
      style: 'currency',
      currency: currency || boot.currency,
      maximumFractionDigits: 0,
    }).format(value);
  } catch {
    return value.toLocaleString();
  }
}

/** Format a plain number with the site locale (quantity, weight, …). */
export function formatNumber(value: number): string {
  const boot = getBootData();

  try {
    return new Intl.NumberFormat(boot.locale.replace('_', '-'), {
      maximumFractionDigits: 2,
    }).format(value);
  } catch {
    return value.toLocaleString();
  }
}

/**
 * Format a 0–1 rate as a percentage string (e.g. 0.231 → "23.1%").
 *
 * Non-finite / missing inputs render as "—" (an undefined ratio is not
 * 0%) so a 0 denominator or an absent value can never surface as
 * "NaN%" / "Infinity%" anywhere in the admin (UICHANGES.md §33).
 */
export function formatPercent(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) {
    return '—';
  }
  return `${(value * 100).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
}

/**
 * Format an already-percentage number (e.g. 12.5 → "12.5%").
 * Use for backend values that are percentage points (0–100), not rates.
 * Non-finite / missing inputs render as "—" like `formatPercent`.
 */
export function formatPercentValue(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) {
    return '—';
  }
  return `${value.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
}

/** Compact number for axis ticks / badges (e.g. 1200 → "1.2K"). */
export function formatCompact(value: number): string {
  try {
    return new Intl.NumberFormat(undefined, {
      notation: 'compact',
      maximumFractionDigits: 1,
    }).format(value);
  } catch {
    return String(Math.round(value));
  }
}

/**
 * Short day label for chart ticks (e.g. "Aug 1") in the site locale.
 * Falls back to the month-day slice of the Y-m-d string.
 */
export function formatShortDay(dateStr: string): string {
  const boot = getBootData();

  try {
    return new Intl.DateTimeFormat(boot.locale.replace('_', '-'), {
      month: 'short',
      day: 'numeric',
    }).format(new Date(`${dateStr}T12:00:00`));
  } catch {
    return dateStr.slice(5);
  }
}

/** Short human schedule label for a goal's starts_at/ends_at pair. */
export function formatSchedule(startsAt: string | null, endsAt: string | null): string {
  const day = (value: string | null) => (value ? value.slice(0, 10) : '');

  if (startsAt && endsAt) {
    return `${day(startsAt)} – ${day(endsAt)}`;
  }
  if (startsAt) {
    return `${day(startsAt)} →`;
  }
  if (endsAt) {
    return `→ ${day(endsAt)}`;
  }
  return '—';
}
