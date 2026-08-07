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

---

## [0.0.0] — Unreleased (project scaffold)

- Initial `AGENT.md` execution roadmap.
