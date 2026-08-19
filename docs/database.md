# FaraCart — Database & Domain Model

> **Phase 3 / Tasks P03-T01–T03.** Defines the persistence layer: the Mission, Campaign and Analytics
> Event domain entities, their tables, and the migration strategy. The schema is authoritative in
> `includes/Database/Schema.php`; this document explains the model and the rules it follows.

---

## 1. Domain entities

### 1.1 Mission

A target the customer can reach (see `docs/PRODUCT_SPEC.md` §2.1). Stored in the `faracart_missions` table.

| Domain field | Column | Notes |
|---|---|---|
| id | `id` | `bigint(20) unsigned AUTO_INCREMENT` |
| name | `name` | `varchar(191)` |
| internal description | `description` | `text`, nullable |
| status | `status` | `varchar(20)`, `'active'` \| `'inactive'`, default `active` |
| type | `type` | `varchar(20)`, e.g. `amount` \| `quantity` \| `category` (MVP), extended in Phase 4 |
| target | `target` | `decimal(19,4)` — the threshold |
| calculation mode | `calculation_mode` | `varchar(20)`, default `subtotal` (tax/discount/shipping basis, Phase 18) |
| reward | `reward_type` / `reward_value` / `reward_max_value` / `reward_meta` | MVP embeds one reward per mission; `reward_meta` is JSON for extended config (eligible products/categories, stacking rules) |
| conditions | `conditions` | JSON — category/product/role/cart conditions (grow in later phases) |
| display settings | `display_settings` | JSON — title, message, completed message, icon, plus the pluggable-template-engine keys `template_id` + `template_settings` (the retired Phase 12 `template` key is no longer read by the engine) |
| priority | `priority` | `int(10) unsigned`, default `10` — conflict resolution (Phase 26) |
| exclusive | `exclusive` | `tinyint(1)`, default `0` — mutually exclusive mission: when reached, lower-priority missions are skipped (Phase 26) |
| per-user completion limit | `max_completions_per_user` | `int(10) unsigned`, nullable — how many times the same shopper may complete this mission; `NULL` = unlimited (Phase 36) |
| schedule | `starts_at` / `ends_at` | `datetime`, nullable — independent scheduling |
| campaign membership | `campaign_id` + `menu_order` | `menu_order` expresses milestone ordering inside a campaign (Phase 10) |
| limits | `limits` | JSON — e.g. per-customer, per-session, stack limits |
| timestamps | `created_at` / `updated_at` | `datetime`, site timezone |

### 1.2 Campaign

A collection of missions active under specific conditions and schedules (`docs/PRODUCT_SPEC.md` §2.3).
Stored in the `faracart_campaigns` table.

| Domain field | Column | Notes |
|---|---|---|
| id | `id` | `bigint(20) unsigned AUTO_INCREMENT` |
| name | `name` | `varchar(191)` |
| description | `description` | `text`, nullable |
| status | `status` | `varchar(20)`, default `active` |
| start date / end date | `starts_at` / `ends_at` | `datetime`, nullable — drives automatic activation |
| priority | `priority` | `int(10) unsigned`, default `10` |
| missions | *(relation)* | `missions.campaign_id` → `campaigns.id` (1:N), ordered by `menu_order` |
| display rules | `display_rules` | JSON — where/how the campaign's missions render |

### 1.3 Analytics Event

Append-only event log (`docs/PRODUCT_SPEC.md` §5.5 / Phase 16). Stored in `faracart_analytics_events`.

| Domain field | Column | Notes |
|---|---|---|
| id | `id` | `bigint(20) unsigned AUTO_INCREMENT` |
| mission / campaign | `mission_id` / `campaign_id` | nullable FKs |
| event type | `event_type` | `varchar(40)` — `mission_impression`, `mission_progress`, `mission_completed`, `reward_activated`, `suggestion_impression`, `suggestion_clicked`, `suggested_product_added` |
| session / customer | `session_id` (32-char anon id), `user_id` | privacy-first: anonymous session id, never raw IP |
| cart/order context | `cart_value` `decimal(19,4)`, `order_id`, `product_id` | order/product reference WooCommerce data by ID as plain indexed columns (no FKs into WC tables — see §2) |
| value | `cart_value` + `meta` | `meta` JSON carries event-specific payload (e.g. `percentage`) |
| timestamp | `created_at` | `datetime`, site timezone |

**Write path (Phase 16):** six events are recorded by `FaraCart\Analytics\Tracker`
when the storefront JS reports them to the public `POST /faracart/v1/track`
endpoint (nonce-guarded); `suggested_product_added` is attributed
server-side on `woocommerce_add_to_cart` (only when the session saw a
`suggestion_impression` for that product within 24h). Rows carry only
aggregate numbers, ids and the anonymous session token — no PII. The
`Tracker` retries an insert once without the FK ids when a referenced
mission/campaign was deleted between impression and report (the FK's
`SET NULL` semantics for a deleted parent), so events are never silently
dropped.

### 1.4 Revenue Event (Phase 33.1)

Raw attribution-funnel log (P33.1). Stored in `faracart_revenue_events` and written by
`FaraCart\Analytics\RevenueTracker` — deliberately separate from `analytics_events`: those rows
are the lightweight Phase 16 dashboard counters, while `revenue_events` carries the attribution
fields (`mission_target`, `incremental_value`) plus order ids and feeds Phase 33.2 attribution.

| Domain field | Column | Notes |
|---|---|---|
| id | `id` | `bigint(20) unsigned AUTO_INCREMENT` |
| event type | `event_type` | `varchar(40)` — `mission_view`, `mission_progress`, `mission_completed`, `order_paid`, `cart_value` |
| mission / campaign / product | `mission_id` / `campaign_id` / `product_id` | nullable ids (no FKs into WC tables — see §2) |
| order | `order_id` | nullable, plain indexed column (HPOS-safe) |
| session / user | `session_id` (32-char anon id), `user_id` | privacy-first, mirrors §1.3 |
| cart value | `cart_value` `decimal(19,4)` | cart value at event time |
| mission target | `mission_target` `decimal(19,4)` | the mission's threshold at event time |
| incremental value | `incremental_value` `decimal(19,4)` | e.g. the cart increase attributed to the mission |
| extra payload | `meta` | scalar-only JSON (percentage, quantity, …) |
| timestamp | `created_at` | `datetime`, site timezone |

### 1.5 Upsell Event (Phase 33.1)

Raw upsell-interaction log (P33.1), stored in `faracart_upsell_events` — the historical-conversion
input for the Phase 33.5 Smart Upsell ranking.

| Domain field | Column | Notes |
|---|---|---|
| id | `id` | `bigint(20) unsigned AUTO_INCREMENT` |
| event type | `event_type` | `varchar(40)` — `upsell_impression`, `upsell_clicked`, `upsell_added`, `upsell_order` |
| mission / product / order | `mission_id` / `product_id` / `order_id` | nullable ids |
| session / user | `session_id` / `user_id` | anonymous session id, logged-in user id |
| cart value | `cart_value` `decimal(19,4)` | cart value at event time |
| extra payload | `meta` | scalar-only JSON |
| timestamp | `created_at` | `datetime`, site timezone |

**Write path (P33.1):** `RevenueTracker::record()` / `record_upsell()` with strict whitelists and
idempotent dedup — views/completions/impressions/clicks dedup per session+mission(+product) within a
24 h window, `mission_progress` within 30 min, and `order_paid` / `upsell_order` exactly once per
order. The `order_dedup` unique key on `(event_type, order_id)` backs the per-order contract at the
database level (concurrent double-reports fail the INSERT). Events reported after their mission was
deleted are retried once without the FK ids so they are never silently dropped (the FK's `SET NULL`
semantics for a deleted parent). A weekly `faracart_revenue_cleanup` cron purges rows past the
retention window (`RevenueTracker::RETENTION_DAYS`, filterable via
`faracart_revenue_retention_days`) in bounded batches and sweeps orphan `upsell_stats` rows.

### 1.6 Daily Revenue Aggregate (Phase 33.3)

One row per mission per day in `faracart_revenue_daily`, pre-computed by
`FaraCart\Analytics\DailyAggregator` on the `daily` cron interval so the dashboard reads an
aggregated table instead of scanning the raw event log on every admin request.

| Domain field | Column | Notes |
|---|---|---|
| date | `report_date` | `date` — the aggregated day |
| mission | `mission_id` | nullable FK (`SET NULL` on mission deletion) |
| funnel | `views` / `progressions` / `completions` / `conversions` | `int unsigned` — mission_view / mission_progress / mission_completed counts and distinct attributed orders |
| revenue | `revenue` `decimal(19,4)` | totals of the orders the mission influenced that day |
| incremental | `incremental_revenue` `decimal(19,4)` | direct (driven) incremental value |
| cost | `reward_cost` `decimal(19,4)` | estimated reward cost of the day's completed missions |
| profit | `estimated_profit` `decimal(19,4)` | estimated profit impact (0 when margin data is unavailable) |
| timestamps | `created_at` / `updated_at` | `datetime`, site timezone |

**Write path (P33.3):** the aggregator computes each day through
`AttributionEngine::daily_metrics()` — the same funnel + summary + reward-cost + profit code the
live dashboard reads, so the aggregate and the live view can never drift. Only missions with activity
that day get rows; rows are delete-then-inserted per date, making re-runs idempotent. Catch-up is
bounded: the job starts the day after `faracart_revenue_last_aggregated` (or the
`faracart_aggregate_lookback_days` floor, aligned with the retention window) and processes at most
`faracart_aggregate_max_days` per tick, so a backlog drains over several runs.

### 1.7 Product Upsell Stats (Phase 33.3)

One row per product in `faracart_upsell_stats`, rebuilt wholesale by `DailyAggregator::aggregate_upsells()`
with a single grouped INSERT...SELECT over the raw `upsell_events` log — the historical conversion
signal the Phase 33.5 Smart Upsell ranking reads.

| Domain field | Column | Notes |
|---|---|---|
| product | `product_id` | unique — one row per product |
| funnel | `impressions` / `clicks` / `adds` / `orders` | `int unsigned` |
| revenue | `revenue` `decimal(19,4)` | sum of order cart values |
| timestamp | `updated_at` | `datetime`, site timezone |

**Read path (P33.3):** `FaraCart\Analytics\RevenueRepository` serves the cached summaries
(`overview`, `mission_performance`, `daily_trend`, `product_stats`) with generation-versioned
transients (`faracart_revenue_cache_version`) and invalidates them on order payment/status
changes, mission CRUD (`faracart_missions_changed`), product saves and the aggregation run
(`faracart_revenue_aggregated`).

### 1.8 Mission Completion (Phase 36)

Server-side completion history — the authoritative record backing the per-user completion limit.
Stored in `faracart_mission_completions`, written by `FaraCart\Missions\CompletionService` when a paid
order meets a mission (one row per mission per order per identity). Deliberately separate from the
analytics/revenue event logs: those are client-reported or analytics-gated + deduped, so they can
never back an enforcement limit.

| Domain field | Column | Notes |
|---|---|---|
| id | `id` | `bigint(20) unsigned AUTO_INCREMENT` |
| mission | `mission_id` | FK → `missions.id`, `SET NULL` |
| customer | `user_id` | logged-in order customer id, `NULL` for guests |
| guest session | `session_id` | anonymous `Session` id (32-char), `NULL` for logged-in orders |
| order | `order_id` | WooCommerce order id — the `order_mission` unique key makes recording exactly-once per order |
| reward | `reward_type` | the mission's reward type at completion (informational) |
| extra | `meta` | `longtext` JSON, reserved |
| timestamp | `created_at` | `datetime`, site timezone |

**Write path (Phase 36):** `woocommerce_checkout_create_order` stamps the anonymous session id
on the order (`_faracart_session`); `woocommerce_payment_complete` + `woocommerce_order_status_completed`
record one completion per met mission for the order's identity (idempotent — the `order_mission` unique
key makes replays no-ops). For a limited mission the count + insert run inside a transaction with a
row lock on the mission (`SELECT ... FOR UPDATE`), so concurrent requests cannot exceed the limit.
The per-user count is a plain indexed `COUNT(*)` over `mission_id + user_id` / `mission_id + session_id`
(`mission_user` / `mission_session` composite keys). Progress (the current cart cycle) and the completion
count (successful cycles) stay separate: recording never touches progress, and progress resets
never touch this table.

---

## 2. Schema summary

| Table | Purpose | Key indexes |
|---|---|---|
| `{prefix}faracart_missions` | Mission entities | `status`, `type`, `campaign_id`, `priority`, `starts_at`, `ends_at` |
| `{prefix}faracart_campaigns` | Campaign entities | `status`, `starts_at`, `ends_at` |
| `{prefix}faracart_analytics_events` | Analytics event log (Phase 16) | `mission_id`, `campaign_id`, `event_type`, `session_id`, `product_id`, `order_id`, `created_at` |
| `{prefix}faracart_revenue_events` | Revenue attribution log (Phase 33.1) | `event_type`, `mission_id`, `campaign_id`, `product_id`, `order_id`, `session_id`, `user_id`, `created_at` + composite `mission_event` / `order_event` + unique `order_dedup` |
| `{prefix}faracart_revenue_daily` | Daily revenue aggregates (Phase 33.1, fed by Phase 33.3) | `mission_id`, `report_date` + composite `mission_date` |
| `{prefix}faracart_mission_attribution` | Per-order mission attribution (Phase 33.1) | `order_id`, `mission_id`, `session_id`, `model`, `created_at` + unique `order_mission_model` |
| `{prefix}faracart_upsell_events` | Upsell interaction log (Phase 33.1) | `event_type`, `mission_id`, `product_id`, `order_id`, `session_id`, `created_at` + composite `product_event` + unique `order_dedup` |
| `{prefix}faracart_upsell_stats` | Per-product upsell aggregates (Phase 33.1) | unique `product_id` |
| `{prefix}faracart_mission_completions` | Per-user completion history (Phase 36) | `mission_id`, `user_id`, `session_id`, `order_id`, `created_at` + composite `mission_user` / `mission_session` + unique `order_mission` |

All tables: InnoDB, `$wpdb->get_charset_collate()`, `bigint(20) unsigned AUTO_INCREMENT` PKs,
`decimal(19,4)` for money, `datetime` in the **site timezone** (`current_time()`), `longtext` for
structured JSON. This mirrors the reference plugin's conventions exactly.

### Foreign keys (applied by `Installer` via ALTER TABLE)

| Name | Column | References | On delete |
|---|---|---|---|
| `fk_faracart_missions_campaign` | `missions.campaign_id` | `campaigns.id` | `SET NULL` |
| `fk_faracart_analytics_mission` | `analytics_events.mission_id` | `missions.id` | `SET NULL` |
| `fk_faracart_analytics_campaign` | `analytics_events.campaign_id` | `campaigns.id` | `SET NULL` |
| `fk_faracart_revenue_mission` | `revenue_events.mission_id` | `missions.id` | `SET NULL` |
| `fk_faracart_revenue_campaign` | `revenue_events.campaign_id` | `campaigns.id` | `SET NULL` |
| `fk_faracart_daily_mission` | `revenue_daily.mission_id` | `missions.id` | `SET NULL` |
| `fk_faracart_attribution_mission` | `mission_attribution.mission_id` | `missions.id` | `SET NULL` |
| `fk_faracart_upsell_mission` | `upsell_events.mission_id` | `missions.id` | `SET NULL` |
| `fk_faracart_completions_mission` | `mission_completions.mission_id` | `missions.id` | `SET NULL` |

`SET NULL` preserves analytics history and standalone missions when a parent is deleted.

**No foreign keys into WooCommerce tables.** `analytics_events.product_id` and `order_id` are plain
indexed columns, never FKs: since WC 8.2 orders live in the High-Performance Order Storage tables
(`wc_orders`) rather than `wp_posts`, so a posts FK would enforce nothing meaningful for HPOS orders —
and regardless of target, an FK would either block WooCommerce's own product/order deletion flows
(`RESTRICT`) or silently cascade-delete analytics history (`CASCADE`). ID-only references mirror the
reference plugin's convention.

> **Migration note:** `dbDelta()` only manages column definitions — it never drops or alters foreign
> keys. If an FK definition ever needs to change, add an explicit version-gated
> `ALTER TABLE ... DROP FOREIGN KEY` / `ADD CONSTRAINT` step in `Installer` (not needed yet: all
> current FKs are additive-only).

---

## 3. Database rules applied (P03-T03)

1. **Reference migration strategy** — version-driven: `FARACART_DB_VERSION` (now `0.6.0` —
   Phase 36's `missions.max_completions_per_user` column + `mission_completions` table on top of
   Phase 33.1's five revenue/upsell tables, the Phase 12 template engine migration and Phase 26's
   `missions.exclusive`) vs the `faracart_db_version` option; `Installer::maybe_upgrade()`
   runs on `plugins_loaded` + `admin_init`; schema recreated idempotently via `dbDelta`; foreign
   keys added with `INFORMATION_SCHEMA`-guarded `ALTER TABLE` (safe to re-run; failures logged,
   never fatal).

   **0.4.0** — the pluggable template engine introduced
   `display_settings.template_id` + `template_settings` as the storage shape for missions. The old
   Phase 12 `display_settings.template` ids (`basic` / `percentage` / `milestone` / `card` / `ring`)
   are no longer registered and are never mapped to a current template — a persisted old id simply
   falls back to the scope default / store-wide template (`template-1`).
2. **Proper indexes** — every query path is covered: status/type filters, campaign grouping,
   date-range scans, event-type/session/order lookups.
3. **No duplicated WooCommerce data** — products/orders are referenced by ID only (plain indexed
   columns, no FKs into WC tables — HPOS moved orders out of `wp_posts` in WC 8.2+); no product
   names, prices, or order details are copied into plugin tables.
4. **Structured JSON only where schema flexibility is required** — `conditions`, `display_settings`,
   `reward_meta`, `limits`, `display_rules`, `analytics.meta` are `longtext` JSON because their
   shapes grow across phases; relational columns are used everywhere else (no JSON columns where a
   real column fits).
5. **No unnecessary sensitive data** — sessions are anonymous 32-char ids; raw IPs, emails and
   personal data are never stored; `user_id` only when the shopper is logged in.
6. **Safe, repeatable upgrades** — all migrations are idempotent and additive; `uninstall()`
   drops only plugin tables + options; deactivation preserves data.
7. **Settings via option, not table** — the reference declares an unused settings *table* while its
   service uses an option; FaraCart keeps a single `faracart_settings` option (no duplicated storage).

## 4. Design decisions & deviations

| Decision | Rationale |
|---|---|
| MVP reward embedded on `missions` | MVP ships exactly one reward per mission; a standalone `rewards` table can be extracted later without schema breakage. |
| Campaign membership via `missions.campaign_id` + `menu_order` | 1:N relation expresses milestone ordering simply; no join table needed in MVP. |
| `SET NULL` for plugin-parent FKs | Deleting a campaign or mission must not destroy analytics history or orphan-*activate* missions; the service layer decides deactivation behavior. |
| No FKs into WooCommerce tables | Orders live in HPOS tables (not `wp_posts`) since WC 8.2, and an FK would block WC deletion flows or cascade-wipe analytics. ID-only indexed columns keep the plugin decoupled from WC storage internals, matching the reference. |
| No settings table | Avoids the reference's unused settings table; single option matches the reference *service* pattern. |
| `longtext` JSON (not MySQL `JSON` type) | Works on all supported MySQL/MariaDB versions and matches reference conventions. |
