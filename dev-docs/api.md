# FaraCart — REST API

## Overview

All endpoints live under the `faracart/v1` namespace. Admin endpoints require `manage_options` (filterable via `faracart_rest_capability`). Public endpoints are rate-limited per IP.

## Conventions

### Response Envelope

```json
{
  "data": { },
  "meta": { },
  "pagination": { "page": 1, "per_page": 20, "total": 12, "total_pages": 1 }
}
```

### Errors

```json
{
  "code": "faracart_mission_not_found",
  "message": "The mission could not be found.",
  "data": { "status": 404 }
}
```

### Rate Limiting

- Admin: 60 req/min per user
- Public: 120 req/min per IP
- Track endpoint: 300 req/min per IP

---

## Admin Endpoints

### Missions

| Endpoint | Method | Description |
|---|---|---|
| `GET /missions` | GET | Paginated list (page, per_page, status, search) |
| `GET /missions/{id}` | GET | Single mission |
| `POST /missions` | POST | Create (name, type required) |
| `PUT /missions/{id}` | PUT | Partial update |
| `DELETE /missions/{id}` | DELETE | Hard delete |
| `POST /missions/{id}/duplicate` | POST | Duplicate with (copy) suffix |

### Campaigns

| Endpoint | Method | Description |
|---|---|---|
| `GET /campaigns` | GET | All campaigns with mission_count |
| `GET /campaigns/{id}` | GET | Single campaign with ordered missions |
| `POST /campaigns` | POST | Create (name required) |
| `PUT /campaigns/{id}` | PUT | Partial update |
| `DELETE /campaigns/{id}` | DELETE | Delete (missions detached) |
| `POST /campaigns/{id}/duplicate` | POST | Duplicate (inactive copy) |

### Settings

| Endpoint | Method | Description |
|---|---|---|
| `GET /settings` | GET | Current settings + meta (hooks, log path) |
| `POST /settings` | POST | Save partial/full settings |

### Search

| Endpoint | Method | Description |
|---|---|---|
| `GET /search/products` | GET | Product search (q, ids, per_page) |
| `GET /search/categories` | GET | Category search |
| `GET /search/coupons` | GET | Coupon search |
| `GET /search/tags` | GET | Tag search |
| `GET /search/attributes` | GET | Attribute search |
| `GET /search/zones` | GET | Shipping zone search |

### Preview

| Endpoint | Method | Description |
|---|---|---|
| `POST /preview` | POST | Admin-only preview against simulated cart |

Body: `mission_id` XOR `campaign_id` + `simulated: { amount, quantity }`

### Analytics

| Endpoint | Method | Description |
|---|---|---|
| `GET /analytics` | GET | Dashboard metrics (from, to, filters) |

### Templates

| Endpoint | Method | Description |
|---|---|---|
| `GET /templates` | GET | Registered templates + schemas + defaults |

### Revenue (Phase 33)

| Endpoint | Method | Description |
|---|---|---|
| `GET /revenue/overview` | GET | Sales Performance overview |
| `GET /revenue/missions` | GET | Mission performance comparison |
| `GET /revenue/attribution` | GET | Attribution dashboard |
| `GET /revenue/mission-recommendations` | GET | Smart mission recommendations |
| `POST /revenue/mission-recommendations/apply` | POST | Apply recommended threshold |
| `GET /revenue/cost-coverage` | GET | Product cost coverage |
| `GET /revenue/upsells` | GET | Upsell ranking |
| `GET /revenue/upsells/{product_id}` | GET | Product score breakdown |

---

## Public Endpoints

### Progress

```
GET /faracart/v1/progress
```

Evaluates every active mission against the current cart. Returns per-mission:

- `current`, `target`, `remaining`, `percentage`, `completed`
- `state`, `message`, `reward`, `suggestions`
- `template`, `template_settings`, `icon`
- `conflict` (resolution status)
- `completion` (per-user completion limit status)

Never cached (`Cache-Control: no-store`). Self-healing tracking nonce included.

### Track

```
POST /faracart/v1/track
```

Nonce-guarded analytics event reporting. Event types: `mission_impression`, `mission_progress`, `mission_completed`, `reward_activated`, `suggestion_impression`, `suggestion_clicked`.

`suggested_product_added` is server-side only (attributed on `woocommerce_add_to_cart`).

### Gift

```
POST /faracart/v1/gift
```

Nonce-guarded. Claims a chosen gift for the `choose` gift mode.

### Upsell

```
GET /faracart/v1/upsell/rank
POST /faracart/v1/upsell/track
```

Public rank endpoint for storefront upsell panel. Track endpoint for upsell interaction events.

---

## Authentication

- **Admin:** WP core cookie auth + `X-WP-Nonce` header
- **Public:** Plugin-specific nonce (`faracart_track`) from `window.faracartTracking`
- **Capability:** `manage_options` (filterable via `faracart_rest_capability`)
