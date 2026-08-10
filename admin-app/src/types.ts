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
  'amount'
  | 'quantity'
  | 'distinct_quantity'
  | 'category'
  | 'product'
  | 'weight'
  | 'composite'
  | 'tag'
  | 'attribute'
  | 'brand';

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
  /** Phase 32 (free gift selection): candidate gifts for 'choose' mode. */
  gift_products?: number[];
  gift_add_mode?: 'automatic' | 'choose';
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
  /** Legacy pre-engine template variant (kept for backward compatibility). */
  template?: string;
  /** Pluggable template engine: the goal's own template id ('' = default). */
  template_id?: string;
  /** Pluggable template engine: per-goal override of the template's appearance. */
  template_settings?: TemplateSettingsValue;
}

/** Pluggable template engine (Phase 12): one template scope. */
export type TemplateScope = 'goal' | 'campaign';

/** Pluggable template engine: the field types a template schema accepts. */
export type TemplateFieldType =
  | 'color'
  | 'text'
  | 'textarea'
  | 'number'
  | 'bool'
  | 'select'
  | 'css';

/** One field of a template's settings schema (drives the dynamic form). */
export interface TemplateField {
  key: string;
  type: TemplateFieldType;
  label: string;
  group?: string;
  default: string | number | boolean;
  help?: string;
  min?: number;
  max?: number;
  options?: Record<string, string>;
}

/** A resolved template settings map (schema-conformant, server-validated). */
export type TemplateSettingsValue = Record<string, string | number | boolean>;

/** One registered template as served by `GET /goalcart/v1/templates`. */
export interface TemplateDefinition {
  id: string;
  label: string;
  description: string;
  version: number;
  scope: TemplateScope;
  schema: TemplateField[];
  /** The effective default appearance (legacy fallbacks already merged). */
  settings: TemplateSettingsValue;
}

/** The `GET /goalcart/v1/templates` payload. */
export interface TemplatesPayload {
  scopes: TemplateScope[];
  defaults: Record<TemplateScope, string>;
  goal: TemplateDefinition[];
  campaign: TemplateDefinition[];
  versions: Record<TemplateScope, Record<string, number>>;
}

/**
 * A campaign template group in the progress/preview payload — the
 * resolved campaign-scoped template that renders the whole milestone
 * group (e.g. the milestone chain) instead of per-goal cards.
 */
export interface ProgressCampaign {
  campaign_id: number;
  name: string;
  template: string;
  settings: TemplateSettingsValue;
  /** Phase 32 (countdown): the latest milestone deadline (ISO local). */
  countdown_end?: string;
}

/** A composite child config — a Goal::from_array() payload (Phase 4). */
export interface GoalChildInput {
  type: GoalType;
  target: number;
  calculation_mode: string;
  categories: number[];
  products: number[];
  /** Phase 32: tag / attribute / brand child scopes. */
  tags?: number[];
  attributes?: string[];
}

/**
 * The payload accepted by `POST /goals` and `PUT /goals/{id}` (Phase 9
 * builder form model — mirrors the Goal REST payload without the
 * server-managed id/timestamps). Phase 32 adds the tag/attribute/brand
 * scopes and the customer/order/cart/shipping condition keys.
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
  tags: number[];
  attributes: string[];
  customer_roles: string[];
  customer_state: Array<'guest' | 'logged_in'>;
  first_order: boolean;
  vip: boolean;
  vip_min_spend: number;
  vip_min_orders: number;
  shipping_zones: number[];
  cart_coupons: string[];
  cart_min_items: number;
  schedule_days: number[];
  schedule_start_time: string;
  schedule_end_time: string;
  operator: 'and' | 'or';
  children: GoalChildInput[];
  reward_type: RewardType;
  reward_value: number | null;
  reward_max_value: number | null;
  reward_meta: RewardMetaInput;
  priority: number;
  /** Mutually exclusive goal (Phase 26): when reached, lower-priority goals are skipped. */
  exclusive: boolean;
  starts_at: string | null;
  ends_at: string | null;
  display_settings: DisplaySettingsInput;
}

/**
 * A goal as served by the Phase 7 REST API (`GET /goalcart/v1/goals`).
 * Mirrors the Goal model's payload shape 1:1 (Phase 32 condition keys
 * included).
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
  tags: number[];
  attributes: string[];
  customer_roles: string[];
  customer_state: Array<'guest' | 'logged_in'>;
  first_order: boolean;
  vip: boolean;
  vip_min_spend: number;
  vip_min_orders: number;
  shipping_zones: number[];
  cart_coupons: string[];
  cart_min_items: number;
  schedule_days: number[];
  schedule_start_time: string;
  schedule_end_time: string;
  operator: 'and' | 'or';
  children: Array<Record<string, unknown>>;
  reward_type: RewardType;
  reward_value: number | null;
  reward_max_value: number | null;
  reward_meta: RewardMetaInput;
  priority: number;
  /** Mutually exclusive goal (Phase 26): when reached, lower-priority goals are skipped. */
  exclusive: boolean;
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
  /** Phase 32 (free gift selection): catalog-safe gift list + chosen flag. */
  gift?: Array<{ id: number; name: string; image: string; price_html: string }>;
  gift_chosen?: boolean;
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
  campaign_id: number;
  goal_name: string;
  goal_type: GoalType;
  is_money: boolean;
  icon: string;
  /** Resolved template id (item override → scope default → legacy → fallback). */
  template: string;
  /** Resolved template settings (what the storefront actually renders). */
  template_settings: TemplateSettingsValue;
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
  /** Phase 32 (countdown): the goal's deadline (ISO local, '' = none). */
  countdown_end?: string;
  /**
   * Phase 26 conflict resolution: whether this goal won its conflict and
   * may grant its reward, plus the machine-readable reason when suppressed
   * (lower_priority | exclusive | not_best | not_first).
   */
  conflict: { resolved: boolean; reason: string };
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
  /** Campaign template groups (campaign-scoped templates, e.g. the chain). */
  campaigns: ProgressCampaign[];
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

/** Search endpoint result item (`GET /search/tags`). */
export interface SearchTag {
  id: number;
  name: string;
  slug: string;
  count: number;
}

/** Search endpoint result item (`GET /search/attributes`). */
export interface SearchAttribute {
  taxonomy: string;
  name: string;
  label: string;
}

/** Search endpoint result item (`GET /search/zones`). */
export interface SearchZone {
  id: number;
  name: string;
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

/** Storefront widget display locations (Phase 18, frontend_locations). */
export type FrontendLocation =
  | 'cart'
  | 'mini-cart'
  | 'checkout'
  | 'shop'
  | 'product'
  | 'sticky';

/** Storefront currency display style (Phase 18, currency_display). */
export type CurrencyDisplay = 'symbol' | 'code' | 'name';

/** How multiple active goals are presented (Phase 18, default_goal_behavior). */
export type GoalBehavior = 'all' | 'first' | 'closest';

/**
 * How completed goals grant rewards when several compete (Phase 26,
 * conflict_resolution): cumulative (all stack), best (only the most
 * valuable reward), first (only the highest-priority matching goal).
 */
export type ConflictResolution = 'cumulative' | 'best' | 'first';

/** Store-wide default money basis (Phase 18, calculation_mode). */
export type CalculationMode = 'subtotal' | 'discounted_subtotal' | 'total';

/** Storefront mobile behavior (Phase 18, frontend_mobile). */
export type MobileBehavior = 'show' | 'hide';

/** Reward-type filter on the analytics dashboard ('' = all rewards). */
export type AnalyticsRewardFilter =
  | ''
  | 'free_shipping'
  | 'percent_discount'
  | 'fixed_discount'
  | 'free_gift'
  | 'coupon';

/** The seven Phase 16 summary KPIs, computed over the filtered window. */
export interface AnalyticsSummary {
  impressions: number;
  completions: number;
  completion_rate: number;
  average_cart_value: number;
  revenue_influenced: number;
  suggestion_ctr: number;
  suggestion_add_to_cart_rate: number;
}

/** One daily bucket of the Phase 17 trend series. */
export interface AnalyticsTrendPoint {
  date: string; // Y-m-d
  impressions: number;
  completions: number;
  revenue: number;
}

/** One top campaign / top goal entry (same shape from the backend). */
export interface AnalyticsTopEntry {
  id: number;
  name: string;
  impressions: number;
  completions: number;
  revenue: number;
  completion_rate: number;
}

/** One top suggested product entry (Phase 17). */
export interface AnalyticsSuggestedProduct {
  product_id: number;
  name: string;
  impressions: number;
  clicks: number;
  added: number;
  ctr: number;
  add_to_cart_rate: number;
}

/**
 * The full Phase 17 dashboard payload served by
 * `GET /goalcart/v1/analytics`.
 */
export interface AnalyticsPayload {
  summary: AnalyticsSummary;
  trend: AnalyticsTrendPoint[];
  top_campaigns: AnalyticsTopEntry[];
  top_goals: AnalyticsTopEntry[];
  top_suggested_products: AnalyticsSuggestedProduct[];
}

/**
 * The settings object persisted by the Phase 7 REST API
 * (`GET/POST /goalcart/v1/settings`). Phase 18 ships the full surface:
 * general, frontend, goal calculation, performance and advanced.
 *
 * The `frontend_*` keys are the Phase 12 progress-template + appearance
 * surface consumed by the storefront widgets and the Appearance page.
 */
export interface GoalCartSettings {
  // General (P18-T01).
  enabled: boolean;
  fullscreen_dashboard: boolean;
  currency_display: CurrencyDisplay;
  default_goal_behavior: GoalBehavior;
  conflict_resolution: ConflictResolution;
  calculation_mode: CalculationMode;

  // Frontend (P18-T02). Phase 32 adds the countdown/celebration toggles
  // and the advanced sticky-bar surface.
  frontend_template: FrontendTemplate;
  frontend_animation: boolean;
  frontend_locations: FrontendLocation[];
  frontend_mobile: MobileBehavior;
  frontend_bar_height: number;
  frontend_accent: string;
  frontend_bg: string;
  frontend_border: string;
  frontend_text: string;
  frontend_radius: number;
  frontend_css_class: string;
  frontend_custom_css: string;
  frontend_countdown: boolean;
  frontend_celebrate: boolean;

  // Phase 32 (advanced sticky bar).
  sticky_position: 'bottom' | 'top';
  sticky_behavior: 'dismissible' | 'auto_hide';
  sticky_delay: number;
  sticky_countdown: boolean;
  sticky_suggestions: boolean;
  sticky_display: 'compact' | 'full';

  // Pluggable template engine (Phase 12 → engine): per-scope default
  // template ids, per-template default appearance and schema versions.
  template_defaults: Record<TemplateScope, string>;
  template_settings: Record<TemplateScope, Record<string, TemplateSettingsValue>>;
  template_versions: Record<TemplateScope, Record<string, number>>;

  // Goal Calculation (P18-T03).
  calculation_include_tax: boolean;
  calculation_include_discount: boolean;
  calculation_include_shipping: boolean;
  calculation_include_sale: boolean;
  calculation_include_virtual: boolean;

  // Performance (P18-T04).
  performance_caching: boolean;
  analytics_enabled: boolean;
  performance_suggestions: boolean;
  /** Phase 32 (advanced upsell ranking): balanced | price | popularity. */
  suggestions_ranking: 'balanced' | 'price' | 'popularity';

  // Advanced (P18-T05).
  debug_mode: boolean;
  logging_enabled: boolean;
  developer_hooks: boolean;
}

/** One entry of the developer-hooks reference (Phase 18 Advanced). */
export interface DeveloperHook {
  type: 'action' | 'filter';
  hook: string;
  description: string;
}

/** Extra settings-page meta served alongside the settings payload. */
export interface SettingsMeta {
  /** Public goalcart_* hooks reference (Phase 18, developer hooks). */
  hooks?: DeveloperHook[];
  /** Absolute path of the debug log file (present when logging is on). */
  log_path?: string;
  /** Site roles (slug → display name) for the Phase 32 role conditions. */
  roles?: Record<string, string>;
}
