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

## [0.0.0] — Unreleased (project scaffold)

- Initial `AGENT.md` execution roadmap.
