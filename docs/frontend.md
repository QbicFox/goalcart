# FaraCart Frontend

This document covers both frontends:

- **Storefront progress UI (Phase 11)** — the customer-facing widgets
  (vanilla JS + CSS in `assets/`), below.
- **React Admin App (Phase 8–10)** — the WP-admin dashboard, further down.

---

# Storefront Progress UI (Phase 11)

The customer-facing widget layer turns the public `GET /faracart/v1/progress`
endpoint into live progress widgets on the storefront. It follows the
reference plugin's frontend convention exactly: **hand-written vanilla JS in
`assets/js/` (no build step)**, enqueued with `FARACART_VERSION` + `in_footer`,
a single inline config object (`window.faracartFrontend`) printed early in
`wp_footer`, and a **must-never-throw contract** in the JS.

## Architecture

```text
includes/Frontend/ProgressUI.php        server-side widget service
assets/js/frontend.js                   vanilla JS widget library
assets/css/frontend.css                 RTL-aware widget styles
GET /faracart/v1/progress               public progress payload (Phase 7)
```

The PHP side never renders progress markup. It prints empty widget
containers at the display locations; the JS fetches `/progress` and fills
each container in, so cart changes update the UI through WooCommerce's
own JS events without a page reload.

## Display locations

| Location | Top hook | Bottom hook | Variant |
|---|---|---|---|
| Cart page | `woocommerce_before_cart` | `woocommerce_after_cart` | full |
| Mini cart | `woocommerce_before_mini_cart` (inside the fragment) | `woocommerce_after_mini_cart` (inside the fragment) | compact |
| Checkout | `woocommerce_before_checkout_form` | `woocommerce_after_checkout_form` | full |
| Shop / archives | `woocommerce_archive_description` | `woocommerce_after_shop_loop` | compact |
| Product page | `woocommerce_single_product_summary` (prio 45) | `woocommerce_after_single_product_summary` (prio 20) | compact |
| Anywhere | `[faracart_progress variant="full|compact"]` shortcode | full/compact |
| Sticky bar | `wp_footer` | fixed bottom bar |

Every location renders **at most once per request** (a rendered-location
registry in `ProgressUI`) and each container has a unique id; the JS
mounts each container exactly once (`data-faracart-mounted`), so a page
can never end up with two widgets in the same spot — including after
mini-cart fragment refreshes.

## Components (`assets/js/frontend.js`)

Each widget renders **every eligible mission as its own card**, stacked in a
shared `.faracart-widget__missions` wrapper — a campaign's milestones each
get a full card instead of one featured card + a tiny ladder:

- **MissionContainer** — one mission's card; `full` = reward chip + progress +
  message + unified recommendations, `compact` = progress + message +
  reward chip.
- **ProgressBar** — percentage fill bar (animated, logical-property
  based so it fills right-to-left on RTL sites).
- **MissionMessage** — the mission's progress message (rendered by the Phase 13
  MessageEngine).
- **RewardStatus** — locked 🔒 / unlocked ✓ reward chip, labels
  localized server-side (`frontend_config()` → `labels`).
- **UnifiedRecommendations** — one customer-facing product panel that
  renders the mission's merged, deduplicated, ranked recommendations
  (Suggestions + Upsells consolidation: the Phase 14 SuggestionEngine
  and the Phase 33.5 UpsellRanker are merged into ONE list by the
  `ProductRecommendationEngine`). Money missions with an open gap fall back
  to the public rank endpoint when the payload carries nothing.
- **StickyMissionBar** — fixed bottom bar with the featured mission's progress
  (the first eligible one — a slim bar keeps a single mission at a glance)
  and a dismiss button; hidden when the cart has no progress to show.

There is no cross-mission ladder anymore: every mission is its own card, each
rendering through its resolved design template. Ineligible missions never
render a card, and when no missions are eligible the container is hidden
(`faracart-widget--empty`) instead of showing a broken bar.

## Refresh & events

The library refetches on every WooCommerce cart event (`added_to_cart`,
`removed_from_cart`, `updated_cart_totals`, `updated_wc_div`,
`wc_fragments_refreshed`, `wc_fragments_loaded`) — bound through jQuery
when present (WooCommerce fires these as jQuery events) with a native
`CustomEvent` fallback — plus an optional poll interval from the config
(`faracart_frontend_refresh_interval` filter, seconds).

Cart events poll **twice: immediately, and once more after 600 ms**
(`refreshAfterCartChange()`). The AJAX request that fired the event only
persists the WooCommerce session on PHP `shutdown` — after its response
has been flushed to the browser — so a poll fired straight from the
event can race that write and read the previous cart, freezing the
widgets on stale progress until the next event. The follow-up poll lands
after the write, so the widgets settle on the persisted cart under
normal load; the extra request is cheap because unchanged payloads are
skipped by the fingerprint check (Phase 23), and the several cart events
WooCommerce fires per mutation (`added_to_cart`, `wc_fragments_refreshed`,
`updated_cart_totals`, …) are coalesced into a single follow-up poll.

Every fetch is **cache-busted with a `?_=<timestamp>` parameter**: the
guest `/progress` payload carries no `Cache-Control` header (WP core only
sends nocache headers for cookie-authenticated requests), so a bare GET
could be heuristically cached by the browser and the widgets would keep
showing the previous cart's progress after the shopper adds or removes
items. The endpoint also stamps the response
`Cache-Control: no-store, no-cache, must-revalidate, max-age=0` — both
layers are asserted by `tests/frontend-test.php`.

## Gate & configuration

- **Master toggle:** the `enabled` setting (filter
  `faracart_frontend_enabled`) — the staff-visibility gate below applies
  on top of it, so the filter alone cannot reveal widgets to a logged-in
  admin.
- **Staff visibility:** logged-in site admins browsing the storefront do
  not see the shopper-facing widgets by default
  (`ProgressUI::is_visible_to_user()` — "admin" means the
  `faracart_admin_capability` capability, default `manage_options`, so
  whoever can administer the plugin is treated as staff). The whole
  decision is filterable via `faracart_frontend_visible_to_user` — e.g.
  hide the widgets from every logged-in user, or from shop managers too.
- **Locations:** the `frontend_locations` setting (Phase 18) drives where
  the widgets mount — the cart / mini-cart / checkout / shop / product
  containers plus the sticky bar are all gated on the configured set
  (filter `faracart_frontend_locations`); dropping `sticky` from the list
  disables the sticky bar entirely.
- **Page position:** the `frontend_position` setting (`top` | `bottom`)
  selects the top or bottom hook for all regular page widgets. The sticky
  bar keeps its independent `sticky_position` setting. Both boundary hooks
  remain registered and the selected one renders the container, so the
  setting is normalized and applied on every request.
- **Mobile behavior:** the `frontend_mobile` setting (`show` | `hide`,
  Phase 18) — when `hide`, the JS adds a `faracart-mobile-hidden` class
  to every container and the CSS hides the widgets under 600 px.
- **Template:** the `frontend_template` setting, overridable per widget
  with the shortcode `template` attribute and globally with the
  `faracart_frontend_template` filter.
- **Animation:** the `frontend_animation` setting, filterable via
  `faracart_frontend_animation` (offs add the no-transition classes).
- **Config payload** (`frontend_config()`): `endpoint`, `refresh`,
  `currency`, `locale`, `isRtl`, `labels` — printed as
  `window.faracartFrontend` at `wp_footer` priority 5, before the
  enqueued footer script. Phase 12 adds `template` (active variant),
  `animation` and `appearance` (resolved tokens); Phase 18 adds
  `currencyDisplay` (`symbol` | `code` | `name` — the widget's amount
  formatter uses it) and `mobile` (`show` | `hide`); Phase 27 adds
  `locale` (`get_locale()`), which the JS passes to `Intl.NumberFormat`
  so amounts and numbers render with the site locale's digits and
  grouping (Persian digits for `fa_IR` — see `docs/i18n.md`). The Phase
  16 Tracker prints a second object, `window.faracartTracking`, at
  priority 4 (see “Analytics Events”).
- Assets load only on pages that can render a widget (cart / checkout /
  shop / product / a page containing the shortcode) via
  `page_needs_widget()`.

---

# Dynamic Messaging (Phase 13)

Every progress message — the widget's `MissionMessage`, the sticky bar copy,
the milestone labels — is rendered server-side by
`FaraCart\Missions\MessageEngine` (`includes/Missions/MessageEngine.php`), a
stateless, database-free template engine fed a `Mission` + `MissionResult`.
The `GET /faracart/v1/progress` payload exposes the result as `message`
plus the raw `state` for styling.

## States

| State | When | Default copy |
|---|---|---|
| `inactive` | mission not active (status / campaign folded) | “This offer is not active right now.” |
| `unavailable` | mission cannot apply to this cart (no matching items, out of schedule, invalid target) | “This offer is not available for your cart.” |
| `progressing` | eligible, below 80% | “Only {remaining} left to reach your mission” |
| `nearly_complete` | eligible, ≥ 80% | “Almost there! Only {remaining} left” |
| `completed` | target reached, no reward configured | “You reached your mission!” |
| `reward_activated` | target reached with a reward | “Reward unlocked: {reward}” |

## Variables

```text
{current}  {target}  {remaining}  {percentage}
{quantity} {remaining_quantity}  {reward}  {mission_name}  {campaign_name}
```

- Money-based missions format `current`/`target`/`remaining` as currency
  (`wc_price` when WooCommerce is active); quantity, weight and
  distinct-quantity missions format plain locale numbers (the type-aware
  `is_money` check — quantity-type missions default to the subtotal mode, so
  the type is what decides).
- `quantity`/`remaining_quantity` come from the cart (the controller
  passes the cart's total quantity) and fall back to current/remaining for
  quantity-mode missions.
- `reward` is value-aware (“10% discount”, “Fixed $20.00 off”).
- `campaign_name` is folded into the mission by the repository's campaign
  join (`Mission::campaign_name()`).
- Unknown placeholders are left untouched — a template can never render
  empty tokens or throw.

## Templates

The mission builder's Display settings override the per-state defaults:
`display_settings.message` drives progress copy (progressing + nearly
complete), `display_settings.completed_message` drives completion copy
(completed + reward activated). Example:

```text
Only {remaining} left until {reward}
```

renders as “Only $38.00 left until Free shipping”. The payload `state`
lands as a `faracart-state--{state}` class on the widget card, so themes
can highlight near-completion (`faracart-state--nearly_complete`) etc.

---

# Progress Templates & Appearance (Phase 12)

The widget body is rendered by a **pluggable template engine**: each
template is a registered, self-describing unit (stable id, scope,
settings schema, version) with its own layout, and templates are scoped
independently for Missions and Campaigns. See `includes/Templates/`
(`Template` contract, `TemplateRegistry`, `TemplateEngine`) and
`docs/REFERENCE_ARCHITECTURE.md` §11.13.

## Built-in templates

The six design templates (`template-1` … `template-6`) replace the
original Phase 12 Mission variants. Retired ids (`basic` / `percentage` /
`milestone` / `card` / `ring`) are no longer registered and are never
mapped to a current template — a persisted old id falls back to the
scope default / store-wide template. The two Campaign templates are
unchanged.

| Variant | Scope | Layout |
|---|---|---|
| `template-1` | mission | Classic progress card — icon badge, mission label + title, percentage chip, bar, current/remaining amounts, CTA; completed + expired states (`t1Panel()`) |
| `template-2` | mission | Minimal inline cart mission — compact strip (small icon, title, remaining, slim bar, small CTA) meant to sit between cart content and totals (`t2Panel()`) |
| `template-3` | mission | Circular progress — percentage inside a ring beside icon, title, description, amounts and a CTA (`t3Panel()`) |
| `template-4` | mission | Product recommendation + mission — gradient progress header plus the mission's recommended products with add-to-cart buttons (`t4Panel()`) |
| `template-5` | mission | Compact floating / sticky mission — dark slim bar with icon, progress, remaining and a CTA (`t5Panel()`) |
| `template-6` | mission | Premium / elegant e-commerce style — gold accents, elegant progress with a marker dot, amounts and a refined CTA (`t6Panel()`) |
| `milestone_chain` | campaign | The campaign's milestones as one connected ladder — dots, names, targets and rewards — with an overall progress bar (`campaignChain()`); the campaign renders as a unit instead of per-mission cards |
| `campaign_progress` | campaign | One overall progress bar for the whole campaign with a milestone counter (`campaignProgress()`) |

In JS terms the shared flow (message, reward chip, suggestions, sticky
bar) stays identical — only `templateBody()` swaps the core visual per
variant (`t1Panel` … `t6Panel`), and a campaign group with a configured
campaign template renders through `campaignChain()` /
`campaignProgress()` instead of per-mission cards. The card icon comes
from the mission's Display settings (`display_settings.icon`, served in
the progress payload as `icon`); each template falls back to its own
MUI-style fallback glyph when a mission has no icon. Compact widgets keep
their slim footprint — every eligible mission still gets its own compact
card, stacked with a tighter gap.

## Resolution

The engine resolves each mission's effective template + appearance
**server-side** and ships it in the progress payload (`template` +
`template_settings`); campaign groups ship under `campaigns` (only for
campaigns with a configured campaign template). The JS never
re-resolves — it renders exactly what the engine resolved:

1. **item override** — `display_settings.template_id` +
   `template_settings` (the Mission Builder's Display section; a campaign's
   `display_rules` analogously),
2. **scope default** — the Appearance page's per-scope default template
   and its stored default appearance (`template_defaults` /
   `template_settings` in the settings option),
3. **store-wide fallback** — the `frontend_template` + `frontend_*`
   appearance tokens (missions only),
4. **hardcoded fallback** — `template-1` for missions; a campaign without
a template renders per-mission cards (the pre-engine behavior).

A stored template id that is no longer registered (an old Phase 12 id
such as `card`, or a removed template) falls back to the scope default
instead of failing — old template ids are never mapped to a current
template. The Phase 15 admin preview resolves through the same engine,
so what the merchant previews is what customers see. The per-widget
shortcode `template` attribute and the `faracart_frontend_template`
filter still override the store-wide variant (`ProgressUI::template()`).

## Customization

Every template exposes its own **settings schema** (colors, radius, bar
height, animation, content toggles, CSS class, custom CSS — genuinely
different per template). The Appearance page (per-scope defaults),
Mission Builder and Campaign Builder all render the settings form
**generically from the schema** (`admin-app/src/templates/SchemaForm.tsx`),
so a new template automatically gets a working settings UI. The
storefront applies each mission's resolved settings as per-card CSS custom
properties (`--faracart-accent`, `--faracart-bg`, `--faracart-border`,
`--faracart-text`, `--faracart-radius`, `--faracart-bar-height`, …) via
`style.setProperty()` and appends any custom CSS per card. Every value
is validated server-side against the schema (colors, ranges, tag-free
CSS, unknown keys dropped). The legacy `frontend_*` surface remains the
fallback for templates never configured, and the store-wide custom-CSS /
CSS-class / animation settings still apply through
`ProgressUI::appearance_css()`.

## Styling

The stylesheet is scoped under `.faracart-widget` / `#faracart-sticky`,
responsive, motion-safe (respects `prefers-reduced-motion`), and
**themeable through CSS custom properties** (`--faracart-accent`,
`--faracart-bg`, `--faracart-border`, `--faracart-text`,
`--faracart-text-muted`, `--faracart-radius`, `--faracart-shadow`,
`--faracart-bar-height`, …) — the Phase 12 Appearance controls override
the same tokens, and the `frontend_custom_css` setting appends custom CSS
to the same inline style block (`ProgressUI::appearance_css()`).

---

# Analytics Events (Phase 16)

The storefront widgets report mission-cart analytics events to
`POST /faracart/v1/track` (see `docs/api.md` §3). Tracking is baked into
`assets/js/frontend.js` — no separate tracker file — with the same
must-never-throw contract as the widgets: a failed or missing report can
never disturb the storefront.

## Config

`FaraCart\Analytics\Tracker` prints a second small inline config object,
`window.faracartTracking`, at `wp_footer` priority 4 (before the widget
config at 5):

```js
{ endpoint, nonce, sessionId }
```

The endpoint + nonce guard the `/track` route; `sessionId` is the
anonymous session cookie (32-hex, HttpOnly, SameSite=Lax — never an IP or
any PII) that groups all of one visitor's events.

The nonce is **self-healing**: every `/progress` response carries a
freshly minted `tracking_nonce` (the same `faracart_track` action) and
the widget JS adopts it before reporting the next event. The page's own
nonce is baked into the HTML and expires after its 12-hour tick — or is
bound to another user's session on a cached page — so without the
refresh those conditions would turn every subsequent `/track` report
into a `faracart_invalid_nonce` (403).

## Events reported

| Event | When | Dedup |
|---|---|---|
| `goal_impression` | an eligible mission renders in a widget | once per mission per page session |
| `goal_progress` | the mission's percentage changes | per mission + percentage |
| `goal_completed` | a mission without a reward reaches 100% | once per mission per page session |
| `reward_activated` | a mission with a reward reaches 100% | once per mission per page session |
| `suggestion_impression` | a suggested product renders | once per mission + product per page session |
| `suggestion_clicked` | a suggestion link is clicked | every click (delegated listener) |

`cart_value` is the first money mission's current value at event time;
`goal_progress` carries the rounded `percentage` in `meta`. Suggestion
clicks are reported through a delegated `document.body` click listener
using the `data-faracart-suggestion-id` / `data-faracart-mission-id`
attributes the widget puts on each suggestion link.

`goal_impression` is deliberately gated on an *eligible* mission rendering —
no payload fetch, no ineligible mission, no bot-poll, no event. Reports use
`navigator.sendBeacon` when available (so they survive page unload) with
an XHR fallback, and the events fire on every widget refresh, deduped
per page session so cart updates don't spam the funnel.

## Server-side attribution

`suggested_product_added` is **not** client-reported: `FaraCart\Analytics\Tracker`
hooks `woocommerce_add_to_cart` and records the event only when the
session saw a `suggestion_impression` for that product within the last
24 hours — so a suggestion conversion can never be self-reported, and an
add-to-cart from anywhere else is simply not attributed.

## Gate

Tracking respects the master `enabled` setting plus the
`faracart_tracking_enabled` filter (default on). Phase 18 adds a
first-class settings toggle; until then the filter is the privacy switch.

---

# Unified Recommendations (Suggestions + Upsells)

The widget's **UnifiedRecommendations** panel is served by
`FaraCart\Recommendations\ProductRecommendationEngine`
(`includes/Recommendations/ProductRecommendationEngine.php`) — the
single customer-facing recommendation layer that merges the Phase 14
SuggestionEngine and the Phase 33.5 UpsellRanker into ONE ranked,
deduplicated list, evaluated server-side per mission and shipped in the
`/progress` payload as `mission.suggestions`. Each item carries a
`source` attribution (`suggestion` | `upsell` | `both`) so the
storefront's existing tracking funnels stay separate without exposing
the strategy to the shopper; `score` carries the unified 0–100 rank.
The widget renders each item's name + server-formatted price
(`price_html`, falling back to the raw price for hand-built payloads)
linked to the product. Money labels are plain text: the `wc_price`
markup is stripped **and** its HTML entities decoded (WooCommerce ships
symbols like the IRT "تومان" as an entity), so the label never shows raw
entity text.

## Sources

The suggestion half gathers candidates from, in order of priority:

1. the mission's own `products` (they count toward it)
2. products in the mission's `categories` (category missions)
3. the cart items' upsells (`_upsell_ids`)
4. the cart items' cross-sells (`_crosssell_ids`)
5. `wc_get_related_products()` of the cart items
6. the shopper's `woocommerce_recently_viewed` cookie
7. best sellers by `total_sales` (low-scoring fallback filler)

The upsell half adds the ranker's own candidates (mission manual +
historical funnel + category + cart-endorsed + taxonomy matches + best
sellers) and scores every candidate on the SAME normalized 0–100 scale
(`score_product`), so the two halves never compare incompatible scores.
A product present in both halves appears exactly once with source
`both`; the stronger (ranker composite) score wins. Rank: score desc,
then lower price, then id (deterministic).

## Filters & ranking

Never recommended: out-of-stock, unpublished or unpriced products, the
cart's own items, `excluded_products`, and ghost/missing ids. Completed
or ineligible missions — or a closed gap — return no recommendations.
Weak candidates that score 0 under the merged weights are dropped, so
the panel never pads to the configured limit (`faracart_frontend_upsell_limit`, 1–6, default 3):
fewer strong candidates → fewer items. Money missions with an open gap
fall back to the public rank endpoint (`/faracart/v1/upsell/rank`) when
the payload carried nothing; the list is filterable via the existing
`faracart_suggestions` filter (4 args: items, mission, result, context).

---

# Upsell Endpoint & Tracking (Phase 33.7)

The Phase 33.5 Smart Upsell engine still exposes its **public** rank
endpoint — the rank-endpoint fallback for the unified panel fetches its
products on demand, and the server computes the gap, so the ranking is
always based on the live cart.

## Contract

```text
includes/REST/UpsellController.php      GET /faracart/v1/upsell/rank (public)
                                        POST /faracart/v1/upsell/track (nonce)
includes/Frontend/ProgressUI.php        cfg.upsells (endpoint/track/limit/labels)
assets/js/frontend.js                   upsellPanel component + add-to-cart
assets/css/frontend.css                 scoped panel styles (mobile strip)
```

The storefront sends only `mission_id` + `limit`. The server resolves the
mission (explicit id, else the featured active money mission), builds the
same `CartContext` the progress widgets evaluate on, runs the mission
through the shared `MissionEngine` and derives the remaining gap as
target − current cart value — **server-side, never trusted from the
client** (explicit `cart` / `cart_value` / `remaining` args exist for
tests and embedded consumers only). The deterministic `UpsellRanker`
runs directly (no per-cart transient churn), so every Phase 33.5
degradation holds: no mission / closed gap / disabled / no candidates →
an unavailable payload with a reason, never a fabricated list. The
payload is catalog data only (name, price, image, score breakdowns,
reasons) — no PII, no secrets, per-IP rate limited like `/progress`,
`Cache-Control: no-store` (cart-dependent), and the store's
cost-derived margin/profit fields (`estimated_profit` /
`profit_available` / `factors.margin_pct` and the margin reason
bullets) are redacted before serving, so an anonymous caller can never
harvest the store's margins (P22-style; the admin analytics surface
keeps them behind manage_options).

## Panel behavior

- Renders for money missions with `remaining > 0` that are not completed;
  hidden entirely when the plugin/analytics toggles are off (the
  `cfg.upsells.enabled` gate mirrors the ranker's
  `faracart_upsells_enabled` gate).
- Results are cached client-side per `mission:remaining`, so a cart-change
  re-render with an unchanged gap reuses the last ranking; a network
  failure drops the panel entirely (never a broken half-render).
- **Add to cart** uses WooCommerce's own public `?wc-ajax=add_to_cart`
  surface (the same endpoint the theme's buttons use —
  theme-compatible by construction), falls back to the classic
  `?add-to-cart=` redirect without it, and sends variation-requiring
  items to their product page. On success the panel reports
  `upsell_added` and funnels into the centralized `faracart:cart-changed`
  bridge, so the widgets re-poll and the gap closes live.

## Conversion tracking

The panel reports through the Phase 33.5 public
`POST /faracart/v1/upsell/track` route (reusing the Phase 16 tracking
nonce + session id): `upsell_impression` once per mission+product per
session, `upsell_clicked` on the product link and the add button, and
`upsell_added` after a successful add. These feed `upsell_events` → the
`DailyAggregator` → `upsell_stats`, closing the historical-learning loop
(P33-35) the conversion scorer reads.

## Mobile & theming

The panel is a responsive grid on desktop and a swipeable horizontal
scroll-snap strip on small screens (`@media (max-width: 600px)`). Every
style is scoped under `.faracart-*` and driven by the existing CSS
custom-property tokens (`--faracart-accent`, `--faracart-bg`,
`--faracart-border`, `--faracart-text`, `--faracart-radius`), so it
inherits the store's Appearance settings and never leaks into or breaks
a theme.

---

# Admin Preview System (Phase 15)

Administrators can see the **exact customer experience before
publishing**: the preview buttons on the Missions and Campaigns lists open a
dialog that evaluates the real engine against a **simulated cart** and
renders the real storefront widget (React mirror of `assets/js/frontend.js`)
at the chosen device width.

## Backend — `POST /faracart/v1/preview` (admin-only)

`FaraCart\REST\PreviewController` (`includes/REST/PreviewController.php`)
accepts `mission_id` XOR `campaign_id` plus a `simulated` object
(`{ amount, quantity }`), builds a **synthetic `CartContext`** (one
simulated cart line carrying the amount / quantity / weight / categories /
product id — so amount, quantity, distinct-quantity, category, product and
weight missions all evaluate honestly; composite missions union their children's
constraints), runs it through the MissionEngine + MessageEngine +
SuggestionEngine, and returns the **same per-mission payload shape as the
public `/progress` endpoint** (built by the shared
`FrontendController::shape_mission()`, so the two can never drift).

Preview **never touches the real WooCommerce cart**: no cart is loaded, no
session is touched, no fees or coupons are applied. It also **ignores
publish gating** on purpose — missions are evaluated as active and
in-schedule — so drafts, inactive missions and scheduled campaigns can be
seen before they go live.

## Preview States

- empty cart · 25% · 50% · 75% · completed (single missions; anchored to the
mission target)
- multiple milestones (campaigns; anchored to the top milestone target, so
mid states naturally show several completed rungs)

## Preview Controls

- **Simulated cart amount** — drives money-based missions (subtotal / total).
- **Simulated quantity** — drives quantity, distinct-quantity and weight
missions.
- **Simulated reward** — auto (from completion) / locked / unlocked chip
state.
- **Device width** — mobile (375px) / tablet (768px) / desktop (1280px)
preview frame.
- **Template** — any registered Mission template (mission preview) or
  Campaign template (campaign preview); empty = the payload's resolved
template + settings (the engine's resolution order, identical to the
storefront).

The dialog debounces the simulated values (300ms), keeps the previous
frame while refetching (`placeholderData`), and applies the Phase 12
appearance tokens, so what the admin sees matches the storefront 1:1.

---

# FaraCart React Admin App (Phase 8)

The admin dashboard is a React + TypeScript SPA (Vite + MUI) mounted
inside the WordPress admin at `#faracart-admin` (see
`includes/Admin/Admin.php`). Phase 8 builds the complete admin shell —
providers, routing, layout, shared components and the six admin pages —
following the reference plugin's React architecture exactly.

```text
admin-app/src/
├── main.tsx                     entry: createRoot + AppProviders + App
├── App.tsx                      createHashRouter (data router) + lazy routes
├── boot.ts                      getBootData() — window.faracart
├── types.ts                     boot data + Mission / Settings / envelope types
├── theme/index.ts               WP-admin MUI theme (RTL-aware)
├── providers/
│   ├── AppProviders.tsx         theme + Emotion cache + TanStack Query +
│   │                            Fullscreen + Snackbar providers
│   ├── FullscreenProvider.tsx   owns the faracart-fullscreen body class
│   └── (SnackbarProvider lives in components/notifications/)
├── date-range/
│   ├── types.ts                 DateRange / preset types
│   ├── dateRange.ts             Gregorian range math + labels (Phase 17)
│   └── DateRangeContext.tsx     global range provider + useDateRange()
├── api/
│   ├── client.ts                apiFetch: X-WP-Nonce + envelope unwrap
│   ├── missions.ts                 typed mission CRUD + duplicate
│   ├── campaigns.ts             typed campaign CRUD + duplicate
│   ├── search.ts                typed /search/{products,categories,coupons}
│   ├── settings.ts              typed GET/POST /settings
│   ├── preview.ts               typed POST /preview (Phase 15)
│   └── analytics.ts             typed GET /analytics (Phase 17)
├── components/
│   ├── layout/                  AdminLayout (header + sidebar + main) + navigation
│   ├── notifications/           SnackbarProvider + useSnackbar()
│   ├── date-range/              Phase 17 filter (DateRangeFilter +
│   │                            lazy CustomRangePicker month grid)
│   ├── ConfirmDialog.tsx        reusable destructive-action dialog
│   ├── EmptyState.tsx           no-data panel
│   ├── ErrorBoundary.tsx        render-error fallback with retry
│   ├── MissionPreviewDialog.tsx    server-driven mission preview (Phase 15)
│   ├── CampaignPreviewDialog.tsx  server-driven campaign preview (Phase 15)
│   ├── preview/                 Phase 15 admin preview system
│   │   ├── PreviewWidget.tsx    React mirror of the storefront widget
│   │   ├── PreviewControls.tsx  state presets + amount/quantity/reward/
│   │   │                        device width/template controls
│   │   ├── usePreviewDialog.ts  shared preview state + queries hook
│   │   └── types.ts             control types, tokens, device widths
│   ├── mission-builder/            Phase 9 builder sections
│   │   ├── SectionCard.tsx      titled section wrapper
│   │   ├── EntityAutocomplete.tsx  debounced async search picker
│   │   ├── MissionTypePicker.tsx   mission type selector cards
│   │   ├── missionTypes.tsx        shared MISSION_TYPES definitions
│   │   ├── TargetFields.tsx     dynamic target by mission type
│   │   ├── CompositeChildrenEditor.tsx  AND/OR child missions
│   │   ├── RewardFields.tsx     dynamic reward configuration
│   │   ├── ConditionFields.tsx  excluded products + schedule
│   │   └── DisplayFields.tsx    message/template/icon
│   └── PageContainer.tsx        shared page header + content wrapper
└── routes/
    ├── Dashboard.tsx            live mission summary (REST-backed)
    ├── Missions.tsx                full mission CRUD list (Phase 9)
    ├── MissionBuilder.tsx          mission create/edit builder (Phase 9)
    ├── Campaigns.tsx            full campaign CRUD list (Phase 10)
    ├── CampaignBuilder.tsx      campaign builder: basics, schedule, priority,
    │                            milestone ordering (Phase 10)
    ├── Analytics.tsx            full analytics dashboard: KPI cards,
    │                            trend chart, top lists + filters (Phase 17)
    ├── Appearance.tsx           per-scope template manager (Phase 12
    │                            template engine)
    ├── Settings.tsx             functional react-hook-form settings page
    └── NotFound.tsx             404
```

## Providers

`AppProviders` wraps the app in:

- **MUI theme** — `createAppTheme()` (WP-admin palette: blue #2271b1,
  canvas #f0f0f1, ink #1d2327; `direction` flips for RTL locales).
- **Dedicated Emotion cache** — key `faracart`, RTL-flipped via the
  stylis RTL plugin when the site locale is RTL, so styles never collide
  with other admin plugins and the whole dashboard mirrors for RTL sites.
  No `CssBaseline` (its global resets would leak into the WP admin);
  scoped resets live in `styles.css` under `#faracart-admin`.
- **TanStack Query** — retry 1, 60s staleTime, no refetch on window
  focus.
- **FullscreenProvider** — initialized from boot data (no layout flash),
  owns the `faracart-fullscreen` body class that
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
Typed per-resource modules (`api/missions.ts`, `api/settings.ts`) consume
it.

## Shared components

- `PageContainer` — consistent title + description + actions header.
- `ConfirmDialog` — MUI dialog for destructive actions (busy state,
  not dismissible while busy).
- `EmptyState` / `ErrorBoundary` — no-data and render-error panels.
- `SnackbarProvider` — shared notifications (the reference renders a
  local Snackbar per page; the shared provider is the FaraCart
  foundation variant).

## Pages

| Page | Phase 8 status | Full implementation |
|---|---|---|
| Dashboard | live summary: mission counts, currency, version | 16–17 (analytics) |
| Missions | full mission CRUD list (search/filter/pagination, edit, duplicate, enable/disable, delete, preview) | 9 (Mission Management UI) |
| MissionBuilder | — | 9 (Mission Builder, `/missions/new` + `/missions/:id/edit`) |
| Campaigns | full campaign CRUD list (milestones, status, priority, schedule, edit, duplicate, enable/disable, delete, preview) | 10 (Campaign Builder) |
| CampaignBuilder | — | 10 (Campaign Builder, `/campaigns/new` + `/campaigns/:id/edit`) |
| Analytics | full dashboard: date-range + campaign/mission/reward/product filters, 7 KPI cards, daily trend chart, top campaigns / top missions / top suggested products | 17 (Analytics Dashboard) |
| Appearance | full (pluggable template engine): per-scope default picker (Missions / Campaigns) with live thumbnails, per-template schema-driven appearance forms, live preview, reset to defaults | 12 (Progress Templates) |
| Settings | full: General / Frontend / Mission Calculation / Performance / Advanced five-tab form (react-hook-form) | 18 (Settings) |

## Settings Page (Phase 18)

The `/settings` page is now the full five-tab settings surface
(`FaraCart\REST\SettingsController`, see `docs/api.md` §2.2), built with
react-hook-form + zod-less schema validation and saved through `POST
/faracart/v1/settings`:

- **General** — master enable/disable, full-screen dashboard, currency
  display (`symbol` | `code` | `name`), default mission behavior (`all` |
  `first` | `closest`) and the store-wide default calculation mode
  (`subtotal` | `discounted_subtotal` | `total`).
- **Frontend** — display locations (checkbox chips for cart / mini-cart /
  checkout / shop / product / sticky), mobile behavior (`show` | `hide`),
  template, animation and the bar-height / color / radius / CSS surface
  shared with the Appearance page.
- **Mission Calculation** — five inclusion toggles: tax, discount,
  shipping, sale items and virtual items (each default preserves the
  pre-Phase-18 engine behavior; see `docs/mission-engine.md`).
- **Performance** — progress caching (10 s transient), analytics
  tracking and product suggestions toggles.
- **Advanced** — debug mode, file logging (with the live log path shown
  when enabled) and the developer-hooks switch plus the documented
  `faracart_*` hooks reference rendered from the settings meta.

Every change is validated by the REST schema and normalized by the
sanitizer before persisting; saving identical settings is a successful
no-op.

## Analytics Dashboard (Phase 17)

The `/analytics` page turns the Phase 16 event pipeline into a full
measurement dashboard, served by the single admin endpoint
`GET /faracart/v1/analytics` (`FaraCart\REST\AnalyticsController`, see
`docs/api.md` §2.6) so every slice renders in one request.

**Filters** — the toolbar shares one date range through
`DateRangeProvider` (wired inside the data router in `App.tsx`, so it
syncs the `?preset=`/`?from=`/`?to=` hash params and persists to
localStorage) plus campaign / mission / reward selects (options from the
existing `/campaigns` + `/missions` list endpoints) and a product filter via
the reusable `EntityAutocomplete`. Every change refetches the whole
payload (the query key embeds the range + filters).

**KPIs** — seven cards: impressions, completions, completion rate,
revenue influenced, average cart value, suggestion CTR and add-to-cart
rate (all zero-denominator guarded server-side).

**Charts** (recharts, the reference plugin's charting library):

- a daily **ComposedChart** — impressions + completions bars on the left
  axis, a revenue line on the right (compact axis labels), legend and
  localized tooltips; the series is zero-filled over the whole window
- **Top campaigns** — a horizontal bar chart of completions
- **Top missions** — a ranked list with completion-rate progress bars
- **Top suggested products** — a table of impressions / clicks / added
  (+ a success chip for converters) / CTR / add-to-cart rate

The dashboard is lazy-loaded (its own route chunk, like the reference's
report pages), with loading skeletons, an error alert and an empty state
when the range has no impressions.

## Build & verification

- `npm run typecheck` / `npm run lint` / `npm run build` (Vite manifest
  consumed by `AssetLoader`), Prettier-formatted.
- Browser smoke test: the built bundle is rendered headlessly with boot
  data injected; every route renders its expected content and no console
  errors are produced.
- RTL is covered by the three-part MUI setup (dir attribute, theme
  direction, flipped Emotion cache); i18n delegates to `wp.i18n` via the
  `@wordpress/i18n` shim.
