import { getBootData } from '../boot';

/**
 * Persian (Farsi) digit map: Western Arabic → Eastern Arabic/Persian.
 * Used to render all numeric output in Persian script when the site
 * locale is RTL (fa_IR). The conversion is safe on already-Persian
 * strings (idempotent) so it can be applied unconditionally.
 */
const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

/**
 * Convert Western Arabic digits (0-9) in a string to Persian digits.
 * Non-digit characters (separators, commas, dots, text) are untouched.
 * Idempotent — a string that already contains Persian digits passes
 * through unchanged.
 */
export function toPersianDigits(value: string): string {
  return value.replace(/[0-9]/g, (d) => PERSIAN_DIGITS[Number(d)]);
}

/**
 * Whether the current site locale uses Persian digits (RTL).
 * True for fa_IR / fa_IR建筑材料 and similar locales.
 */
function usesPersianDigits(): boolean {
  const locale = getBootData().locale;
  return locale === 'fa_IR' || locale.startsWith('fa_') || locale === 'fa';
}

/**
 * Normalize the WordPress locale (e.g. `fa_IR`) to an `Intl`-compatible
 * BCP-47 tag (e.g. `fa-IR`). Falls back to `'en'` when the locale is
 * empty or missing.
 */
function siteLocale(): string {
  const raw = getBootData().locale;
  return raw ? raw.replace('_', '-') : 'en';
}

/**
 * Format a number as store currency using the site locale + currency from
 * the boot data (currency-aware formatting). Falls back to a
 * plain "1,234" when Intl cannot handle the locale/currency pair.
 */
export function formatCurrency(value: number, currency?: string): string {
  const boot = getBootData();

  try {
    const formatted = new Intl.NumberFormat(siteLocale(), {
      style: 'currency',
      currency: currency || boot.currency,
      currencyDisplay: boot.currencyDisplay || 'symbol',
      maximumFractionDigits: 0,
    }).format(value);

    return usesPersianDigits() ? toPersianDigits(formatted) : formatted;
  } catch {
    const fallback = value.toLocaleString(siteLocale());
    return usesPersianDigits() ? toPersianDigits(fallback) : fallback;
  }
}

/** Format a plain number with the site locale (quantity, weight, …). */
export function formatNumber(value: number): string {
  try {
    const formatted = new Intl.NumberFormat(siteLocale(), {
      maximumFractionDigits: 2,
    }).format(value);

    return usesPersianDigits() ? toPersianDigits(formatted) : formatted;
  } catch {
    const fallback = value.toLocaleString(siteLocale());
    return usesPersianDigits() ? toPersianDigits(fallback) : fallback;
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
  const raw = (value * 100).toLocaleString(siteLocale(), { maximumFractionDigits: 1 });
  const formatted = `${raw}%`;
  return usesPersianDigits() ? toPersianDigits(formatted) : formatted;
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
  const raw = value.toLocaleString(siteLocale(), { maximumFractionDigits: 1 });
  const formatted = `${raw}%`;
  return usesPersianDigits() ? toPersianDigits(formatted) : formatted;
}

/**
 * Percentage change from a previous value to a current value, as a signed
 * percentage-point number (e.g. 18.4 for +18.4%). Returns null when no
 * meaningful change can be computed (missing values, or a zero previous
 * value — a %-change off zero is undefined). `previous` may be negative
 * (e.g. a prior-period loss), so the denominator uses its absolute value.
 */
export function percentChange(
  previous: number | null | undefined,
  current: number
): number | null {
  if (
    previous === null ||
    previous === undefined ||
    !Number.isFinite(previous) ||
    !Number.isFinite(current) ||
    previous === 0
  ) {
    return null;
  }

  return ((current - previous) / Math.abs(previous)) * 100;
}

/** Compact number for axis ticks / badges (e.g. 1200 → "1.2K"). */
export function formatCompact(value: number): string {
  try {
    const formatted = new Intl.NumberFormat(siteLocale(), {
      notation: 'compact',
      maximumFractionDigits: 1,
    }).format(value);

    return usesPersianDigits() ? toPersianDigits(formatted) : formatted;
  } catch {
    const fallback = String(Math.round(value));
    return usesPersianDigits() ? toPersianDigits(fallback) : fallback;
  }
}

/**
 * Format a number using the site locale with Persian digit support.
 * Used for inline formatting outside the core format helpers (e.g.
 * FunnelVisual, Appearance preview) that previously used bare
 * `toLocaleString()`.
 */
export function formatInline(value: number, options?: Intl.NumberFormatOptions): string {
  try {
    const formatted = new Intl.NumberFormat(siteLocale(), options).format(value);
    return usesPersianDigits() ? toPersianDigits(formatted) : formatted;
  } catch {
    const fallback = value.toLocaleString(siteLocale());
    return usesPersianDigits() ? toPersianDigits(fallback) : fallback;
  }
}

/** Short day label for chart ticks (e.g. "Aug 1") in the site locale.
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

/** Short human schedule label for a mission's starts_at/ends_at pair. */
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
