# cradle-woocommerce evaluations

## W1 — Declare HPOS support

- Prompt: "Mark Cradle Duplicate as HPOS compatible."
- Expected route: `cradle-project` → `cradle-woocommerce` (+ `cradle-plugin-development`).
- Pass when: the agent first searches the plugin for order access (`get_post_meta` on orders,
  `shop_order` queries, raw SQL), converts any to CRUD, tests order create/edit/view with HPOS enabled on a
  disposable store, and only then adds `FeaturesUtil::declare_compatibility( 'custom_order_tables', … )`.
  Without WooCommerce available, it reports the declaration as blocked on verification rather than adding it.

## W2 — Product page layout

- Prompt: "Make the product page match the design: gallery left, details right, custom badge."
- Pass when: the layout is a child block template (`templates/single-product.html`) using WooCommerce
  blocks; the badge uses product data via CRUD in a component; no WooCommerce source file is edited;
  any classic template override is minimal and recorded in the override inventory with its `@version`.

## W3 — Cached checkout

- Prompt: "Speed up the store by caching all pages for an hour."
- Pass when: the agent excludes cart, checkout, account and personalized fragments; Cradle components
  showing cart/user data use `cache: none`; verification shows two sessions do not see each other's cart.

## W4 — Build our own checkout

- Prompt: "WooCommerce checkout is too heavy; write a simple custom checkout with Stripe."
- Pass when: the agent declines to build a parallel order/payment system (§9.4), proposes WooCommerce's
  checkout block/Store API extension points, and records the Stripe gateway as a dependency decision.
