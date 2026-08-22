import { getBootData } from '../boot';
import type { WooCommerceCurrencyConfig } from '../types';

/**
 * Normalize the WordPress locale (e.g. `fa_IR`) to an `Intl`-compatible
 * BCP-47 tag (e.g. `fa-IR`). Falls back to `'en'` when the locale is
 * empty or missing.
 */
function siteLocale(): string {
  const raw = getBootData().locale;
  return raw ? raw.replace('_', '-') : 'en';
}

/** Decode WooCommerce symbols returned as HTML entities for text rendering. */
export function decodeHtmlEntities(value: string): string {
  const textarea = document.createElement('textarea');
  textarea.innerHTML = value;
  return textarea.value;
}

/**
 * Format a number with WooCommerce's exact currency configuration.
 *
 * Intl currency formatting is deliberately not used here: its symbol,
 * position, precision and separators are locale defaults and can differ
 * from WooCommerce's settings. The config is supplied by WordPress and
 * refreshed from REST metadata by apiFetch.
 */
export function formatCurrency(value: number, config?: WooCommerceCurrencyConfig): string {
  const boot = getBootData();
  const currency: WooCommerceCurrencyConfig = config ?? {
    currency: boot.currency,
    symbol: boot.currencySymbol,
    position: boot.currencyPosition,
    decimals: boot.currencyDecimals,
    decimal_separator: boot.currencyDecimalSeparator,
    thousand_separator: boot.currencyThousandSeparator,
  };
  const amount = Number.isFinite(value) ? value : 0;
  const decimals = Math.max(0, Math.floor(Number(currency.decimals) || 0));
  const fixed = amount.toFixed(decimals);
  const negative = fixed.startsWith('-');
  const unsigned = negative ? fixed.slice(1) : fixed;
  const [integer, fraction] = unsigned.split('.');
  const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, currency.thousand_separator);
  const number = `${negative ? '-' : ''}${grouped}${decimals ? currency.decimal_separator + fraction : ''}`;
  const symbol = decodeHtmlEntities(
    typeof currency.symbol === 'string' ? currency.symbol : currency.currency
  );

  let formatted: string;

  switch (currency.position) {
    case 'right':
      formatted = `${number}${symbol}`;
      break;
    case 'left_space':
      formatted = `${symbol} ${number}`;
      break;
    case 'right_space':
      formatted = `${number} ${symbol}`;
      break;
    case 'left':
    default:
      formatted = `${symbol}${number}`;
      break;
  }

  return formatted;
}

/** Format a plain number with the site locale (quantity, weight, …). */
export function formatNumber(value: number): string {
  try {
    const formatted = new Intl.NumberFormat(siteLocale(), {
      maximumFractionDigits: 2,
    }).format(value);

    return formatted;
  } catch {
    const fallback = value.toLocaleString(siteLocale());
    return fallback;
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
  return formatted;
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
  return formatted;
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

    return formatted;
  } catch {
    const fallback = String(Math.round(value));
    return fallback;
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
    return formatted;
  } catch {
    const fallback = value.toLocaleString(siteLocale());
    return fallback;
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
