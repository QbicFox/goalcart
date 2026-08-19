# FaraCart — Compatibility

## Support Matrix

### WordPress / PHP / WooCommerce

| Component | Minimum | Tested | Enforced by |
|---|---|---|---|
| WordPress | 6.3 | 7.0 | `Compatibility::REQUIRED_WP` |
| PHP | 7.4 | 8.4 | `Compatibility::REQUIRED_PHP` |
| WooCommerce | 8.0 | 11.0 | `Compatibility::REQUIRED_WC` |

When any requirement fails, the plugin stops booting, shows an admin notice, and never registers REST routes or storefront widgets.

### WooCommerce Surfaces

| Surface | Classic template | Blocks |
|---|---|---|
| Cart | `woocommerce_before_cart` → full widget | Cart Block → `render_block` |
| Checkout | `woocommerce_before_checkout_form` → full widget | Checkout Block → `render_block` |
| Mini Cart | `woocommerce_after_mini_cart` → compact widget | Mini Cart Block → `render_block` |
| Shop / product | `woocommerce_archive_description` + `woocommerce_single_product_summary` | Shortcode `[faracart_progress]` |
| Sticky bar | `wp_footer` widget | Same footer bar |

### Product & Pricing

| Surface | Behavior |
|---|---|
| Variable products | Categories resolve from parent product |
| Product variations | `variation_id` preserved; missions match effective id |
| Coupons | Apply/remove invalidate memoized context |
| Sale prices | `calculation_include_sale` drops on-sale products |
| Taxes | `calculation_include_tax` folds line taxes into money bases |
| Virtual/downloadable | `calculation_include_virtual` drops them |

### Shipping

- Public API only: `woocommerce_package_rates`
- Zone/method restrictions compose with existing shipping settings
- Store's own `free_shipping` method settings never modified

### Users & Orders

- **Guest checkout** — `CartContext::is_guest()` for anonymous carts
- **Logged-in users** — `user_id` captured on snapshot
- **HPOS** — declared compatible via `FeaturesUtil::declare_compatibility()`

## Page Builder & Block Compatibility

| Builder | Integration | Mechanism |
|---|---|---|
| Gutenberg | `faracart/progress` block | Server-side `register_block_type()` |
| WooCommerce Blocks | Cart / Checkout / Mini Cart blocks | `render_block` filter |
| Elementor | Shortcode element | `[faracart_progress]` |
| Bricks | Shortcode element | `[faracart_progress]` |

## Public Hook Contract

Only supported, public WooCommerce hooks are used:

| Hook | Purpose |
|---|---|
| `woocommerce_cart_loaded_from_session` | Cart initialization |
| `woocommerce_add_to_cart` | Item added |
| `woocommerce_cart_item_removed` | Item removed |
| `woocommerce_cart_item_restored` | Item restored |
| `woocommerce_after_cart_item_quantity_update` | Quantity changed |
| `woocommerce_applied_coupon` / `woocommerce_removed_coupon` | Coupon changes |
| `woocommerce_shipping_method_chosen` | Shipping changed (classic) |
| `woocommerce_store_api_cart_select_shipping_rate` | Shipping changed (Blocks) |
| `woocommerce_before_calculate_totals` | Reward reconciliation |
| `woocommerce_cart_calculate_fees` | Discount fees |
| `woocommerce_package_rates` | Free shipping |
| `woocommerce_before_cart` | Cart page injection |
| `woocommerce_before_checkout_form` | Checkout injection |
| `woocommerce_after_mini_cart` | Mini cart injection |
| `woocommerce_archive_description` | Shop page injection |
| `woocommerce_single_product_summary` | Product page injection |
| `render_block` | Block injection |
| `before_woocommerce_init` | HPOS declaration |

## Multisite

Supported. All tables use `$wpdb->prefix` per site. Schema version + settings live in per-site options.

## RTL

- Admin: `dir="rtl"` + MUI theme direction + Emotion cache flip
- Storefront: `isRtl` config + logical CSS properties only
- No physical `left`/`right` properties in widget CSS

## Localization

- Text domain: `faracart`
- PHP: `__()` / `_e()` with domain
- React: `@wordpress/i18n` shim + JED JSON
- POT extraction: `php bin/extract-pot.php`
- Build: `php bin/build-i18n.php`

## Admin Capabilities

- Menu and REST default to `manage_options`
- Both filterable: `faracart_admin_capability`, `faracart_rest_capability`

## Test Commands

```bash
php tests/woocommerce-compatibility-test.php   # WC matrix (29 checks)
php tests/wordpress-compatibility-test.php     # WP matrix (28 checks)
php tests/frontend-test.php                    # Widget injection + blocks
php tests/cart-integration-test.php            # Lifecycle + caching
```
