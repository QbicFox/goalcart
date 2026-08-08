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
