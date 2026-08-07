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

export type GoalStatus = 'active' | 'inactive';

export type GoalType =
  'amount' | 'quantity' | 'distinct_quantity' | 'category' | 'product' | 'weight' | 'composite';

export type RewardType =
  'free_shipping' | 'percent_discount' | 'fixed_discount' | 'free_gift' | 'coupon' | null;

/**
 * A goal as served by the Phase 7 REST API (`GET /goalcart/v1/goals`).
 * Mirrors the Goal model's payload shape 1:1.
 */
export interface Goal {
  id: number;
  name: string;
  description: string;
  status: GoalStatus;
  type: GoalType;
  target: number;
  calculation_mode: string;
  categories: number[];
  products: number[];
  excluded_products: number[];
  operator: 'and' | 'or';
  children: Array<Record<string, unknown>>;
  reward_type: RewardType;
  reward_value: number | null;
  reward_max_value: number | null;
  reward_meta: Record<string, unknown>;
  priority: number;
  campaign_id: number | null;
  menu_order: number;
  starts_at: string | null;
  ends_at: string | null;
  display_settings: Record<string, unknown>;
  limits: Record<string, unknown>;
  created_at: string;
  updated_at: string;
}

/**
 * The settings object persisted by the Phase 7 REST API
 * (`GET/POST /goalcart/v1/settings`). Grows with the Phase 18 surface.
 */
export interface GoalCartSettings {
  enabled: boolean;
  fullscreen_dashboard: boolean;
}

/** Paginated goals list payload (envelope `data` for GET /goals). */
export interface GoalsList {
  items: Goal[];
}
