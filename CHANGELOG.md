# Changelog

All notable changes to the **Goal Cart for WooCommerce** plugin are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project adheres to
[Semantic Versioning](https://semver.org/). Task IDs reference the register in `AGENT.md`.

## [Unreleased]

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

## [0.0.0] — Unreleased (project scaffold)

- Initial `AGENT.md` execution roadmap.
