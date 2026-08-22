import type { FaraCartBootData, WooCommerceCurrencyConfig } from './types';

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

/**
 * Apply the latest WooCommerce currency configuration to the shared boot
 * object. REST responses carry this metadata so changing WooCommerce
 * settings is reflected on the next dashboard request without a FaraCart
 * setting or page reload.
 */
export function setBootCurrencyConfig(config: WooCommerceCurrencyConfig): void {
  const boot = getBootData();

  boot.currency = config.currency;
  boot.currencySymbol = config.symbol;
  boot.currencyPosition = config.position;
  boot.currencyDecimals = config.decimals;
  boot.currencyDecimalSeparator = config.decimal_separator;
  boot.currencyThousandSeparator = config.thousand_separator;
}
