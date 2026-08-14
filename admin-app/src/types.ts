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

/** Stable machine-readable profit reason codes (Improvement.md §39). */
export type ProfitReasonCode =
  | 'available'
  | 'missing_product_cost'
  | 'incomplete_product_cost'
  | 'insufficient_data';

/**
 * The product-cost sources the backend estimator consults, in order
 * (Improvement.md Phase 3 / §10 + UPSELL_REFACTOR §20). Keys are stable;
 * translate them in the UI when explaining where cost data comes from.
 */
export type CostSource =
  | '_goalcart_product_cost'
  | '_cost'
  | '_wc_cog_cost'
  | 'goalcart_product_cost'
  | 'variation_fallback';

/**
 * Product-level cost coverage (UPSELL_REFACTOR §25): how much of the
 * catalog carries cost data — served by `GET /revenue/cost-coverage`.
 */
export interface ProductCostCoverage {
  total_products: number;
  products_with_cost: number;
  /** 0–100, null when there are no published products. */
  coverage_pct: number | null;
  available: boolean;
}

/** The `GET /goalcart/v1/revenue/cost-coverage` payload (UPSELL_REFACTOR §46). */
export interface CostCoveragePayload {
  product_coverage: ProductCostCoverage;
  store_has_cost_data: boolean;
  cost_sources: CostSource[];
}

/** The result of applying a goal-threshold recommendation (§10). */
export interface RecommendationApplyResult {
  goal_id: number;
  name: string;
  target: number;
  previous_target: number;
}

/** Cost-data coverage over the attributed (incremental) orders (§11). */
export interface CostCoverage {
  /** Direct-attribution orders that carry incremental revenue. */
  attributed_orders: number;
  /** Of those, how many had complete product cost data. */
  orders_with_cost_data: number;
  /** 0–100, null when there are no attributed orders. */
  coverage_pct: number | null;
  available: boolean;
}

/** The profit-model building blocks behind estimated profit (§12). */
export interface ProfitDetails {
  incremental_revenue: number;
  margin_pct: number | null;
  reward_cost: number;
  shipping_cost: number | null;
}

/**
 * The Phase 16/17 summary KPIs computed over the filtered window, plus the
 * Phase 2 purchase/profit metrics derived from the attribution layer
 * (Improvement.md §37) — null when the active filter cannot be expressed
 * in attribution (e.g. product_id) or there is no data yet.
 */
export interface AnalyticsSummary {
  impressions: number;
  completions: number;
  completion_rate: number;
  average_cart_value: number;
  revenue_influenced: number;
  suggestion_ctr: number;
  suggestion_add_to_cart_rate: number;
  // Phase 2 — purchase analysis (from the cached attribution layer).
  progressed: number | null;
  purchased_orders: number | null;
  purchase_rate: number | null;
  attributed_sales: number | null;
  estimated_profit: number | null;
  profit_available: boolean;
  profit_reason: string | null;	profit_reason_code: ProfitReasonCode | null;
  cost_coverage: CostCoverage;
  // Phase 3 — UI-ready profit availability metadata (§10): which cost
  // sources are consulted and whether the store carries any cost data.
  // `store_has_cost_data` is null when the filter cannot be expressed in
  // attribution (e.g. product_id).
  cost_sources: CostSource[];
  store_has_cost_data: boolean | null;
  /** Profit-model building blocks (§12) — null for unsupported filters. */
  profit_details: ProfitDetails | null;
  // Phase 6 — the full attribution funnel (views → progressed → completed
  // → purchased) so the analytics funnel (§23) and the purchase analysis
  // (§24/§25) render from one self-consistent pipeline, plus the
  // assisted/influenced revenue splits for the advanced attribution
  // section (§30). All null when the active filter cannot be expressed in
  // attribution (e.g. product_id).
  funnel: RevenueFunnel | null;
  assisted_sales: number | null;
  influenced_sales: number | null;
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
  /**
   * Phase 6 — per-goal purchase comparison rows (same shape as
   * `/revenue/goals`, §27), sliced by the same filters. Null when the
   * active filter cannot be expressed in attribution (e.g. product_id).
   */
  goal_comparison: GoalPerformanceRow[] | null;
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

/**
 * Phase 33 revenue optimization types (Phase 33.6 React Admin).
 *
 * Every payload mirrors the PHP shapes served by the Phase 33.3–33.5
 * repository reads (RevenueRepository) through the REST layer — the
 * overview / attribution / goal-performance endpoints (RevenueController)
 * and the existing goal-recommendations / upsells endpoints.
 */

/** The goal funnel counts + rates (attribution funnel, Phase 33.2). */
export interface RevenueFunnel {
  views: number;
  progressed: number;
  completed: number;
  converted: number;
  completion_rate: number | null;
  conversion_rate: number | null;
}

/** The attribution summary block of the revenue overview payload. */
export interface RevenueSummary {
  goal_driven_revenue: number;
  goal_assisted_revenue: number;
  goal_influenced_revenue: number;
  orders: number;
  reward_cost: number;
  reward_cost_available: boolean;
  profit_impact: number | null;
  profit_available: boolean;
  profit_reason: string | null;
  // Phase 2 — profit availability metadata (Improvement.md §38/§39/§11/§12).
  profit_reason_code: ProfitReasonCode;
  profit_details: ProfitDetails;
  cost_coverage: CostCoverage;
  // Phase 3 — UI-ready profit availability metadata (§10).
  cost_sources: CostSource[];
  store_has_cost_data: boolean;
  funnel: RevenueFunnel;
}

/** Incremental cart value analysis (Phase 33.2). */
export interface IncrementalCartValue {
  average: number;
  total: number;
  average_baseline: number;
  sessions: number;
  sessions_with_gain: number;
  data_sufficiency: 'low' | 'medium' | 'high';
}

/** AOV comparison — goal-exposed vs store-wide (labeled observed impact). */
export interface AovAnalysis {
  overall_aov: number;
  exposed_aov: number;
  non_exposed_aov: number | null;
  absolute_change: number;
  percentage_change: number;
  exposed_orders: number;
  total_orders: number;
  label: 'observed_impact';
  comparison_available: boolean;
}

/** One shipping method's stats (per-method averages). */
export interface ShippingMethodStats {
  orders: number;
  average: number;
}

/** Shipping statistics over the store's orders in the window. */
export interface ShippingStats {
  available: boolean;
  average_shipping: number;
  orders_with_shipping: number;
  free_shipping_orders: number;
  orders?: number;
  by_method: Record<string, ShippingMethodStats>;
}

/** One daily bucket of the Phase 33.3 revenue trend series. */
export interface RevenueTrendPoint {
  date: string; // Y-m-d
  views: number;
  progressions: number;
  completions: number;
  conversions: number;
  revenue: number;
  incremental_revenue: number;
  reward_cost: number;
  estimated_profit: number;
}

/**
 * The `GET /goalcart/v1/revenue/overview` payload — the Revenue Overview
 * page's data source (summary + incremental + AOV + shipping + trend).
 */
export interface RevenueOverviewPayload {
  summary: RevenueSummary;
  incremental_cart_value: IncrementalCartValue;
  aov: AovAnalysis;
  shipping: ShippingStats;
  trend: RevenueTrendPoint[];
  generated_at: string;
}

/**
 * The `GET /goalcart/v1/revenue/attribution` payload — the Attribution
 * Dashboard page's data source (the overview without the trend series).
 */
export type RevenueAttributionPayload = Omit<RevenueOverviewPayload, 'trend'>;

/** One Goal Performance row (`GET /goalcart/v1/revenue/goals`). */
export interface GoalPerformanceRow {
  goal_id: number;
  name: string;
  reward_type: RewardType;
  target: number;
  views: number;
  progressed: number;
  completed: number;
  converted: number;
  completion_rate: number | null;
  conversion_rate: number | null;
  average_cart_value: number;
  incremental_cart_value: number;
  // Phase 5 — commercial-outcome + detail-drawer fields (§16/§20): total
  // influenced order value, the engine's attribution window and the
  // session-count data-sufficiency signal.
  influenced_revenue: number;
  attribution_window_days: number;
  data_sufficiency: 'low' | 'medium' | 'high';
  attributed_revenue: number;
  assisted_revenue: number;
  reward_cost: number;
  reward_cost_available: boolean;
  profit_impact: number | null;
  profit_available: boolean;
  profit_reason: string | null;	profit_reason_code: ProfitReasonCode;
  profit_details: ProfitDetails;
  cost_coverage: CostCoverage;
  // Phase 3 — UI-ready profit availability metadata (§10).
  cost_sources: CostSource[];
  store_has_cost_data: boolean;
  // UPSELL_REFACTOR §30/§32/§33 — Smart Upsell linkage: how many of this
  // goal's completions were assisted by a product recommendation, the
  // assisted rate (assisted ÷ completed, null without completions), and
  // the goal's full upsell funnel (impressions → clicks → adds →
  // purchases) over the window.
  upsell_assisted: number;
  upsell_assisted_rate: number | null;
  upsell_funnel: {
    impressions: number;
    clicks: number;
    adds: number;
    orders: number;
  };
}

/** The `GET /goalcart/v1/revenue/goals` payload. */
export interface GoalPerformancePayload {
  items: GoalPerformanceRow[];
}

/** One upsell analytics row (`GET /goalcart/v1/revenue/upsells?analytics=1`). */
export interface UpsellAnalyticsRow {
  product_id: number;
  name: string;
  impressions: number;
  clicks: number;
  adds: number;
  orders: number;
  revenue: number;
  conversion_rate: number;
  upsell_score: number;
  /** Per-unit estimated margin — null when the store stores no costs. */
  estimated_profit: number | null;
  profit_available: boolean;
  margin_pct: number | null;
}

/** The six 0–100 upsell component scores (P33-34 breakdown). */
export interface UpsellComponentScores {
  price_gap: number;
  relevance: number;
  popularity: number;
  inventory: number;
  margin: number;
  conversion: number;
}

/** Historical upsell funnel stats for one product. */
export interface UpsellConversionStats {
  impressions: number;
  clicks: number;
  adds: number;
  orders: number;
  revenue: number;
  conversion_rate: number;
  available: boolean;
}

/** One ranked upsell product (`GET /goalcart/v1/revenue/upsells`). */
export interface UpsellRecommendation {
  product_id: number;
  name: string;
  permalink: string;
  price: number | null;
  price_html: string;
  image: string;
  stock_status: string;
  source: string;
  score: number;
  components: UpsellComponentScores;
  conversion: UpsellConversionStats;
  estimated_profit: number | null;
  profit_available: boolean;
  reasons: string[];
  factors: {
    price: number | null;
    gap_ratio: number | null;
    gap: number | null;
    sales: number;
    rating: number;
    margin_pct: number | null;
    stock_quantity: number | null;
    components: UpsellComponentScores;
  };
}

/**
 * The `GET /goalcart/v1/revenue/upsells` ranking payload (context mode).
 */
export interface UpsellRankingPayload {
  available: boolean;
  status: 'available' | 'unavailable';
  reason: string | null;
  context: {
    goal_id: number;
    cart_value: number;
    remaining: number | null;
    cart: number[];
    limit: number;
    goal_name: string;
  };
  candidates: number;
  weights: Record<string, number>;
  recommendations: UpsellRecommendation[];
  generated_at: string;
}

/**
 * The current performance of the goal being recommended for (UPSELL_REFACTOR
 * §9 — the "Current Goal" block of the recommendation detail). Built from
 * the same goal_metrics() the Goal Performance page reads, so the numbers
 * always agree with the analytics.
 */
export interface RecommendationGoalHistory {
  views: number;
  progressed: number;
  completed: number;
  converted: number;
  completion_rate: number | null;
  conversion_rate: number | null;
  /** Purchase rate (converted ÷ completed) — never confused with completion. */
  purchase_rate: number | null;
  current_target: number;
  reward_type: RewardType;
  attributed_sales: number;
  influenced_sales: number;
  estimated_profit: number | null;
  profit_available: boolean;
  upsell_assisted: number;
}

/**
 * One AOV-relative order-value distribution bucket
 * (GoalRecommendationEngine::distribution()): `share` is a 0–1 rate of
 * orders in this bucket (never a raw percentage), `min`/`max` are the
 * bucket edges in store currency (null for the open-ended outer buckets).
 */
export interface DistributionBucket {
  /** Translated bucket label (e.g. "< 0.5× AOV"). */
  label: string;
  min: number | null;
  max: number | null;
  count: number;
  share: number;
}

/** The analyzed store data block of a goal recommendation. */
export interface RecommendationData {
  aov: number;
  median: number;
  coefficient_of_variation: number;
  distribution: DistributionBucket[];
  shipping: {
    available: boolean;
    average_shipping: number | null;
    free_share: number | null;
  };
  margin: {
    available: boolean;
    average_margin_pct: number | null;
    /** Newest-catalog products sampled for the average. */
    sampled?: number;
    /** Of the sample, how many carried cost data. */
    with_cost?: number;
    reason?: string | null;
  } | null;
  goal_history: RecommendationGoalHistory | null;
  reward_type: string | null;
}

/**
 * The raw scoring factors behind one recommendation candidate
 * (Improvement.md §33 — shown only in the Advanced details).
 */
export interface RecommendationFactors {
  threshold: number;
  /** Candidate ÷ store AOV (null when AOV is 0). */
  aov_ratio: number | null;
  /** Candidate ÷ store median order value (null when median is 0). */
  median_ratio: number | null;
  /** Share of orders within the reach band below the threshold. */
  reach_share: number;
  /** Share of orders already at or above the threshold. */
  already_at_share: number;
  /** The four 0–100 component scores. */
  reachability_score: number;
  distance_score: number;
  economics_score: number;
  history_score: number;
  /** Estimated reward cost at this threshold (null when not costable). */
  reward_cost: number | null;
  reward_cost_available: boolean;
  /** Sampled average product margin (null when the store stores no costs). */
  margin_pct: number | null;
}

/** One scored recommendation candidate (`candidates[]`). */
export interface RecommendationCandidate {
  threshold: number;
  score: number;
  confidence: number;
  expected_aov_impact: { low: number; high: number };
  expected_completion_rate: number;
  expected_profit: number | null;
  expected_profit_available: boolean;
  reachable_orders_pct: number;
  reward_cost: number | null;
  reasons: string[];
  factors: RecommendationFactors;
}

/**
 * The `GET /goalcart/v1/revenue/goal-recommendations` payload — the
 * Recommendations page's data source (Phase 33.4; UICHANGES.md §40
 * label).
 */
export interface GoalRecommendationsPayload {
  available: boolean;
  /**
   * The goal the payload was computed for (null when unavailable). The
   * page validates it against the selected goal before rendering — a
   * recommendation can never belong to a different goal.
   */
  goal_id: number | null;
  status: string;
  insufficient_reason: string | null;
  window_days: number;
  from: string;
  to: string;
  orders: number;
  data: RecommendationData | null;
  candidates: RecommendationCandidate[];
  recommendation: RecommendationCandidate | null;
  generated_at: string;
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
