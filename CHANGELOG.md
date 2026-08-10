# Changelog

All notable changes to the **Goal Cart for WooCommerce** plugin are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project adheres to
[Semantic Versioning](https://semver.org/). Task IDs reference the register in `AGENT.md`.

## [Unreleased]

### Added

- **Phase 32 — Advanced V2 features** — the full V2 surface:
  - **Goal types** — `tag`, `attribute` and `brand` goals (amount or
    quantity restricted to products carrying the configured product tags,
    global attribute taxonomies or the brand attribute), evaluated by new
    `TagEvaluator` / `AttributeEvaluator` / `BrandEvaluator` against
    per-item tags and attribute taxonomies preloaded by `CartIntegration`
    in the same batched way as categories; the admin Goal Builder gained
    the three types with taxonomy/tag pickers.
  - **Advanced conditions** — `customer_roles`, `customer_state`
    (guest / logged-in), `first_order`, `vip` (+ `vip_min_spend` /
    `vip_min_orders`), `shipping_zones`, `cart_coupons` and
    `cart_min_items` gate goals through
    `GoalEngine::conditions_reason()`, with new GoalResult reasons
    (`customer_conditions`, `first_order_only`, `vip_only`,
    `shipping_zone`, `cart_conditions`). The CartContext snapshot now
    carries applied coupons and the matching shipping zone; first-order
    and VIP use public WC customer-history helpers.
  - **Advanced scheduling** — recurring weekdays + a daily time window
    (midnight-crossing supported) on goals, and campaign-level rules in
    `display_rules` that milestones inherit; the Campaign Builder gained
    a Recurring schedule section.
  - **Free gift selection** — free-gift rewards support `choose` mode
    with a `gift_products` list; the storefront renders a gift picker
    that claims the chosen product via the new public
    `POST /goalcart/v1/gift` endpoint (`GiftController`);
    `RewardEngine` reconciles the chosen gift. The RewardFields UI gains
    the multi-product picker.
  - **Countdown** — goals and campaign groups ship `countdown_end`;
    the storefront runs one global ticker for live, locale-aware
    countdown chips, gated by `frontend_countdown` / `sticky_countdown`.
  - **Campaign templates** — the second campaign-scoped template
    `campaign_progress` (`CampaignProgressTemplate`, React renderer,
    storefront `campaignProgress()`) renders the whole campaign as one
    progress readout with a milestone counter.
  - **Celebration animations** — a one-per-goal-per-session confetti
    burst + card pulse on completion, gated by `frontend_celebrate`.
  - **Advanced sticky bar** — position (bottom/top), behavior
    (dismissible / auto-hide with scroll-direction tracking), appear
    delay, countdown chip and top-suggestion link, driven by the new
    `sticky_*` settings.
  - **Advanced upsell ranking** — `suggestions_ranking`
    (balanced | price | popularity) re-orders suggestions by price
    proximity vs. sales/rating popularity.
  - **Search endpoints** — `/search/tags`, `/search/attributes` and
    `/search/zones` for the new pickers.
  - **Tests & i18n** — new `tests/phase32-test.php` (54 checks) plus
    extended fa_IR translations for every new admin string and a
    regenerated POT/JED.
- **Persian (fa_IR) translation of the admin dashboard** —
  `languages/goalcart-fa_IR.po` now translates all 408 strings
  referenced from `admin-app/src` (navigation, analytics KPIs, goal &
  campaign builders, settings, appearance, previews, empty states,
  confirmations, toasts, …) plus the 16 storefront strings (417 entries).
  Compiled by `bin/build-i18n.php` into `goalcart-fa_IR.mo` and the
  `goalcart-fa_IR-goalcart-admin.json` JED the admin app loads via
  `wp_set_script_translations`. `tests/i18n-test.php` section 1b gained a
  coverage scan — every admin-app string in the POT must carry a non-empty
  Persian translation or the suite fails, and duplicate msgids are rejected
  (now 53 checks) — plus JED label checks.
  `docs/i18n.md` §2b documents the coverage.
- **WooCommerce Blocks storefront widgets (P19-T01)** — the progress
  widget now renders on cart/checkout/mini-cart pages built from WooCommerce
  Blocks. The classic template actions (`woocommerce_before_cart`,
  `woocommerce_before_checkout_form`, `woocommerce_after_mini_cart`) never
  fire on block-based storefronts, so `ProgressUI::render_block_widget()`
  hooks the public `render_block` filter and appends the widget after the
  `woocommerce/cart`, `woocommerce/checkout` (full variant) and
  `woocommerce/mini-cart` (compact variant) blocks. The duplicate-render
  registry is shared across both render paths, so a hybrid classic + block
  page can never show the widget twice.
- **WooCommerce compatibility test suite (P19)** —
  `tests/woocommerce-compatibility-test.php` verifies the full Phase 19
  must-test matrix against the installed WooCommerce: classic Cart, classic
  Checkout, Mini Cart, Cart/Checkout/Mini Cart blocks, variable products and
  variants, coupons, sale prices, tax folds, shipping zones + multiple
  shipping methods, guest and logged-in contexts, and the HPOS
  (`custom_order_tables`) feature declaration. `docs/compatibility.md`
  documents the support matrix and the public hook/API contract.
- **WordPress compatibility test suite (P20)** —
  `tests/wordpress-compatibility-test.php` verifies the Phase 20 checklist
  against the installed stack: WordPress / PHP / WooCommerce version gates,
  the plugin header contract (`Requires at least`, `Requires PHP`, `WC
  requires at least`, `Text Domain`, `Domain Path`), activation/deactivation
  hook wiring, multisite-safe per-site table prefix and options, localization
  (text domain on `init`), RTL (admin dashboard `dir` attribute + storefront
  `isRtl`), and the filterable admin/REST capabilities. The WordPress/PHP
  support matrix is documented in `docs/compatibility.md` §6.

- **Gutenberg block (P21)** — the progress widget is now available as a
  native Gutenberg block, `goalcart/progress` (api version 2, widgets
  category), registered server-side via `register_block_type()` with no JS
  editor build and no new dependency. Attributes `variant` (full/compact)
  and `template` map to the shortcode contract; the block renders the same
  inert widget container the storefront JS mounts, and
  `ProgressUI::page_needs_widget()` auto-loads the storefront assets on any
  page carrying the block (`has_block()`). Elementor and Bricks are covered
  through their shortcode elements (`[goalcart_progress ...]`), consistent
  with the roadmap priority: Gutenberg → WooCommerce Blocks (Phase 19) →
  Elementor → Bricks. Documented in `docs/compatibility.md` §7.

### Fixed

- **The storefront progress widgets now update live on every AJAX cart
  change (Phase 11).** Previously the widgets only refreshed on the
  classic jQuery cart events (`added_to_cart` … `wc_fragments_refreshed`)
  — coupon apply/remove and cart-emptied were not bound, and WooCommerce
  Blocks cart mutations (Store API) never fired a classic jQuery event,
  so on block-driven pages (or after coupon actions) the progress froze
  until a full page reload. `assets/js/frontend.js` now funnels every
  cart-change mechanism into one `goalcart:cart-changed` bridge on
  `document.body`: the classic events (now incl. `applied_coupon` /
  `removed_coupon` / `wc_cart_emptied`), the Blocks `wc-blocks_*` DOM
  events, and a `wp.data.subscribe` subscription to the `wc/store/cart`
  store whose fingerprint folds in the totals (so coupon/shipping-driven
  total changes are caught). Refreshes are trailing-debounced (150 ms,
  no request storm from quantity steppers) with one 700 ms follow-up
  after the session write lands; a supersede guard (epoch counter +
  abort of the in-flight XHR) ensures a stale response can never
  overwrite fresher progress; and a subtle `goalcart-widget--updating`
  dim (never a blank or flash, disabled under
  `prefers-reduced-motion`) signals an in-flight refresh. Purely
  presentational — no change to goal/reward calculation, REST payloads
  or caching. `tests/frontend-test.php` gained 10 source-scan checks
  (98 total).
- **Free-gift rewards are now shopper-proof — and actually get added.**
  Two gaps fixed. (1) When the reward type is free gift, the gift is now
  added to the cart **with a zero price** as soon as the goal is complete:
  `FreeGiftApplicator::apply()` stamps the gift line with the
  `goalcart_gift_product` marker and `RewardEngine::zero_gift_prices()`
  zeroes gift lines during totals calculation (priority-10 hook before
  sync, plus a re-zero after the adding pass). (2) Shoppers can no longer
  remove an earned gift from the cart: the remove link is hidden
  (`woocommerce_cart_item_remove_link`), the quantity is locked to 1 with
  a hidden input (`woocommerce_cart_item_quantity`), and a gift line a
  shopper removes while its goal still grants an automatic free gift is
  **restored immediately** (`woocommerce_cart_item_removed` →
  `RewardEngine::restore_removed_gift()`, which re-checks the goal's
  current state via a cache-free `find()` so deactivated goals are never
  re-granted — the engine's own revocations are suppressed via the
  removing-gift flag). Restored lines are re-zeroed on the spot. The goal
  builder now warns when a free-gift reward has no gift product selected
  (goal #1484 on this store was configured as free gift with an empty
  `reward_meta`, so the engine correctly refused to add anything).
  `tests/reward-test.php` gained the full protection matrix (94 checks):
  remove-link/quantity/restore wiring, zero-pricing, quantity lock,
  remove-link hiding, non-gift and orphaned-goal removals not restored,
  engine revocation not restored, and the transactional positive restore
  path (gift re-added with marker + zero price, permanent removal once
  the goal is inactive). Note: the remove-link/quantity filters are
  classic cart-table hooks, so on WooCommerce Blocks carts the shopper
  still sees the UI affordances — but the engine-level
  `woocommerce_cart_item_removed` restore blocks the removal there too,
  because Blocks mutations funnel through `WC_Cart`.
- **Free-gift rewards: quantity lock, stale removal and selectable mode
  now actually work (Phase 5).** Three bugs fixed in the reward engine,
  no calculation semantics changed. (1) **Quantity can no longer be
  changed by the shopper on any path:** `RewardEngine::clamp_gift_quantities()`
  (priority 5 on `woocommerce_before_calculate_totals`) resets gift
  lines to 1 before every totals pass — classic cart updates, AJAX and
  the Store API all funnel through it — and a
  `woocommerce_store_api_product_quantity_editable` filter renders the
  Blocks-cart quantity fixed (no editable stepper). Previously only the
  classic cart page display was locked, so a direct Store API request
  could raise the quantity. (2) **A gift whose granting goal stops
  qualifying is now removed live (stale-reward guarantee restored):**
  `reconcile_gifts()` sweeps goal-marked cart lines (scoped to the goals
  evaluated in the pass) in addition to the session-tracked removal, so
  session/cart divergence (expiry, restored persistent cart) can no
  longer leave a stale gift in the cart; customer-added lines of the
  same product are never touched, and optional/selectable choices are
  recovered from the cart when the session record is lost. (3)
  **Selectable (choose) mode works end-to-end:** choosing a candidate
  adds exactly one gift line, re-selecting replaces it instead of
  silently keeping the old product, and losing eligibility revokes the
  choice (the picker re-prompts on the next completion) —
  `FreeGiftApplicator::apply()` is now product-aware and
  `add_chosen_gift()` swaps the previous selection. (4) **Removal
  permission is enforced per mode:** the add-mode is stamped on every
  gift line (`goalcart_gift_mode`, self-healed on kept legacy lines);
  mandatory gifts keep the hidden remove control and server-side
  rejection (re-add while granted), while optional and selectable gifts
  remain removable with their quantity still locked.
  `tests/reward-test.php` grew to 122 checks (0 failures) with
  transactional coverage of stale removal, customer-line survival and
  choose-mode re-selection.
- **Choosing a gift in the storefront picker no longer fails with
  "Your cart is empty" (Phase 32/Phase 6).** `POST /goalcart/v1/gift`
  rejected every claim with `goalcart_gift_empty_cart` because
  WooCommerce does not initialize the cart for custom REST routes and
  `GiftController::handle()` read a bare `WC()->cart` — always null on
  REST — so the shopper's session-backed cart was invisible to the
  endpoint. `GiftController` now acquires the cart through
  `CartIntegration::live_cart()` (promoted from protected), the same
  Phase 6 single source of truth the progress endpoint uses: it restores
  the session-backed cart via idempotent `wc_load_cart()` guarded to REST
  requests after `woocommerce_init`. Genuinely empty carts still return
  the 400. Covered by new `live_cart()` public-access checks in
  `tests/cart-rest-initialization-test.php` (24 checks) and container
  wiring guards in `tests/reward-test.php` (124 checks).
- **The analytics dashboard showed "No analytics yet" even with recorded
  events.** The date filter built `created_at <= 'YYYY-MM-DD'` with the
  raw date-only `to` bound; MySQL casts a bare date to midnight, so every
  event recorded on the `to` day was excluded. Because the default range
  ends today (last 30 days), all of today's activity silently vanished
  and the page rendered the empty state. `AnalyticsRepository::clauses()`
  now widens date-only bounds to the full day (`00:00:00` / `23:59:59`)
  via the same `day_bounds_start()` / `day_bounds_end()` helpers the
  trend query already used — summary KPIs and top lists now include the
  `to` day. `tests/analytics-dashboard-test.php` gained today-inclusive
  range checks.
- **Disabling a goal (or campaign) reset its untouched fields — the goal
  amount was saved as 0.** The Goals/Campaigns list switches send a
  partial update (`PUT` with only `{ status }`), but the update route
  schemas declared defaults for every field and WP_REST_Server injects
  those defaults into params the client did not send during
  sanitization. `handle_update()` passes the full param set to the
  repository, so a status-only toggle silently wrote `target = 0`,
  `campaign_id = null`, `children = []`, `reward_meta/display_settings/limits
  = []`, `priority = 10`, `exclusive = false`, `description = ''` and the
  `status/type/calculation_mode/operator` defaults on top of the goal's
  real values. The update arg schemas in `GoalsController::update_args()`
  and `CampaignsController::update_args()` now strip every `default`, so
  only the keys the client actually sent are ever written (the create
  schemas keep their defaults). `tests/rest-api-test.php` gained
  dispatched-PUT regression checks that toggle a goal/campaign/composite
  goal as an admin and assert the untouched fields survive (142 checks).
- **Saved settings sometimes did not show when opening the Settings page.**
  Three consumers shared the `['settings']` TanStack Query cache but
  disagreed on its shape: the Settings page cached the REST envelope
  `{ data, meta }`, while the Appearance page wrote a **raw** settings
  object under the same key on save, and the preview dialogs read the
  raw shape. After an Appearance save, opening Settings within the
  60 s stale window served the mismatched cache, `data?.data` resolved
  to `undefined`, and the form fell back to defaults — the saved
  settings looked “not shown” (and a Settings save corrupted the
  Appearance/preview reads the same way). Every consumer now reads and
  writes the envelope shape: Appearance uses `fetchSettingsEnvelope` and
  writes `{ data: saved, meta }`, the preview dialogs read
  `settingsQuery.data?.data`, and the raw `fetchSettings()` helper was
  removed so the mismatch cannot reappear.
- **Storefront widgets showed only one goal when a campaign was active —
  its other milestones were invisible.** The widget layer rendered a
  single “featured” goal per container plus a tiny milestone ladder
  (target dots), and compact locations (mini-cart, shop, product,
  sticky) skipped even that — so a campaign with several milestone
  goals looked like a single-goal widget. Every eligible goal now
  renders as its own full card, stacked in a shared
  `.goalcart-widget__goals` wrapper (`renderWidget()` in
  `assets/js/frontend.js`), in full *and* compact widgets; the
  cross-goal ladder was removed (each goal IS a card) and the
  `milestone` template shows the goal's own threshold as a single rung.
  The sticky bar keeps the featured (first eligible) goal — a slim bar
  stays at-a-glance. The admin preview mirror
  (`admin-app/src/components/preview/PreviewWidget.tsx`) renders the
  same stacked-card layout so previews stay 1:1 with the storefront.
- **Storefront progress widgets were shown to logged-in admins browsing
  their own shop.** The widget layer only gated on `is_admin()` (the
  admin *area*) and the `enabled` setting — a site administrator
  visiting the cart, checkout or shop saw the same shopper-facing
  progress bars, rewards and sticky bar as customers.
  `ProgressUI::is_enabled()` now also consults a new
  `is_visible_to_user()` gate: logged-in users with the
  `goalcart_admin_capability` capability (default `manage_options` — the
  same capability as the admin menu, so every user who can administer
  the plugin is treated as staff) no longer get the containers, config,
  assets or sticky bar. The whole decision is filterable via the new
  `goalcart_frontend_visible_to_user` filter (e.g. hide for every
  logged-in user). `tests/frontend-test.php` gained transactional admin
  checks; docs/frontend.md documents the gate.
- **Admin builders showed the pre-save values after editing a goal or
  campaign.** The Goal/Campaign builder detail queries (`['goal', id]`
  / `['campaign', id]`) were never invalidated after a save — only the
  list queries (`['goals']` / `['campaigns']`) were — so with the
  client's 60 s stale window, reopening a just-edited goal or campaign
  served the cached pre-save row and the form loaded the old settings.
  The builders now invalidate their detail query on save, and the
  Goals/Campaigns list mutations (enable/disable, duplicate, delete)
  invalidate the matching detail prefix too, so a reopened builder
  always refetches the current row. `tests/rest-api-test.php` already
  proves the update endpoints return the fresh row (120/120).
- **Storefront money labels showed raw HTML entity text instead of the
  currency symbol — “&#x062A;&#x0648;&#x0645;&#x0627;&#x0646;1.000.000”
  instead of “تومان 1.000.000”.** WooCommerce ships the IRT symbol as an
  HTML entity in its currency table, and the plugin's plain-text money
  formatter stripped the `wc_price` markup with `wp_strip_all_tags`
  without decoding the remaining entities; the payload strings are
  inserted into the DOM via `textContent`, so the literal entity text
  was visible to shoppers. `MessageEngine::format_number()` (progress
  messages, reward labels) and `SuggestionEngine::shape()` (suggestion
  `price_html`) now decode the stripped markup with
  `html_entity_decode(…, ENT_QUOTES, 'UTF-8')`, and the money
  expectation helper in `tests/message-test.php` mirrors the same
  pipeline.
- **Storefront progress stayed frozen after an AJAX add-to-cart — the
  widget never reflected the added item.** The AJAX request only
  persists the WooCommerce session on PHP `shutdown`, after its
  response has been flushed to the browser; a poll fired straight from
  WooCommerce's `added_to_cart` event could race that write and read
  the previous cart. Because the widget is event-driven (the poll
  interval defaults to off), a lost race left the bar and message
  stale until the next cart event. Cart events now poll twice —
  immediately and once more after 600 ms
  (`refreshAfterCartChange()` in `assets/js/frontend.js`) — so the
  follow-up poll lands after the session write and the widgets settle on
  the persisted cart under normal load; the several cart events WooCommerce
  fires per mutation are coalesced into a single follow-up poll, and the
  extra request is cheap because unchanged payloads are skipped by the
  Phase 23 fingerprint check.
  Verified against the real WooCommerce 11.0.0 session flow
  (poll-before-save sees an empty cart, poll-after-save sees the item)
  and with a simulated DOM run of the cart-event binding.
- **Storefront analytics went quiet after a cached page or long-lived
  tab — every `/track` report failed with `goalcart_invalid_nonce`
  (403).** The tracking nonce is baked into the page HTML and is only
  valid for ~12–24 h, bound to the rendering user's session; a cached
  page serving an expired or another user's nonce (or a tab left open
  past the lifetime) turned every subsequent event report into a 403.
  The public `GET /goalcart/v1/progress` payload now mints a fresh
  `goalcart_track` nonce (`tracking_nonce`) on every response and
  `assets/js/frontend.js` adopts it before reporting the next event —
  pages self-heal within one poll. The nonce is withheld while
  `analytics_enabled` is off and is never stored in the optional
  progress cache (it is re-injected fresh on every cache read), so a
  cached payload can never serve a stale or another user's nonce.
  `tests/frontend-test.php` and `tests/settings-test.php` assert the
  payload nonce and both cache paths. Also removed a leftover
  `error_log()` debug line in `CartIntegration::live_cart()` that
  referenced an undefined variable.
- **Storefront progress went stale after cart changes — the widget kept
  showing the previous cart's numbers.** WP core only sends `Cache-Control`
  headers for cookie-authenticated REST requests, so the guest
  `GET /goalcart/v1/progress` response was cacheable; the widget's bare
  GET poll (no cache-buster) let browsers heuristically cache the first
  payload (e.g. empty cart → 0%) and serve it on every later poll — the
  bar and message never reflected items added to the cart, reading as
  “progress not calculated correctly”. The endpoint now stamps
  `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` on both
  the fresh and transient-cached response paths
  (`FrontendController::prevent_progress_caching()`), and
  `assets/js/frontend.js` cache-busts every poll with a `?_=<timestamp>`
  parameter. `tests/frontend-test.php` asserts both layers (75 checks).
- **Per-goal display template now reaches the storefront** — the goal
  builder's Display → Template picker (`display_settings.template`) was
  saved but never served: `GET /goalcart/v1/progress` exposed the goal's
  `icon` but not its `template`, and `frontend.js` only honored the
  per-widget `data-goalcart-template` override or the store-wide
  Appearance setting. The payload now carries each goal's normalized
  `template` (`goal_template()` in `FrontendController`), and the widget
  resolves it per goal — container override → goal template → global
  Appearance template → `basic` — so a goal set to Milestones/Card/
  Percentage renders that variant on the storefront.
- **All templates looked identical on the storefront — two causes
  fixed.** (1) The frontend JS/CSS were enqueued with the static
  `GOALCART_VERSION`, which never changes between releases, so browsers
  kept serving the stale cached bundle no matter what template was
  chosen; `ProgressUI::enqueue_assets()` now versions both assets by
  `filemtime()` (falling back to `GOALCART_VERSION`), so every edit
  cache-busts. (2) The milestone template with a single goal rendered as
  a bare progress bar (the ladder needs ≥2 rungs), visually identical to
  basic; `milestonePanel()` in `assets/js/frontend.js` now renders the
  single threshold as one rung (dot + target label) — in full *and*
  compact widgets — so Milestones is visibly distinct even with one
  goal. Also hardened `goalContainer()` with a null guard for
  reward-less goals (an unguarded `appendChild` of the optional reward
  chip crashed the widget, leaving reward-less goals invisible).

### Phase 0 — Reference Plugin Discovery (100% complete)

- **P00-T01 Objective** — Phase 0 objective defined: reverse-engineer `wooinsights` and produce a reusable architectural specification.
- **P00-T02 Verify Reference Path** — Verified `/home/qbicfox/public_html/woo-app/wp-content/plugins/wooinsights` exists, is readable, and contains the main plugin file, Composer config, package manager config, and build configuration.
- **P00-T03 Generate Complete File Inventory** — Added `docs/reference-plugin-file-inventory.md` covering all PHP, TS/TSX, CSS, JSON, Composer, package, build, config, test, documentation, and language files with purposes.
- **P00-T04 Inspect PHP Architecture** — Documented bootstrap (singleton + file-scope `boot()`), PSR-4 autoloading, DI container, HookManager, services, REST controllers, settings, tracking services, cron jobs, and activation/deactivation/uninstall behavior.
- **P00-T05 Inspect React Architecture** — Documented entry/mount, hash data router with lazy routes, layout, components, hooks, API client, TanStack Query + context state, react-hook-form, MUI theming, and RTL handling.
- **P00-T06 Inspect Build System** — Documented Vite 5, TypeScript strict config, ESLint 9 flat config, Prettier, npm scripts, manifest-based WordPress enqueue, dev-server HMR, and cache-busting.
- **P00-T07 Inspect Coding Conventions** — Documented naming, PHPDoc, strictness, i18n, error handling, and TypeScript conventions.
- **P00-T08 Inspect Database Conventions** — Documented table naming, dbDelta migrations, indexes, foreign keys, timestamps, money columns, and upgrade strategy.
- **P00-T09 Inspect API Conventions** — Documented endpoint naming, methods, permissions, nonce/auth, validation, response envelope, pagination, errors, and the frontend wrapper.
- **P00-T10 Create Architecture Report** — Added `docs/REFERENCE_ARCHITECTURE.md` with all 12 required sections (directory, PHP, React, build, API, database, testing, coding conventions, asset, security, reusable patterns, patterns NOT to copy).
- **P00-T11 Acceptance Criteria** — All Phase 0 acceptance criteria satisfied; reference plugin not modified.

**Overall project progress: 5%** (Phase 0 weight 5% × 100%).

### Phase 1 — Product Specification (100% complete)

- **P01-T01 Objective** — Defined Goal Cart as a **WooCommerce Cart Revenue Optimization Engine** (goals + rewards + progress + suggestions + campaigns + analytics), not merely a progress-bar widget; documented positioning and success metrics.
- **P01-T02 Core Product Concepts** — Defined Goal (types/target/priority/conditions), Reward (free shipping, percentage discount, fixed discount + safety rules), Campaign (scheduled milestone collections), Progress (current/target/remaining/percentage/completed/reward_state/eligible), and Suggestion (sources, ranking, gap-closing pricing).
- **P01-T03 Initial MVP Scope** — Captured all 20 MVP features (amount/quantity/category goals, multiple + milestone goals, free shipping/percentage/fixed rewards, progress bar, dynamic messages, cart/mini-cart/checkout integration, AJAX updates, responsive UI, RTL, currency-aware formatting, product suggestions, campaign scheduling, basic analytics) with explicit deferrals of advanced AI and A/B testing.
- Added `docs/PRODUCT_SPEC.md` — the product source of truth covering vision, concepts, user journeys, MVP scope, functional/non-functional requirements, success metrics, and Definition of Done.

**Overall project progress: 8%** (Phase 0 5% + Phase 1 weight 3% × 100%).

### Phase 2 — Plugin Foundation (100% complete)

- **P02-T01 Objective** — Created the Goal Cart plugin using the exact architectural conventions discovered in Phase 0 (reference: `wooinsights`): namespace `GoalCart\` → `includes/`, singleton bootstrap at file scope, DI container, HookManager, Schema/Installer migration framework, Vite admin-app.
- **P02-T02 Tasks** — Implemented all foundation tasks: plugin slug `goalcart` (`goalcart.php`); plugin bootstrap; Composer config (PSR-4, PHP ≥ 7.4); frontend build stack (`admin-app/`: Vite 5, TypeScript strict, ESLint 9 flat, Prettier, npm scripts); activation/deactivation/uninstall (`Installer` + `uninstall.php`); plugin constants; WooCommerce dependency checks; WP/PHP/WC minimum version compatibility checks (`Compatibility`, load-order-safe `plugins_loaded` gate); capability checks (`goalcart_admin_capability` filter); nonce strategy (`wp_rest` nonce in admin boot data + `X-WP-Nonce` client header); translation loading (`load_plugin_textdomain`); logging strategy consistent with the reference (`error_log` with phpcs annotations).
- **P02-T03 Definition of Done** — Plugin loads and boots without fatal errors in a real WordPress 7.0.2 + WooCommerce 11.0.0 context (verified via read-only WP smoke test: constants, DI wiring, all hooks, compatibility gate, admin shell); follows the reference architecture.
- Added files: `goalcart.php`, `uninstall.php`, `composer.json`, `.editorconfig`, `.gitignore`, `README.md`, `includes/{Plugin,Container,Compatibility}.php`, `includes/Hooks/HookManager.php`, `includes/Database/{Schema,Installer}.php`, `includes/Settings/Settings.php`, `includes/Admin/{Admin,AssetLoader}.php`, `assets/css/admin-fullscreen.css`, `languages/.gitkeep`, `tests/.gitkeep`, and the `admin-app/` Vite + TypeScript + MUI scaffold.
- **Verification:** `php -l` clean on all PHP files; `composer dump-autoload` generates the PSR-4 autoloader; `npm install` + `npm run typecheck` + `npm run lint` + `npm run build` all pass (production bundle + Vite manifest generated); read-only WP-context smoke test passes. The plugin was **not** activated on the live site.

**Overall project progress: 12%** (Phase 0 5% + Phase 1 3% + Phase 2 weight 4% × 100%).

---

### Phase 3 — Database & Domain Model (100% complete)

- **P03-T01 Objective** — Designed the persistence layer to store goals, campaigns, and analytics events, following the reference (`wooinsights`) migration strategy: `Schema::get_schema()` table definitions + `Installer::maybe_upgrade()` version-gated `dbDelta` migrations with `WOOINSIGHTS_DB_VERSION`-style version constant.
- **P03-T02 Recommended Domain Entities** — Implemented three tables in `includes/Database/Schema.php`: `goalcart_goals` (goal definition: type, target, calculation_mode, reward_type, campaign FK, priority, menu_order), `goalcart_campaigns` (name, status, starts_at, ends_at, priority), `goalcart_analytics_events` (goal/campaign FKs, event_type, session_id, cart_value). Added plugin-owned foreign keys (SET NULL) and indexes on lookup columns (campaign_id, goal_id, status, dates, event_type).
- **P03-T03 Database Rules** — Applied the rules: indexes on all FK + frequently-filtered columns; JSON used only where genuinely needed (deferred); no duplication of WC data (product/order values referenced by ID, never copied); safe upgrades via version-gated `dbDelta`; foreign-key-aware uninstall (FK checks disabled around table drops).
- Added files: `docs/database.md` (domain model, schema DDL, ERD, index/FK rationale, migration & upgrade policy, uninstall behavior).
- **Verification:** `php -l` clean; WP-context DB round-trip test passes against the real WordPress 7.0.2 + WooCommerce 11.0.0 database — all three tables created, all 3 plugin-owned foreign keys verified (the two product/order FKs into `wp_posts` were removed during review: HPOS stores orders outside `wp_posts` and FKs into WC tables would block WC deletions or cascade-wipe analytics), insert/delete round-trip OK, uninstall drops everything leaving zero `goalcart_` tables (no trace). DB version bumped to `0.2.0` (`GOALCART_DB_VERSION`). Tables are **not** left installed on the live site (test cleans up after itself); plugin not activated.

**Overall project progress: 15%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 weight 3% × 100%).

---

### Phase 4 — Goal Engine (100% complete)

- **P04-T01 Objective** — Built the central calculation engine independently from any UI: it never renders, never touches the database, and never reads request state — callers supply a `Goal` + `CartContext` and receive a `GoalResult`.
- **P04-T02 Architecture** — Implemented the `CartContext → GoalEvaluator → GoalResult → ProgressCalculator` pipeline in `includes/Goals/`: `Goal` + `CartItem` + `CartContext` value objects (with `CartContext::from_cart()` WC adapter), `GoalEvaluator` interface, `GoalEvaluatorRegistry` (filterable via `goalcart_goal_evaluator_classes`), `GoalEngine` facade (status/schedule/target eligibility checks), and shared `ProgressCalculator` math. Engine registered in the DI container (`Plugin::goal_engine()`).
- **P04-T03 Goal Types** — All seven types implemented as stateless evaluators in `includes/Goals/Evaluators/`: amount (subtotal/total/discounted_subtotal), quantity (decimal-aware), distinct quantity, category (amount or quantity), product (variations + parents; quantity default), weight, and composite (AND = weakest child, OR = best child, over nested child goals). Type-aware default calculation bases.
- **P04-T04 Goal Result** — Consistent 8-field result object: `current`, `target`, `remaining` (never negative), `percentage` (0–100 capped), `completed`, `reward_state` (not_applicable/locked/unlocked), `eligible`, `reason` (goal_inactive/out_of_schedule/invalid_target/no_matching_items/unknown_type), plus `to_array()` for the REST/JS layer.
- **P04-T05 Edge Cases** — Added `tests/engine-test.php` (71 checks, runnable via `php tests/engine-test.php`): empty cart, zero/negative target, sale prices, coupons, taxes, shipping, virtual/downloadable products, variable products + variations, excluded products, decimal quantities, guest vs logged-in users, inactive goals, scheduling windows, unknown types. All pass in the real WordPress 7.0.2 + WooCommerce 11.0.0 context.
- Added files: `includes/Goals/` (11 classes), `tests/engine-test.php`, `docs/goal-engine.md`.
- **Verification:** `php -l` clean; engine test suite 71/71; Phase 2 smoke test still passes (no regressions); no database changes, plugin not activated.

**Overall project progress: 22%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 weight 7% × 100%).

---

### Phase 5 — Reward Engine (100% complete)

- **P05-T01 Objective** — Decoupled rewards from goal calculation: the GoalEngine (Phase 4) computes a `GoalResult` and the RewardEngine turns it into a `RewardResult` using the goal's reward configuration, so rewards can be changed, stacked, and safety-checked independently of the math.
- **P05-T02 Reward Types** — Implemented the reward layer in `includes/Rewards/`: `Reward` value object (typed accessors over the reward columns + JSON `reward_meta`), `RewardResult` (5 states + blocking reasons), `RewardApplicator` interface, `RewardApplicatorRegistry` (filterable via `goalcart_reward_applicator_classes`), and five applicators — free shipping (shipping zones + method instances, via `woocommerce_package_rates`), percentage discount (cap + eligible/excluded products & categories, negative cart fee), fixed discount (clamped to the eligible value), free gift (automatic or optional), and coupon (existing validated codes or deterministic generated coupons, cleaned up on uninstall). The `RewardEngine` registers the WooCommerce hooks (`woocommerce_before_calculate_totals` ×2, `woocommerce_cart_calculate_fees`, `woocommerce_package_rates`) and is DI-wired via `Plugin::reward_engine()`; `Goals\GoalRepository` loads active goals (with campaign gating) once per request.
- **P05-T03 Reward Safety** — Enforced every Phase 5 guarantee: duplicate/stacking rules (`RewardSafety::stacking_allows()`), reward-loop prevention (own-fee exclusion in `CartContext::own_fees_total()` + idempotent reconciliation), stale-reward reversal (coupons/gifts session-tracked and reconciled on **every** totals pass, so a goal that stops qualifying without any cart change — schedule expiry, admin deactivation — has its reward revoked immediately; discount fees rebuilt per pass), invalid-coupon validation (+ generated-coupon ownership marker and save-failure guard), and excluded-product exclusion in discount bases and generated coupons.
- **Live-cart correctness (review-driven)** — Verified empirically that WC zeroes the cart's aggregate totals before `woocommerce_before_calculate_totals` fires; `CartContext::from_cart()` now derives the money bases from the cart line items (always current) so amount-mode goals evaluate honestly on the live cart, with the grand total falling back to the after-discount line value until totals are computed (tax refinement deferred to Phase 6).
- Added files: `includes/Rewards/` (12 classes), `includes/Goals/GoalRepository.php`, `tests/reward-test.php`, `docs/rewards.md`.
- **Verification:** `php -l` clean; reward test suite 72/72 (free-shipping rate filtering, stacking, coupon/gift safety, WC hook wiring, `from_cart` line-item bases); engine test suite 75/75 (no regressions); PHP 8.4 implicit-nullable deprecations removed from the applicator interface; tests stay read-only (no products created, no database writes, plugin not activated).

**Overall project progress: 27%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 weight 5% × 100%).

---

### Phase 6 — Cart Context & WooCommerce Integration (100% complete)

- **P06-T01 Objective** — Created `includes/Cart/CartIntegration.php` (`GoalCart\Cart`): the single, request-level source of truth for the live-cart snapshot. The reward engine now consumes `CartIntegration::context()` instead of building the context itself (falling back to a direct build when the service is absent, so the Phase 5 tests stay untouched).
- **P06-T02 Integrate With** — Registered 10 cart-lifecycle invalidation hooks: cart init/session restore (`woocommerce_cart_loaded_from_session`), add (`woocommerce_add_to_cart`), remove/restore (`woocommerce_cart_item_removed` / `_restored`), quantity updates (`woocommerce_after_cart_item_quantity_update`), coupon apply/remove (`woocommerce_applied_coupon` / `woocommerce_removed_coupon`), shipping changes (`woocommerce_shipping_method_chosen` + Store API `woocommerce_store_api_cart_select_shipping_rate`), and checkout AJAX (`woocommerce_checkout_update_order_review`). WooCommerce Blocks cart mutations funnel through the classic `WC_Cart` methods, so the classic hooks cover Blocks automatically.
- **P06-T03 Cart Context** — `CartContext::from_cart()` now accepts a preloaded product-id → category-ids map and resolves variation categories from the **parent** product (the WC convention — categories live on the parent), so category goals count variations correctly; per-item term queries only run as a fallback.
- **P06-T04 Performance** — Request-level memoization of the built context (cache keyed by shopper-controlled line data + args, rebuilt automatically when contents change), batched category preloading via a single `wp_get_object_terms()` call (WP object-cache backed), and the existing per-request-cached `GoalRepository`/`GoalEngine` retained. Verified on the live store: a 6-item cart builds its context in **1 query** (previously 6+), the memoized second build runs **0 queries**.
- Added files: `includes/Cart/CartIntegration.php`, `tests/cart-integration-test.php`, `docs/cart-integration.md`.
- **Verification:** `php -l` clean; cart-integration test suite 22/22 (hook wiring, memoization, invalidation, cache keying, preloaded variation categories, null-cart guard); reward test suite 72/72 and engine test suite 75/75 (no regressions); live-cart behavior verified read-only against the real WordPress 7.0.2 + WooCommerce 11.0.0 environment (no products created, no database writes, plugin not activated).

**Overall project progress: 32%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 weight 5% × 100%).

---

### Phase 7 — REST API / AJAX Layer (100% complete)

- **P07-T01 Objective** — Exposed a clean API for the React admin and frontend components through a `goalcart/v1` REST namespace (`includes/REST/BaseController`), mirroring the reference plugin's REST conventions: the `{ data, meta, pagination }` response envelope (matching the `ApiEnvelope` type already in `admin-app/src/types.ts`), capability + rate-limited permission callbacks, and structured `WP_Error` responses.
- **P07-T02 Admin API** — Implemented the admin endpoints: goals list (paginated, status filter, name search), goal details, create, update (partial — only provided keys written), delete, and duplicate (`GoalsController`); settings GET/POST (`SettingsController`); product/category/coupon search for the goal builder (`SearchController`, capped at 50 results, server-side); and a read-only campaigns list/detail (`CampaignsController` + `CampaignRepository`) so the builder can assign goals to campaigns — full campaign CRUD arrives with Phase 10. Analytics endpoints are deliberately deferred to Phases 16–17 (no analytics data exists yet). `GoalRepository` gained the full CRUD layer (`all`/`get`/`create`/`update`/`delete`/`duplicate`) plus a fix that spreads the persisted `conditions` JSON onto the Goal model's `categories`/`products`/`excluded_products`/`operator`/`children` keys — so stored category/product/composite goals now evaluate correctly.
- **P07-T03 Frontend API** — Added the public `GET /goalcart/v1/progress` endpoint (`FrontendController`): evaluates every active goal against the live cart snapshot (via `CartIntegration`) and exposes only the minimum necessary data — `current, target, remaining, percentage, completed, message, reward, suggestions` (plus reward state, eligibility and reason) — with a minimal built-in message until the Phase 13 template engine and empty suggestions until Phase 14.
- **P07-T04 Security** — Every endpoint implements: capability checks (`manage_options`, filterable via `goalcart_rest_capability`), per-user rate limiting on admin routes and per-IP rate limiting on the public progress route, REST arg-schema validation/sanitization (enums, types, ranges, datetime + campaign-existence validate callbacks, sanitize callbacks), predictable `{ code, message, data: { status } }` errors, and payload shaping that serializes only known fields. Anonymous admin access is rejected with 403 (verified through a real `WP_REST_Server` dispatch).
- Added files: `includes/REST/` (BaseController + 5 controllers), `includes/Campaigns/CampaignRepository.php`, `tests/rest-api-test.php`, `docs/api.md`.
- **Verification:** `php -l` clean; REST test suite 62/62 (route registration, response envelope, permission callbacks, schema validation, transactional goal CRUD + duplicate, progress payload, search, settings — every write wrapped in a rolled-back transaction and asserted absent afterwards); reward 72/72, engine 75/75, cart-integration 22/22 (no regressions); end-to-end dispatch verified read-only against the real WordPress 7.0.2 + WooCommerce 11.0.0 store with a real admin user (create/list/rollback through the live dispatch path, real product search, guest progress).

**Overall project progress: 35%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 weight 3% × 100%).

---

### Phase 8 — React Admin Foundation (100% complete)

- **P08-T01 Objective** — Built the complete admin shell in `admin-app/src/` using the reference plugin's React architecture: MUI theme (WP-admin palette), dedicated RTL-aware Emotion cache, TanStack Query, `createHashRouter` data router, responsive `AdminLayout`, sidebar navigation, page containers, and the six admin pages — Dashboard, Goals, Campaigns, Analytics, Settings, Appearance.
- **P08-T02 Required** — Implemented every required capability: React 18 + TypeScript (strict); hash routing with lazy-loaded secondary routes (code splitting — Settings/Campaigns/Analytics/Appearance are separate chunks); shared layout with collapsible nav groups (persisted in localStorage) + mobile drawer + user menu + full-screen mode (FullscreenProvider owns the `goalcart-fullscreen` body class, switching instantly when saved); page container (`PageContainer`); API client (`apiFetch` with `X-WP-Nonce` + Phase 7 envelope unwrap) with typed `api/goals.ts` + `api/settings.ts`; server state (TanStack Query mutations/queries); forms (react-hook-form in Settings); validation (RHF + REST contract); notifications (shared `SnackbarProvider` + `useSnackbar`); loading states (skeletons); error states (query error alerts + `ErrorBoundary` with retry); confirmation dialogs (`ConfirmDialog`, wired into goal deletion).
- **P08-T03 Admin Pages** — Initial navigation matches the spec (Dashboard / Goals / Campaigns / Analytics / Settings / Appearance). Dashboard is live REST-backed (goal counts, currency, version); Goals shows a read-only list (name, type, target, reward, status) with delete-through-confirmation + snackbar + refetch (Phase 9 replaces it with the full CRUD builder); Settings is fully functional (enabled + full-screen toggles saved via the Phase 7 REST API, full-screen mode previews live); Campaigns/Analytics/Appearance are page containers with empty states for their phases (10, 16–17, 12); `NotFound` 404 route added. `types.ts` gained the `Goal` / `GoalCartSettings` / pagination types mirroring the Phase 7 payloads.
- Added files: `docs/frontend.md`, plus `admin-app/src/{theme,providers,api,components,routes}/` (16 new modules).
- **Verification:** `npm run typecheck`, `npm run lint`, Prettier check and `npm run build` all pass (manifest regenerated; lazy chunks emitted). Browser smoke test of the built bundle with injected boot data renders every route correctly — Dashboard shows live summary (Total 2 / Active 1 / Inactive 1), Goals lists both goals, Settings renders both toggles, empty states render for Campaigns/Analytics/Appearance, unknown routes hit the 404 page — with zero console errors. PHP regression suites unaffected (no PHP changes): reward 72/72, engine 75/75, cart-integration 22/22, rest-api 67/67.

**Overall project progress: 39%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 weight 4% × 100%).

---

### Phase 9 — Goal Management UI (100% complete)

- **P09-T01 Objective** — Built a professional Goal CRUD experience on top of the Phase 7 REST layer: a full goal list and a seven-section goal builder, replacing the Phase 8 read-only list.
- **P09-T02 Goal List** — `routes/Goals.tsx` now shows all required columns — name (with target + campaign), type, reward, status, priority, schedule, completion stats (placeholder until Phase 16–17 analytics), actions — with server-side search, status filter and pagination (Phase 23 admin-list pattern). Actions: create (→ builder), edit (→ `/goals/:id/edit`), duplicate (`POST /goals/{id}/duplicate`), enable/disable (partial status update), delete (confirm dialog), and preview (lightweight simulated-progress dialog; the full preview system is Phase 15).
- **P09-T03 Goal Builder** — New `routes/GoalBuilder.tsx` (`/goals/new` + `/goals/:id/edit`, lazy-loaded) with all seven sections: Basic Information (name, description, status), Goal Type (7 types, visual picker), Target (dynamic per type — amount/quantity/distinct_quantity/weight targets, category/product scoped pickers + calculation basis, composite children with AND/OR), Reward (dynamic per reward type — free shipping, percentage/fixed discount with eligible/excluded products & categories + cap, free gift product + mode, coupon via existing-code search or generated rules, label, stacking), Conditions (excluded products + schedule window; roles/customer-state/cart-state conditions are roadmap Phase 32 deferrals that need schema fields), Display (title, message, completed message, icon, template), and Priority. Backed by `api/goals.ts` (fetchGoal/createGoal/updateGoal/duplicateGoal), new `api/search.ts`, new `lib/format.ts` (locale/currency-aware formatting), and the `goal-builder/` component set (`EntityAutocomplete` debounced async picker with id preload, `SectionCard`, `GoalTypePicker`, `TargetFields`, `CompositeChildrenEditor`, `RewardFields`, `ConditionFields`, `DisplayFields`).
- Backend: `SearchController` search routes now accept an `ids` array param (positive ints, schema-validated) to preload saved builder selections; `types.ts` gained `GoalInput`, `RewardMetaInput`, `DisplaySettingsInput`, `GoalChildInput` and the search-result types.
- **Verification:** `php -l` clean on changed PHP; REST suite now 75/75 (new `ids` param checks); `npm run typecheck`, `npm run lint`, Prettier and `npm run build` all pass (GoalBuilder emitted as its own lazy chunk). No database changes; plugin not activated.

**Overall project progress: 43%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 weight 4% × 100%).

---

### Phase 10 — Campaign Builder (100% complete)

- **P10-T01 Objective** — Built the Campaign Builder on the Phase 7 read-only campaign layer: multiple goals now work as a scheduled, prioritized campaign with ordered milestones (e.g. 500K → Free Shipping, 1M → Free Gift, 1.5M → 10% discount).
- **P10-T02 Features** — Backend: `CampaignRepository` gained the full CRUD surface (`create`/`update`/`delete`/`duplicate`) plus milestone ordering — an ordered `goals` array of goal ids becomes `goals.campaign_id` + `goals.menu_order`, and goals removed from the list are detached for reuse; reads carry `goal_count` (list) and `goals` (detail). `CampaignsController` gained `POST /campaigns`, `PUT /campaigns/{id}`, `DELETE /campaigns/{id}` and `POST /campaigns/{id}/duplicate` (copy starts inactive, its goals are copied as new rows). Frontend: new `api/campaigns.ts`; `routes/Campaigns.tsx` is now a full CRUD list (name, milestone count, status + enable/disable switch, priority, schedule, actions: preview/edit/duplicate/delete, create → builder); new `routes/CampaignBuilder.tsx` (`/campaigns/new` + `/campaigns/:id/edit`, lazy-loaded) with Basic information (name/description/status), Schedule & priority (datetime window + conflict priority) and Milestones (goal ordering with move up/down/remove + add-from-goals chips); `CampaignPreviewDialog` shows the milestone ladder at simulated progress. Activation, scheduling, priority, customer-facing milestone copy and preview all covered — customer-state rules remain a roadmap deferral (Phase 32, needs schema fields).
- Also fixed a Phase 9 list bug found during review: `fetchGoals` read `envelope.data.items`, but `GET /goals` returns `data` as a plain array — the Dashboard and Goals page silently showed an empty list even with stored goals. The client now reads the array directly (verified against the live envelope shape).
- **Verification:** `php -l` clean; REST suite 102/102 (new campaign create/order/reorder/duplicate/delete + rollback checks); engine 75/75, reward 72/72, cart-integration 22/22 (no regressions); `npm run typecheck`, `npm run lint`, Prettier and `npm run build` all pass (CampaignBuilder emitted as its own lazy chunk). No database changes; plugin not activated.

**Overall project progress: 45%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 weight 2% × 100%).

---

### Phase 11 — Frontend Progress UI (100% complete)

- **P11-T01 Objective** — Built reusable, customer-facing progress components on the storefront, following the reference plugin's frontend convention: hand-written vanilla JS in `assets/js/` (no build step), a single inline `window.goalcartFrontend` config printed early in `wp_footer`, and a must-never-throw contract in the JS.
- **P11-T02 Components** — New `assets/js/frontend.js` implements the full component set (GoalContainer, ProgressBar, GoalMessage, GoalMilestones, RewardStatus, SuggestionList, StickyGoalBar) fed by the public `GET /goalcart/v1/progress` endpoint: full widgets render reward chip + progress + message + milestone ladder + suggestions, compact widgets render progress + message + reward chip, the sticky bar is a fixed dismissible bottom bar, and empty/no-eligible-goal states hide the widget. The progress payload gained an `is_money` flag so the JS formats milestone labels as currency vs plain numbers correctly. New `assets/css/frontend.css` is scoped, responsive, RTL-aware (logical properties), motion-safe, and themeable via `--goalcart-*` custom properties (Phase 12 Appearance reuses the tokens).
- **P11-T03 Display Locations** — New `includes/Frontend/ProgressUI.php` service (wired into the DI container + HookManager in `Plugin.php`) prints empty widget containers at every location with a rendered-location registry guarding against double injection: cart (`woocommerce_before_cart`), mini cart (`woocommerce_after_mini_cart`, re-mounted after fragment refreshes), checkout (`woocommerce_before_checkout_form`), shop/archives (`woocommerce_archive_description`), product pages (`woocommerce_single_product_summary` prio 45), the `[goalcart_progress variant="full|compact"]` shortcode (unique ids per instance), and the sticky bar (`wp_footer`). The UI is gated by the `enabled` setting (`goalcart_frontend_enabled` filter), locations are filterable (`goalcart_frontend_locations`), assets load only on widget-capable pages, and the JS refreshes on WooCommerce's cart events (jQuery-bound with a native fallback) plus an optional `goalcart_frontend_refresh_interval` poll.
- Added files: `includes/Frontend/ProgressUI.php`, `assets/js/frontend.js`, `assets/css/frontend.css`, `tests/frontend-test.php`.
- **Verification:** `php -l` clean; new frontend suite 36/36 (container resolution, hook registration incl. priorities, shortcode markup + unique ids, duplicate-render guard, config payload, enabled gate + filter, page gating with a rolled-back shortcode post); engine 75/75, reward 72/72, cart-integration 22/22, rest-api 102/102 (no regressions); `node --check` on the JS; headless-Chrome smoke test against a mock `/progress` endpoint renders the full/compact/sticky widgets (42.5% fill, milestone targets, reward chips, completed state) with zero console errors. No database changes; plugin not activated.

**Overall project progress: 49%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 weight 4% × 100%).

---

### Phase 12 — Progress Templates (100% complete)

- **P12-T01 Templates** — The storefront widget body now renders per an active template variant. `assets/js/frontend.js` gained a `widgetTemplate()` resolver (per-widget `data-goalcart-template` override, else the config) and `templateBody()` variants: **basic** (bar + message), **percentage** (large percent readout above the bar), **milestone** (the goal ladder as the hero visual, bar underneath), and **card** (icon + goal-title header above the bar). The shared flow — message, reward chip, suggestions, sticky bar — stays identical across variants. `assets/css/frontend.css` adds the `.goalcart-template--*` styles, a `--goalcart-bar-height` token, and the milestone/card/percentage layouts.
- **P12-T02 Customization** — Full appearance surface: new `frontend_*` settings (template, animation, bar height, accent/bg/border/text colors, radius, CSS class, custom CSS) with defaults in `Settings`, REST schema + sanitizer in `SettingsController` (enum validation, hex-color fallbacks, range clamping, tag-stripping). `ProgressUI` resolves `template()`/`appearance()`, prints the token + custom-CSS inline block via `wp_add_inline_style`, appends the custom class to every container, and exposes the shortcode `template` attribute. The admin **Appearance** page (`routes/Appearance.tsx`) is now fully functional: a 4-card template picker with live thumbnails, color pickers, bar-height/radius sliders, an animation switch, custom class + custom CSS fields, a live preview panel driven by the form values, and reset-to-defaults — all persisted through `POST /goalcart/v1/settings`.
- **Verification:** `php -l` clean; frontend suite 50/50 (config template/animation/appearance keys, shortcode template override, custom container class, token + custom CSS output, template filter), REST suite 113/113 (Phase 12 settings sanitization + schema checks); engine 75/75, reward 72/72, cart-integration 22/22 (no regressions); `node --check` on the JS; `npm run typecheck`, `npm run lint` and `npm run build` all pass (Appearance emitted as its own lazy chunk); headless-Chrome smoke test renders all four templates against a mock `/progress` endpoint (58% readout, milestone ladder targets, card icon/title, no-anim classes on and off) with zero console errors. No database changes; plugin not activated.

**Overall project progress: 51%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 weight 2% × 100%).

---

### Phase 13 — Dynamic Messaging (100% complete)

- **P13-T01 Objective** — New `includes/Goals/MessageEngine.php` (`GoalCart\Goals`): a stateless, database-free message template engine (Phase 4 engine contract) that turns a `Goal` + `GoalResult` into localized copy. Rendered server-side by the frontend controller, so every storefront message (widget, sticky bar, milestones) shares one engine; wired into the DI container (`Plugin.php`).
- **P13-T02 Variables** — Nine placeholders: `{current}` `{target}` `{remaining}` `{percentage}` `{quantity}` `{remaining_quantity}` `{reward}` `{goal_name}` `{campaign_name}`. Money-based goals format currency via `wc_price` (plain locale numbers otherwise); `quantity`/`remaining_quantity` come from the cart (controller passes `CartContext::total_quantity()`) with quantity-mode fallback; `reward` is value-aware ("10% discount", "Fixed $20.00 off"); `campaign_name` is folded into the goal by the repository's campaign join (`Goal::campaign_name()`); unknown placeholders stay untouched. Also fixed a Phase 11 bug surfaced here: quantity/distinct-quantity/weight goals default to the subtotal calculation mode, so `is_money_goal` is now type-aware (both `MessageEngine` and `FrontendController` mirror the fix) — quantity goals no longer format as currency.
- **P13-T03 States** — Six-state detection: `inactive` (goal inactive), `unavailable` (no matching items / out of schedule / invalid target), `progressing` (< 80%), `nearly_complete` (≥ 80%), `completed` (no reward), `reward_activated` (with reward) — each with localized default copy; the goal builder's `display_settings.message` / `completed_message` override progress/completion copy. The public `/progress` payload now carries `state`; the widget maps it to a `goalcart-state--{state}` card class (near-completion copy highlighted).
- **P13-T04 Example** — `Only {remaining} left until {reward}` → “Only $38.00 left until Free shipping”; documented in `docs/frontend.md` (states/variables/templates tables) and `docs/api.md` (payload `state` + engine-rendered `message`).
- **Verification:** `php -l` clean; new message suite 47/47 (container wiring, all six states, all nine variables incl. currency-agnostic money formatting, reward labels, display-settings overrides, unknown-placeholder safety); REST suite 116/116 (payload `state` + no unresolved placeholders); engine 75/75, reward 72/72, cart-integration 22/22, frontend 50/50 (no regressions); `node --check` on the JS; headless-Chrome smoke renders the `goalcart-state--nearly_complete` class + engine message with zero console errors. No database changes; plugin not activated.

**Overall project progress: 53%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 2% + Phase 13 weight 2% × 100%).

---

### Phase 14 — Smart Product Suggestions (100% complete)

- **P14-T01 Objective** — Turned Goal Cart into an actual revenue-optimization feature: new `includes/Suggestions/SuggestionEngine.php` (`GoalCart\Suggestions`, DI-wired in `Plugin.php`) turns goal progress into product recommendations that close the gap, consumed by the public `/progress` payload and rendered by the Phase 11 `SuggestionList`.
- **P14-T02 Recommendation Sources** — Seven candidate sources, deduped with the first (highest-priority) source kept for the badge: manual (the goal's own `products`), category (goal categories via a term-relationship query — no `wc_product_meta_lookup` dependency, so products created outside WC CRUD are found), the cart items' upsells/cross-sells/`wc_get_related_products()`, the shopper's `woocommerce_recently_viewed` cookie, and best sellers by `total_sales`. Bounded (per-source limits, `MAX_CANDIDATES` 40, batched load with a `wc_get_product` fallback when the lookup table lags).
- **P14-T03 Ranking** — Stock availability filters first (published, in stock, priced); then a score from goal eligibility (manual +3, counts toward the goal +2), relevance (shares a cart-item category +1), WC-endorsed sources (+0.5) and — for money goals — price proximity to `remaining` (0.6–1.4× band +2, cheaper +0.75; the spec's "prefer 150K–220K when 180K is left"). Capped at `MAX_SUGGESTIONS` (4), deterministic (score desc, id asc), never suggests cart items / excluded / out-of-stock / ghost ids, and the final list is filterable via the `goalcart_suggestions` filter.
- **P14-T04 Example** — In-band products rank above arbitrary expensive ones; verified in `tests/suggestion-test.php` (28 checks, transactional products rolled back): sources, stock filter, ranking, cap, dedupe, exclusion, cart-item skip, quantity-goal no-price-banding, and the filter.
- **P14-T05 Future** — Margin-aware recommendations and AI optimization remain documented roadmap deferrals (Phases 32–34).
- Refactor: the type-aware `is_money_goal()` check moved onto the `Goal` model (single source of truth); `MessageEngine` and `FrontendController` delegate to it. `frontend.js` prefers the server-formatted `price_html` in the suggestion list (raw `price` fallback) and keeps the suggestion-URL protocol guard.
- **Verification:** `php -l` clean; new suggestion suite 28/28; engine 75/75, reward 72/72, cart-integration 22/22, rest-api 120/120, frontend 50/50, message 47/47 (no regressions); `node --check` on the JS; `npm run typecheck`, `npm run lint` and `npm run build` all pass. No database changes; plugin not activated.

**Overall project progress: 57%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 2% + Phase 13 2% + Phase 14 weight 4% × 100%).

---

### Phase 15 — Admin Preview System (100% complete)

- **P15-T01 Objective** — Administrators can now see the customer
experience before publishing: the Preview buttons on the Goals and
Campaigns lists open a server-driven dialog that evaluates the **real
goal engine** against a **simulated cart** and renders the real storefront
widget (templates, messages, rewards, suggestions) at the chosen device
width. New `includes/REST/PreviewController.php` registers
`POST /goalcart/v1/preview` (admin-only, `manage_options` + rate limit):
it builds a synthetic `CartContext` (one simulated line carrying amount /
quantity / weight / categories / product id, so amount / quantity /
distinct-quantity / category / product / weight / composite goals all
evaluate honestly) and returns the **same per-goal payload shape as the
public `/progress` endpoint** via a newly extracted shared
`FrontendController::shape_goal()` — the two payload builders can never
drift. **Preview never touches the real WooCommerce cart** (no cart
loaded, no session touched, no fees/coupons applied — verified by test)
and ignores publish gating on purpose, so drafts, inactive goals and
scheduled campaigns are previewable before going live.
- **P15-T02 Preview States** — Empty cart / 25% / 50% / 75% / Completed
presets for single goals (anchored to the goal target) and **multiple
milestones** for campaigns (every milestone evaluated against the same
simulated cart, anchored to the top milestone target so mid states show
several completed rungs).
- **P15-T03 Preview Controls** — Simulated cart amount and simulated
quantity fields (debounced 300 ms), simulated reward state (auto / locked
/ unlocked chip), device width (mobile 375 / tablet 768 / desktop 1280)
and template variant (basic / percentage / milestone / card, defaulting
through the goal's Display template → the store-wide Appearance template
— the widget's own resolution order). The React side is a faithful mirror
of `assets/js/frontend.js`: new `components/preview/` (`PreviewWidget`,
`PreviewControls`, `usePreviewDialog` shared hook, `types`), rewritten
`GoalPreviewDialog` / `CampaignPreviewDialog`, `api/preview.ts`, and the
`ProgressGoal` / `PreviewPayload` types. Appearance tokens come from the
Phase 12 settings, so the admin sees the storefront 1:1.
- Added files: `includes/REST/PreviewController.php`, `tests/preview-test.php`,
`admin-app/src/api/preview.ts`, `admin-app/src/components/preview/`
(4 modules). Also extended `admin-app/src/types.ts` (progress/preview
payload types) and `admin-app/src/lib/format.ts` (optional currency param
for the preview's payload-currency formatting).
- **Verification:** `php -l` clean; new preview suite 90/90 (routes,
schema, anonymous 403, empty/50%/completed states, live-cart-unchanged,
publish-gating bypass, every goal type's simulated context — quantity,
distinct-quantity, category (both modes), product, weight, composite —
quantity goals, campaign milestones in order, 400/404 paths, rollback);
rest-api 120/120, engine 75/75, reward 72/72, cart-integration 22/22,
message 47/47, suggestion 28/28 (no regressions); `npm run typecheck`,
`npm run lint` and `npm run build` all pass (the preview widgets are part
of the main chunk). Note: `frontend-test.php`
reports 4 pre-existing failures in this environment because the live
site's saved Appearance settings differ from the defaults the suite
asserts (template `milestone`, bar height 28, accent `#cf20b8`) —
unrelated to this phase's changes. No database changes; plugin not
activated.

**Overall project progress: 59%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 2% + Phase 13 2% + Phase 14 4% + Phase 15 weight 2% × 100%).

---

### Phase 16 — Analytics Foundation (100% complete)

- **P16-T01 Objective** — Goal Cart now measures whether it actually
increases revenue: an append-only analytics event pipeline records what
shoppers see and do (goal impressions, progress, completions, reward
activations, suggestion impressions/clicks/adds) into the existing
`goalcart_analytics_events` table, keyed by anonymous sessions.
- **P16-T02 Events** — New `includes/Analytics/` services (DI-wired in
`Plugin.php`): `Session` (32-hex HttpOnly SameSite=Lax cookie, sliding
30-day expiry), `Tracker` (the event recorder — whitelisted event types,
FK-resilient inserts, the `goalcart_tracking_enabled` filter, frontend
config print `window.goalcartTracking` at `wp_footer` priority 4, and the
`woocommerce_add_to_cart` hook that attributes `suggested_product_added`
server-side only when the session saw a `suggestion_impression` for that
product within 24h), and `AnalyticsRepository` (all seven P16-T03
metrics). New `includes/REST/TrackController.php` registers the public
`POST /goalcart/v1/track` endpoint — nonce-guarded (own tracking nonce
instead of an admin capability), 300 req/min per-IP budget, arg schema
validating the event-type whitelist, and `suggested_product_added`
rejected from the client so conversions can never be self-reported. The
storefront `assets/js/frontend.js` reports six events with a
must-never-throw contract (sendBeacon with XHR fallback): impressions
once per goal per page session, progress on percentage change, completion
/reward events once per goal, suggestion impressions once per goal +
product, and suggestion clicks through a delegated listener on the
`data-goalcart-suggestion-id` / `data-goalcart-goal-id` link attributes.
- **P16-T03 Metrics** — `AnalyticsRepository` computes impressions,
completions (goal_completed + reward_activated), completion rate,
average cart value at impression, revenue associated with completed
goals (SUM of cart_value at completion events), suggestion CTR and
suggestion add-to-cart rate — each filterable by date range (from/to),
campaign and goal for the Phase 17 dashboard, with zero-denominator
guards. The shared progress payload and `Goal` model now carry
`campaign_id` so events attribute to campaigns.
- **P16-T04 Privacy** — The events table stores only aggregate numbers
(cart value, percentage), goal/campaign/product/order ids and the
anonymous session token — no IP, user agent, email or other PII (the
table has no PII columns at all); `user_id` is recorded only when the
shopper is logged in; guests stay anonymous. Tracking respects the master
`enabled` toggle + the `goalcart_tracking_enabled` filter (Phase 18 adds
a settings toggle).
- Added files: `includes/Analytics/{Session,Tracker,AnalyticsRepository}.php`,
`includes/REST/TrackController.php`, `tests/analytics-test.php`.
- **Verification:** `php -l` clean; new analytics suite 72/72 (wiring,
sessions, whitelist, recording, privacy columns, gates, /track schema +
nonce + dispatch, suggestion-add attribution incl. FK-resilience and
fresh-session negatives, all seven metrics + filters + zero-denominator
guards, rollback); rest-api 120/120, engine 75/75, reward 72/72,
cart-integration 22/22, message 47/47, suggestion 28/28, preview 90/90
(no regressions); `node --check` on the JS; `npm run typecheck` and
`npm run lint` pass. No database changes (the analytics_events table
ships since Phase 3); plugin not activated.

**Overall project progress: 61%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 2% + Phase 13 2% + Phase 14 4% + Phase 15 2% + Phase 16 weight 2% × 100%).

---

### Phase 17 — Analytics Dashboard (100% complete)

- **P17-T01 Dashboard** — The admin `/analytics` page is now a full
measurement dashboard served by one admin endpoint,
`GET /goalcart/v1/analytics` (`includes/REST/AnalyticsController.php`, DI
wired in `Plugin.php`): seven KPI cards (impressions, completions,
completion rate, revenue influenced, average cart value, suggestion CTR,
suggestion add-to-cart rate), a daily trend chart, top campaigns / top
goals / top suggested products, loading skeletons, an error alert and an
empty state when the range has no impressions.
- **P17-T02 Filters** — The dashboard toolbar shares one date range
through a new `date-range/` module (`DateRangeContext` provider wired
inside the data router in `App.tsx`, `DateRangeFilter` + a lazy-loaded
Gregorian `CustomRangePicker` — mirroring the reference plugin's date
filter, minus the Jalali calendar) plus campaign / goal / reward selects
and a product filter reusing `EntityAutocomplete`. Server-side, the
`AnalyticsRepository` gained `goal_ids`, `product_id` and `reward_type`
(goal-join subquery, whitelisted against `Reward::types()`) filters with
table-alias support, and `AnalyticsController` validates every filter
through its route arg schema (dates, reward enum, goal_ids items, the
1–20 limit clamp).
- **P17-T03 Charts** — recharts (the reference plugin's charting
convention): a ComposedChart with impressions/completions bars and a
revenue line over a zero-filled daily window, a horizontal top-campaigns
bar chart, completion-rate progress bars for top goals, and a top
suggested products table — all localized, WP-admin-palette themed, with
formatted tooltips.
- New `AnalyticsRepository` queries: `trend()` (daily buckets, default
30-day window, zero-filled gaps), `top_campaigns()`, `top_goals()`
(INNER JOIN campaigns/goals for names, ranked by completions) and
`top_suggested_products()` (INNER JOIN `wp_posts` for product names,
ranked by conversions) — every query fully `$wpdb->prepare`-bound.
- Added files: `includes/REST/AnalyticsController.php`,
`tests/analytics-dashboard-test.php`, `admin-app/src/date-range/*`,
`admin-app/src/components/date-range/*`,
`admin-app/src/api/analytics.ts`; extended
`includes/Analytics/AnalyticsRepository.php`, `includes/Rewards/Reward.php`
(`types()`), `admin-app/src/{types,App}.tsx` and
`admin-app/src/routes/Analytics.tsx`.
- **Verification:** `php -l` clean; new analytics-dashboard suite 82/82
(wiring + GET-only route, arg schema, anonymous 403 + authenticated 200
dispatch, summary KPIs, multi-day zero-filled trend, top-list ranking
and derived rates, every filter slice, rollback); analytics 72/72,
rest-api 120/120, engine 75/75, reward 72/72, cart-integration 22/22,
message 47/47, suggestion 28/28, preview 90/90 (no regressions); `npm
run typecheck`, `npm run lint` and `npm run build` all pass (recharts
ships in the lazy-loaded Analytics chunk). No database changes.

**Overall project progress: 63%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 2% + Phase 13 2% + Phase 14 4% + Phase 15 2% + Phase 16 2% + Phase 17 weight 2% × 100%).

---

### Phase 18 — Settings (100% complete)

- **P18-T01 General** — The Settings page now carries the full store
surface: enable/disable, currency display (symbol / code / name, consumed
by the storefront widget's `currencyDisplay` config and the frontend JS
number/currency formatter), default goal behavior (all | first | closest
— `FrontendController::active_goals_for()` narrows the `/progress` goal
set per the setting, with 'closest' picking the eligible goal with the
highest percentage) and the store-wide default calculation mode
(`goalcart_default_calculation_mode` filter in `Settings::register()` +
`Goal::default_calculation_mode()` — amount/category/composite goals
follow the store mode, quantity-style goals keep their type defaults;
each default preserves the pre-Phase-18 behavior).
- **P18-T02 Frontend** — Display locations are now driven by the
`frontend_locations` setting (was hard-coded in `ProgressUI`): the widget
mounts only in the configured locations (cart / mini-cart / checkout /
shop / product / sticky), the sticky bar is gated on the 'sticky'
location, `frontend_template`, `frontend_animation`, `frontend_bar_height`
and the color tokens continue to drive the Appearance page, and
`frontend_mobile` (show | hide) now reaches the storefront — `ProgressUI`
prints the `mobile` config key and `assets/js/frontend.js` applies a
`goalcart-mobile-hidden` class (CSS media query) that hides every widget
under 600 px.
- **P18-T03 Goal Calculation** — `CartContext::from_cart()` now honors
five inclusion toggles, each defaulting to the pre-Phase-18 engine
behavior: `include_tax` (line taxes fold into the subtotal/discounted
bases), `include_discount` (when off, the discounted basis ignores
discounts), `include_shipping` (total basis keeps/drops the shipping
line; legacy `exclude_shipping` still wins), `include_sale` and
`include_virtual` (sale/virtual items are dropped from the snapshot and
the bases rebased onto the remaining lines). `CartItem` gained `line_tax`;
`CartIntegration::context()` merges the settings into the build args
(explicit caller args win) and stays optional-injected so `new
CartIntegration()` callers keep working. `Goal::default_calculation_mode()`
became filterable for the P18-T01 store mode.
- **P18-T04 Performance** — Three switches: `performance_caching` (a
10-second `goalcart_progress_*` transient keyed by cart snapshot + goal
ids + behavior + suggestions serves repeat widget polls without
re-evaluating every goal; off by default), `analytics_enabled` (the new
settings toggle gates the Phase 16 Tracker — the analytics config is only
printed and events only recorded while on) and `performance_suggestions`
(the storefront suggestion list is emptied when off; filterable via
`goalcart_suggestions_enabled`).
- **P18-T05 Advanced** — `debug_mode` + `logging_enabled` power a new
`includes/Utils/Logger.php` (mirrors the reference plugin's Utils-folder
convention): a best-effort `goalcart-debug.log` in `WP_CONTENT_DIR`
(error lines always write when logging is on, debug lines only with debug
mode), with the log path surfaced in the settings GET meta and REST
failures logged through `BaseController::error()`. The Settings page
rewrite (`admin-app/src/routes/Settings.tsx`) is a five-tab layout —
General / Frontend / Goal Calculation / Performance / Advanced — with a
documented-hooks reference (developer hooks toggle) rendered from
`HookManager::documented_hooks()`.
- `Settings::save()` no-op fix — `update_option()` returns `false` both
for real failures and for unchanged values; saving identical settings no
longer 500s (`goalcart_settings_save_failed`), it is treated as a
successful save (the reference plugin carries the same latent bug; this
implementation fixes it).
- Added files: `includes/Utils/Logger.php`, `tests/settings-test.php`;
extended `includes/Settings/Settings.php`, `includes/REST/SettingsController.php`,
`includes/Goals/{Goal,CartItem,CartContext}.php`, `includes/Cart/CartIntegration.php`,
`includes/Frontend/ProgressUI.php`, `includes/REST/{FrontendController,BaseController}.php`,
`includes/Analytics/Tracker.php`, `includes/Hooks/HookManager.php`,
`includes/Plugin.php`, `assets/js/frontend.js`, `assets/css/frontend.css`,
`admin-app/src/{types.ts,api/settings.ts,routes/Settings.tsx,routes/Appearance.tsx}`.
- **Verification:** `php -l` clean; new settings suite 119/119 (defaults
for every new key preserving pre-Phase-18 behavior, REST schema + sanitizer
normalization, calculation toggles incl. line-tax folding and
discount/shipping/sale/virtual drops, the store-mode filter, locations +
sticky gating, currencyDisplay/mobile config, goal behavior all/first/closest,
progress caching write + read + sentinel serve, analytics/suggestions toggles,
developer-hooks meta, Logger gating + cleanup — every DB write rolled back
and residue asserted); regressions: engine 75/75, cart-integration 22/22,
frontend 53/53, analytics 72/72, rest-api 120/120, reward 72/72, message
47/47, suggestion 28/28, preview 90/90, analytics-dashboard 82/82; `node
--check` on the JS; `npm run typecheck`, `npm run lint` and `npm run build`
all pass (Settings ships as its own lazy chunk). No database changes.

**Overall project progress: 65%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 2% + Phase 13 2% + Phase 14 4% + Phase 15 2% + Phase 16 2% + Phase 17 2% + Phase 18 weight 2% × 100%).

---

### Phase 22 — Security Hardening (100% complete)

- **P22-T01 PHP** — Full audit of the PHP layer: nonce verification (the
  public track route rejects a bad nonce with 403 — `wp_verify_nonce` on
  `goalcart_track`; admin routes rely on WP core `wp_rest` cookie auth),
  capability checks (`manage_options`, filterable via
  `goalcart_rest_capability` / `goalcart_admin_capability`, on every admin
  route and the admin menu), sanitization (REST arg schemas, repository
  column sanitizers, and the recursive composite-children whitelist
  `GoalsController::sanitize_children` — unknown keys dropped, strings
  sanitized, ids positive-int-cast), escaping (widget container attributes,
  admin page markup, inline config JSON), SQL parameterization (every
  repository query `$wpdb->prepare`-bound), and safe serialization
  (`wp_json_encode` only — no unsafe unserialize anywhere). New hardening:
  `TrackController` now clamps `percentage` to 0–100 and `cart_value` to
  ≥ 0 in the handler (defense-in-depth on top of the arg-schema ranges, so
  direct-handler callers can never persist out-of-range values).
- **P22-T02 REST** — Every `goalcart/v1` route carries a permission
  callback (verified by iterating the registered routes); arg-schema
  validation on the full request surface (enums, types, ranges, datetime /
  campaign-existence validate callbacks); per-user rate limiting on admin
  routes and per-IP rate limiting on the public routes, verified to 429
  past the budget; the public `/progress` payload keeps the
  data-minimization contract — `reward_meta` is redacted for guests, so
  coupon codes, gift product ids and shipping restrictions never leave the
  server (verified: the secret string never appears anywhere in the public
  JSON while the admin detail payload still carries it).
- **P22-T03 React** — Source scan of `admin-app/src` and the storefront
  `assets/js/frontend.js` for `dangerouslySetInnerHTML`, `innerHTML`,
  `document.write`, `insertAdjacentHTML`, `eval` and `new Function`: zero
  violations. The render path is `createElement` + `textContent` only,
  suggestion URLs pass the `isSafeUrl` scheme guard, external links carry
  `rel="noreferrer"`, the API client authenticates with `X-WP-Nonce`, and
  localStorage/JSON parsing is exception-guarded. `npm run typecheck`,
  `npm run lint` and `npm run build` all pass.
- **P22-T04 Database** — Prepared statements throughout: SQL-injection
  payloads in the goal search/status filters neither error nor widen the
  result set (verified), the analytics date-range clamp caps any trend
  window at 366 days (a pathological 2000–2100 range is clamped), and a
  schema-hygiene audit surfaced a real migration gap — **dbDelta cannot
  add indexes to an existing table**, so the composite analytics keys
  (`goal_event`, `campaign_event`) declared in the schema were missing on
  upgraded installs. `Schema::indexes()` now centralizes the full index
  set and `Installer::maybe_add_indexes()` applies any missing key
  idempotently (INFORMATION_SCHEMA check + ALTER TABLE, the existing
  foreign-key pattern); `GOALCART_DB_VERSION` bumped to `0.2.1` so
  existing installs migrate once on upgrade. Index and foreign-key
  presence is now verified against INFORMATION_SCHEMA.
- Added files: `tests/security-test.php` (65 checks covering all four
  P22 areas — route protection, nonce, rate limits, sanitization,
  escaping, injection resistance, date-range clamp, schema indexes/FKs,
  public-payload redaction, React/frontend source scan, rollback
  hygiene).
- **Verification:** `php -l` clean; new security suite 65/65; full
  regression run — analytics-dashboard 82/82, analytics 72/72,
  cart-integration 22/22, engine 75/75, frontend 73/73, message 47/47,
  preview 90/90, rest-api 120/120, reward 72/72, settings 119/119,
  suggestion 28/28, woocommerce-compatibility 29/29,
  wordpress-compatibility 28/28 (all pass, zero failures); `node --check`
  on the JS; `npm run typecheck`, `npm run lint` and `npm run build` all
  pass. Database migration `0.2.1` (two composite indexes) applied
  idempotently to the development database.

**Overall project progress: 73%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 2% + Phase 13 2% + Phase 14 4% + Phase 15 2% + Phase 16 2% + Phase 17 2% + Phase 18 2% + Phase 19 2% + Phase 20 2% + Phase 21 1% + Phase 22 weight 3% × 100%).

---

### Phase 23 — Performance Optimization (100% complete)

- **P23-T01 Frontend** — Audit of the React admin app: secondary routes
  were already lazy-loaded (`React.lazy` + `Suspense` in `App.tsx` —
  Campaigns, Analytics, Appearance, Settings, and both builders split into
  their own chunks), server state is cached via TanStack Query
  (`staleTime: 60s`, `refetchOnWindowFocus: false` in `AppProviders`),
  product/category/coupon searches are debounced (300 ms) and capped
  (`per_page: 20`) in `EntityAutocomplete`, the goal list search is
  debounced client-side and paginated server-side, and the analytics trend
  series is `useMemo`-memoized. **New: bundle-size minimization** —
  `admin-app/vite.config.ts` now splits vendors with `manualChunks`
  (react, router, mui, query, charts, pickers, vendor groups). The entry
  dropped from **646 kB → 41 kB** and the Analytics chunk from **417 kB →
  13 kB**, eliminating Vite's >500 kB chunk warning. Documented deviation
  from the reference plugin (AGENT.md rule 15): the reference ships the
  same minimal config without `manualChunks`, and the deviation is driven
  by the Phase 23 roadmap requirement to minimize bundle size — the
  routing/base/manifest architecture is unchanged.
- **P23-T02 WooCommerce Frontend** — Audit of the storefront path: goal
  evaluation is cached within a request (`GoalRepository` per-request
  active-goal cache — verified: a second `active_goals()` call runs zero
  queries; `CartIntegration` memoizes the cart context per cart contents;
  `RewardEngine` caches per-request reward results), product categories
  are preloaded in one batched `wp_get_object_terms` call (no per-item
  term queries), repeated widget polls are served from the 10-second
  progress transient when `performance_caching` is enabled, and goal
  calculations are snapshot-based (no per-render re-computation). **New:
  update only changed UI fragments** — `assets/js/frontend.js` now
  computes a payload fingerprint (`payloadFingerprint`) per refresh and
  skips the DOM rebuild for every widget whose fingerprint is unchanged
  (and already holds content), so the poll interval and cart events only
  touch fragments whose numbers actually moved; a freshly swapped
  mini-cart container still mounts (empty containers always render), the
  sticky bar follows the same rule, and the mobile-hide toggle is folded
  into the fingerprint so breakpoint crossings still re-render.
- **P23-T03 Admin** — Audit of the admin list surfaces: the goal list is
  server-paginated (page/per_page + envelope) with server-side search
  (name `LIKE`) and status filtering (verified behaviorally), the list
  endpoint clamps `per_page` to 100, the search endpoints declare
  `per_page` maximum 50 in the arg schema and clamp in the handler (so
  the product/category/coupon pickers never load thousands of records at
  once), and the picker UI requests `per_page: 20`.
- Added files: `tests/performance-test.php` (38 checks covering all
  three P23 areas — React source guarantees, request-level caching,
  progress transient + JS change-detection, server-side pagination /
  search / filtering, per_page caps, rollback hygiene).
- **Verification:** new performance suite 38/38; full regression run —
  analytics-dashboard 82/82, analytics 72/72, cart-integration 22/22,
  engine 75/75, frontend 73/73, message 47/47, performance 38/38,
  preview 90/90, rest-api 120/120, reward 72/72, security 65/65,
  settings 119/119, suggestion 28/28, woocommerce-compatibility 29/29,
  wordpress-compatibility 28/28 (all pass, zero failures); `npm run
  typecheck`, `npm run lint` and `npm run build` all pass (main 41 kB,
  Analytics 13 kB, no chunk-size warning).

**Overall project progress: 76%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 2% + Phase 13 2% + Phase 14 4% + Phase 15 2% + Phase 16 2% + Phase 17 2% + Phase 18 2% + Phase 19 2% + Phase 20 2% + Phase 21 1% + Phase 22 3% + Phase 23 weight 3% × 100%).

---

### Phase 26 — Conflict & Priority Engine (100% complete)

- **P26-T01 Objective & deterministic order** — When several goals are
  active — standalone goals, campaign milestones, or both — the plugin
  now behaves **deterministically**. New `includes/Goals/ConflictResolver.php`
  is the single authoritative rule, shared by the live cart
  (`RewardEngine::sync_cart()`) and the display paths (`FrontendController`
  progress payload + `PreviewController` campaign preview), so the reward
  granted and the reward displayed can never drift apart.
  `GoalRepository::active_goals()` now returns the active goals in the
  deterministic Phase 26 order — `ORDER BY COALESCE(campaigns.priority,
  10) ASC, goals.priority ASC, goals.id ASC`: campaign priority is the
  primary sort key (a campaign is a deliberate merchandising unit, so its
  priority outranks any goal inside it), standalone goals compete at the
  schema-default campaign priority 10, then goal priority, then id for a
  stable tie-break.
- **P26-T02 Resolution modes** — New store-wide `conflict_resolution`
  setting (Settings → General → Conflict resolution, default
  `cumulative`) selects how *completed* reward-bearing goals compete:
  **cumulative** (every completed goal grants, subject to the existing
  per-reward stacking rules — exactly the pre-Phase-26 behavior),
  **first** (only the first matching goal in priority order grants;
  later completions suppressed with `not_first`), and **best** (only the
  highest-value reward grants; `not_best` for the rest). "Best" compares
  the reward's *computed* discount amount on the current cart when
  available (the `RewardEngine` pass — percentage discounts resolved to
  their real value), falling back to a deterministic static score
  (fixed/percentage value; free shipping, gifts and coupons count as
  equal-value offers), ties broken by priority then id. Suppressed goals
  still show their progress on the storefront (a shopper may be working
  toward the next milestone) but their reward never renders as unlocked
  and never grants. The progress-cache transient key now includes the
  mode so a settings change invalidates cached payloads.
  **Display/grant parity:** the reward engine is injected into
  `FrontendController` and `PreviewController`, so the storefront
  payload and the admin preview resolve `best` with the *same computed
  amounts* the cart grants with and mirror the engine's pass-2 stacking
  suppression (`ConflictResolver::apply_stacking()`) — a same-type
  non-stacking loser is reported `stacking`, never as unlocked. What a
  shopper or an admin sees is exactly what the live cart grants (review-
  driven hardening; verified end-to-end by the payload parity checks in
  `tests/conflict-test.php`).
- **P26-T03 Mutually exclusive goals** — A goal marked **Exclusive** in
  the goal builder (`goals.exclusive`, new column, database version
  bumped to `0.3.0`) suppresses every lower-priority *completed* goal
  (`exclusive` reason) in **every** mode — exclusivity is resolved
  **before** mode selection, so e.g. in `best` mode an exclusive goal
  beats a higher-value lower-priority reward. Priority above the
  exclusive goal is still respected (exclusive means "I win over
  everything below me", never "I silence everything").
  `Goal::exclusive()` / the REST goal shape and schema expose the flag.
- **P26-T04 Admin UI communication** — The behavior is visible in every
  surface: Settings → General gains the **Conflict resolution** picker
  with a plain explanation of each mode; the goal builder's **Priority &
  conflicts** section carries the priority field (lower wins) plus the
  **Exclusive (mutually exclusive)** toggle with its behavior explained;
  the Goals list shows an **Exclusive** chip; campaign priority already
  participates (campaigns compete before goals); and the goal/campaign
  preview shows a **"Blocked — …"** conflict chip naming the reason.
  On the storefront, `assets/js/frontend.js` renders a suppressed
  reward as **locked** (never unlocked) and reports `goal_completed`
  instead of `reward_activated` for it (verified: the tracking event
  follows the conflict state).
- **Payload contract** — The public `GET /goalcart/v1/progress` and the
  admin `POST /goalcart/v1/preview` payloads carry per goal
  `"conflict": { "resolved": true, "reason": "" }`; `resolved: false`
  means the reward is blocked, with the machine-readable reason
  (`not_first` / `not_best` / `exclusive` / `stacking`; `lower_priority`
  reserved). The `RewardResult` mirrors it through its `blocked` state,
  so analytics and previews agree with the live cart.
- Added files: `includes/Goals/ConflictResolver.php`,
  `tests/conflict-test.php` (45 checks), `docs/conflicts.md`; extended
  `includes/Goals/{Goal,GoalRepository}.php`, `includes/Database/Schema.php`,
  `includes/Rewards/RewardEngine.php`, `includes/Settings/Settings.php`,
  `includes/REST/{SettingsController,FrontendController,PreviewController,GoalsController}.php`,
  `includes/Plugin.php`, `goalcart.php` (DB version `0.3.0`),
  `assets/js/frontend.js`, `tests/settings-test.php`, `docs/{api,database}.md`,
  and the admin app (`types.ts`, `routes/{Settings,GoalBuilder,Goals,Appearance}.tsx`,
  `components/preview/PreviewWidget.tsx`).
- **Verification:** `php -l` clean on every changed PHP file; new
  conflict suite 57/57 (modes, exclusive, campaign-priority ordering,
  engine grant/block parity, payload conflict fragment, best-mode
  computed-amount parity, cumulative stacking parity, settings schema,
  rollback); settings suite now 124/124 (defaults + REST
  schema/sanitizer + cache-key coverage for `conflict_resolution`); full
  regression run — engine 75/75, reward 72/72, cart-integration 22/22,
  rest-api 120/120, message 47/47, suggestion 28/28, preview 90/90,
  analytics 72/72, analytics-dashboard 82/82, security 65/65,
  performance 38/38, woocommerce-compatibility 29/29,
  wordpress-compatibility 28/28 (all pass, zero failures); `frontend
  73/73` reports its 1 pre-existing environment-dependent failure
  (config template: the suite asserts the default `basic` but this site
  has the Appearance template saved as `card` — same documented
  artifact as Phase 15, untouched by this phase); `node --check` on the
  JS; `npm run typecheck`, `npm run lint` and `npm run build` all pass.
  Database migration `0.3.0` adds `goals.exclusive` (idempotent,
  existing rows default to 0 = not exclusive).

**Overall project progress: 78%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 2% + Phase 13 2% + Phase 14 4% + Phase 15 2% + Phase 16 2% + Phase 17 2% + Phase 18 2% + Phase 19 2% + Phase 20 2% + Phase 21 1% + Phase 22 3% + Phase 23 3% + Phase 26 weight 2% × 100%).

---

### Phase 27 — Internationalization (100% complete)

- **P27-T01 Text domain** — Verified and hardened the translation
  posture: the plugin header declares `Text Domain: goalcart` and
  `Domain Path: /languages`; `Plugin::load_textdomain()` loads the
  domain on `init`; every PHP string routes through `__()` / `_e()` /
  `esc_html__()` / `_x()` / `sprintf()` with the `goalcart` domain; and
  the React admin app imports `__` / `_x` / `_n` / `sprintf` from
  `@wordpress/i18n` (aliased to the `wp-i18n.ts` shim that delegates to
  WP core's `wp.i18n`, with the admin script declaring the `wp-i18n`
  dependency and `wp_set_script_translations` wired to the
  `languages/` directory — the pipeline was already in place from the
  foundation phase; Phase 27 proves it with tests and makes it
  operational). `tests/i18n-test.php` scans every PHP and TS/TSX file
  and fails on any domain-less translation call.
- **P27-T02 POT generation & build pipeline** — New self-contained
  tooling mirroring the reference plugin's `makepot`/`i18n:*` npm
  scripts, with no wp-cli required: `bin/extract-pot.php` scans
  `goalcart.php`, `includes/` (PHP) and  `admin-app/src` (TS/TSX — the
  same `__( '…', 'goalcart' )` syntax) and writes a deterministic
  `languages/goalcart.pot` (445 entries — including printf positional
  placeholders like `%1$s` — sorted, deduped, `#: file:line`
  references) with `--check` for CI freshness; `bin/build-i18n.php`
  compiles `languages/goalcart-<locale>.po` files into gettext `.mo`
  (verified magic bytes) and the JED JSON the admin loads — named
  `goalcart-<locale>-goalcart-admin.json` per WP 7's
  `load_script_textdomain()` convention (confirmed against the installed
  core). `admin-app/package.json` gained `makepot`, `i18n:extract`,
  `i18n:build`, `i18n:verify` and `i18n:all`.
- **P27-T03 RTL** — Verified end to end: the admin mount sets `dir`
  from `is_rtl()` (`Admin`), the MUI theme direction flips on
  `boot.isRtl` (`theme/index.ts`), the Emotion cache is RTL-flipped via
  `@mui/stylis-plugin-rtl` (`AppProviders.tsx`), the storefront config
  exposes `isRtl`, and the storefront CSS is physical-direction-free
  (logical properties only — asserted by the new suite).
- **P27-T04 Locale-aware formatting** — The storefront config now
  carries `locale` (`get_locale()`), and `assets/js/frontend.js` passes
  a BCP-47 tag to `Intl.NumberFormat` in `formatMoney()`/
  `formatNumber()` instead of the browser default locale — verified
  behaviorally: `new Intl.NumberFormat('fa-IR').format(1234.5)` →
  `۱٬۲۳۴٫۵` and the widget path renders `ریال ۲۵۰٬۰۰۰` for `fa_IR`
  (Persian digits). The admin side was already locale-driven
  (`lib/format.ts` and the date-range/analytics `Intl.DateTimeFormat`
  with `boot.locale`) and is now asserted by tests. Persian support is
  architecture-only: `tests/i18n-test.php` scans all PHP/TS/JS source
  for Persian/Arabic script characters and finds zero (no hard-coded
  strings).
- Added files: `bin/{extract-pot,build-i18n}.php`,
  `languages/goalcart.pot`, `tests/i18n-test.php` (44 checks),
  `docs/i18n.md`; extended `includes/Frontend/ProgressUI.php` (config
  `locale`), `assets/js/frontend.js` (UI_LOCALE Intl formatting),
  `admin-app/package.json` (i18n npm scripts), `docs/{frontend,
  compatibility}.md`.
- **Verification:** `php -l` clean on all changed PHP; new i18n suite
  44/44 (header + text domain, POT headers/contents/freshness, domain-
  less-call scan, no-hardcoded-Persian scan, storefront locale/isRtl,
  RTL setup + physical-free CSS, admin Intl + script-translations
  wiring, PO→MO+JED build with magic-byte and JED round-trip checks,
  temp outputs removed); full regression run — engine 75/75, reward
  72/72, cart-integration 22/22, rest-api 120/120, settings 124/124,
  preview 90/90, conflict 57/57, analytics 72/72, analytics-dashboard
  82/82, message 47/47, suggestion 28/28, security 65/65, performance
  38/38, woocommerce-compatibility 29/29, wordpress-compatibility
  28/28 (all pass, zero failures); `frontend 73/73` still reports its 1
  pre-existing environment-dependent failure (config template asserts
  the default `basic`; this site has it saved as `card` — same
  documented Phase 15 artifact, untouched by this phase); `node --check`
  on the JS; Persian-digit and widget-formatting outputs verified
  behaviorally with Node; `npm run typecheck`, `npm run lint` and
  `npm run build` all pass. No database changes.

**Overall project progress: 79%** (Phase 0 5% + Phase 1 3% + Phase 2 4% + Phase 3 3% + Phase 4 7% + Phase 5 5% + Phase 6 5% + Phase 7 3% + Phase 8 4% + Phase 9 4% + Phase 10 2% + Phase 11 4% + Phase 12 2% + Phase 13 2% + Phase 14 4% + Phase 15 2% + Phase 16 2% + Phase 17 2% + Phase 18 2% + Phase 19 2% + Phase 20 2% + Phase 21 1% + Phase 22 3% + Phase 23 3% + Phase 26 2% + Phase 27 weight 1% × 100%).

---

## [0.0.0] — Unreleased (project scaffold)

- Initial `AGENT.md` execution roadmap.
