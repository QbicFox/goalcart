# FaraCart — Missions

## Overview

A **mission** is a target the customer can reach (e.g., cart amount, item quantity, category spend). Missions are the core unit of FaraCart — they drive progress bars, trigger rewards, and power the smart upsell engine.

## Mission Types

| Type | Current value | Calculation basis |
|---|---|---|
| `amount` | Cart money value | `subtotal` · `total` · `discounted_subtotal` |
| `quantity` | Total item quantity (decimal-aware) | — |
| `distinct_quantity` | Unique products/SKUs | — |
| `category` | Amount or quantity restricted to categories | `subtotal` · `total` · `discounted_subtotal` · `quantity` |
| `product` | Quantity or amount of specific products | `quantity` · `subtotal` · `total` · `discounted_subtotal` |
| `weight` | Σ quantity × unit weight | — |
| `composite` | AND/OR combination of child missions | — |
| `tag` | Amount or quantity restricted to product tags | — |
| `attribute` | Amount or quantity restricted to product attributes | — |
| `brand` | Amount or quantity restricted to brands | — |

### Composite Semantics

- **AND** — progress = weakest child (min %); completed when every child completes.
- **OR** — progress = best child (max %); completed when any child completes.

## Mission Model

| Field | Type | Notes |
|---|---|---|
| `id` | bigint | Auto-increment PK |
| `name` | varchar(191) | Display name |
| `description` | text | Internal description |
| `status` | varchar(20) | `active` / `inactive` |
| `type` | varchar(20) | Mission type |
| `target` | decimal(19,4) | Threshold |
| `calculation_mode` | varchar(20) | `subtotal` / `total` / `discounted_subtotal` / `quantity` |
| `reward_type` | varchar(20) | `free_shipping` / `percent_discount` / `fixed_discount` / `free_gift` / `coupon` |
| `reward_value` | decimal(19,4) | Reward amount/percentage |
| `reward_max_value` | decimal(19,4) | Cap on reward |
| `reward_meta` | JSON | Extended reward config (eligible products/categories, stacking, gift settings) |
| `conditions` | JSON | Category/product/role/cart conditions |
| `display_settings` | JSON | Template, messages, icon, template_id, template_settings |
| `priority` | int | Conflict resolution (lower wins) |
| `exclusive` | tinyint | Mutually exclusive with lower-priority missions |
| `max_completions_per_user` | int | Per-user completion limit (NULL = unlimited) |
| `starts_at` / `ends_at` | datetime | Schedule window |
| `campaign_id` | bigint | FK to campaigns (NULL = standalone) |
| `menu_order` | int | Milestone ordering within a campaign |
| `limits` | JSON | Per-customer, per-session, stack limits |

## Mission Engine

The engine (`includes/Missions/MissionEngine.php`) is pure, UI-independent, and stateless:

```
CartContext → MissionEvaluator → MissionResult → ProgressCalculator
```

### MissionResult Contract

| Field | Meaning |
|---|---|
| `current` | Current value for the mission's basis |
| `target` | Threshold (clamped ≥ 0) |
| `remaining` | `target − current`, never negative |
| `percentage` | 0–100, capped at 100 |
| `completed` | `current >= target` |
| `eligible` | Whether the mission applies to this cart/shopper |
| `reason` | Why not eligible: `mission_inactive`, `out_of_schedule`, `invalid_target`, `no_matching_items`, `unknown_type`, `customer_conditions`, `first_order_only`, `vip_only`, `shipping_zone`, `cart_conditions` |

### Extension Point

```php
add_filter( 'faracart_mission_evaluator_classes', function ( $classes ) {
    $classes['membership'] = My_Membership_Evaluator::class;
    return $classes;
} );
```

## Conditions

Missions support rich conditions stored in the `conditions` JSON column:

- **Categories** — restrict to specific product categories
- **Products** — restrict to specific products/variations
- **Excluded products** — products that don't count toward the mission
- **Customer roles** — restrict to logged-in users with specific roles
- **Customer state** — first order only, VIP (min spend / min orders)
- **Cart state** — minimum items, applied coupons
- **Shipping zones** — restrict to specific shipping zones
- **Schedule** — recurring day/time windows (`schedule_days`, `schedule_start_time`, `schedule_end_time`)

## Conflict Resolution

When multiple missions are active, the `ConflictResolver` (`includes/Missions/ConflictResolver.php`) determines which rewards grant:

| Mode | Behavior |
|---|---|
| `cumulative` (default) | Every completed mission grants, subject to stacking rules |
| `first` | Only the first matching mission in priority order grants |
| `best` | Only the mission with the best reward grants |

**Deterministic order:** `COALESCE(campaigns.priority, 10) ASC, missions.priority ASC, missions.id ASC`

**Exclusive missions:** A completed exclusive mission suppresses every lower-priority completed mission, resolved before mode selection.

## REST API

| Endpoint | Method | Description |
|---|---|---|
| `GET /missions` | GET | Paginated mission list |
| `GET /missions/{id}` | GET | Single mission |
| `POST /missions` | POST | Create mission |
| `PUT /missions/{id}` | PUT | Update mission |
| `DELETE /missions/{id}` | DELETE | Delete mission |
| `POST /missions/{id}/duplicate` | POST | Duplicate mission |

## Admin UI

The Mission Builder (`admin-app/src/routes/MissionBuilder.tsx`) provides:

- **Basic Information** — name, description, status
- **Mission Type** — type selector with cards
- **Target** — dynamic fields based on type
- **Reward** — dynamic reward configuration
- **Conditions** — excluded products, customer roles, schedule
- **Display** — title, message, template, icon
- **Priority & Conflicts** — priority, exclusive toggle

## Edge Cases

| Case | Behavior |
|---|---|
| Empty cart | Eligible; current = 0 |
| Zero target | Trivially completed |
| Negative/invalid target | Ineligible |
| Sale prices | Computed from actual price paid |
| Coupons | `discounted_subtotal` reflects coupons |
| Variable products | Categories resolve from parent product |
| Decimal quantities | Summed exactly |
| Guest / logged-in | Both evaluated identically |
