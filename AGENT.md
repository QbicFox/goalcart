# Goal Cart for WooCommerce — AI Agent Implementation Roadmap

**Document Type:** Execution Roadmap  
**Language:** English  
**Primary Goal:** Build a production-ready WooCommerce plugin that increases Average Order Value (AOV) by showing cart goals, progress bars, rewards, milestones, and smart product suggestions.  
**Reference Plugin:** `/home/qbicfox/public_html/woo-app/wp-content/plugins/wooinsights`  
**Admin UI:** React + TypeScript  
**Implementation Model:** Phase-by-phase, task-by-task execution by an AI coding agent.

---

## 0. Non-Negotiable Agent Rules

Before implementation, the agent MUST follow these rules.

1. The reference plugin at `/home/qbicfox/public_html/woo-app/wp-content/plugins/wooinsights` is the architectural source of truth.
2. Do NOT invent a new PHP architecture, folder structure, naming convention, bootstrap pattern, asset-loading pattern, API pattern, React structure, or build system if an equivalent pattern exists in the reference plugin.
3. First inspect the reference plugin completely enough to understand its architecture.
4. Preserve the reference plugin's:
   - folder conventions
   - namespace conventions
   - class naming
   - service/controller/repository conventions
   - WordPress/WooCommerce hook registration style
   - REST/AJAX conventions
   - React application structure
   - TypeScript conventions
   - API client conventions
   - state-management conventions
   - form conventions
   - component conventions
   - styling conventions
   - build tooling
   - testing conventions
   - asset handling
   - translations/i18n conventions
5. Reuse architectural patterns, NOT business logic. Do not copy unrelated WooInsights features.
6. Do not modify `wooinsights`.
7. Do not begin Goal Cart business implementation until the reference architecture report has been produced.
8. Every phase must end with verification and a Definition of Done.
9. Never mark a task complete merely because files were created. Run the relevant checks.
10. Prefer small, reversible changes.
11. Avoid unnecessary dependencies.
12. Maintain backward compatibility with supported WordPress/WooCommerce versions.
13. Security is mandatory for every PHP, REST, AJAX, database, and React-admin operation.
14. WooCommerce compatibility must be treated as a first-class requirement.
15. If an architectural decision conflicts with the reference plugin, document the reason before implementing it.

---

# Phase 0 — Reference Plugin Discovery

**Phase Weight:** 5%  
**Phase Progress:** 100%  
**Project Contribution:** 5.00%  

```text
Phase 0: ████████████████████ 100%
```

## Objective

Reverse-engineer the existing `wooinsights` plugin and create a reusable architectural specification for the new plugin.

## 0.1 Verify Reference Path

Check:

```text
/home/qbicfox/public_html/woo-app/wp-content/plugins/wooinsights
```

Verify:

- directory exists
- plugin is readable
- main plugin file exists
- Composer configuration, if any
- package manager configuration, if any
- build configuration, if any

If the path is unavailable, STOP and report the exact reason. Do not guess the architecture.

## 0.2 Generate Complete File Inventory

Inspect:

- all PHP files
- all JS/TS/TSX files
- CSS/SCSS files
- JSON files
- Composer files
- package files
- build files
- config files
- tests
- documentation
- database/migration files

Create:

```text
docs/reference-plugin-file-inventory.md
```

Include file purpose where identifiable.

## 0.3 Inspect PHP Architecture

Identify:

- plugin bootstrap
- namespaces
- autoloading
- service providers
- dependency injection
- controllers
- services
- repositories
- models
- DTOs
- value objects
- validators
- database layer
- migrations
- settings
- hooks
- filters
- admin registration
- REST endpoints
- AJAX handlers
- cron jobs
- activation/deactivation
- uninstall behavior
- logging
- exceptions
- helpers/utilities

## 0.4 Inspect React Architecture

Identify:

- entry points
- React root mounting
- routing
- layouts
- pages
- components
- hooks
- API clients
- state management
- server-state management
- forms
- validation
- tables
- dialogs
- notifications
- loading states
- error boundaries
- theme
- RTL handling
- localization
- CSS/styling system

## 0.5 Inspect Build System

Determine exactly:

- Vite/Webpack/other
- TypeScript configuration
- ESLint
- Prettier
- package manager
- production build command
- development command
- asset output
- WordPress enqueue integration
- cache-busting/versioning
- source maps

## 0.6 Inspect Coding Conventions

Document:

- naming
- file naming
- class naming
- method naming
- variable naming
- constants
- comments
- PHPDoc
- TypeScript types
- component structure
- import ordering
- error handling
- return types
- strictness

## 0.7 Inspect Database Conventions

Determine:

- table naming
- migrations
- prefixes
- schema style
- indexes
- timestamps
- JSON columns
- serialization
- repositories
- upgrade strategy

## 0.8 Inspect API Conventions

Document:

- endpoint naming
- HTTP methods
- permissions
- nonce/authentication
- request validation
- response format
- pagination
- errors
- frontend API wrapper

## 0.9 Create Architecture Report

Create:

```text
docs/REFERENCE_ARCHITECTURE.md
```

It must contain:

1. Directory architecture
2. PHP architecture
3. React architecture
4. Build architecture
5. API architecture
6. Database architecture
7. Testing architecture
8. Coding conventions
9. Asset conventions
10. Security conventions
11. Reusable patterns
12. Patterns that MUST NOT be copied

## Acceptance Criteria

- Reference plugin inspected.
- Architecture is documented.
- No major structural assumption remains undocumented.
- New plugin architecture can be derived from the report.

---

# Phase 1 — Product Specification

**Phase Weight:** 3%  
**Phase Progress:** 100%  
**Project Contribution:** 3.00%  

```text
Phase 1: ████████████████████ 100%
```

## Objective

Define Goal Cart as a revenue-optimization engine, not merely a progress-bar widget.

## Core Product Concepts

### Goal

A target the customer can reach.

Examples:

- cart amount
- item quantity
- distinct item count
- category amount
- category quantity
- product quantity
- weight
- conditional combinations

### Reward

The benefit unlocked by completing a goal.

Examples:

- free shipping
- percentage discount
- fixed discount
- free gift
- coupon
- loyalty points integration

### Campaign

A collection of goals active under specific conditions and schedules.

### Progress

Current state relative to a goal.

### Suggestion

A product recommendation designed to help the customer reach a goal.

## Initial MVP Scope

Implement:

- amount goal
- quantity goal
- category goal
- multiple goals
- milestone goals
- free shipping reward
- percentage discount
- fixed discount
- progress bar
- dynamic messages
- cart integration
- mini-cart integration
- checkout summary
- AJAX updates
- responsive UI
- RTL
- currency-aware formatting
- product suggestions
- campaign scheduling
- basic analytics

Explicitly defer advanced AI and A/B testing to later phases.

---

# Phase 2 — Plugin Foundation

**Phase Weight:** 4%  
**Phase Progress:** 100%  
**Project Contribution:** 4.00%  

```text
Phase 2: ████████████████████ 100%
```

## Objective

Create the new plugin using the exact architectural conventions discovered in Phase 0.

## Tasks

- Choose plugin slug.
- Create plugin bootstrap.
- Create namespace using the reference convention.
- Configure Composer if the reference uses Composer.
- Configure frontend using the reference build stack.
- Configure TypeScript according to the reference project.
- Configure linting/formatting according to the reference.
- Implement activation.
- Implement deactivation.
- Implement uninstall.
- Register plugin constants.
- Register WooCommerce dependency checks.
- Register minimum WordPress/PHP/WooCommerce compatibility checks.
- Implement capability checks.
- Implement nonce strategy.
- Implement translation loading.
- Implement logging/error strategy consistent with the reference.

## Definition of Done

Plugin activates without fatal errors and follows the reference plugin's architecture.

---

# Phase 3 — Database & Domain Model

**Phase Weight:** 3%  
**Phase Progress:** 100%  
**Project Contribution:** 3.00%  

```text
Phase 3: ████████████████████ 100%
```

## Objective

Design a maintainable persistence layer.

## Recommended Domain Entities

### Goal

Fields should support:

- id
- name
- status
- type
- target
- calculation mode
- reward
- conditions
- display settings
- priority
- schedule
- limits
- timestamps

### Campaign

Support:

- name
- status
- start date
- end date
- priority
- goals
- display rules

### Analytics Event

Support:

- goal/campaign ID
- event type
- session/customer identifier where appropriate
- cart/order context
- value
- timestamp

## Database Rules

- Follow the reference plugin's migration strategy.
- Use proper indexes.
- Avoid storing unnecessary duplicated WooCommerce data.
- Prefer structured JSON only where schema flexibility is required.
- Never store sensitive customer data unnecessarily.
- Make schema upgrades safe and repeatable.

---

# Phase 4 — Goal Engine

**Phase Weight:** 7%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 4: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Build the central calculation engine independently from UI.

## Architecture

The engine should conceptually support:

```text
CartContext
    ↓
GoalEvaluator
    ↓
GoalResult
    ↓
ProgressCalculator
```

## Goal Types

### Amount Goal

Examples:

- subtotal
- cart total
- discounted subtotal
- configurable calculation basis

### Quantity Goal

Total item quantity.

### Distinct Quantity Goal

Number of unique products/SKUs.

### Category Goal

Amount or quantity restricted to one or more categories.

### Product Goal

Specific products or variations.

### Weight Goal

Based on WooCommerce product/cart weight.

### Composite Goal

AND/OR combinations of conditions.

## Goal Result

Every evaluator should produce a consistent result containing concepts such as:

- current value
- target value
- remaining value
- percentage
- completed
- reward state
- eligible
- reason when not eligible

## Edge Cases

Test:

- empty cart
- zero target
- negative/invalid target
- sale prices
- coupons
- taxes
- shipping costs
- virtual products
- downloadable products
- variable products
- variations
- excluded products
- decimal quantities
- refunds/returns where relevant
- guest users
- logged-in users

---

# Phase 5 — Reward Engine

**Phase Weight:** 5%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 5: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Decouple rewards from goal calculation.

## Reward Types

### Free Shipping

Support:

- WooCommerce shipping zones
- shipping methods
- minimum thresholds
- compatibility with existing shipping rules

### Percentage Discount

Support:

- percentage
- maximum discount
- eligible products/categories
- stacking rules

### Fixed Discount

Support:

- fixed amount
- eligible products/categories
- stacking rules

### Free Gift

MVP:

- predefined gift product
- automatic or optional addition

Future:

- customer-selected gift
- multiple gift choices
- stock limits

### Coupon Reward

Generate/apply a coupon according to configured rules.

## Reward Safety

Prevent:

- duplicate rewards
- reward loops
- reward persistence after goal becomes incomplete
- invalid coupon application
- unintended stacking
- reward application to excluded products

---

# Phase 6 — Cart Context & WooCommerce Integration

**Phase Weight:** 5%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 6: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Create a single reliable source of truth for current cart state.

## Integrate With

- cart initialization
- add to cart
- remove from cart
- quantity updates
- cart totals recalculation
- coupon application
- coupon removal
- shipping method changes
- checkout updates
- mini-cart refresh
- WooCommerce Blocks where supported

## Cart Context

Create a normalized context containing only information required by the Goal Engine.

Avoid querying products repeatedly.

## Performance Requirement

Do not execute expensive database queries on every frontend render.

Use:

- memoization
- request-level caching
- object cache where appropriate
- WooCommerce cart data
- indexed queries
- preloaded data where possible

---

# Phase 7 — REST API / AJAX Layer

**Phase Weight:** 3%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 7: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Expose a clean API to the React admin and frontend components.

## Admin API

Endpoints for:

- goals list
- goal details
- create goal
- update goal
- delete goal
- duplicate goal
- campaigns
- settings
- analytics
- product search
- category search
- coupon/gift selection where required

## Frontend API

Expose only the minimum necessary data:

```text
current
target
remaining
percentage
completed
message
reward
suggestions
```

## Security

Every endpoint must implement:

- authentication where required
- capability checks
- nonce validation
- input validation
- sanitization
- output escaping/serialization
- predictable error responses

---

# Phase 8 — React Admin Foundation

**Phase Weight:** 4%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 8: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Build the complete admin shell using the reference plugin's React architecture.

## Required

- React
- TypeScript
- routing if used by reference plugin
- shared layout
- sidebar/navigation
- page container
- API client
- server state
- forms
- validation
- notifications
- loading states
- error states
- confirmation dialogs

## Admin Pages

Initial navigation:

```text
Dashboard
Goals
Campaigns
Analytics
Settings
Appearance
```

Follow the exact visual/structural conventions of the reference plugin wherever applicable.

---

# Phase 9 — Goal Management UI

**Phase Weight:** 4%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 9: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Create a professional Goal CRUD experience.

## Goal List

Columns:

- name
- type
- reward
- status
- priority
- schedule
- completion stats
- actions

Actions:

- create
- edit
- duplicate
- enable/disable
- delete
- preview

## Goal Builder

Sections:

### Basic Information

- name
- internal description
- status

### Goal Type

- amount
- quantity
- category
- product
- weight
- composite

### Target

Dynamic fields based on type.

### Reward

Dynamic reward configuration.

### Conditions

- categories
- products
- roles
- customer state
- cart state
- schedule

### Display

- title
- message
- completed message
- icon
- template

### Priority

Define conflict resolution.

---

# Phase 10 — Campaign Builder

**Phase Weight:** 2%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 10: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Allow multiple goals to work as a campaign.

Example:

```text
Campaign: Summer Sale

500,000 → Free Shipping
1,000,000 → Free Gift
1,500,000 → 10% Discount
2,000,000 → Premium Gift
```

## Features

- campaign CRUD
- goal ordering
- milestone ordering
- activation
- scheduling
- priority
- customer conditions
- preview
- duplicate campaign

---

# Phase 11 — Frontend Progress UI

**Phase Weight:** 4%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 11: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Build reusable frontend components.

## Components

Conceptually:

```text
GoalCart
├── GoalContainer
├── ProgressBar
├── GoalMessage
├── GoalMilestones
├── RewardStatus
├── SuggestionList
└── StickyGoalBar
```

Names must follow the reference project's conventions.

## Display Locations

- Cart
- Mini Cart
- Checkout
- Shop
- Product page
- configurable widget/shortcode
- sticky bar

Do not inject into locations that could cause duplicate rendering.

---

# Phase 12 — Progress Templates

**Phase Weight:** 2%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 12: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Templates

### Basic

```text
████████████░░░░
Only 250,000 تومان left for free shipping
```

### Percentage

```text
75%
███████████████░░░
```

### Milestone

```text
●────●────○────○
500K  1M   1.5M  2M
```

### Card

A configurable card containing:

- icon
- title
- progress
- message
- reward
- CTA

## Customization

Support:

- colors
- typography
- border
- radius
- spacing
- height
- icons
- animation
- CSS class
- custom CSS

---

# Phase 13 — Dynamic Messaging

**Phase Weight:** 2%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 13: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Create a reusable message template engine.

## Variables

Support relevant variables such as:

```text
{current}
{target}
{remaining}
{percentage}
{quantity}
{remaining_quantity}
{reward}
{goal_name}
{campaign_name}
```

## States

- inactive
- unavailable
- progressing
- nearly complete
- completed
- reward activated

## Example

```text
Only {remaining} left until {reward}
```

---

# Phase 14 — Smart Product Suggestions

**Phase Weight:** 4%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 14: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Turn Goal Cart into an actual revenue optimization feature.

## Recommendation Sources

- related products
- cross-sells
- upsells
- category products
- manually selected products
- best sellers
- recently viewed products where available

## Ranking

Initial ranking can consider:

1. stock availability
2. goal eligibility
3. price proximity to remaining amount
4. product relevance
5. manual priority

## Example

If:

```text
remaining = 180,000
```

prefer suitable products around:

```text
150,000–220,000
```

rather than arbitrary expensive products.

## Future

Add margin-aware recommendations and AI optimization.

---

# Phase 15 — Admin Preview System

**Phase Weight:** 2%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 15: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Allow administrators to see the customer experience before publishing.

## Preview States

- empty cart
- 25%
- 50%
- 75%
- completed
- multiple milestones

## Preview Controls

- simulated cart amount
- simulated quantity
- simulated reward
- device width
- template

Preview must not affect the real WooCommerce cart.

---

# Phase 16 — Analytics Foundation

**Phase Weight:** 2%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 16: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Objective

Measure whether Goal Cart actually increases revenue.

## Events

At minimum:

- goal_impression
- goal_progress
- goal_completed
- reward_activated
- suggestion_impression
- suggestion_clicked
- suggested_product_added

## Metrics

- impressions
- completions
- completion rate
- average cart value
- revenue associated with completed goals
- suggestion CTR
- suggestion add-to-cart rate

## Privacy

Do not collect unnecessary personally identifiable information.

---

# Phase 17 — Analytics Dashboard

**Phase Weight:** 2%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 17: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Dashboard

Display:

- total goal impressions
- completed goals
- completion rate
- revenue influenced
- AOV
- top campaigns
- top goals
- top suggested products

## Filters

- date range
- campaign
- goal
- reward
- product/category where applicable

## Charts

Use the reference plugin's existing charting conventions if available.

---

# Phase 18 — Settings

**Phase Weight:** 2%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 18: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## General

- enable/disable
- currency display
- default goal behavior
- calculation mode

## Frontend

- default location
- template
- animation
- mobile behavior
- sticky bar

## Goal Calculation

- tax inclusion
- discount inclusion
- shipping inclusion
- sale product inclusion
- virtual product inclusion

## Performance

- caching
- analytics
- suggestion engine

## Advanced

- debug mode
- logging
- custom CSS
- developer hooks

---

# Phase 19 — WooCommerce Compatibility

**Phase Weight:** 2%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 19: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Must Test

- WooCommerce classic Cart
- WooCommerce Cart Block
- WooCommerce classic Checkout
- WooCommerce Checkout Block where supported
- Mini Cart
- variable products
- product variations
- coupons
- sale prices
- tax
- shipping zones
- multiple shipping methods
- guest checkout
- logged-in users
- HPOS

## Important

Do not assume WooCommerce internals are stable across versions. Use supported public APIs/hooks whenever possible.

---

# Phase 20 — WordPress Compatibility

Test supported versions of:

- WordPress
- WooCommerce
- PHP

Define the exact support matrix in plugin documentation.

Check:

- multisite behavior if supported
- localization
- RTL
- admin capabilities
- plugin activation/deactivation

---

# Phase 21 — Page Builder & Block Compatibility

Implement integrations according to the reference plugin's patterns where applicable.

Priority:

1. Gutenberg
2. WooCommerce Blocks
3. Elementor
4. Bricks

Do not add dependencies solely for builder integration unless justified.

---

# Phase 22 — Security Hardening

Audit every layer.

**Phase Weight:** 3%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 22: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## PHP

- nonce verification
- capability checks
- sanitization
- escaping
- SQL parameterization
- safe serialization
- protected endpoints

## REST

- permission callbacks
- schema validation
- rate/abuse considerations where relevant

## React

- never trust admin input
- never render untrusted HTML without sanitization
- avoid unsafe HTML injection

## Database

- prepared statements
- indexed queries
- safe migrations
- no raw user-controlled SQL

---

# Phase 23 — Performance Optimization

**Phase Weight:** 3%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 23: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Frontend

- lazy-load admin pages where appropriate
- minimize bundle size
- avoid unnecessary React renders
- cache server state
- debounce product/category searches
- virtualize long lists where needed

## WooCommerce Frontend

- avoid expensive calculations on every render
- cache goal evaluation within a request
- avoid repeated product queries
- avoid repeated database calls
- update only changed UI fragments

## Admin

- paginate large lists
- server-side search
- server-side filtering
- avoid loading thousands of products at once

---

# Phase 24 — Testing Strategy

**Phase Weight:** 4%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 24: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## PHP Unit Tests

Test:

- amount calculation
- quantity calculation
- category calculation
- product conditions
- composite conditions
- percentage calculation
- remaining calculation
- reward eligibility

## Integration Tests

Test:

- WooCommerce cart
- coupons
- shipping
- rewards
- product conditions
- guest users
- logged-in users

## React Tests

Test:

- progress rendering
- goal builder
- validation
- API loading
- error states
- dynamic fields
- preview

## E2E Tests

Scenarios:

1. Empty cart.
2. Add product.
3. Progress updates.
4. Goal completion.
5. Reward activation.
6. Remove product.
7. Goal becomes incomplete.
8. Coupon changes total.
9. Shipping changes.
10. Guest checkout.
11. Logged-in checkout.
12. Multiple goals.
13. Milestone progression.
14. Product suggestion click.
15. Mobile rendering.

---

# Phase 25 — Edge Case Matrix

The agent must explicitly test:

- cart total exactly equal to target
- cart total one unit below target
- cart total one unit above target
- target = 0
- empty cart
- product deleted after goal creation
- category deleted
- reward product out of stock
- reward product deleted
- campaign expired
- campaign not started
- overlapping campaigns
- overlapping rewards
- conflicting goals
- duplicate rewards
- multiple tabs
- AJAX race conditions
- currency decimals
- RTL
- Persian digits
- very large cart
- very large product catalog
- guest session
- logged-in session
- cache enabled
- object cache enabled

---

# Phase 26 — Conflict & Priority Engine

When multiple goals/campaigns are active, define deterministic behavior.

Support:

- priority
- mutually exclusive goals
- cumulative rewards
- best reward
- first matching goal
- campaign priority

The admin UI must clearly communicate the behavior.

---

# Phase 27 — Internationalization

The plugin must be translation-ready.

Requirements:

- WordPress translation functions
- text domain
- POT generation
- React translations following the reference plugin
- RTL
- locale-aware number formatting
- currency formatting
- date/time formatting

Persian must be supported well, but the architecture must not hard-code Persian strings.

---

# Phase 28 — Developer API

Provide documented hooks/actions/filters.

Examples conceptually:

```text
goal_cart_before_evaluate
goal_cart_after_evaluate
goal_cart_goal_completed
goal_cart_reward_activated
goal_cart_before_render
goal_cart_after_render
goal_cart_suggestions
goal_cart_calculation_context
```

Exact naming must follow the plugin's namespace and conventions.

---

# Phase 29 — Developer Documentation

Create:

```text
docs/
├── architecture.md
├── goals.md
├── rewards.md
├── campaigns.md
├── frontend.md
├── api.md
├── hooks.md
├── analytics.md
├── compatibility.md
└── troubleshooting.md
```

Document all public APIs.

---

# Phase 30 — Production Build & Packaging

**Phase Weight:** 2%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 30: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Tasks

- production PHP validation
- production React build
- dependency audit
- remove development artifacts
- verify generated assets
- verify plugin header
- verify version
- verify translations
- verify activation
- verify deactivation
- verify uninstall
- create production ZIP
- verify ZIP installation

The ZIP must contain only required production files.

---

# Phase 31 — Release QA

Run a complete release checklist.

**Phase Weight:** 2%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 31: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Static Checks

- PHP syntax
- PHP lint
- TypeScript
- ESLint
- formatting
- build
- dependency audit

## Runtime Checks

- activation
- admin page
- React app
- REST
- cart
- mini-cart
- checkout
- rewards
- analytics

## Regression

Compare architecture and conventions against `wooinsights`.

Confirm:

- no accidental changes to reference plugin
- no duplicated architecture that violates conventions
- no unrelated dependencies
- no production debug output

---

# Phase 32 — Advanced V2 Features

After MVP stability, implement:

- free gift selection
- countdown
- campaign templates
- advanced conditional rules
- customer roles
- first-order goals
- VIP goals
- shipping-zone goals
- brand/tag/attribute conditions
- scheduled campaigns
- celebration animations
- advanced sticky bar
- advanced upsell ranking

---

# Phase 33 — Advanced V3 Revenue Optimization

Implement:

**Phase Weight:** 3%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 33: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## A/B Testing

Compare:

- copy
- design
- threshold
- reward
- CTA
- recommendation strategy

## Revenue Attribution

Measure:

- incremental cart value
- goal-driven revenue
- AOV change
- reward cost
- estimated profit impact

## Smart Goal Recommendation

Use store data to recommend thresholds.

Possible inputs:

- AOV
- median order value
- order distribution
- shipping cost
- product margins where available

## Smart Upsell

Rank products based on:

- price gap
- relevance
- inventory
- popularity
- margin
- historical conversion

---

# Phase 34 — AI Features

AI features are optional and must not become a dependency for the core plugin.

Potential features:

- generate campaign copy
- recommend goal thresholds
- recommend rewards
- recommend products
- analyze goal performance
- suggest campaign improvements

Core Goal Cart functionality must work without AI.

---

# Phase 35 — Final Architecture Review

The agent must perform a final architectural review.

Check:

### Backend

- bootstrap
- services
- controllers
- repositories
- domain logic
- database
- hooks
- API
- security

### Frontend

- React architecture
- components
- hooks
- API layer
- state management
- forms
- validation
- styling
- build

### WooCommerce

- cart
- checkout
- shipping
- coupons
- products
- variations
- Blocks
- HPOS

### Product

- goals
- rewards
- campaigns
- progress
- suggestions
- analytics

---

# Agent Execution Protocol

For every task, the AI agent must follow:

```text
1. Read the task.
2. Inspect existing relevant files.
3. Compare with wooinsights conventions.
4. Identify dependencies.
5. Implement the smallest coherent change.
6. Run focused tests/checks.
7. Fix failures.
8. Run related regression checks.
9. Update documentation.
10. Update the task's status and numeric progress.
11. Recalculate phase progress.
12. Recalculate overall project progress.
13. Mark the task complete only after Definition of Done.
```

Never implement an entire phase blindly without verification.

---

# Required Project Documentation

The agent must maintain:

```text
ROADMAP.md
CHANGELOG.md
docs/REFERENCE_ARCHITECTURE.md
docs/architecture.md
docs/api.md
docs/hooks.md
docs/testing.md
docs/compatibility.md
```

The roadmap itself must be updated with task status.

Use:

```text
[ ] Not Started
[-] In Progress
[x] Completed
[!] Blocked
```

---


---

# Task Progress Register

This is the authoritative task-level progress register. Each task is represented by one `##` section in the roadmap.

**Update rule:** when a task changes, update its `Status`, `Progress`, and the corresponding phase/project progress.

| ID | Phase | Task | Task Weight | Status | Progress | Project Contribution |
|---|---:|---|---:|---|---:|---:|
| P00-T01 | 0 | Objective | 9.09% | [x] | 100% | 0.4545% |
| P00-T02 | 0 | 0.1 Verify Reference Path | 9.09% | [x] | 100% | 0.4545% |
| P00-T03 | 0 | 0.2 Generate Complete File Inventory | 9.09% | [x] | 100% | 0.4545% |
| P00-T04 | 0 | 0.3 Inspect PHP Architecture | 9.09% | [x] | 100% | 0.4545% |
| P00-T05 | 0 | 0.4 Inspect React Architecture | 9.09% | [x] | 100% | 0.4545% |
| P00-T06 | 0 | 0.5 Inspect Build System | 9.09% | [x] | 100% | 0.4545% |
| P00-T07 | 0 | 0.6 Inspect Coding Conventions | 9.09% | [x] | 100% | 0.4545% |
| P00-T08 | 0 | 0.7 Inspect Database Conventions | 9.09% | [x] | 100% | 0.4545% |
| P00-T09 | 0 | 0.8 Inspect API Conventions | 9.09% | [x] | 100% | 0.4545% |
| P00-T10 | 0 | 0.9 Create Architecture Report | 9.09% | [x] | 100% | 0.4545% |
| P00-T11 | 0 | Acceptance Criteria | 9.09% | [x] | 100% | 0.4545% |
| P01-T01 | 1 | Objective | 33.33% | [x] | 100% | 1.00% |
| P01-T02 | 1 | Core Product Concepts | 33.33% | [x] | 100% | 1.00% |
| P01-T03 | 1 | Initial MVP Scope | 33.33% | [x] | 100% | 1.00% |
| P02-T01 | 2 | Objective | 33.33% | [x] | 100% | 1.3333% |
| P02-T02 | 2 | Tasks | 33.33% | [x] | 100% | 1.3333% |
| P02-T03 | 2 | Definition of Done | 33.33% | [x] | 100% | 1.3333% |
| P03-T01 | 3 | Objective | 33.33% | [x] | 100% | 1.00% |
| P03-T02 | 3 | Recommended Domain Entities | 33.33% | [x] | 100% | 1.00% |
| P03-T03 | 3 | Database Rules | 33.33% | [x] | 100% | 1.00% |
| P04-T01 | 4 | Objective | 20.00% | [ ] | 0% | 0.00% |
| P04-T02 | 4 | Architecture | 20.00% | [ ] | 0% | 0.00% |
| P04-T03 | 4 | Goal Types | 20.00% | [ ] | 0% | 0.00% |
| P04-T04 | 4 | Goal Result | 20.00% | [ ] | 0% | 0.00% |
| P04-T05 | 4 | Edge Cases | 20.00% | [ ] | 0% | 0.00% |
| P05-T01 | 5 | Objective | 33.33% | [ ] | 0% | 0.00% |
| P05-T02 | 5 | Reward Types | 33.33% | [ ] | 0% | 0.00% |
| P05-T03 | 5 | Reward Safety | 33.33% | [ ] | 0% | 0.00% |
| P06-T01 | 6 | Objective | 25.00% | [ ] | 0% | 0.00% |
| P06-T02 | 6 | Integrate With | 25.00% | [ ] | 0% | 0.00% |
| P06-T03 | 6 | Cart Context | 25.00% | [ ] | 0% | 0.00% |
| P06-T04 | 6 | Performance Requirement | 25.00% | [ ] | 0% | 0.00% |
| P07-T01 | 7 | Objective | 25.00% | [ ] | 0% | 0.00% |
| P07-T02 | 7 | Admin API | 25.00% | [ ] | 0% | 0.00% |
| P07-T03 | 7 | Frontend API | 25.00% | [ ] | 0% | 0.00% |
| P07-T04 | 7 | Security | 25.00% | [ ] | 0% | 0.00% |
| P08-T01 | 8 | Objective | 33.33% | [ ] | 0% | 0.00% |
| P08-T02 | 8 | Required | 33.33% | [ ] | 0% | 0.00% |
| P08-T03 | 8 | Admin Pages | 33.33% | [ ] | 0% | 0.00% |
| P09-T01 | 9 | Objective | 33.33% | [ ] | 0% | 0.00% |
| P09-T02 | 9 | Goal List | 33.33% | [ ] | 0% | 0.00% |
| P09-T03 | 9 | Goal Builder | 33.33% | [ ] | 0% | 0.00% |
| P10-T01 | 10 | Objective | 50.00% | [ ] | 0% | 0.00% |
| P10-T02 | 10 | Features | 50.00% | [ ] | 0% | 0.00% |
| P11-T01 | 11 | Objective | 33.33% | [ ] | 0% | 0.00% |
| P11-T02 | 11 | Components | 33.33% | [ ] | 0% | 0.00% |
| P11-T03 | 11 | Display Locations | 33.33% | [ ] | 0% | 0.00% |
| P12-T01 | 12 | Templates | 50.00% | [ ] | 0% | 0.00% |
| P12-T02 | 12 | Customization | 50.00% | [ ] | 0% | 0.00% |
| P13-T01 | 13 | Objective | 25.00% | [ ] | 0% | 0.00% |
| P13-T02 | 13 | Variables | 25.00% | [ ] | 0% | 0.00% |
| P13-T03 | 13 | States | 25.00% | [ ] | 0% | 0.00% |
| P13-T04 | 13 | Example | 25.00% | [ ] | 0% | 0.00% |
| P14-T01 | 14 | Objective | 20.00% | [ ] | 0% | 0.00% |
| P14-T02 | 14 | Recommendation Sources | 20.00% | [ ] | 0% | 0.00% |
| P14-T03 | 14 | Ranking | 20.00% | [ ] | 0% | 0.00% |
| P14-T04 | 14 | Example | 20.00% | [ ] | 0% | 0.00% |
| P14-T05 | 14 | Future | 20.00% | [ ] | 0% | 0.00% |
| P15-T01 | 15 | Objective | 33.33% | [ ] | 0% | 0.00% |
| P15-T02 | 15 | Preview States | 33.33% | [ ] | 0% | 0.00% |
| P15-T03 | 15 | Preview Controls | 33.33% | [ ] | 0% | 0.00% |
| P16-T01 | 16 | Objective | 25.00% | [ ] | 0% | 0.00% |
| P16-T02 | 16 | Events | 25.00% | [ ] | 0% | 0.00% |
| P16-T03 | 16 | Metrics | 25.00% | [ ] | 0% | 0.00% |
| P16-T04 | 16 | Privacy | 25.00% | [ ] | 0% | 0.00% |
| P17-T01 | 17 | Dashboard | 33.33% | [ ] | 0% | 0.00% |
| P17-T02 | 17 | Filters | 33.33% | [ ] | 0% | 0.00% |
| P17-T03 | 17 | Charts | 33.33% | [ ] | 0% | 0.00% |
| P18-T01 | 18 | General | 20.00% | [ ] | 0% | 0.00% |
| P18-T02 | 18 | Frontend | 20.00% | [ ] | 0% | 0.00% |
| P18-T03 | 18 | Goal Calculation | 20.00% | [ ] | 0% | 0.00% |
| P18-T04 | 18 | Performance | 20.00% | [ ] | 0% | 0.00% |
| P18-T05 | 18 | Advanced | 20.00% | [ ] | 0% | 0.00% |
| P19-T01 | 19 | Must Test | 50.00% | [ ] | 0% | 0.00% |
| P19-T02 | 19 | Important | 50.00% | [ ] | 0% | 0.00% |
| P22-T01 | 22 | PHP | 25.00% | [ ] | 0% | 0.00% |
| P22-T02 | 22 | REST | 25.00% | [ ] | 0% | 0.00% |
| P22-T03 | 22 | React | 25.00% | [ ] | 0% | 0.00% |
| P22-T04 | 22 | Database | 25.00% | [ ] | 0% | 0.00% |
| P23-T01 | 23 | Frontend | 33.33% | [ ] | 0% | 0.00% |
| P23-T02 | 23 | WooCommerce Frontend | 33.33% | [ ] | 0% | 0.00% |
| P23-T03 | 23 | Admin | 33.33% | [ ] | 0% | 0.00% |
| P24-T01 | 24 | PHP Unit Tests | 25.00% | [ ] | 0% | 0.00% |
| P24-T02 | 24 | Integration Tests | 25.00% | [ ] | 0% | 0.00% |
| P24-T03 | 24 | React Tests | 25.00% | [ ] | 0% | 0.00% |
| P24-T04 | 24 | E2E Tests | 25.00% | [ ] | 0% | 0.00% |
| P30-T01 | 30 | Tasks | 100.00% | [ ] | 0% | 0.00% |
| P31-T01 | 31 | Static Checks | 33.33% | [ ] | 0% | 0.00% |
| P31-T02 | 31 | Runtime Checks | 33.33% | [ ] | 0% | 0.00% |
| P31-T03 | 31 | Regression | 33.33% | [ ] | 0% | 0.00% |
| P33-T01 | 33 | A/B Testing | 25.00% | [ ] | 0% | 0.00% |
| P33-T02 | 33 | Revenue Attribution | 25.00% | [ ] | 0% | 0.00% |
| P33-T03 | 33 | Smart Goal Recommendation | 25.00% | [ ] | 0% | 0.00% |
| P33-T04 | 33 | Smart Upsell | 25.00% | [ ] | 0% | 0.00% |
| P35-T01 | 35 | Goals | 16.67% | [ ] | 0% | 0.00% |
| P35-T02 | 35 | Rewards | 16.67% | [ ] | 0% | 0.00% |
| P35-T03 | 35 | Frontend | 16.67% | [ ] | 0% | 0.00% |
| P35-T04 | 35 | Admin | 16.67% | [ ] | 0% | 0.00% |
| P35-T05 | 35 | Smart Upsell | 16.67% | [ ] | 0% | 0.00% |
| P35-T06 | 35 | Reliability | 16.67% | [ ] | 0% | 0.00% |

## Progress Update Template

When a task is completed, update the corresponding row:

```text
| P04-T02 | 4 | Goal Types | 20.00% | [x] | 100% | 1.40% |
```

Then recalculate:

1. Task progress.
2. Phase progress.
3. Phase project contribution.
4. Overall project progress.
5. The overall progress bar.

---

# Phase Progress Dashboard

Use this compact dashboard during development:

| Phase | Weight | Progress | Contribution |
|---:|---:|---:|---:|
| 0 | 5% | 100% | 5.00% |
| 1 | 3% | 100% | 3.00% |
| 2 | 4% | 100% | 4.00% |
| 3 | 3% | 100% | 3.00% |
| 4 | 7% | 0% | 0.00% |
| 5 | 5% | 0% | 0.00% |
| 6 | 5% | 0% | 0.00% |
| 7 | 3% | 0% | 0.00% |
| 8 | 4% | 0% | 0.00% |
| 9 | 4% | 0% | 0.00% |
| 10 | 2% | 0% | 0.00% |
| 11 | 4% | 0% | 0.00% |
| 12 | 2% | 0% | 0.00% |
| 13 | 2% | 0% | 0.00% |
| 14 | 4% | 0% | 0.00% |
| 15 | 2% | 0% | 0.00% |
| 16 | 2% | 0% | 0.00% |
| 17 | 2% | 0% | 0.00% |
| 18 | 2% | 0% | 0.00% |
| 19 | 2% | 0% | 0.00% |
| 20 | 2% | 0% | 0.00% |
| 21 | 1% | 0% | 0.00% |
| 22 | 3% | 0% | 0.00% |
| 23 | 3% | 0% | 0.00% |
| 24 | 4% | 0% | 0.00% |
| 25 | 2% | 0% | 0.00% |
| 26 | 2% | 0% | 0.00% |
| 27 | 1% | 0% | 0.00% |
| 28 | 1% | 0% | 0.00% |
| 29 | 1% | 0% | 0.00% |
| 30 | 2% | 0% | 0.00% |
| 31 | 2% | 0% | 0.00% |
| 32 | 3% | 0% | 0.00% |
| 33 | 3% | 0% | 0.00% |
| 34 | 2% | 0% | 0.00% |
| 35 | 1% | 0% | 0.00% |
| **TOTAL** | **100%** | **15%** | **15.00%** |


# Phase Completion Rule

A phase is complete only when:

- all tasks are implemented
- every task in the phase is at **100%**
- the calculated phase progress is **100%**
- tests pass
- lint/build passes
- documentation is updated
- no known blocker remains
- acceptance criteria are satisfied
- the next phase has its prerequisites available
- the phase's project contribution has been recalculated

After phase completion, update the Overall Project Progress section.

---

# MVP Release Definition

The first production release is complete when all of the following work is complete **and Overall Project Progress reaches 100%**:

**Phase Weight:** 1%  
**Phase Progress:** 0%  
**Project Contribution:** 0.00%  

```text
Phase 35: ░░░░░░░░░░░░░░░░░░░░ 0%
```

## Goals

- amount
- quantity
- category
- multiple goals
- milestones

## Rewards

- free shipping
- percentage discount
- fixed discount

## Frontend

- cart
- mini-cart
- checkout
- responsive progress UI
- dynamic messages
- AJAX updates

## Admin

- React dashboard
- goals CRUD
- goal builder
- campaign basics
- settings
- preview

## Smart Upsell

- product suggestions
- remaining-value matching

## Reliability

- security
- WooCommerce compatibility
- tests
- performance
- RTL/i18n

---

# Post-MVP Backlog

Prioritize:

1. Free gift.
2. Customer-selected gifts.
3. Advanced conditions.
4. Shipping-zone rules.
5. Customer segmentation.
6. Countdown.
7. Sticky bar.
8. Campaign templates.
9. Analytics dashboard.
10. A/B testing.
11. Revenue attribution.
12. Smart goal recommendation.
13. Margin-aware recommendations.
14. AI campaign optimization.

---

# Final Success Metric

The product is not considered successful merely because the progress bar renders.

The primary product objective is:

> Increase Average Order Value and conversion by encouraging customers to add incremental products to their cart.

Every major feature should therefore be evaluated against:

- Does it reduce friction?
- Does it make the next purchase step obvious?
- Does it increase goal completion?
- Does it increase cart value?
- Does it improve conversion?
- Does it preserve performance?
- Does it avoid harming checkout UX?

The plugin should evolve from a "cart progress bar" into a reusable **WooCommerce Cart Revenue Optimization Engine**.
