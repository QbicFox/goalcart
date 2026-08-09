# Goal Cart — WooCommerce Compatibility

> **Phase 19 / Tasks P19-T01–T02.** The WooCommerce compatibility contract:
> what is tested, on which surfaces, and why the plugin only ever relies on
> WooCommerce's supported public hooks and APIs — never undocumented
> internals. Verified against the installed WooCommerce version with
> `php tests/woocommerce-compatibility-test.php` (all checks green).

---

## 1. Support Matrix

| WooCommerce surface | Classic template | Blocks (storefront) |
|---|---|---|
| **Cart** | `woocommerce_before_cart` → full widget | Cart Block → `render_block('woocommerce/cart')` appends the full widget |
| **Checkout** | `woocommerce_before_checkout_form` → full widget | Checkout Block → `render_block('woocommerce/checkout')` appends the full widget |
| **Mini Cart** | `woocommerce_after_mini_cart` → compact widget (fragment refresh re-mounts) | Mini Cart Block → `render_block('woocommerce/mini-cart')` appends a compact widget |
| **Shop / product pages** | `woocommerce_archive_description` + `woocommerce_single_product_summary` compact widgets | Shortcode `[goalcart_progress]` works inside Gutenberg content |
| **Sticky bar** | `wp_footer` widget (`#goalcart-sticky`) | Same footer bar (location-agnostic) |

Every display location renders **at most once per page**: the duplicate-render
registry is shared between the classic actions and the `render_block` filter,
so a classic + block hybrid page can never show the widget twice.

## 2. Product & Pricing Surfaces

| Surface | Behavior | Where |
|---|---|---|
| Variable products | Categories resolve from the **parent** product (WooCommerce convention) | `CartContext::from_cart()` + `CartIntegration::load_categories()` |
| Product variations | `variation_id` preserved on the context item; product goals match by the **effective** id (variation → parent fallback) | `Goals\CartItem::effective_product_id()` |
| Coupons | Cart-order coupon apply/remove invalidate the memoized context; discount bases use line totals | `CartIntegration` lifecycle hooks; `CartContext::from_cart()` |
| Sale prices | `calculation_include_sale` drops on-sale products from the snapshot | `settings-test` §3.4 |
| Taxes | `calculation_include_tax` folds line taxes into money bases | `settings-test` §3.1 |
| Virtual/downloadable | `calculation_include_virtual` drops them from the snapshot | `settings-test` §3.5 |

## 3. Shipping Compatibility

- **Public API only:** free-shipping rewards hook `woocommerce_package_rates`
  and resolve zones through `WC_Shipping_Zones::get_zone_matching_package()`
  (`includes/Rewards/Applicators/FreeShippingApplicator.php`).
- Zone and/or shipping-method restrictions (`flat_rate`, `flat_rate:3`) compose
  with the store's existing shipping zones and methods; the store's own
  `free_shipping` method settings are never modified.
- Multiple shipping methods: each active reward is matched per
  method-instance spec, so one goal can free one method while another stays
  paid.

## 4. Users & Orders (HPOS)

- **Guest checkout** — `CartContext::is_guest()` is the default for anonymous
  carts; the frontend `/progress` REST endpoint is public by design (guest
  shoppers must read their own progress) and rate limited per IP.
- **Logged-in users** — `user_id` is captured on the snapshot; customer
  conditions in goal builder evaluate against it.
- **HPOS (custom order tables)** — the plugin declares compatibility via the
  public `Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility()`
  in `Plugin::declare_feature_compatibility()` (hooked to
  `before_woocommerce_init`). Analytics read Goal Cart's own tables; no
  WooCommerce order tables are read or written directly.

## 5. Public Hook / API Contract (P19-T02)

Only supported, public WooCommerce hooks and APIs are used:

| Hook / API | Purpose |
|---|---|
| `woocommerce_cart_loaded_from_session`, `woocommerce_add_to_cart`, `woocommerce_cart_item_removed`, `woocommerce_cart_item_restored`, `woocommerce_after_cart_item_quantity_update` | Cart-change invalidation |
| `woocommerce_applied_coupon`, `woocommerce_removed_coupon` | Coupon-change invalidation |
| `woocommerce_shipping_method_chosen`, `woocommerce_store_api_cart_select_shipping_rate` | Shipping-change invalidation (classic + Store API) |
| `woocommerce_checkout_update_order_review` | Checkout AJAX refresh |
| `woocommerce_before_calculate_totals`, `woocommerce_cart_calculate_fees`, `woocommerce_package_rates` | Reward reconciliation on the live cart |
| `woocommerce_before_cart`, `woocommerce_before_checkout_form`, `woocommerce_after_mini_cart`, `woocommerce_archive_description`, `woocommerce_single_product_summary` | Classic storefront injection |
| `render_block` (WordPress) | Cart / Checkout / Mini Cart **Block** injection |
| `WC_Cart::get_cart()`, `fees_api()`, `WC_Shipping_Zones`, `WC_Shipping_Rate`, `WC_Product` public getters | Cart + product data access |
| `FeaturesUtil::declare_compatibility()` | HPOS feature declaration |

No private or version-locked WooCommerce properties, tables, or methods are
relied on. The compatibility suite pins the public-hook wiring so a WooCommerce
update that breaks a supported API fails the test before a shopper sees it.

## 6. WordPress / PHP Support Matrix (P20)

The exact version contract is pinned in the plugin header
(`goalcart.php`) and enforced by `includes/Compatibility.php`:

| Component | Minimum | Tested | Enforced by |
|---|---|---|---|
| WordPress | 6.3 | 7.0 | `Compatibility::REQUIRED_WP` — version gate on `plugins_loaded` |
| PHP | 7.4 | 8.4 | `Compatibility::REQUIRED_PHP` — version gate on `plugins_loaded` |
| WooCommerce | 8.0 | 11.0 | `Compatibility::REQUIRED_WC` — version gate on `plugins_loaded` |

When any requirement fails the plugin stops booting its admin UI, shows a
single admin notice listing exactly what is missing, and never registers
REST routes or storefront widgets (`Admin::register_menu()` gate).

- **Multisite** — supported. All tables use `$wpdb->prefix` per site
  (`Schema::table()`), schema version + settings live in per-site
  WordPress options, and analytics sessions are per-site.
- **Localization** — translatable via the `goalcart` text domain
  (`load_plugin_textdomain` on `init`, domain path `/languages`); every
  PHP string uses `__()`/`_e()` with the domain.
- **RTL** — the admin dashboard mount sets an explicit `dir` attribute
  from `is_rtl()` and the storefront config exposes `isRtl` to widgets.
- **Admin capabilities** — menu and REST endpoints default to
  `manage_options`, both filterable (`goalcart_admin_capability`,
  `goalcart_rest_capability`).
- **Activation / deactivation** — `Installer::activate` /
  `Installer::deactivate` are registered on the WP hooks; upgrades run
  via `Installer::maybe_upgrade` on every load.

## 7. Test Command

```bash
php tests/woocommerce-compatibility-test.php   # P19 matrix (29 checks)
php tests/wordpress-compatibility-test.php     # P20 matrix (28 checks)
php tests/frontend-test.php                    # widget injection incl. blocks
php tests/cart-integration-test.php            # lifecycle + caching
```