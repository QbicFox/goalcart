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
(public), stored in transients. Admin: 60 req/min. Public: 120 req/min.

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
{ "data": { "enabled": true, "fullscreen_dashboard": true } }
```

#### `POST /settings`

Saves a partial or full settings object. Only known keys are applied
(unknown keys are ignored), so saving the whole object never clobbers
keys the client does not know about. Returns the persisted settings, or
`goalcart_settings_empty` (400) when no known keys are present. The full
settings surface grows here in Phase 18.

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

---

## 3. Frontend API

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
        "goal_type": "amount",
        "is_money": true,
        "current": 250000,
        "target": 500000,
        "remaining": 250000,
        "percentage": 50,
        "completed": false,
        "message": "Only ۲۵۰٬۰۰۰ left to reach your goal",
        "reward": { "type": "free_shipping", "value": null, "max_value": null, "meta": {} },
        "suggestions": [],
        "reward_state": "locked",
        "eligible": true,
        "reason": ""
      }
    ],
    "currency": "IRR"
  },
  "meta": { "total_goals": 1 }
}
```

Notes:

- `message` is a minimal built-in placeholder until the Phase 13 message
  template engine ships; `suggestions` is always empty until Phase 14.
- `is_money` tells the widgets whether to format the goal's numbers as
  currency (amount/category/product/composite) or as plain numbers
  (quantity / distinct-quantity / weight) — it drives the milestone
  labels in `assets/js/frontend.js`.
- The payload contains only aggregate numbers for the shopper's own cart
  — no PII — which is what allows it to be public.
- The Phase 11 progress widgets poll this endpoint and re-render on every
  WooCommerce cart event (`added_to_cart`, `updated_cart_totals`,
  `wc_fragments_refreshed`, …), driven by the config object printed by
  `GoalCart\Frontend\ProgressUI` (`window.goalcartFrontend`).

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
| Analytics endpoints | Phase 16 (events) / Phase 17 (dashboard) — no analytics data exists yet |
| Customer-state campaign rules | Phase 32 (needs schema fields) |
| Message templates | Phase 13 (Dynamic Messaging) |
| Suggestions payload | Phase 14 (Smart Product Suggestions) |

## 6. Testing

`tests/rest-api-test.php` (102 checks, `php tests/rest-api-test.php`):

- route registration for every endpoint
- response envelope + pagination
- permission callbacks (anonymous 403 through a real server dispatch,
  public progress allowed)
- arg-schema validation (enums, ranges, datetime + campaign callbacks)
- goal create → get → list → duplicate → update → delete through the
  handlers, plus an end-to-end server dispatch of `/progress`
- settings read + save (success and error paths)
- campaign CRUD + milestone ordering: create, order/reorder goals,
  duplicate (inactive copy with copied milestones), delete (goals
  detached), schema + 404 paths
- all writes run inside a single database transaction that is rolled
  back; residue is asserted absent afterwards (read-only guarantee)
