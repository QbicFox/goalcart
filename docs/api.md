# Goal Cart REST API (Phase 7)

The plugin exposes a clean API for the React admin app and the frontend
progress widgets through a single REST namespace:

```text
goalcart/v1
```

All conventions mirror the reference plugin (`wooinsights`): a shared
`BaseController` provides the namespace, permission callbacks, response
envelope and rate limiting, and every controller registers its routes on
`rest_api_init` through the `HookManager`.

---

## 1. Conventions

### 1.1 Response envelope

Every successful response uses the standard envelope (mirroring
`ApiEnvelope` in `admin-app/src/types.ts`):

```json
{
  "data": { },
  "meta": { },
  "pagination": { "page": 1, "per_page": 20, "total": 12, "total_pages": 1 }
}
```

`meta` and `pagination` are present only when meaningful.

### 1.2 Errors

Every failure returns a structured `WP_Error`, serialized by WP core as:

```json
{
  "code": "goalcart_goal_not_found",
  "message": "The goal could not be found.",
  "data": { "status": 404 }
}
```

Machine-readable codes, human-readable (translatable) messages, and HTTP
status codes are always set. The common codes:

| Code | Status | Meaning |
|---|---:|---|
| `goalcart_forbidden` | 403 | Missing capability |
| `goalcart_rate_limited` | 429 | Rate limit exceeded (`retry_after` in data) |
| `goalcart_goal_not_found` / `goalcart_campaign_not_found` | 404 | Resource missing |
| `goalcart_preview_target_required` | 400 | Neither / both of `goal_id` + `campaign_id` given |
| `goalcart_preview_not_found` | 404 | Preview target (goal/campaign) missing |
| `goalcart_invalid_nonce` | 403 | Invalid tracking nonce (`/track`) |
| `goalcart_tracking_disabled` | 403 | Tracking master toggle off (`/track`) |
| `goalcart_invalid_event_type` | 400 | Unknown event type (`/track`, direct handler) |
| `goalcart_track_failed` | 500 | Event insert failed (`/track`) |
| `goalcart_settings_empty` | 400 | No known settings keys in the payload |
| `rest_invalid_param` | 400 | Arg-schema validation failure |
| `goalcart_*_create/update/delete_failed` | 500 | Persistence failure |

### 1.3 Authentication & capability

- **Admin endpoints** require the `manage_options` capability (filterable
  via `goalcart_rest_capability`). Logged-in admin requests are
  authenticated by WP core cookie auth, which also validates the
  `X-WP-Nonce` header (the admin app sends the `wp_rest` nonce from its
  boot data). Anonymous access is rejected with 403.
- **Public endpoint** (`/progress`) requires no capability — guests must
  be able to read their own cart progress — and is rate limited per IP.

### 1.4 Rate limiting

Fixed-window limiters, keyed by user + route (admin) or IP + route
(public), stored in transients. Admin: 60 req/min. Public: 120 req/min
(the `/track` route gets 300 req/min per IP — the widgets report events
on every cart refresh).

---

## 2. Admin API

All admin endpoints are `manage_options`-gated. Base URL:
`https://site/wp-json/goalcart/v1`.

### 2.1 Goals

#### `GET /goals`

Paginated goal list. Query args:

| Arg | Type | Default | Notes |
|---|---|---|---|
| `page` | int ≥ 1 | 1 | |
| `per_page` | int 1–100 | 20 | |
| `status` | `''`/`active`/`inactive` | `''` | empty = all |
| `search` | string | `''` | name `LIKE` filter |

Response: `pagination` envelope; each item is a full goal object (see
below).

#### `GET /goals/{id}`

Single goal object, or `goalcart_goal_not_found` (404).

#### `POST /goals`

Create a goal. `name` and `type` are required; every other field is
optional (schema defaults apply). Returns the created goal object.

#### `PUT /goals/{id}`

Partial update — only the keys present in the body are written. Returns
the updated goal object.

#### `DELETE /goals/{id}`

Hard delete (analytics history survives via `ON DELETE SET NULL`).
Returns `{ "deleted": true, "id": N }`.

#### `POST /goals/{id}/duplicate`

Copies the goal with a ` (copy)` name suffix. Returns the new goal.

#### Goal object

```json
{
  "id": 5,
  "name": "Free shipping",
  "description": "",
  "status": "active",
  "type": "amount",
  "target": 500000,
  "calculation_mode": "subtotal",
  "categories": [],
  "products": [],
  "excluded_products": [],
  "operator": "and",
  "children": [],
  "reward_type": "free_shipping",
  "reward_value": null,
  "reward_max_value": null,
  "reward_meta": {},
  "priority": 10,
  "exclusive": false,
  "campaign_id": null,
  "menu_order": 0,
  "starts_at": null,
  "ends_at": null,
  "display_settings": {},
  "limits": {},
  "created_at": "2026-08-07 10:00:00",
  "updated_at": "2026-08-07 10:00:00"
}
```

Validation highlights (all enforced by the route arg schemas):

- `type` — one of `amount`, `quantity`, `distinct_quantity`, `category`,
  `product`, `weight`, `composite`.
- `calculation_mode` — `subtotal`, `total`, `discounted_subtotal`,
  `quantity`.
- `reward_type` — `free_shipping`, `percent_discount`, `fixed_discount`,
  `free_gift`, `coupon`, or `null`.
- `status` — `active` / `inactive`; `operator` — `and` / `or`.
- `target` — number ≥ 0.
- `priority` — int ≥ 0 (lower wins conflicts); `exclusive` — boolean
  (mutually exclusive goal, Phase 26).
- `campaign_id` — 0 (none) or an existing campaign id.
- `starts_at` / `ends_at` — `Y-m-d` or `Y-m-d H:i:s` or `null`.

Persistence mapping: `categories`, `products`, `excluded_products`,
`operator` and `children` are stored inside the `conditions` JSON column;
`reward_meta`, `display_settings` and `limits` are stored as JSON. The
repository spreads them back onto the Goal model on read, so persisted
category/product/composite goals evaluate correctly.

### 2.2 Settings

#### `GET /settings`

```json
{
  "data": {
    "enabled": true,
    "fullscreen_dashboard": true,
    "currency_display": "symbol",
    "default_goal_behavior": "all",
    "conflict_resolution": "cumulative",
    "calculation_mode": "subtotal",
    "frontend_template": "basic",
    "frontend_animation": true,
    "frontend_locations": ["cart", "mini-cart", "checkout", "shop", "product", "sticky"],
    "frontend_mobile": "show",
    "frontend_bar_height": 10,
    "frontend_accent": "#2271b1",
    "frontend_bg": "#ffffff",
    "frontend_border": "#dcdcde",
    "frontend_text": "#1d2327",
    "frontend_radius": 10,
    "frontend_css_class": "",
    "frontend_custom_css": "",
    "calculation_include_tax": false,
    "calculation_include_discount": true,
    "calculation_include_shipping": true,
    "calculation_include_sale": true,
    "calculation_include_virtual": true,
    "performance_caching": false,
    "analytics_enabled": true,
    "performance_suggestions": true,
    "debug_mode": false,
    "logging_enabled": false,
    "developer_hooks": true
  },
  "meta": {
    "hooks": [
      { "type": "filter", "hook": "goalcart_suggestions_enabled", "description": "..." }
    ],
    "log_path": "/path/to/wp-content/goalcart-debug.log"
  }
}
```

`meta.hooks` (Phase 18 Advanced → developer hooks) carries the public
`goalcart_*` hooks reference rendered by the Settings page;
`meta.log_path` is present only while `logging_enabled` is on.

#### `POST /settings`

Saves a partial or full settings object. Only known keys are applied
(unknown keys are ignored), so saving the whole object never clobbers
keys the client does not know about. Returns the persisted settings, or
`goalcart_settings_empty` (400) when no known keys are present.

Every key is validated by the route arg schema and normalized by the
sanitizer (direct handler saves included). The full Phase 18 surface:

| Key | Type / constraints | Sanitization |
|---|---|---|
| `enabled` / `fullscreen_dashboard` | boolean | cast |
| `currency_display` | enum `symbol` `code` `name` | unknown → `symbol` |
| `default_goal_behavior` | enum `all` `first` `closest` | unknown → `all` |
| `conflict_resolution` | enum `cumulative` `best` `first` | unknown → `cumulative` |
| `calculation_mode` | enum `subtotal` `discounted_subtotal` `total` | unknown → `subtotal` |
| `frontend_template` | enum `basic` `percentage` `milestone` `card` | unknown → `basic` |
| `frontend_animation` | boolean | cast |
| `frontend_locations` | array of the location enum (`cart` `mini-cart` `checkout` `shop` `product` `sticky`) | filtered + deduped |
| `frontend_mobile` | enum `show` `hide` | unknown → `show` |
| `frontend_bar_height` | int 4–48 | clamped |
| `frontend_accent` / `bg` / `border` / `text` | string (hex color) | `sanitize_hex_color`, invalid → default |
| `frontend_radius` | int 0–40 | clamped |
| `frontend_css_class` | string | `sanitize_text_field` (tags stripped) |
| `frontend_custom_css` | string | tag-stripped, capped at 16 KB (admin-authored CSS) |
| `calculation_include_tax` / `_discount` / `_shipping` / `_sale` / `_virtual` | boolean | cast |
| `performance_caching` / `analytics_enabled` / `performance_suggestions` | boolean | cast |
| `debug_mode` / `logging_enabled` / `developer_hooks` | boolean | cast |

Saving identical settings is a successful no-op (an unchanged option is
not a failure). A successful save fires the `goalcart_settings_saved`
action and logs a debug entry when logging is enabled.

### 2.3 Search (goal builder)

Server-side, capped at 50 results (`no_found_rows`).

| Endpoint | Returns |
|---|---|
| `GET /search/products?q=&ids=&per_page=` | `{ id, name, type, sku, price, stock_status, permalink }` for products & variations |
| `GET /search/categories?q=&ids=&per_page=` | `{ id, name, slug, parent, count }` product_cat terms |
| `GET /search/coupons?q=&ids=&per_page=` | `{ id, code, discount_type, amount }` shop_coupon posts |

All three accept an optional `ids` array (repeated query args, e.g.
`ids=5&ids=12`). When present, the result is narrowed to exactly those
ids — the Phase 9 goal builder uses this to preload already-selected
products/categories/coupons when editing a goal. Non-positive ids are
rejected by the arg schema.

### 2.4 Campaigns

Campaign CRUD + milestone ordering (Phase 10 — Campaign Builder):

- `GET /campaigns` — all campaigns, each with `goal_count`.
- `GET /campaigns/{id}` — one campaign (with its ordered `goals`), or
  `goalcart_campaign_not_found` (404).
- `POST /campaigns` — create. `name` is required.
- `PUT /campaigns/{id}` — partial update.
- `DELETE /campaigns/{id}` — delete; the campaign's goals are detached
  (`campaign_id` → null) and survive for reuse.
- `POST /campaigns/{id}/duplicate` — copy the campaign (name ` (copy)`
  suffix, starts **inactive**) plus its goals as new goal rows.

#### Campaign object

```json
{
  "id": 3,
  "name": "Summer Sale",
  "description": "",
  "status": "active",
  "starts_at": null,
  "ends_at": null,
  "priority": 10,
  "display_rules": {},
  "goal_count": 2,
  "goals": [
    { "id": 8, "name": "Free shipping", "type": "amount", "target": 500000, "reward_type": "free_shipping", "menu_order": 1 },
    { "id": 9, "name": "Free gift", "type": "amount", "target": 1000000, "reward_type": "free_gift", "menu_order": 2 }
  ],
  "created_at": "2026-08-08 10:00:00",
  "updated_at": "2026-08-08 10:00:00"
}
```

Milestone ordering: create/update accept an ordered `goals` array of goal
ids (e.g. `{ "goals": [8, 9] }`); the repository assigns
`goals.campaign_id` + `goals.menu_order` accordingly and detaches goals
removed from the list. Validation: `name` (required on create),
`status` (`active`/`inactive`), `starts_at`/`ends_at` (`Y-m-d` or
`Y-m-d H:i:s`), `priority` (≥ 0), `display_rules` (object),
`goals` (array of positive ints).

### 2.5 Preview (Phase 15)

#### `POST /preview` — admin-only, rate limited per user

Evaluates a goal (or a campaign's milestone goals) against a **simulated
cart** and returns the exact same per-goal payload shape as the public
`GET /progress` endpoint, so the admin preview renders the real
storefront widget before publishing. The simulation never touches the
real WooCommerce cart, and publish gating is ignored (goals preview as
active and in-schedule) so drafts and scheduled campaigns can be seen
first.

Body args:

| Arg | Type | Default | Notes |
|---|---|---|---|
| `goal_id` | int ≥ 0 | 0 | Preview a single goal (XOR with `campaign_id`) |
| `campaign_id` | int ≥ 0 | 0 | Preview a campaign's milestone ladder |
| `simulated.amount` | number ≥ 0 | 0 | Simulated cart amount (money goals) |
| `simulated.quantity` | number ≥ 0 | 0 | Simulated item quantity (count goals) |

Exactly one of `goal_id` / `campaign_id` is required — neither or both
returns `goalcart_preview_target_required` (400); a missing target
returns `goalcart_preview_not_found` (404). Unknown keys inside
`simulated` are rejected by the arg schema.

```json
{
  "goal_id": 5,
  "simulated": { "amount": 500000, "quantity": 1 }
}
```

Response:

```json
{
  "data": {
    "goals": [
      {
        "goal_id": 5,
        "goal_name": "Free shipping",
        "goal_type": "amount",
        "is_money": true,
        "current": 250000,
        "target": 500000,
        "remaining": 250000,
        "percentage": 50,
        "completed": false,
        "state": "progressing",
        "message": "Only ۲۵۰٬۰۰۰ left to reach your goal",
        "reward": { "type": "free_shipping", "value": null, "max_value": null, "meta": {} },
        "suggestions": [],
        "reward_state": "locked",
        "eligible": true,
        "reason": "",
        "conflict": { "resolved": true, "reason": "" }
      }
    ],
    "currency": "IRR",
    "simulated": { "amount": 500000, "quantity": 1 }
  },
  "meta": { "mode": "goal" }
}
```

`meta.mode` is `goal` or `campaign`; campaign previews return every
milestone goal in `menu_order` (each with the same shape), which is what
drives the admin's milestone-ladder rendering. The payload is built by
the shared `FrontendController::shape_goal()` — identical to `/progress`.

### 2.6 Analytics (Phase 17)

#### `GET /analytics` — admin-only, rate limited per user

The single read-only endpoint powering the admin Analytics dashboard.
It computes the Phase 16 metrics over the filtered window and returns
them in one payload, so the page renders with a single request.

Query args (all validated by the route arg schema):

| Arg | Type | Default | Notes |
|---|---|---|---|
| `from` / `to` | `Y-m-d` or `Y-m-d H:i:s` | last 30 days | Inclusive window (`from` is taken from 00:00:00, `to` to 23:59:59 on the trend) |
| `campaign_id` | int ≥ 0 | 0 | Restrict to one campaign |
| `goal_id` | int ≥ 0 | 0 | Restrict to one goal |
| `goal_ids` | int[] ≥ 1 | — | Restrict to a set of goals (`IN` clause) |
| `reward` | enum | `''` | `free_shipping` `percent_discount` `fixed_discount` `free_gift` `coupon` (empty = all) |
| `product_id` | int ≥ 0 | 0 | Restrict to one product (suggestion metrics) |
| `limit` | int 1–20 | 5 | Max entries per top list |

Response:

```json
{
  "data": {
    "summary": {
      "impressions": 512,
      "completions": 87,
      "completion_rate": 0.1699,
      "average_cart_value": 142.0,
      "revenue_influenced": 760.0,
      "suggestion_ctr": 0.3333,
      "suggestion_add_to_cart_rate": 0.5
    },
    "trend": [
      { "date": "2026-07-10", "impressions": 12, "completions": 3, "revenue": 300.0 }
    ],
    "top_campaigns": [
      { "id": 3, "name": "Summer Sale", "impressions": 40, "completions": 10, "revenue": 5000.0, "completion_rate": 0.25 }
    ],
    "top_goals": [
      { "id": 8, "name": "Free shipping", "impressions": 30, "completions": 9, "revenue": 4500.0, "completion_rate": 0.3 }
    ],
    "top_suggested_products": [
      { "product_id": 42, "name": "Wireless Charger", "impressions": 120, "clicks": 18, "added": 6, "ctr": 0.15, "add_to_cart_rate": 0.3333 }
    ]
  },
  "meta": {
    "applied": { "from": "2026-07-10", "to": "2026-08-08", "campaign_id": 0, "goal_id": 0, "goal_ids": null, "product_id": 0, "reward": "", "limit": 5 }
  }
}
```

Semantics:

- `summary` is the seven Phase 16 metrics (impressions, completions =
  goal_completed + reward_activated, completion rate, average cart value
  at impression, revenue associated with completed goals, suggestion
  CTR, suggestion add-to-cart rate), all with zero-denominator guards.
- `trend` is one daily point per day of the window (default last 30
  days), zero-filled so the chart is continuous; `revenue` sums
  `cart_value` at completion events.
- `top_campaigns` / `top_goals` rank by completions (then impressions),
  joining the campaigns/goals tables for names; `top_suggested_products`
  ranks by conversions (added → clicks → impressions) and joins
  `wp_posts` for product names.
- `meta.applied` echoes the exact filters that produced the payload.

Errors: `goalcart_forbidden` (403, anonymous).

### `GET /progress` — public, rate limited per IP

Evaluates every active goal against the current shopper's cart and
exposes only the minimum data the widgets need:

```json
{
  "data": {
    "goals": [
      {
        "goal_id": 5,
        "goal_name": "Free shipping",
        "goal_type": "amount",		"is_money": true,
		"icon": "",
		"template": "card",
		"current": 250000,
        "target": 500000,
        "remaining": 250000,
        "percentage": 50,
        "completed": false,
        "state": "progressing",		"message": "Only ۲۵۰٬۰۰۰ left to reach your goal",
		"reward": { "type": "free_shipping", "value": null, "max_value": null, "meta": {} },
		"suggestions": [
			{
				"id": 42,
				"name": "Wireless Charger",
				"permalink": "https://site/product/wireless-charger/",
				"price": 150000,
				"price_html": "۱۵۰٬۰۰۰ تومان",
				"image": "",
				"stock_status": "instock",
				"source": "upsell"
			}
		],
		"reward_state": "locked",
        "eligible": true,
        "reason": "",
        "conflict": { "resolved": true, "reason": "" }
      }
    ],
    "currency": "IRR"
  },
  "meta": { "total_goals": 1 }
}
```

Notes:

- `message` is rendered by the Phase 13 MessageEngine: state-aware
  (inactive / unavailable / progressing / nearly_complete / completed /
  reward_activated), variable-substituted ({current}, {target},
  {remaining}, {percentage}, {quantity}, {remaining_quantity},  {reward}, {goal_name}, {campaign_name}) and overridable through the goal's
  `display_settings.message` / `completed_message`. `state` carries the
  raw state for styling.
- `suggestions` (Phase 14 — Smart Product Suggestions) is a capped list
  (max 4) of products that close the gap to the goal. Each item carries
  `id`, `name`, `permalink`, `price`, `price_html` (server-formatted via
  `wc_price` — the widget renders this, falling back to the raw price),
  `image`, `stock_status` and `source` (`manual` `category` `upsell`
  `cross_sell` `related` `recently_viewed` `best_seller`). Candidates
  come from the goal's own products, its categories, the cart items'
  upsells/cross-sells/related products, the shopper's recently-viewed
  cookie, and best sellers; out-of-stock products, cart items, excluded
  products and ghost ids are never suggested. Ranking: stock first, then
  goal eligibility (+3 manual, +2 counts toward the goal), relevance
  (shares a cart category +1), WC-endorsed sources (+0.5) and, for money
  goals, price proximity to `remaining` — products in the 0.6–1.4× band
  score +2 (the spec's "prefer 150K–220K when 180K is left"). The final
  list is filterable via the `goalcart_suggestions` filter.
- `is_money` tells the widgets whether to format the goal's numbers as
  currency (amount/category/product/composite) or as plain numbers
  (quantity / distinct-quantity / weight) — it drives the milestone
  labels in `assets/js/frontend.js`.
- `icon` is the goal's Display icon (`display_settings.icon`, empty when
  none was configured) — the Phase 12 card template renders it, falling
  back to its own default icon.
- `template` is the goal's own Display template
  (`display_settings.template`, normalized to the `basic` `percentage`
  `milestone` `card` enum, empty when none was configured). The widget
  uses it for that goal (container override → goal template → global
  Appearance template → `basic`), so the goal builder's template picker
  takes effect on the storefront per goal.
- `conflict` (Phase 26) is the per-goal conflict-resolution fragment:
  `{ "resolved": true|false, "reason": "" | "not_first" | "not_best" |
  "exclusive" | "stacking" | "lower_priority" }`. `resolved: false`
  means the goal reached its target but its reward is suppressed by the
  store's conflict rules (`conflict_resolution` mode, an exclusive goal,
  or the per-reward stacking safety — a same-type non-stacking reward
  never both grants and displays as won) — the widget renders such a
  reward as locked, never unlocked, and the analytics layer records
  `goal_completed` instead of `reward_activated` for it. The same
  fragment appears in the admin preview payload, which renders a
  "Blocked — …" chip for suppressed milestones. The reasons are always
  exactly what the live cart grants: the payload resolves `best` with
  the same computed reward amounts the reward engine uses, and applies
  the same stacking suppression in the same priority order. See
  `docs/conflicts.md` for the full rule set.
- The payload contains only aggregate numbers for the shopper's own cart
  — no PII — which is what allows it to be public.
- **Never cached** — the response carries
  `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`. WP
  core only sends nocache headers for cookie-authenticated requests, so a
  bare guest GET could otherwise be heuristically cached by the browser
  and the widget would keep showing the previous cart's progress after
  the shopper adds or removes items. `assets/js/frontend.js` additionally
  cache-busts each poll with a `?_=<timestamp>` parameter (both are
  asserted by `tests/frontend-test.php`).
- The Phase 11 progress widgets poll this endpoint and re-render on every
  WooCommerce cart event (`added_to_cart`, `updated_cart_totals`,
  `wc_fragments_refreshed`, …), driven by the config object printed by
  `GoalCart\Frontend\ProgressUI` (`window.goalcartFrontend`). The config
  also carries the Phase 12 template variant, the animation flag and the
  resolved appearance tokens (`template`, `animation`, `appearance`), so
  the widgets render the configured storefront template without another
  round-trip.

### `POST /track` — public, nonce-guarded, rate limited per IP (Phase 16)

The storefront JS reports analytics events to this endpoint. It is public
(guests are the analytics population) but protected by the plugin's own
tracking nonce (from the `window.goalcartTracking` config printed by
`GoalCart\Analytics\Tracker`) instead of an admin capability, plus a
generous per-IP rate limit (300/min — the widgets fire events on every
cart refresh).

Body args (all typed in the route arg schema):

| Arg | Type | Default | Notes |
|---|---|---|---|
| `event_type` | string (required) | — | One of the seven event types (below) — validated against the whitelist |
| `goal_id` | int ≥ 0 | 0 | Goal the event is about |
| `campaign_id` | int ≥ 0 | 0 | Campaign the goal belongs to |
| `product_id` | int ≥ 0 | 0 | Suggested product (suggestion events) |
| `cart_value` | number ≥ 0 | 0 | Cart money value at event time |
| `percentage` | number 0–100 | 0 | Progress percentage (goal_progress), stored in `meta` |
| `session_id` | string | cookie | Anonymous session id; the request cookie is the fallback |
| `nonce` | string (required) | — | `goalcart_track` nonce from the tracking config |

Event types (whitelist): `goal_impression`, `goal_progress`,
`goal_completed`, `reward_activated`, `suggestion_impression`,
`suggestion_clicked` — plus `suggested_product_added`, which is
**server-side only** (attributed on `woocommerce_add_to_cart`, never
accepted from the client, so a conversion can never be self-reported).

**Trust boundary:** the six client-reported events are directional
analytics signals, not audited counters — a visitor holding the page
nonce could inflate completion counts. The JS dedupes per page session
and the `suggested_product_added` conversion is server-verified; treat
the dashboard metrics accordingly.

Response: `{ "data": { "id": 42 } }`. Errors: `goalcart_invalid_nonce`
(403), `goalcart_tracking_disabled` (403), `rest_invalid_param` (400,
bad event type or field), `goalcart_track_failed` (500).

---

## 4. Security checklist (P07-T04)

- [x] Authentication where required — WP core cookie auth + `X-WP-Nonce`
- [x] Capability checks — `manage_options` (filterable) on every admin route
- [x] Nonce validation — `wp_rest` nonce for logged-in admin requests
- [x] Input validation — REST arg schemas (types, enums, ranges, custom
      `validate_callback`s)
- [x] Sanitization — `sanitize_callback`s + per-column repository
      sanitization (`sanitize_text_field`, `sanitize_key`, casts)
- [x] Output escaping/serialization — WP core serializes responses; only
      known fields are shaped into payloads
- [x] Predictable error responses — structured `WP_Error`s with codes,
      messages and statuses
- [x] Rate limiting — per-user (admin) and per-IP (public)

## 5. Deliberate deferrals

| Surface | Where it lands |
|---|---|
| Customer-state campaign rules | Phase 32 (needs schema fields) |

## 6. Testing

`tests/analytics-test.php` (72 checks, `php tests/analytics-test.php`):

- service wiring (Session / Tracker / AnalyticsRepository / TrackController
  resolve and register their hooks)
- anonymous sessions: 32-hex ids, cookie validation
- the event-type whitelist (all seven types, nothing else)
- recording: rows carry goal/campaign/product ids, cart value, session,
  scalar-only meta; guest `user_id` stays NULL
- privacy: the events table has no PII columns; the `goalcart_tracking_enabled`
  filter and the master toggle both gate recording
- the public `/track` route: arg-schema whitelist, bad nonce → 403, valid
  dispatch records end-to-end
- `suggested_product_added` attribution: attributed only when the session
  saw a suggestion_impression for that product (fresh/unseen sessions are
  never attributed), and the FK-resilience retry for deleted goals
- all seven metrics over seeded events (impressions, completions,
  completion rate, average cart value, revenue on completed goals,
  suggestion CTR, suggestion add-to-cart rate) + campaign/goal/date
  filters + zero-denominator guards
- full rollback verification (no residue)

`tests/analytics-dashboard-test.php` (82 checks,
`php tests/analytics-dashboard-test.php`):

- service wiring + route registration (GET-only `/analytics`)
- arg-schema validation: dates, the reward-type whitelist, `goal_ids`
  items, the `limit` clamp
- permissions: anonymous dispatch → 403, authenticated administrator
  dispatch → 200 with the full payload
- summary KPIs over seeded events (impressions 5, completions 3,
  completion rate 0.6, average cart value 142, revenue influenced 760,
  CTR 0.3333, add-to-cart rate 0.5)
- the daily trend: window-length zero-filled series summing exactly to
  the seeded totals, multi-day buckets
- top goals / top campaigns / top suggested products: ranking, resolved
  names, derived rates
- every filter (campaign, goal, goal ids, reward type, product, future
  window, limit) slicing the payload correctly
- full rollback verification (no residue)

`tests/preview-test.php` (90 checks, `php tests/preview-test.php`):

- route registration + arg-schema validation (simulated object, negative
  values, unknown keys, exactly-one-target rule)
- permission: anonymous rejected on `/preview` (admin-only)
- preview states against a simulated cart (empty / 50% / completed) with
the live cart's contents asserted byte-identical afterwards
- every goal type's simulated context: quantity, distinct-quantity,
category (quantity + money modes), product (and the honest
no-matching-items ineligibility), weight and composite (AND) goals
- publish-gating bypass (inactive goal previews as active)
- campaign preview: all milestone goals evaluated in order (the
"multiple milestones" state)
- error paths (400 / 404) and full rollback verification

`tests/settings-test.php` (119 checks, `php tests/settings-test.php`):

- defaults for every Phase 18 key (each preserving the pre-Phase-18
  behavior) + the `goalcart_default_calculation_mode` filter wiring
- the store-wide calculation mode: amount/category goals follow it,
  quantity-style goals keep their type defaults
- REST schema coverage (enums for currency display / goal behavior /
  calculation mode / mobile, the location items enum, booleans) and the
  sanitizer's normalization of invalid values through `handle_save`
- goal calculation toggles: `include_tax` (line-tax folding into
  subtotal/discounted bases), `include_discount`, `include_shipping`
  (incl. legacy `exclude_shipping` precedence), `include_sale` and
  `include_virtual` (items dropped and bases rebased), plus
  `CartIntegration` applying the settings to a live cart snapshot
- frontend: locations follow the setting, sticky gated on the 'sticky'
  location, the locations filter override, and the config carrying
  `currencyDisplay` + `mobile`
- goal behavior `all` / `first` / `closest` narrowing the progress
  payload, and progress caching (off → no transient, on → payload
  written, sentinel payload served verbatim on read)
- performance toggles: `analytics_enabled` gates the tracker and
  `performance_suggestions` empties the suggestion list (with the
  `goalcart_suggestions_enabled` filter override)
- advanced: the GET settings meta carries the developer-hooks reference,
  and the Logger respects `logging_enabled` + `debug_mode` (error vs
  debug levels, log path in meta, cleanup)
- every real write (settings option, goals, transients, log file) is
  rolled back / removed and residue is asserted

`tests/rest-api-test.php` (120 checks, `php tests/rest-api-test.php`):

- route registration for every endpoint
- response envelope + pagination
- permission callbacks (anonymous 403 through a real server dispatch,
  public progress allowed)
- arg-schema validation (enums, ranges, datetime + campaign callbacks)
- goal create → get → list → duplicate → update → delete through the
  handlers, plus an end-to-end server dispatch of `/progress`
- settings read + save (success and error paths) — including Phase 12
  progress-template sanitization (enum fallback, color fallback, range
  clamping, tag-stripping) and the REST schema validation of the new keys
- Phase 13 messaging: the public /progress payload carries the
  engine-rendered message (no unresolved placeholders) and the message
  state
- per-goal display template: `display_settings.template` persists on
  create and the /progress payload carries the normalized per-goal
  `template`
- campaign CRUD + milestone ordering: create, order/reorder goals,
  duplicate (inactive copy with copied milestones), delete (goals
  detached), schema + 404 paths
- all writes run inside a single database transaction that is rolled
  back; residue is asserted absent afterwards (read-only guarantee)
