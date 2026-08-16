import type { GoalCartBootData } from './types';

let cached: GoalCartBootData | null = null;

/**
 * Access the boot data localized by WordPress (`window.goalcart`).
 *
 * Populated by `wp_localize_script()` in `includes/Admin/AssetLoader.php`:
 * REST nonce, API base URLs, current user, caps, locale and site info.
 */
export function getBootData(): GoalCartBootData {
  if (cached) {
    return cached;
  }

  if (!window.goalcart) {
    throw new Error(
      'FaraCart boot data is missing. Make sure the admin app is enqueued by the FaraCart plugin.'
    );
  }

  cached = window.goalcart;

  return cached;
}

/** Update boot data that can change without a full admin-page reload. */
export function setBootCurrencyDisplay(display: GoalCartBootData['currencyDisplay']): void {
  getBootData().currencyDisplay = display;
}
