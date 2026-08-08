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

/** Extended reward configuration (stored in the `reward_meta` JSON). */
export interface RewardMetaInput {
  label?: string;
  stacking?: 'none' | 'stack';
  eligible_products?: number[];
  eligible_categories?: number[];
  excluded_products?: number[];
  shipping_zone_ids?: number[];
  shipping_method_ids?: string[];
  gift_product_id?: number;
  gift_add_mode?: 'automatic' | 'optional';
  coupon_code?: string;
  coupon_generate?: boolean;
  coupon_discount_type?: 'percent' | 'fixed_cart';
}

/** Display configuration (stored in the `display_settings` JSON). */
export interface DisplaySettingsInput {
  title?: string;
  message?: string;
  completed_message?: string;
  icon?: string;
  template?: string;
}

/** A composite child config — a Goal::from_array() payload (Phase 4). */
export interface GoalChildInput {
  type: GoalType;
  target: number;
  calculation_mode: string;
  categories: number[];
  products: number[];
}

/**
 * The payload accepted by `POST /goals` and `PUT /goals/{id}` (Phase 9
 * builder form model — mirrors the Goal REST payload without the
 * server-managed id/timestamps).
 */
export interface GoalInput {
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
  children: GoalChildInput[];
  reward_type: RewardType;
  reward_value: number | null;
  reward_max_value: number | null;
  reward_meta: RewardMetaInput;
  priority: number;
  starts_at: string | null;
  ends_at: string | null;
  display_settings: DisplaySettingsInput;
}

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
  reward_meta: RewardMetaInput;
  priority: number;
  campaign_id: number | null;
  menu_order: number;
  starts_at: string | null;
  ends_at: string | null;
  display_settings: DisplaySettingsInput;
  limits: Record<string, unknown>;
  created_at: string;
  updated_at: string;
}

/** Message states produced by the Phase 13 MessageEngine. */
export type ProgressState =
  'inactive' | 'unavailable' | 'progressing' | 'nearly_complete' | 'completed' | 'reward_activated';

/** The flat reward summary in the progress/preview payload. */
export interface ProgressReward {
  type: RewardType;
  value: number | null;
  max_value: number | null;
  meta: RewardMetaInput;
}

/** A suggested product in the progress payload (Phase 14). */
export interface SuggestionProduct {
  id: number;
  name: string;
  permalink: string;
  price: number | null;
  price_html: string;
  image: string;
  stock_status: string;
  source: string;
}

/**
 * One goal entry in the public `GET /progress` payload and the Phase 15
 * admin `POST /preview` payload (same shape, built by
 * `FrontendController::shape_goal()`).
 */
export interface ProgressGoal {
  goal_id: number;
  goal_name: string;
  goal_type: GoalType;
  is_money: boolean;
  icon: string;
  template: string;
  current: number;
  target: number;
  remaining: number;
  percentage: number;
  completed: boolean;
  state: ProgressState;
  message: string;
  reward: ProgressReward | null;
  suggestions: SuggestionProduct[];
  reward_state: 'not_applicable' | 'locked' | 'unlocked';
  eligible: boolean;
  reason: string;
}

/** Simulated cart values sent to the preview endpoint (Phase 15). */
export interface PreviewSimulated {
  amount: number;
  quantity: number;
}

/**
 * The admin preview endpoint payload (`POST /goalcart/v1/preview`,
 * Phase 15). Same per-goal shape as /progress, plus the simulated values
 * echoed back so the preview frame can label itself.
 */
export interface PreviewPayload {
  goals: ProgressGoal[];
  currency: string;
  simulated: PreviewSimulated;
}

/** Search endpoint result item (`GET /search/products`). */
export interface SearchProduct {
  id: number;
  name: string;
  type: string;
  sku: string;
  price: number | null;
  stock_status: string;
  permalink: string;
}

/** Search endpoint result item (`GET /search/categories`). */
export interface SearchCategory {
  id: number;
  name: string;
  slug: string;
  parent: number;
  count: number;
}

/** Search endpoint result item (`GET /search/coupons`). */
export interface SearchCoupon {
  id: number;
  code: string;
  discount_type: string;
  amount: number | null;
}

/** A milestone inside a campaign (Phase 10 payload shape). */
export interface CampaignGoal {
  id: number;
  name: string;
  type: GoalType;
  target: number;
  reward_type: RewardType;
  menu_order: number;
}

/**
 * A campaign as served by the Phase 10 REST API
 * (`GET /goalcart/v1/campaigns`). Groups goals into scheduled,
 * prioritized milestones (Phase 3 `campaigns` table + `goals.menu_order`).
 */
export interface Campaign {
  id: number;
  name: string;
  description: string;
  status: GoalStatus;
  starts_at: string | null;
  ends_at: string | null;
  priority: number;
  display_rules: Record<string, unknown>;
  goal_count: number;
  goals: CampaignGoal[];
  created_at: string;
  updated_at: string;
}

/** The payload accepted by `POST /campaigns` and `PUT /campaigns/{id}`. */
export interface CampaignInput {
  name: string;
  description: string;
  status: GoalStatus;
  starts_at: string | null;
  ends_at: string | null;
  priority: number;
  display_rules: Record<string, unknown>;
  /** Ordered goal ids — the campaign's milestone ordering. */
  goals: number[];
}

/** Storefront progress template variants (Phase 12). */
export type FrontendTemplate = 'basic' | 'percentage' | 'milestone' | 'card';

/**
 * The settings object persisted by the Phase 7 REST API
 * (`GET/POST /goalcart/v1/settings`). Grows with the Phase 18 surface.
 *
 * The `frontend_*` keys are the Phase 12 progress-template + appearance
 * surface consumed by the storefront widgets and the Appearance page.
 */
export interface GoalCartSettings {
  enabled: boolean;
  fullscreen_dashboard: boolean;
  /** Storefront template variant: basic | percentage | milestone | card. */
  frontend_template: FrontendTemplate;
  /** Animate the progress-bar fill on updates. */
  frontend_animation: boolean;
  /** Progress bar thickness in px (4–48). */
  frontend_bar_height: number;
  /** Primary accent color (hex). */
  frontend_accent: string;
  /** Widget surface color (hex). */
  frontend_bg: string;
  /** Hairline border color (hex). */
  frontend_border: string;
  /** Primary text color (hex). */
  frontend_text: string;
  /** Widget corner radius in px (0–40). */
  frontend_radius: number;
  /** Extra CSS class(es) added to every widget container. */
  frontend_css_class: string;
  /** Custom CSS appended to the storefront widget styles. */
  frontend_custom_css: string;
}
