# FaraCart — Hooks (Actions & Filters)

## Overview

FaraCart exposes a comprehensive set of WordPress hooks (actions and filters) for developers to extend and customize plugin behavior. All hooks follow the `faracart_` prefix convention.

---

## Filters

### Core

| Filter | Args | Description |
|---|---|---|
| `faracart_admin_capability` | `$capability` | Admin menu and capability check (default: `manage_options`) |
| `faracart_rest_capability` | `$capability` | REST API permission callback (default: `manage_options`) |
| `faracart_loaded` | `$plugin` | Action: fires after plugin boots |

### Frontend

| Filter | Args | Description |
|---|---|---|
| `faracart_frontend_enabled` | `$enabled` | Master toggle for storefront widgets |
| `faracart_frontend_visible_to_user` | `$visible`, `$user` | Whether widgets show to the current user |
| `faracart_frontend_locations` | `$locations` | Active display locations |
| `faracart_frontend_position` | `$position` | Widget position (`top` \| `bottom`) |
| `faracart_frontend_template` | `$template` | Override storefront template |
| `faracart_frontend_animation` | `$enabled` | Enable/disable animations |
| `faracart_frontend_countdown` | `$enabled` | Enable/disable countdown |
| `faracart_frontend_celebrate` | `$enabled` | Enable/disable celebration animations |
| `faracart_frontend_mobile` | `$behavior` | Mobile behavior (`show` \| `hide`) |
| `faracart_frontend_refresh_interval` | `$seconds` | Poll interval in seconds |
| `faracart_frontend_upsell_limit` | `$limit` | Max suggestions per mission (1–6) |

### Mission Engine

| Filter | Args | Description |
|---|---|---|
| `faracart_mission_evaluator_classes` | `$classes` | Register custom mission type evaluators |
| `faracart_default_calculation_mode` | `$mode`, `$type` | Override default calculation mode per type |

### Reward Engine

| Filter | Args | Description |
|---|---|---|
| `faracart_reward_applicator_classes` | `$classes` | Register custom reward applicators |

### Template Engine

| Filter | Args | Description |
|---|---|---|
| `faracart_template_classes` | `$classes` | Register custom progress templates |

### Suggestions & Upsells

| Filter | Args | Description |
|---|---|---|
| `faracart_suggestions` | `$items`, `$mission`, `$result`, `$context` | Modify suggestion list |
| `faracart_suggestions_enabled` | `$enabled` | Enable/disable suggestions |
| `faracart_upsells_enabled` | `$enabled` | Enable/disable upsell ranker |
| `faracart_upsell_candidates` | `$candidates`, `$args`, `$ranker` | Modify upsell candidates |
| `faracart_upsells` | `$payload`, `$args`, `$ranker` | Modify upsell ranking payload |
| `faracart_upsell_weights` | `$weights` | Override upsell scoring weights |

### Logging & Debugging

Debug logging is a developer feature (no admin toggle). Enable it with the
`FARACART_LOGGING` / `FARACART_DEBUG` constants, or these filters:

| Filter | Args | Description |
|---|---|---|
| `faracart_logging_enabled` | `$enabled` | Master switch for the debug log file (default: off) |
| `faracart_debug_mode` | `$enabled` | Write debug-level entries too (default: off; errors always log when logging is on) |

### Analytics & Attribution

| Filter | Args | Description |
|---|---|---|
| `faracart_tracking_enabled` | `$enabled` | Enable/disable analytics tracking |
| `faracart_attribution_enabled` | `$enabled` | Enable/disable revenue attribution |
| `faracart_revenue_tracking_enabled` | `$enabled` | Enable/disable revenue event recording |
| `faracart_attribution_metric_rows` | `$limit` | Max rows for metric queries |
| `faracart_attribution_order_scan_pages` | `$pages` | Max pages for store-wide order scan |
| `faracart_revenue_cache_enabled` | `$enabled` | Enable/disable revenue caching |
| `faracart_revenue_cache_ttl` | `$ttl` | Revenue cache TTL in seconds |
| `faracart_revenue_retention_days` | `$days` | Revenue event retention window |
| `faracart_revenue_aggregated` | `$days`, `$products` | Action: fires after daily aggregation |

### Recommendations

| Filter | Args | Description |
|---|---|---|
| `faracart_recommendations_enabled` | `$enabled` | Enable/disable mission recommendations |
| `faracart_recommendation_min_orders` | `$min` | Minimum orders for reliable recommendation |
| `faracart_recommendation_candidates` | `$candidates`, `$stats`, `$reward_type`, `$shipping` | Modify recommendation candidates |
| `faracart_recommendation_weights` | `$weights` | Override recommendation scoring weights |
| `faracart_recommendation_margin_products` | `$count` | Number of products to sample for margin data |
| `faracart_recommendation_cache_ttl` | `$ttl` | Recommendation cache TTL |
| `faracart_recommendations` | `$payload`, `$args`, `$engine` | Modify recommendation payload |

### Product Cost

| Filter | Args | Description |
|---|---|---|
| `faracart_product_cost` | `$cost`, `$product` | Supply product cost for margin analytics |
| `faracart_order_cost_snapshot` | `$cost`, `$product_id`, `$item` | Override order-item cost snapshot |

### Admin

| Filter | Args | Description |
|---|---|---|
| `faracart_admin_boot_data` | `$data` | Modify React admin boot data |

---

## Actions

| Action | Args | Description |
|---|---|---|
| `faracart_loaded` | `$plugin` | Fires after plugin boots |
| `faracart_settings_saved` | `$settings`, `$old_settings` | Fires after settings are saved |
| `faracart_missions_changed` | `$mission_id` | Fires after mission create/update/delete |
| `faracart_revenue_aggregated` | `$days`, `$products` | Fires after daily revenue aggregation |

---

## Usage Examples

### Custom Mission Type

```php
add_filter( 'faracart_mission_evaluator_classes', function ( $classes ) {
    $classes['membership'] = My_Membership_Evaluator::class;
    return $classes;
} );
```

### Custom Reward Type

```php
add_filter( 'faracart_reward_applicator_classes', function ( $classes ) {
    $classes['loyalty_points'] = My_Loyalty_Applicator::class;
    return $classes;
} );
```

### Custom Template

```php
add_filter( 'faracart_template_classes', function ( $classes ) {
    $classes['countdown'] = My_Countdown_Template::class;
    return $classes;
} );
```

### Modify Suggestions

```php
add_filter( 'faracart_suggestions', function ( $items, $mission, $result, $context ) {
    // Add custom products to the suggestion list
    return $items;
}, 10, 4 );
```

### Supply Product Cost

```php
add_filter( 'faracart_product_cost', function ( $cost, $product ) {
    return (float) $product->get_meta( '_my_cost_field' );
}, 10, 2 );
```

### Hide Widgets from Shop Managers

```php
add_filter( 'faracart_frontend_visible_to_user', function ( $visible, $user ) {
    return ! is_user_role( 'shop_manager', $user );
}, 10, 2 );
```
