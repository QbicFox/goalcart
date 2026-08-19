# FaraCart — Troubleshooting

## Common Issues

### Widgets Not Showing

1. **Check master toggle:** Settings → General → Enable FaraCart
2. **Check locations:** Settings → Frontend → Display Locations (ensure the page's location is enabled)
3. **Check staff visibility:** Logged-in admins are hidden by default. Filter: `faracart_frontend_visible_to_user`
4. **Check page type:** Assets load only on cart/checkout/shop/product pages or pages with the `[faracart_progress]` shortcode
5. **Check compatibility:** WordPress ≥ 6.3, PHP ≥ 7.4, WooCommerce ≥ 8.0

### Progress Not Updating

1. **Check AJAX events:** Widgets refetch on `added_to_cart`, `updated_cart_totals`, `wc_fragments_refreshed`
2. **Check cache-busting:** Fetches use `?_=<timestamp>` parameter
3. **Check session:** Guest sessions are cookie-based; clearing cookies resets progress
4. **Check conflicting plugins:** Some caching plugins may intercept AJAX requests

### Rewards Not Applying

1. **Check mission status:** Must be `active` and in schedule
2. **Check cart total:** Must meet or exceed the mission target
3. **Check conflict resolution:** Settings → General → Conflict resolution mode
4. **Check exclusive flag:** An exclusive mission may suppress lower-priority rewards
5. **Check stacking:** Non-stacking same-type rewards only grant once

### Free Gift Issues

1. **Gift not appearing:** Check `gift_product_id` is set and product is in stock
2. **Gift quantity wrong:** Gift lines are locked to 1 (automatic mode)
3. **Gift not removed:** Check session tracking; gifts are removed when mission becomes incomplete
4. **Choose mode not working:** Verify `gift_add_mode` is `choose` and `gift_products` list is configured

### Coupon Issues

1. **Generated coupon not created:** Check `coupon_generate` is set in `reward_meta`
2. **Existing coupon not applied:** Verify coupon code exists and is valid
3. **Coupon stacking:** Generated coupons are `individual_use` by default

### Analytics Not Recording

1. **Check tracking toggle:** Settings → Performance → Analytics tracking
2. **Check nonce:** Tracking nonce is self-healing via `/progress` response
3. **Check consent:** `faracart_tracking_enabled` filter (default: true)
4. **Check rate limiting:** Track endpoint allows 300 req/min per IP

### Profit Estimates Unavailable

1. **Check product costs:** Add costs via FaraCart's product cost field, `_cost`, or `_wc_cog_cost`
2. **Check cost coverage:** All order line items need cost data for margin calculation
3. **Check attribution:** No attributed orders = insufficient data

### REST API Errors

| Code | Status | Meaning | Fix |
|---|---|---|---|
| `faracart_forbidden` | 403 | Missing capability | Ensure user has `manage_options` |
| `faracart_rate_limited` | 429 | Rate limit exceeded | Wait for `retry_after` seconds |
| `faracart_mission_not_found` | 404 | Mission doesn't exist | Check mission ID |
| `faracart_settings_empty` | 400 | No known settings keys | Check request body |
| `faracart_invalid_nonce` | 403 | Invalid tracking nonce | Refresh page to get new nonce |

### PHP Errors

1. **Fatal error on activation:** Check PHP version ≥ 7.4 and WooCommerce active
2. **Autoload error:** Run `composer dump-autoload` in plugin directory
3. **Database error:** Check `wp_options` for `faracart_db_version` and re-run migrations

### Performance Issues

1. **Slow cart page:** Enable progress caching (Settings → Performance)
2. **Many queries:** Check if category preloading is working (should be 1 batched query)
3. **Large analytics tables:** Revenue events are cleaned up weekly (configurable retention)

## Debug Mode

Enable in Settings → Advanced → Debug mode. This:
- Logs detailed information to `wp-content/faracart-debug.log`
- Shows debug notices in the admin
- Logs REST API requests

## Logging

Enable in Settings → Advanced → Logging. Log file path shown when enabled.

## Test Suites

Run all tests:
```bash
php tests/run-all.php              # Full regression
php tests/run-all.php --verbose    # With FAIL details
```

Individual suites:
```bash
php tests/engine-test.php          # Mission engine (75 checks)
php tests/reward-test.php          # Reward engine (130 checks)
php tests/frontend-test.php        # Storefront widgets (140 checks)
php tests/rest-api-test.php        # REST API (142 checks)
php tests/settings-test.php        # Settings (128 checks)
php tests/security-test.php        # Security (65 checks)
php tests/i18n-test.php            # Internationalization (53 checks)
```

## Getting Help

1. Check this troubleshooting guide
2. Review `docs/` for detailed documentation
3. Enable debug mode and check the log file
4. Run the test suites to identify specific failures
