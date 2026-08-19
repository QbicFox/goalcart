# FaraCart — Frontend

## Overview

The frontend has two parts:
1. **Storefront Progress UI** — customer-facing widgets (vanilla JS + CSS)
2. **React Admin App** — WP-admin dashboard (React + TypeScript + MUI)

---

## Storefront Progress UI

### Architecture

```
includes/Frontend/ProgressUI.php        server-side widget service
assets/js/frontend.js                   vanilla JS widget library
assets/css/frontend.css                 RTL-aware widget styles
GET /faracart/v1/progress               public progress payload
```

### Display Locations

| Location | Hook | Variant |
|---|---|---|
| Cart page | `woocommerce_before_cart` | full |
| Mini cart | `woocommerce_before_mini_cart` | compact |
| Checkout | `woocommerce_before_checkout_form` | full |
| Shop / archives | `woocommerce_archive_description` | compact |
| Product page | `woocommerce_single_product_summary` | compact |
| Anywhere | `[faracart_progress variant="full|compact"]` | configurable |
| Sticky bar | `wp_footer` | fixed bottom bar |
| Cart Block | `render_block('woocommerce/cart')` | full |
| Checkout Block | `render_block('woocommerce/checkout')` | full |
| Mini Cart Block | `render_block('woocommerce/mini-cart')` | compact |
| Gutenberg | `faracart/progress` block | configurable |

### Components (`assets/js/frontend.js`)

- **MissionContainer** — one mission's card (full or compact variant)
- **ProgressBar** — percentage fill bar (animated, RTL-aware)
- **MissionMessage** — state-aware progress message
- **RewardStatus** — locked/unlocked reward chip
- **UnifiedRecommendations** — merged suggestions + upsells panel
- **StickyMissionBar** — fixed bottom bar with featured mission

### Template Engine

Each mission renders through its resolved design template (server-side resolved):

| Template | Layout |
|---|---|
| `template-1` | Classic progress card with icon, label, bar, amounts, CTA |
| `template-2` | Minimal inline cart mission strip |
| `template-3` | Circular progress ring |
| `template-4` | Product recommendation + mission |
| `template-5` | Compact floating/sticky mission |
| `template-6` | Premium/elegant e-commerce style |

Resolution order: item override → scope default → store-wide fallback → `template-1`

### Refresh Behavior

Widgets refetch on every WooCommerce cart event (`added_to_cart`, `removed_from_cart`, `updated_cart_totals`, `wc_fragments_refreshed`, etc.). Cart events trigger two polls: immediately and after 600ms. All fetches are cache-busted with `?_=<timestamp>`.

### Configuration

Printed as `window.faracartFrontend` at `wp_footer` priority 5:

```js
{
  endpoint, refresh, currency, locale, isRtl, labels,
  template, animation, appearance, currencyDisplay, mobile,
  upsells: { endpoint, track, limit, labels }
}
```

### Analytics Events

| Event | When | Dedup |
|---|---|---|
| `goal_impression` | Mission renders in widget | once per mission per session |
| `goal_progress` | Percentage changes | per mission + percentage |
| `goal_completed` | Mission without reward reaches 100% | once per session |
| `reward_activated` | Mission with reward reaches 100% | once per session |
| `suggestion_impression` | Suggested product renders | once per mission + product |
| `suggestion_clicked` | Suggestion link clicked | every click |

### Gate & Visibility

- **Master toggle:** `enabled` setting + `faracart_frontend_enabled` filter
- **Staff visibility:** logged-in admins hidden by default (`faracart_frontend_visible_to_user`)
- **Locations:** `frontend_locations` setting drives where widgets mount
- **Mobile:** `frontend_mobile` setting (`show` | `hide`)
- **Assets:** loaded only on pages that can render a widget

---

## React Admin App

### Structure

```
admin-app/src/
├── main.tsx          entry: createRoot + AppProviders + App
├── App.tsx           createHashRouter + lazy routes
├── boot.ts           getBootData() — window.faracart
├── types.ts          boot + Mission / Settings / envelope types
├── api/              API client + per-domain fetchers
├── components/       layout/, mission-builder/, preview/, shared
├── routes/           One file per page (lazy-loaded)
├── templates/        Template renderers + SchemaForm
├── providers/        AppProviders, FullscreenProvider
├── date-range/       Global date-range context
└── theme/            MUI theme factory
```

### Pages

| Page | Route | Description |
|---|---|---|
| Dashboard | `/dashboard` | Mission counts, revenue overview |
| Missions | `/missions` | Mission CRUD list |
| Mission Builder | `/missions/new`, `/missions/:id/edit` | Mission create/edit |
| Campaigns | `/campaigns` | Campaign CRUD list |
| Campaign Builder | `/campaigns/new`, `/campaigns/:id/edit` | Campaign create/edit |
| Analytics | `/analytics` | Date-range + filters, KPIs, charts |
| Revenue | `/revenue` | Sales Performance overview |
| Mission Performance | `/revenue/missions` | Per-mission comparison table |
| Recommendations | `/optimization/missions` | Smart mission targets |
| Upsells | `/optimization/upsells` | Product recommendation ranking |
| Appearance | `/appearance` | Per-scope template manager |
| Settings | `/settings` | Five-tab settings form |

### Providers

- **MUI theme** — WP-admin palette, RTL-aware
- **Emotion cache** — key `faracart`, RTL-flipped
- **TanStack Query** — retry 1, 60s staleTime
- **FullscreenProvider** — `faracart-fullscreen` body class
- **SnackbarProvider** — shared notifications

### Routing

Hash-based data router (`createHashRouter`), all non-dashboard routes lazy-loaded with skeleton fallback.

### Build & Verification

```bash
npm run typecheck    # TypeScript strict check
npm run lint         # ESLint
npm run build        # Vite production build
npm run dev          # Vite dev server (localhost:5173)
```
