# FaraCart for WooCommerce — Product Specification

> **Phase 1 / Tasks P01-T01–T03** — Defines FaraCart as a **WooCommerce Cart Revenue Optimization Engine**, not merely a cart progress-bar widget.
> This document is the product source of truth for all implementation phases (Phases 2–35). Technical architecture decisions live in `docs/REFERENCE_ARCHITECTURE.md`; developer docs are produced in Phase 29.

---

## 1. Product Objective (P01-T01)

FaraCart helps WooCommerce store owners **increase Average Order Value (AOV) and conversion** by showing shoppers a clear, motivating path from their current cart to a reachable next goal, and by suggesting the incremental products that close the gap.

The product is **not** a passive progress-bar widget. It is a revenue-optimization engine built on four connected mechanisms:

1. **Goals** — a target the shopper can reach (cart amount, item quantity, category spend, …).
2. **Rewards** — the benefit unlocked when the goal is completed (free shipping, percentage discount, fixed discount, …).
3. **Progress** — continuous, honest feedback showing exactly how close the shopper is.
4. **Suggestions** — smart product recommendations that make the next incremental purchase obvious and frictionless.

Every feature is evaluated against the **Final Success Metric**:

> Increase Average Order Value and conversion by encouraging customers to add incremental products to their cart.

Concretely, each feature must answer **yes** to: does it reduce friction, make the next purchase step obvious, increase goal completion, increase cart value, improve conversion, preserve performance, and avoid harming checkout UX?

### Positioning

| Question | Answer |
|---|---|
| Who is it for? | WooCommerce store owners (SMB and larger) who want a measurable lift in AOV. |
| What problem does it solve? | Shoppers abandon carts just below a natural spending threshold; stores miss the incremental purchase. |
| What makes it different from a plain progress bar? | Goals + rewards + suggestions + campaigns + analytics work together as one engine; the bar is only the visible surface. |
| What is the storefront feel? | Lightweight, theme-agnostic, responsive, RTL-capable, and never intrusive at checkout. |
| Language focus | Persian-first storefront copy is a showcase target, but the architecture is fully localized — no hard-coded Persian strings. |

---

## 2. Core Product Concepts (P01-T02)

### 2.1 Goal

A **target the customer can reach** within a session of shopping. Goals are the unit of motivation.

| Goal dimension | Values (MVP) | Notes |
|---|---|---|
| **Type** | `amount`, `quantity`, `category` | Amount = cart/subtotal threshold; Quantity = total item count; Category = spend or quantity within one or more categories. |
| **Target** | Numeric threshold per type | e.g. 1,000,000 تومان subtotal; 5 items; 300,000 تومان in the “Kitchen” category. |
| **Calculation mode** | Subtotal basis (configurable in Phase 18) | Tax/discount/shipping inclusion rules come later; MVP uses a defined, documented basis. |
| **Status** | `active`, `inactive`, `scheduled` | Scheduled goals belong to campaigns (see §2.3). |
| **Reward** | One reward per goal (MVP) | Free shipping, percentage discount, or fixed discount. |
| **Priority** | Integer, lower = higher | Used for conflict resolution when multiple goals/campaigns compete (Phase 26). |
| **Conditions** | MVP: campaign schedule + cart state | Category/product/role conditions expand in later phases. |

**Goal examples (roadmap reference):** cart amount, item quantity, distinct item count, category amount, category quantity, product quantity, weight, conditional combinations. **MVP ships:** amount, quantity, category. The engine (Phase 4) is designed so `product`, `weight`, `distinct quantity`, and `composite` goals can be added without re-architecting.

### 2.2 Reward

The **benefit unlocked by completing a goal**. Rewards are decoupled from goal calculation (Phase 5) so they can be changed, stacked, and safety-checked independently.

| Reward type | MVP status | Semantics |
|---|---|---|
| **Free shipping** | ✅ MVP | Waives shipping when the goal is met; must respect existing WooCommerce shipping zones/methods (compatibility with `free_shipping`/method settings). |
| **Percentage discount** | ✅ MVP | e.g. “10% off the cart”; configurable percentage, optional max discount, stacking rules. |
| **Fixed discount** | ✅ MVP | e.g. “50,000 تومان off”; fixed amount, stacking rules. |
| Free gift | 🔜 Post-MVP | Predefined gift product; automatic or optional addition (V2). |
| Coupon reward | 🔜 Post-MVP | Generate/apply a coupon per configured rules. |
| Loyalty points integration | 🔜 Later | Out of MVP scope. |

> **Note on classification:** the roadmap's Phase 5 text labels a *predefined gift* reward as “MVP”, but the **MVP Release Definition** and **Post-MVP Backlog** (§4.5) both treat free gift as post-MVP (backlog #1). This spec follows the release definition: only **free shipping, percentage discount, and fixed discount** ship in the MVP.

**Reward safety (non-negotiable, Phase 5):** prevent duplicate rewards, reward loops, rewards persisting after a goal becomes incomplete, invalid coupon application, unintended stacking, and application to excluded products.

### 2.3 Campaign

A **collection of goals active under specific conditions and schedules**. Campaigns let a store run a cohesive promotion (e.g. a summer sale with tiered milestones) instead of one-off goals.

- A campaign owns: `name`, `status`, `start date`, `end date`, `priority`, ordered goals (milestones), and display rules.
- Goals inside a campaign act as **milestones**: reaching each threshold unlocks that tier's reward.
- **Campaign scheduling** is in MVP scope: `start_date` / `end_date` activate and deactivate the campaign automatically.
- Conflict rules (overlapping campaigns, conflicting goals, best-reward vs cumulative) are defined deterministically in Phase 26; the admin UI must communicate the behavior.

**Campaign example (roadmap reference):**

```text
Campaign: Summer Sale
  500,000  → Free Shipping
  1,000,000 → Free Gift
  1,500,000 → 10% Discount
  2,000,000 → Premium Gift
```

### 2.4 Progress

The **current state relative to a goal**, always computed honestly from the live cart. Progress is a first-class data object, not a hard-coded percentage:

| Field | Meaning |
|---|---|
| `current` | Current cart value for the goal's basis (amount / quantity / category). |
| `target` | The goal threshold. |
| `remaining` | `target − current` (never negative). |
| `percentage` | `current / target` (clamped 0–100). |
| `completed` | Boolean — goal reached. |
| `reward_state` | Which reward applies and whether it is activated. |
| `eligible` | Whether the goal applies to this cart/shopper at all (+ reason when not). |

**Progress guarantees:** updates live via AJAX on cart changes; a goal that becomes incomplete *reverts* its reward state; no stale values are shown.

### 2.5 Suggestion

A **product recommendation designed to help the customer reach a goal** — the "close the gap" mechanic that turns motivation into revenue.

- **Sources (MVP):** products in the goal's category, related/cross-sell/upsell products, best sellers, manually selected products. (Recently viewed where available, later.)
- **Ranking (MVP):** stock availability → goal eligibility → price proximity to `remaining` → relevance → manual priority.
- **Core example (roadmap):** when `remaining = 180,000`, prefer products priced ~150,000–220,000 rather than arbitrary expensive products.
- Suggestions must be **add-to-cart frictionless** (single click where the theme allows) and must never push excluded/out-of-stock products.

---

## 3. Users & Journeys

### 3.1 Shopper journey (frontend)

1. Shopper adds items → sees a compact progress bar ("Only 250,000 تومان left for free shipping") in the cart, mini-cart, and checkout summary.
2. The bar updates instantly on add/remove/quantity change (AJAX).
3. As the shopper approaches the threshold, a suggestion strip offers 1–3 products priced to close the remaining gap.
4. On completion, the reward state flips to "unlocked" (e.g. free shipping granted), with a brief success message.
5. If the shopper removes items, progress and reward state revert honestly.
6. Everything renders responsively and mirrors correctly in RTL locales with currency-aware formatting.

### 3.2 Store-owner journey (admin)

1. Create a goal (name, type, target, reward, message) in the React admin.
2. Bundle goals into a scheduled campaign (tiers/milestones).
3. Preview the shopper experience at 0% / 25% / 50% / 75% / 100% before publishing.
4. Watch basic analytics (impressions, completions, completion rate, AOV, suggestion CTR) to tune thresholds.

---

## 4. Initial MVP Scope (P01-T03)

The MVP is defined by the roadmap's feature list. Each item below states its requirement and where it lands in the implementation phases.

### 4.1 Goal types

| # | Feature | Requirement | Phase |
|---|---|---|---|
| 1 | **Amount goal** | Threshold on the cart amount (configurable calculation basis; default subtotal). | 4 |
| 2 | **Quantity goal** | Threshold on total item quantity in cart. | 4 |
| 3 | **Category goal** | Amount or quantity restricted to one or more product categories. | 4 |
| 4 | **Multiple goals** | Several independent goals can be active simultaneously (each with its own progress/reward). | 4, 26 |
| 5 | **Milestone goals** | Ordered thresholds within a campaign, each unlocking its own reward tier. | 10 |

### 4.2 Rewards

| # | Feature | Requirement | Phase |
|---|---|---|---|
| 6 | **Free shipping reward** | Unlock free shipping on goal completion; honor WooCommerce shipping zones/methods. | 5 |
| 7 | **Percentage discount** | Configurable percent discount with optional cap; stacking rules. | 5 |
| 8 | **Fixed discount** | Configurable fixed amount; stacking rules. | 5 |

### 4.3 Frontend experience

| # | Feature | Requirement | Phase |
|---|---|---|---|
| 9 | **Progress bar** | Reusable, theme-agnostic progress component (multiple templates in Phase 12). | 11 |
| 10 | **Dynamic messages** | Message engine with variables (`{remaining}`, `{reward}`, …) and states (progressing / nearly complete / completed / reward activated / …). | 13 |
| 11 | **Cart integration** | Render progress UI on the WooCommerce classic cart page; update via AJAX. | 11 |
| 12 | **Mini-cart integration** | Render (optionally) in the WooCommerce mini-cart without duplicate rendering. | 11 |
| 13 | **Checkout summary** | Show a compact, non-intrusive progress line in the checkout summary. | 11 |
| 14 | **AJAX updates** | Live progress updates on add-to-cart, remove, and quantity changes — no full page reloads. | 6, 11 |
| 15 | **Responsive UI** | Progress UI must be responsive (desktop/mobile) and mobile-friendly. | 11 |
| 16 | **RTL** | Full RTL mirroring of the storefront progress UI. | 11, 27 |
| 17 | **Currency-aware formatting** | All amounts formatted with the store currency and locale (e.g. `wc_price` / locale-aware number formatting). | 11, 27 |

### 4.4 Revenue engine

| # | Feature | Requirement | Phase |
|---|---|---|---|
| 18 | **Product suggestions** | Suggest products that close the remaining gap, ranked per §2.5. | 14 |
| 19 | **Campaign scheduling** | Campaigns activate/deactivate automatically by start/end date. | 10 |
| 20 | **Basic analytics** | Track `goal_impression`, `goal_progress`, `goal_completed`, `reward_activated`, `suggestion_impression`, `suggestion_clicked`, `suggested_product_added`; report impressions, completions, completion rate, AOV, goal revenue, suggestion CTR/add-to-cart rate — with privacy by default (no unnecessary PII). | 16 |

### 4.5 Explicit deferrals (out of MVP)

The roadmap explicitly defers to later phases — they must **not** block or become dependencies of the MVP:

- **Advanced AI** (Phase 34): campaign copy generation, smart thresholds, AI recommendations. Core FaraCart must work fully without AI.
- **A/B testing** (Phase 33): copy/design/threshold/reward/CTA experiments.
- **Revenue attribution & smart upsell/margins** (Phase 33).
- **Post-MVP backlog** (from roadmap, prioritized): free gift → customer-selected gifts → advanced conditions → shipping-zone rules → customer segmentation → countdown → sticky bar → campaign templates → full analytics dashboard → A/B testing → revenue attribution → smart goal recommendation → margin-aware recommendations → AI campaign optimization.
- **V2 advanced features** (Phase 32): free gift selection, countdown, campaign templates, advanced conditional rules, customer roles, first-order/VIP goals, shipping-zone goals, brand/tag/attribute conditions, scheduled campaigns (advanced), celebration animations, advanced sticky bar, advanced upsell ranking.

---

## 5. Functional Requirements (initial, non-normative for later phases)

These are the acceptance-level expectations the implementation phases must satisfy.

### 5.1 Goals engine
- Evaluate against a normalized `CartContext` (Phase 6) — a single source of truth built once per request.
- Every evaluator returns a consistent `GoalResult` (§2.4) regardless of goal type.
- Support empty cart (progress 0, not completed), zero/negative/invalid targets (never crash; treated as ineligible or completed per defined rules), sale prices, coupons, taxes, shipping costs, virtual/downloadable/variable products and variations, excluded products, decimal quantities, guest and logged-in users.
- Performance: no expensive DB queries on every frontend render (memoization, request-level caching, object cache where appropriate).

### 5.2 Rewards
- Decoupled from calculation; applied only while the goal is complete; reverted when it becomes incomplete.
- Stacking, deduplication, and exclusion rules enforced (Phase 5 “Reward Safety”).

### 5.3 Frontend
- Reusable component set: `GoalContainer` (one card per eligible goal),
  `ProgressBar`, `GoalMessage`, `RewardStatus`, `UnifiedRecommendations`
  (Suggestions + Upsells consolidation, Phase 14 + Phase 33.7),
  `StickyGoalBar` (Phase 11) — names following the reference project
  conventions. Every eligible goal renders as its own stacked card; the
  cross-goal `GoalMilestones` ladder was removed when that landed.
- Display locations: cart, mini-cart, checkout, shop, product page, configurable widget/shortcode, sticky bar (later). **Never** inject into locations that could double-render.
- Templates: the pluggable engine ships the six design templates `template-1` … `template-6` (classic progress card, minimal inline cart goal, circular progress, product recommendation + goal, compact floating/sticky goal, premium/elegant e-commerce style) — each with its own structure and schema-driven settings (colors, typography, border, radius, spacing, height, icons, animation, CSS class, custom CSS); the retired Phase 12 ids (`basic` / `percentage` / `milestone` / `card` / `ring`) are never mapped to a current template (a stored old id falls back to the default), and third parties register more via `goalcart_template_classes` (see `docs/api.md` §3).
- Message variables: `{current} {target} {remaining} {percentage} {quantity} {remaining_quantity} {reward} {goal_name} {campaign_name}` (Phase 13).

### 5.4 Admin
- React admin shell (Phase 8) with pages: Dashboard, Goals, Campaigns, Analytics, Settings, Appearance.
- Goal CRUD + builder (Phase 9): basic info, type, target, reward, conditions, display, priority.
- Campaign builder (Phase 10): CRUD, goal/milestone ordering, activation, scheduling, priority, customer conditions, preview, duplicate.
- Preview system (Phase 15): simulate empty cart / 25% / 50% / 75% / completed / multiple milestones at chosen device width — must never affect the real WooCommerce cart.
- Settings (Phase 18): general, frontend, goal-calculation, performance, advanced (debug/logging/custom CSS/hooks).

### 5.5 Analytics
- Events per §4.4 with a privacy-first posture: no unnecessary personally identifiable information; session/customer identifiers only where appropriate.
- Basic analytics dashboard (Phase 17): impressions, completions, completion rate, revenue influenced, AOV, top campaigns/goals/suggested products, with date-range, campaign, goal, and reward filters; charts follow the reference plugin's charting conventions.

---

## 6. Non-Functional Requirements

| Concern | Requirement |
|---|---|
| **Security** | Mandatory for every PHP, REST, AJAX, database, and React-admin operation (Phase 22): nonce verification, capability checks, sanitization, escaping, SQL parameterization, safe serialization, protected endpoints. |
| **WooCommerce compatibility** | First-class requirement (Phase 19): classic cart/checkout, Cart/Checkout Blocks where supported, mini-cart, variable products/variations, coupons, sale prices, tax, shipping zones/methods, guest + logged-in checkout, HPOS. Use supported public APIs/hooks; never assume internals are stable. |
| **WordPress compatibility** | Support matrix documented (Phase 20): WordPress, WooCommerce, PHP versions; multisite, localization, RTL, admin capabilities, activation/deactivation. |
| **i18n / RTL** | Translation-ready (Phase 27): WP translation functions, text domain, POT generation, React translations per reference, RTL, locale-aware number/currency/date formatting. Persian supported well without hard-coding Persian strings. |
| **Performance** | (Phase 23): lazy-load admin pages, minimize bundle size, cache server state, debounce searches, avoid repeated product/DB queries, update only changed UI fragments, paginate/server-side search admin lists. |
| **Testing** | Strategy in Phase 24: PHP unit tests (calculations, conditions, rewards), integration tests (cart/coupons/shipping/rewards/guest+member), React tests (rendering, builder, validation, loading/error states, preview), E2E scenarios (empty cart → add → progress → complete → reward → remove → revert → coupon/shipping changes → guest/member checkout → multiple goals → milestones → suggestion click → mobile). |

---

## 7. Success Metrics

Product success is not "the progress bar renders". MVP success is measured by:

- **AOV lift** — average order value before vs after enabling FaraCart.
- **Goal completion rate** — completed goals ÷ impressions.
- **Suggestion conversion** — CTR and add-to-cart rate of suggested products, and their contribution to cart value.
- **Friction** — checkout UX not harmed; no performance regression on storefront.
- **Adoption** — store owner can create a goal + campaign and see analytics without support.

---

## 8. Out of Scope for Phase 1

Phase 1 is a **specification-only phase**: it produces this document and the progress update. It does **not** produce code, and it must not begin FaraCart business implementation (rule 7) — the architecture report (Phase 0) is complete and `docs/REFERENCE_ARCHITECTURE.md` is authoritative for conventions.

## 9. Definition of Done (Phase 1)

- [x] Product defined as a revenue-optimization engine, not a progress-bar widget (P01-T01).
- [x] Core product concepts — Goal, Reward, Campaign, Progress, Suggestion — defined with examples (P01-T02).
- [x] Initial MVP scope captured, including all 20 roadmap items and explicit deferrals of AI/A-B testing (P01-T03).
- [x] Spec written to `docs/PRODUCT_SPEC.md` and consistent with the roadmap (goals/rewards/campaigns/progress/suggestions/analytics).
- [x] Progress register, phase dashboard, and CHANGELOG updated.
- [x] Phase 2 prerequisites available: Phase 0 architecture report (`docs/REFERENCE_ARCHITECTURE.md`) — **available** ✅.
