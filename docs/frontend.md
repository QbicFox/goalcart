# Goal Cart Frontend

This document covers both frontends:

- **Storefront progress UI (Phase 11)** — the customer-facing widgets
  (vanilla JS + CSS in `assets/`), below.
- **React Admin App (Phase 8–10)** — the WP-admin dashboard, further down.

---

# Storefront Progress UI (Phase 11)

The customer-facing widget layer turns the public `GET /goalcart/v1/progress`
endpoint into live progress widgets on the storefront. It follows the
reference plugin's frontend convention exactly: **hand-written vanilla JS in
`assets/js/` (no build step)**, enqueued with `GOALCART_VERSION` + `in_footer`,
a single inline config object (`window.goalcartFrontend`) printed early in
`wp_footer`, and a **must-never-throw contract** in the JS.

## Architecture

```text
includes/Frontend/ProgressUI.php        server-side widget service
assets/js/frontend.js                   vanilla JS widget library
assets/css/frontend.css                 RTL-aware widget styles
GET /goalcart/v1/progress               public progress payload (Phase 7)
```

The PHP side never renders progress markup. It prints empty widget
containers at the display locations; the JS fetches `/progress` and fills
each container in, so cart changes update the UI through WooCommerce's
own JS events without a page reload.

## Display locations

| Location | Hook | Variant |
|---|---|---|
| Cart page | `woocommerce_before_cart` | full |
| Mini cart | `woocommerce_after_mini_cart` (inside the fragment) | compact |
| Checkout | `woocommerce_before_checkout_form` | full |
| Shop / archives | `woocommerce_archive_description` | compact |
| Product page | `woocommerce_single_product_summary` (prio 45) | compact |
| Anywhere | `[goalcart_progress variant="full|compact"]` shortcode | full/compact |
| Sticky bar | `wp_footer` | fixed bottom bar |

Every location renders **at most once per request** (a rendered-location
registry in `ProgressUI`) and each container has a unique id; the JS
mounts each container exactly once (`data-goalcart-mounted`), so a page
can never end up with two widgets in the same spot — including after
mini-cart fragment refreshes.

## Components (`assets/js/frontend.js`)

Each widget renders one featured goal (the first eligible one):

- **GoalContainer** — the widget body; `full` = reward chip + progress +
  message + milestones + suggestions, `compact` = progress + message +
  reward chip.
- **ProgressBar** — percentage fill bar (animated, logical-property
  based so it fills right-to-left on RTL sites).
- **GoalMessage** — the goal's progress message (rendered by the Phase 13
  MessageEngine).
- **GoalMilestones** — the active goals as an ordered ladder with target
  labels (currency-aware via the payload `is_money` flag); shown when
  there is more than one goal.
- **RewardStatus** — locked 🔒 / unlocked ✓ reward chip, labels
  localized server-side (`frontend_config()` → `labels`).
- **SuggestionList** — renders `goal.suggestions` when present (filled
  by the Phase 14 SuggestionEngine — upsells, cross-sells, related,
  in-category and best-seller products ranked by price proximity).
- **StickyGoalBar** — fixed bottom bar with the featured goal's progress
  and a dismiss button; hidden when the cart has no progress to show.

Empty state: when no goals are eligible the container is hidden
(`goalcart-widget--empty`) instead of showing a broken bar.

## Refresh & events

The library refetches on every WooCommerce cart event (`added_to_cart`,
`removed_from_cart`, `updated_cart_totals`, `updated_wc_div`,
`wc_fragments_refreshed`, `wc_fragments_loaded`) — bound through jQuery
when present (WooCommerce fires these as jQuery events) with a native
`CustomEvent` fallback — plus an optional poll interval from the config
(`goalcart_frontend_refresh_interval` filter, seconds).

## Gate & configuration

- **Master toggle:** the `enabled` setting (filter `goalcart_frontend_enabled`).
- **Locations:** filter `goalcart_frontend_locations` (Phase 18 wires the
  settings UI to it).
- **Template:** the `frontend_template` setting, overridable per widget
  with the shortcode `template` attribute and globally with the
  `goalcart_frontend_template` filter.
- **Animation:** the `frontend_animation` setting, filterable via
  `goalcart_frontend_animation` (offs add the no-transition classes).
- **Config payload** (`frontend_config()`): `endpoint`, `refresh`,
  `currency`, `isRtl`, `labels` — printed as `window.goalcartFrontend` at
  `wp_footer` priority 5, before the enqueued footer script. Phase 12 adds
  `template` (active variant), `animation` and `appearance` (resolved
  tokens).
- Assets load only on pages that can render a widget (cart / checkout /
  shop / product / a page containing the shortcode) via
  `page_needs_widget()`.

---

# Dynamic Messaging (Phase 13)

Every progress message — the widget's `GoalMessage`, the sticky bar copy,
the milestone labels — is rendered server-side by
`GoalCart\Goals\MessageEngine` (`includes/Goals/MessageEngine.php`), a
stateless, database-free template engine fed a `Goal` + `GoalResult`.
The `GET /goalcart/v1/progress` payload exposes the result as `message`
plus the raw `state` for styling.

## States

| State | When | Default copy |
|---|---|---|
| `inactive` | goal not active (status / campaign folded) | “This offer is not active right now.” |
| `unavailable` | goal cannot apply to this cart (no matching items, out of schedule, invalid target) | “This offer is not available for your cart.” |
| `progressing` | eligible, below 80% | “Only {remaining} left to reach your goal” |
| `nearly_complete` | eligible, ≥ 80% | “Almost there! Only {remaining} left” |
| `completed` | target reached, no reward configured | “You reached your goal!” |
| `reward_activated` | target reached with a reward | “Reward unlocked: {reward}” |

## Variables

```text
{current}  {target}  {remaining}  {percentage}
{quantity} {remaining_quantity}  {reward}  {goal_name}  {campaign_name}
```

- Money-based goals format `current`/`target`/`remaining` as currency
  (`wc_price` when WooCommerce is active); quantity, weight and
  distinct-quantity goals format plain locale numbers (the type-aware
  `is_money` check — quantity-type goals default to the subtotal mode, so
  the type is what decides).
- `quantity`/`remaining_quantity` come from the cart (the controller
  passes the cart's total quantity) and fall back to current/remaining for
  quantity-mode goals.
- `reward` is value-aware (“10% discount”, “Fixed $20.00 off”).
- `campaign_name` is folded into the goal by the repository's campaign
  join (`Goal::campaign_name()`).
- Unknown placeholders are left untouched — a template can never render
  empty tokens or throw.

## Templates

The goal builder's Display settings override the per-state defaults:
`display_settings.message` drives progress copy (progressing + nearly
complete), `display_settings.completed_message` drives completion copy
(completed + reward activated). Example:

```text
Only {remaining} left until {reward}
```

renders as “Only $38.00 left until Free shipping”. The payload `state`
lands as a `goalcart-state--{state}` class on the widget card, so themes
can highlight near-completion (`goalcart-state--nearly_complete`) etc.

---

# Progress Templates & Appearance (Phase 12)

The widget body renders per an active **template variant**. Resolution
order: a per-widget `data-goalcart-template` override on the shortcode
(`[goalcart_progress template="card"]`), then the goal's own Display
template (`display_settings.template`, served per goal in the progress
payload as `template` — the goal builder's template picker), then the
store-wide Appearance setting (`cfg.template`), then `basic`.

## Templates

| Variant | Layout |
|---|---|
| `basic` | Progress bar + message (the Phase 11 layout) |
| `percentage` | Large percent readout (`goalcart-percentage__value`) above the bar |
| `milestone` | The goal ladder (`goalcart-milestones`) as the hero visual, bar underneath |
| `card` | Icon + goal-title header (`goalcart-card-panel`) above the bar |

In JS terms the shared flow (message, reward chip, suggestions, sticky
bar) stays identical — only `templateBody()` swaps the core visual per
variant (`progressBar`, `percentagePanel`, `milestonePanel`,
`cardPanel`). The card icon comes from the goal's Display settings
(`display_settings.icon`, served in the progress payload as `icon`); the
widget falls back to 🎯 when a goal has no icon. Compact widgets keep
their slim footprint — the milestone ladder is skipped in the compact
variant.

Typography and spacing have no dedicated settings: they are tuned through
the shared tokens (bar height, radius) and the custom-CSS field, keeping
the settings surface small (Phase 18 grows the general/frontend
sections).

## Customization

All keys live under the `frontend_*` settings (see `docs/api.md` §2.2),
edited in the Appearance page and applied two ways:

- **Tokens** — `ProgressUI::appearance_css()` prints an inline style block
  (via `wp_add_inline_style`) overriding the `--goalcart-*` custom
  properties on `.goalcart-widget, #goalcart-sticky`: colors (accent /
  bg / border / text), corner radius and bar height
  (`--goalcart-bar-height`).
- **Custom CSS** — the `frontend_custom_css` setting is appended verbatim
  to the same inline block; `frontend_css_class` is added to every widget
  container so themes can target the widgets.
- **Animation** — when `frontend_animation` is off, the JS adds
  `goalcart-widget--no-anim` / `goalcart-no-anim` classes that disable the
  fill transition (`transition: none`), complementing the
  `prefers-reduced-motion` CSS.

## Styling

The stylesheet is scoped under `.goalcart-widget` / `#goalcart-sticky`,
responsive, motion-safe (respects `prefers-reduced-motion`), and
**themeable through CSS custom properties** (`--goalcart-accent`,
`--goalcart-bg`, `--goalcart-border`, `--goalcart-text`,
`--goalcart-text-muted`, `--goalcart-radius`, `--goalcart-shadow`,
`--goalcart-bar-height`, …) — the Phase 12 Appearance controls override
the same tokens, and the `frontend_custom_css` setting appends custom CSS
to the same inline style block (`ProgressUI::appearance_css()`).

---

# Smart Product Suggestions (Phase 14)

The widget's **SuggestionList** is served by
`GoalCart\Suggestions\SuggestionEngine`
(`includes/Suggestions/SuggestionEngine.php`), evaluated server-side per
goal and shipped in the `/progress` payload as `goal.suggestions` — the
widget renders each item's name + server-formatted price
(`price_html`, falling back to the raw price for hand-built payloads)
linked to the product.

## Sources

Candidates are gathered from, in order of priority:

1. the goal's own `products` (they count toward it)
2. products in the goal's `categories` (category goals)
3. the cart items' upsells (`_upsell_ids`)
4. the cart items' cross-sells (`_crosssell_ids`)
5. `wc_get_related_products()` of the cart items
6. the shopper's `woocommerce_recently_viewed` cookie
7. best sellers by `total_sales` (low-scoring fallback filler)

## Filters & ranking

Never suggested: out-of-stock, unpublished or unpriced products, the
cart's own items, `excluded_products`, and ghost/missing ids (a stale
upsell id can never break the engine — loads fall back to the direct
product data store when the `wc_product_meta_lookup` table lags, e.g.
after bulk imports).

Ranking score (higher wins, then id asc):

| Signal | Bonus |
|---|---:|
| Manual — in the goal's `products` | +3 |
| Counts toward the goal (product / category) | +2 |
| Price in the 0.6–1.4× band of `remaining` (money goals) | +2 |
| Shares a category with a cart item | +1 |
| Cheaper than the band (still helps) | +0.75 |
| WC-endorsed source (upsell / cross-sell / related) | +0.5 |

Completed or ineligible goals return no suggestions; the list is capped
at `SuggestionEngine::MAX_SUGGESTIONS` (4) and filterable via the
`goalcart_suggestions` filter (4 args: items, goal, result, context).
Margin-aware and AI-ranked recommendations remain roadmap futures
(P14-T05).

---

# Goal Cart React Admin App (Phase 8)

The admin dashboard is a React + TypeScript SPA (Vite + MUI) mounted
inside the WordPress admin at `#goalcart-admin` (see
`includes/Admin/Admin.php`). Phase 8 builds the complete admin shell —
providers, routing, layout, shared components and the six admin pages —
following the reference plugin's React architecture exactly.

```text
admin-app/src/
├── main.tsx                     entry: createRoot + AppProviders + App
├── App.tsx                      createHashRouter (data router) + lazy routes
├── boot.ts                      getBootData() — window.goalcart
├── types.ts                     boot data + Goal / Settings / envelope types
├── theme/index.ts               WP-admin MUI theme (RTL-aware)
├── providers/
│   ├── AppProviders.tsx         theme + Emotion cache + TanStack Query +
│   │                            Fullscreen + Snackbar providers
│   ├── FullscreenProvider.tsx   owns the goalcart-fullscreen body class
│   └── (SnackbarProvider lives in components/notifications/)
├── api/
│   ├── client.ts                apiFetch: X-WP-Nonce + envelope unwrap
│   ├── goals.ts                 typed goal CRUD + duplicate
│   ├── campaigns.ts             typed campaign CRUD + duplicate
│   ├── search.ts                typed /search/{products,categories,coupons}
│   └── settings.ts              typed GET/POST /settings
├── components/
│   ├── layout/                  AdminLayout (header + sidebar + main) + navigation
│   ├── notifications/           SnackbarProvider + useSnackbar()
│   ├── ConfirmDialog.tsx        reusable destructive-action dialog
│   ├── EmptyState.tsx           no-data panel
│   ├── ErrorBoundary.tsx        render-error fallback with retry
│   ├── GoalPreviewDialog.tsx    lightweight goal preview (simulated progress)
│   ├── CampaignPreviewDialog.tsx  lightweight milestone-ladder preview
│   ├── goal-builder/            Phase 9 builder sections
│   │   ├── SectionCard.tsx      titled section wrapper
│   │   ├── EntityAutocomplete.tsx  debounced async search picker
│   │   ├── GoalTypePicker.tsx   goal type selector cards
│   │   ├── goalTypes.tsx        shared GOAL_TYPES definitions
│   │   ├── TargetFields.tsx     dynamic target by goal type
│   │   ├── CompositeChildrenEditor.tsx  AND/OR child goals
│   │   ├── RewardFields.tsx     dynamic reward configuration
│   │   ├── ConditionFields.tsx  excluded products + schedule
│   │   └── DisplayFields.tsx    message/template/icon
│   └── PageContainer.tsx        shared page header + content wrapper
└── routes/
    ├── Dashboard.tsx            live goal summary (REST-backed)
    ├── Goals.tsx                full goal CRUD list (Phase 9)
    ├── GoalBuilder.tsx          goal create/edit builder (Phase 9)
    ├── Campaigns.tsx            full campaign CRUD list (Phase 10)
    ├── CampaignBuilder.tsx      campaign builder: basics, schedule, priority,
    │                            milestone ordering (Phase 10)
    ├── Analytics.tsx            container (Phase 16–17)
    ├── Appearance.tsx           container (Phase 12)
    ├── Settings.tsx             functional react-hook-form settings page
    └── NotFound.tsx             404
```

## Providers

`AppProviders` wraps the app in:

- **MUI theme** — `createAppTheme()` (WP-admin palette: blue #2271b1,
  canvas #f0f0f1, ink #1d2327; `direction` flips for RTL locales).
- **Dedicated Emotion cache** — key `goalcart`, RTL-flipped via the
  stylis RTL plugin when the site locale is RTL, so styles never collide
  with other admin plugins and the whole dashboard mirrors for RTL sites.
  No `CssBaseline` (its global resets would leak into the WP admin);
  scoped resets live in `styles.css` under `#goalcart-admin`.
- **TanStack Query** — retry 1, 60s staleTime, no refetch on window
  focus.
- **FullscreenProvider** — initialized from boot data (no layout flash),
  owns the `goalcart-fullscreen` body class that
  `assets/css/admin-fullscreen.css` keys on.
- **SnackbarProvider** — `useSnackbar().notify(message, severity)` from
  any page or mutation.

## Routing

`createHashRouter` (a data router, so `useBlocker`-style hooks are
available later) inside the single admin page — URL shape `#/route`.
The root redirects to `#/dashboard`; secondary routes are lazy-loaded
with a skeleton fallback (code splitting keeps the dashboard bundle
small); unknown routes render `NotFound`.

## Layout

`AdminLayout` mirrors the reference: a white header (title, view-store
link, user menu) in normal document flow below the WP admin bar, a
responsive sidebar with collapsible nav groups (persisted in
localStorage) that collapses to a temporary drawer on mobile, a pinned
footer (collapse-all + version chip), and the routed content area wrapped
in an `ErrorBoundary`. In full-screen mode the shell owns the viewport
and only the content area scrolls.

## API client

`apiFetch(path, options, unwrap)` sends `X-WP-Nonce` on every request
(Phase 2 nonce strategy), parses the Phase 7 `{ data, meta, pagination }`
envelope, throws typed `ApiError`s (network errors included), and
optionally returns the full envelope when pagination metadata is needed.
Typed per-resource modules (`api/goals.ts`, `api/settings.ts`) consume
it.

## Shared components

- `PageContainer` — consistent title + description + actions header.
- `ConfirmDialog` — MUI dialog for destructive actions (busy state,
  not dismissible while busy).
- `EmptyState` / `ErrorBoundary` — no-data and render-error panels.
- `SnackbarProvider` — shared notifications (the reference renders a
  local Snackbar per page; the shared provider is the Goal Cart
  foundation variant).

## Pages

| Page | Phase 8 status | Full implementation |
|---|---|---|
| Dashboard | live summary: goal counts, currency, version | 16–17 (analytics) |
| Goals | full goal CRUD list (search/filter/pagination, edit, duplicate, enable/disable, delete, preview) | 9 (Goal Management UI) |
| GoalBuilder | — | 9 (Goal Builder, `/goals/new` + `/goals/:id/edit`) |
| Campaigns | full campaign CRUD list (milestones, status, priority, schedule, edit, duplicate, enable/disable, delete, preview) | 10 (Campaign Builder) |
| CampaignBuilder | — | 10 (Campaign Builder, `/campaigns/new` + `/campaigns/:id/edit`) |
| Analytics | container | 16–17 |
| Appearance | full: template picker (live thumbnails), colors, bar height/radius sliders, animation switch, custom class + custom CSS, live preview, reset-to-defaults | 12 (Progress Templates) |
| Settings | functional: enabled + fullscreen toggles (react-hook-form) | 18 (full surface) |

## Build & verification

- `npm run typecheck` / `npm run lint` / `npm run build` (Vite manifest
  consumed by `AssetLoader`), Prettier-formatted.
- Browser smoke test: the built bundle is rendered headlessly with boot
  data injected; every route renders its expected content and no console
  errors are produced.
- RTL is covered by the three-part MUI setup (dir attribute, theme
  direction, flipped Emotion cache); i18n delegates to `wp.i18n` via the
  `@wordpress/i18n` shim.
