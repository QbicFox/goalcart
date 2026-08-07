export interface GoalCartUser {
  displayName: string;
  avatarUrl: string;
}

export interface GoalCartCaps {
  manageOptions: boolean;
  manageWooCommerce: boolean;
}

/**
 * Boot data injected by WordPress via `wp_localize_script()` (see
 * `includes/Admin/AssetLoader.php` → `boot_data()`).
 */
export interface GoalCartBootData {
  nonce: string;
  restBase: string;
  restUrl: string;
  adminUrl: string;
  homeUrl: string;
  siteName: string;
  locale: string;
  isRtl: boolean;
  currency: string;
  /** Site-local today (Y-m-d), so date math matches the backend timezone. */
  currentDate: string;
  userId: number;
  user: GoalCartUser;
  caps: GoalCartCaps;
  version: string;
  isPro: boolean;
  /** Whether the dashboard opens full-screen (hides the WP admin chrome). */
  fullscreen: boolean;
}

/**
 * Standard REST response envelope used by the Goal Cart API
 * (Phase 7: base controller + response envelope, mirroring the reference).
 */
export interface ApiEnvelope<T> {
  data: T;
  meta?: Record<string, unknown>;
  pagination?: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}
