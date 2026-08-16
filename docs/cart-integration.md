# FaraCart — Cart Context & WooCommerce Integration

> **Phase 6 / Tasks P06-T01–T04.** The single, reliable source of truth for the
> live cart state. Phase 4 built the pure `CartContext` value object; Phase 5
> wired rewards into the WooCommerce totals pipeline; this phase formalizes how
> the live `WC_Cart` becomes a `CartContext` — memoized per request, invalidated
> on every cart lifecycle event, and built without repeating product queries.

---

## 1. Objective (P06-T01)

Every consumer of cart state (the reward engine today; the REST layer and
frontend in later phases) reads the **same snapshot** from one service:
`Cart\CartIntegration::context()`. It converts the live WooCommerce cart into the
normalized `Goals\CartContext` the goal engine needs, so a shopper sees the same
numbers everywhere and nothing rebuilds the snapshot redundantly.

## 2. Architecture (P06-T02)

```text
WC_Cart (live)
    ↓
CartIntegration::context()   single source of truth, memoized per request
    │  ├─ load_categories()  one batched term query (variations → parent)
    │  └─ CartContext::from_cart()
    ↓
CartContext (goals engine snapshot)
    ↓
RewardEngine / REST / frontend consumers
```

| Piece | Role |
|---|---|
| `Cart\CartIntegration` | Request-level memoized `context()`; lifecycle invalidation hooks; batched category preloading. Registered as a DI singleton (`Plugin::cart_integration()`). |
| `Goals\CartContext::from_cart()` | Pure-ish WC adapter; accepts the preloaded category map and resolves variation categories from the parent product. |
| `Rewards\RewardEngine` | Consumes `CartIntegration::context()` for its per-pass evaluation (falls back to a direct build when constructed without the service, e.g. in tests). |

### Lifecycle hooks (all invalidate the memoized context)

| Hook | Event |
|---|---|
| `woocommerce_cart_loaded_from_session` | Cart initialization / session restore |
| `woocommerce_add_to_cart` | Item added (classic cart and Blocks) |
| `woocommerce_cart_item_removed` | Item removed |
| `woocommerce_cart_item_restored` | Removed item restored |
| `woocommerce_after_cart_item_quantity_update` | Quantity changed |
| `woocommerce_applied_coupon` / `woocommerce_removed_coupon` | Coupon applied / removed |
| `woocommerce_shipping_method_chosen` | Shipping method changed (classic) |
| `woocommerce_checkout_update_order_review` | Checkout AJAX refresh |
| `woocommerce_store_api_cart_select_shipping_rate` | Blocks shipping-rate change |

**WooCommerce Blocks:** Store API cart mutations funnel through the classic
`WC_Cart` methods (`add_to_cart`, `remove_cart_item`, `set_quantity`,
`apply_coupon`, `remove_coupon`), so the classic hooks above cover Blocks;
the Store API shipping-rate route is hooked explicitly.

## 3. Cart Context (P06-T03)

- The memoized `CartContext` carries only the fields the Goal Engine reads
  (totals by basis, items, quantities, weights, categories, user/guest flags).
- **Category preloading:** `CartIntegration::load_categories()` collects every
  line's canonical product id and resolves them with **one**
  `wp_get_object_terms()` call; the map is passed to `from_cart()` so no
  per-item term queries run. WP core caches the object-term relations, so
  repeated builds stay cheap.
- **Variations:** categories are resolved from the **parent** product (the
  WooCommerce convention — categories live on the parent, not the variation),
  so category goals count variations correctly. The canonical id is the cart
  item's `product_id` (the parent id for variation lines).
- `from_cart()` falls back to per-item `wp_get_post_terms()` when no preloaded
  map is supplied (headless/tests), keeping the adapter self-sufficient.

## 4. Performance (P06-T04)

| Requirement | Implementation |
|---|---|
| Memoization | `context()` caches the built `CartContext` per request, keyed by the shopper-controlled line data + args. |
| Request-level caching | The cache is an instance property (one request = one PHP execution); `GoalRepository::active_goals()` and `GoalEngine` stay per-request cached. |
| Object cache | Category preloading uses `wp_get_object_terms()`, whose results WP stores in the object cache. |
| WooCommerce cart data | Everything is derived from the live cart (no duplicated storage). |
| Indexed queries | Goal lookups use the indexed `status`/`priority` columns (`docs/database.md`). |
| Preloaded data | Categories are batched per build, not loaded per item. |

Verified on the live store: a 6-item cart builds its context in **1 query**
(previously 6+); the memoized second build runs **0 queries**.

## 5. Edge cases

All covered by `tests/cart-integration-test.php` (22 checks, run with
`php tests/cart-integration-test.php`):

| Case | Behavior |
|---|---|
| Hook wiring | All 10 lifecycle hooks are live with the `invalidate` callback at priority 10 |
| Memoization | Repeated `context()` calls with an unchanged cart return the same instance |
| Invalidation | Any lifecycle hook clears the cache; the next read rebuilds |
| Args keying | Different args (`exclude_shipping`) get separate cache entries |
| Contents change | A changed line set rebuilds with the new values |
| Preloaded categories | The map keys by the canonical product id; variations inherit parent categories |
| Unknown product ids | Batched lookup degrades gracefully (no fatal, empty categories) |
| Custom REST request | Missing `WC()->cart` after WooCommerce init → load the WooCommerce session/cart once, then build the real `CartContext` |
| Null cart | WooCommerce unavailable, too early, or non-cart CLI/cron/admin context → an empty `CartContext` |

The live-cart behavior with real products (query count, variation inheritance)
was additionally verified against the real WordPress 7.0.2 + WooCommerce 11.0.0
environment (read-only; no products created, no database writes).

`php tests/cart-rest-initialization-test.php` is a database-independent
regression for the custom REST lifecycle: guest/member session restoration,
empty and populated carts, repeated reads, add/remove/quantity mutations, and
variation lines.

## 6. Design decisions

| Decision | Rationale |
|---|---|
| One memoized service, not static calls | Consumers share a single snapshot and the caching work; later phases (REST, frontend) reuse it instead of re-adapting the cart. |
| Cache key includes line values | A totals pass that updates line totals (coupon discounts) naturally changes the key, so cached snapshots are never stale w.r.t. what the shopper sees. |
| Invalidation, not per-read freshness checks | WC already fires a precise lifecycle hook for every mutation; hooking them is simpler and covers Blocks for free. |
| Parent-based variation categories | Matches WooCommerce's data model; category goals would otherwise silently miss variations. |
| Backward-compatible engine | `RewardEngine` still works without the service (tests/headless), so the Phase 5 test suite is untouched. |
