import type { FaraCartBootData } from './types';

let cached: FaraCartBootData | null = null;

/**
 * Access the boot data localized by WordPress (`window.faracart`).
 *
 * Populated by `wp_localize_script()` in `includes/Admin/AssetLoader.php`:
 * REST nonce, API base URLs, current user, caps, locale and site info.
 */
export function getBootData(): FaraCartBootData {
  if (cached) {
    return cached;
  }

  if (!window.faracart) {
    throw new Error(
      'FaraCart boot data is missing. Make sure the admin app is enqueued by the FaraCart plugin.'
    );
  }

  cached = window.faracart;

  return cached;
}

/** Update boot data that can change without a full admin-page reload. */
export function setBootCurrencyDisplay(display: FaraCartBootData['currencyDisplay']): void {
  getBootData().currencyDisplay = display;
}

/**
 * Update the resolved display currency unit in boot data (after a
 * Settings save). formatCurrency reads boot.currency, so every dashboard
 * amount re-renders with the new unit immediately.
 */
export function setBootCurrency(currency: string): void {
  getBootData().currency = currency;
}
