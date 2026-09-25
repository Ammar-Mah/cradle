# Commerce checklist

Source: northstar §9.4, §15.2, §15.9, §16.3, §20.5; WooCommerce HPOS recipe book [R27] and template
structure documentation [R28]. Verify API names against the installed WooCommerce version.

## 1. Inventory (record in docs/decisions.md or docs/verification.md)

| Item | Value |
| --- | --- |
| WooCommerce version | e.g. read from `WC()->version` |
| Order storage | HPOS on/off; compatibility (sync) mode on/off |
| Cart/checkout | Blocks (`woocommerce/cart`, `woocommerce/checkout`) or classic shortcodes |
| Product templates | Block templates or classic PHP templates |
| Gateways / shipping | Approved list; test mode used for verification |
| Emails | Suppressed in tests? How (disposable mail catcher, `pre_wp_mail` filter in the test target) |

## 2. CRUD rules

| Do | Do not |
| --- | --- |
| `wc_get_product( $id )`, `$product->get_price()`, `$product->set_stock_quantity()` + `save()` | `update_post_meta( $id, '_price', … )` |
| `wc_get_orders( array( 'customer_id' => $id, 'limit' => 20 ) )` | `get_posts( array( 'post_type' => 'shop_order' ) )` |
| `$order->get_meta( 'acme_ref' )`, `$order->update_meta_data()`, `$order->save()` | `get_post_meta( $order_id, … )` / raw SQL on `wp_postmeta` |
| `wc_update_product_stock()` | Direct stock arithmetic in meta |
| Store API / checkout block extension points for checkout data | Editing checkout HTML with string replacement |
| `OrderUtil::custom_orders_table_usage_is_enabled()` to branch diagnostics | Assuming orders live in `wp_posts` |

## 3. HPOS declaration (only after verification)

```php
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CRADLE_SITE_FILE, true );
	}
} );
```

Evidence required first: create, edit, search, and view orders with HPOS enabled; the integration's
order reads/writes go through CRUD; admin order screens show the integration's data; migration restore
of an HPOS store keeps orders consistent.

## 4. Template override inventory

| Override path (child) | Upstream template | Upstream `@version` at copy | Current upstream version | Reason | Status |
| --- | --- | --- | --- | --- | --- |
| `woocommerce/single-product/meta.php` | `templates/single-product/meta.php` | 9.x.y | … | Hide SKU label style | current / outdated |
| `templates/single-product.html` (block template) | WooCommerce block template | n/a | n/a | Layout per design | — |

Rules: override only when hooks, blocks or settings cannot achieve the result; keep each override minimal;
update the inventory on every WooCommerce update (WooCommerce → Status → Templates lists outdated
overrides); never copy the whole `templates/` folder.

## 5. Journeys to test (disposable store, test payment method)

1. Catalog: shop page, category archive, product search, sorting/filtering used by the design.
2. Simple product: view, add to cart, quantity change, remove.
3. Variable product: select variation, price/stock/image update, add to cart; unavailable variation message.
4. Stock: out-of-stock product; quantity exceeding stock shows an error, no negative stock.
5. Cart: coupon if used, shipping calculation if used, totals.
6. Checkout: guest and logged-in; validation errors; offline/test gateway; order confirmation page.
7. Account: login (with `cradle-login` routing on and off), orders list, view order, addresses, lost password.
8. Permissions: customer A cannot view customer B's order URL; shop manager can edit orders; editor cannot
   see orders unless granted.
9. Caching: cart/checkout/account responses are not publicly cacheable; mini-cart reflects the session.
10. Emails: generated but not delivered externally during tests.

## 6. Migration and fixtures

- Export/restore includes WooCommerce tables (HPOS `wc_orders*`, `wc_order_*`, Action Scheduler
  `actionscheduler_*`) when selected; destination safety profile pauses scheduled actions, webhooks,
  gateways and emails until verified.
- Fixtures: products tagged with `_cradle_generated` + batch; orders/customers only in an isolated,
  deliberate mode.
