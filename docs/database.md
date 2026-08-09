# Goal Cart — Database & Domain Model

> **Phase 3 / Tasks P03-T01–T03.** Defines the persistence layer: the Goal, Campaign and Analytics
> Event domain entities, their tables, and the migration strategy. The schema is authoritative in
> `includes/Database/Schema.php`; this document explains the model and the rules it follows.

---

## 1. Domain entities

### 1.1 Goal

A target the customer can reach (see `docs/PRODUCT_SPEC.md` §2.1). Stored in the `goalcart_goals` table.

| Domain field | Column | Notes |
|---|---|---|
| id | `id` | `bigint(20) unsigned AUTO_INCREMENT` |
| name | `name` | `varchar(191)` |
| internal description | `description` | `text`, nullable |
| status | `status` | `varchar(20)`, `'active'` \| `'inactive'`, default `active` |
| type | `type` | `varchar(20)`, e.g. `amount` \| `quantity` \| `category` (MVP), extended in Phase 4 |
| target | `target` | `decimal(19,4)` — the threshold |
| calculation mode | `calculation_mode` | `varchar(20)`, default `subtotal` (tax/discount/shipping basis, Phase 18) |
| reward | `reward_type` / `reward_value` / `reward_max_value` / `reward_meta` | MVP embeds one reward per goal; `reward_meta` is JSON for extended config (eligible products/categories, stacking rules) |
| conditions | `conditions` | JSON — category/product/role/cart conditions (grow in later phases) |
| display settings | `display_settings` | JSON — title, message, completed message, icon, template |
| priority | `priority` | `int(10) unsigned`, default `10` — conflict resolution (Phase 26) |
| exclusive | `exclusive` | `tinyint(1)`, default `0` — mutually exclusive goal: when reached, lower-priority goals are skipped (Phase 26) |
| schedule | `starts_at` / `ends_at` | `datetime`, nullable — independent scheduling |
| campaign membership | `campaign_id` + `menu_order` | `menu_order` expresses milestone ordering inside a campaign (Phase 10) |
| limits | `limits` | JSON — e.g. per-customer, per-session, stack limits |
| timestamps | `created_at` / `updated_at` | `datetime`, site timezone |

### 1.2 Campaign

A collection of goals active under specific conditions and schedules (`docs/PRODUCT_SPEC.md` §2.3).
Stored in the `goalcart_campaigns` table.

| Domain field | Column | Notes |
|---|---|---|
| id | `id` | `bigint(20) unsigned AUTO_INCREMENT` |
| name | `name` | `varchar(191)` |
| description | `description` | `text`, nullable |
| status | `status` | `varchar(20)`, default `active` |
| start date / end date | `starts_at` / `ends_at` | `datetime`, nullable — drives automatic activation |
| priority | `priority` | `int(10) unsigned`, default `10` |
| goals | *(relation)* | `goals.campaign_id` → `campaigns.id` (1:N), ordered by `menu_order` |
| display rules | `display_rules` | JSON — where/how the campaign's goals render |

### 1.3 Analytics Event

Append-only event log (`docs/PRODUCT_SPEC.md` §5.5 / Phase 16). Stored in `goalcart_analytics_events`.

| Domain field | Column | Notes |
|---|---|---|
| id | `id` | `bigint(20) unsigned AUTO_INCREMENT` |
| goal / campaign | `goal_id` / `campaign_id` | nullable FKs |
| event type | `event_type` | `varchar(40)` — `goal_impression`, `goal_progress`, `goal_completed`, `reward_activated`, `suggestion_impression`, `suggestion_clicked`, `suggested_product_added` |
| session / customer | `session_id` (32-char anon id), `user_id` | privacy-first: anonymous session id, never raw IP |
| cart/order context | `cart_value` `decimal(19,4)`, `order_id`, `product_id` | order/product reference WooCommerce data by ID as plain indexed columns (no FKs into WC tables — see §2) |
| value | `cart_value` + `meta` | `meta` JSON carries event-specific payload (e.g. `percentage`) |
| timestamp | `created_at` | `datetime`, site timezone |

**Write path (Phase 16):** six events are recorded by `GoalCart\Analytics\Tracker`
when the storefront JS reports them to the public `POST /goalcart/v1/track`
endpoint (nonce-guarded); `suggested_product_added` is attributed
server-side on `woocommerce_add_to_cart` (only when the session saw a
`suggestion_impression` for that product within 24h). Rows carry only
aggregate numbers, ids and the anonymous session token — no PII. The
`Tracker` retries an insert once without the FK ids when a referenced
goal/campaign was deleted between impression and report (the FK's
`SET NULL` semantics for a deleted parent), so events are never silently
dropped.

---

## 2. Schema summary

| Table | Purpose | Key indexes |
|---|---|---|
| `{prefix}goalcart_goals` | Goal entities | `status`, `type`, `campaign_id`, `priority`, `starts_at`, `ends_at` |
| `{prefix}goalcart_campaigns` | Campaign entities | `status`, `starts_at`, `ends_at` |
| `{prefix}goalcart_analytics_events` | Analytics event log | `goal_id`, `campaign_id`, `event_type`, `session_id`, `product_id`, `order_id`, `created_at` |

All tables: InnoDB, `$wpdb->get_charset_collate()`, `bigint(20) unsigned AUTO_INCREMENT` PKs,
`decimal(19,4)` for money, `datetime` in the **site timezone** (`current_time()`), `longtext` for
structured JSON. This mirrors the reference plugin's conventions exactly.

### Foreign keys (applied by `Installer` via ALTER TABLE)

| Name | Column | References | On delete |
|---|---|---|---|
| `fk_goalcart_goals_campaign` | `goals.campaign_id` | `campaigns.id` | `SET NULL` |
| `fk_goalcart_analytics_goal` | `analytics_events.goal_id` | `goals.id` | `SET NULL` |
| `fk_goalcart_analytics_campaign` | `analytics_events.campaign_id` | `campaigns.id` | `SET NULL` |

`SET NULL` preserves analytics history and standalone goals when a parent is deleted.

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

1. **Reference migration strategy** — version-driven: `GOALCART_DB_VERSION` (now `0.3.0` —
   Phase 26 added `goals.exclusive`) vs the `goalcart_db_version` option;
   `Installer::maybe_upgrade()` runs on `plugins_loaded` + `admin_init`;
   schema recreated idempotently via `dbDelta`; foreign keys added with `INFORMATION_SCHEMA`-guarded
   `ALTER TABLE` (safe to re-run; failures logged, never fatal).
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
   service uses an option; Goal Cart keeps a single `goalcart_settings` option (no duplicated storage).

## 4. Design decisions & deviations

| Decision | Rationale |
|---|---|
| MVP reward embedded on `goals` | MVP ships exactly one reward per goal; a standalone `rewards` table can be extracted later without schema breakage. |
| Campaign membership via `goals.campaign_id` + `menu_order` | 1:N relation expresses milestone ordering simply; no join table needed in MVP. |
| `SET NULL` for plugin-parent FKs | Deleting a campaign or goal must not destroy analytics history or orphan-*activate* goals; the service layer decides deactivation behavior. |
| No FKs into WooCommerce tables | Orders live in HPOS tables (not `wp_posts`) since WC 8.2, and an FK would block WC deletion flows or cascade-wipe analytics. ID-only indexed columns keep the plugin decoupled from WC storage internals, matching the reference. |
| No settings table | Avoids the reference's unused settings table; single option matches the reference *service* pattern. |
| `longtext` JSON (not MySQL `JSON` type) | Works on all supported MySQL/MariaDB versions and matches reference conventions. |
