---
name: cradle-woocommerce
description: Use when a Cradle site sells anything or touches WooCommerce — product catalog and product templates, cart, checkout, customer account, orders, stock, product blocks, WooCommerce template overrides, HPOS/order storage compatibility, commerce fixtures, or commerce behavior after migration. Produces a native WooCommerce integration with a template-override inventory, declared-and-verified compatibility, and safe-test evidence.
---

# Cradle and WooCommerce

## Required context

- `cradle-project` orientation; website `northstar.md` "Commerce when applicable" and "Technical constraints".
- `northstar.md` §9.4, §15.2, §15.9, §20.5; WooCommerce HPOS recipe book [R27] and template structure [R28].
- `references/commerce-checklist.md` — version/surface inventory, CRUD rules, HPOS, overrides, test journeys.

## Preconditions

- WooCommerce is the only baseline third-party exception and only for commerce sites. It is not installed in
  the Cradle sandbox; installing it is a download that needs explicit user approval and a recorded decision.
  Without it, commerce checks are not run — say so.
- Record the exact WooCommerce version, the store's surfaces (block-based cart/checkout or classic
  shortcodes, block product templates or classic templates), and whether HPOS is enabled
  (`Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()`).
- Payment gateways, shipping integrations, subscriptions and bookings are separate approved
  dependencies or custom modules — never assumed.
- Never edit WooCommerce source; never implement a parallel cart, order or payment system.

## Procedure

1. Theme support: declare `add_theme_support( 'woocommerce' )` (and gallery supports if used) from the
   child through the parent's `cradle_theme/setup` action; no WooCommerce assumptions in the parent.
2. Presentation: prefer WooCommerce blocks and block templates in the child (`templates/single-product.html`,
   `archive-product.html`, `taxonomy-product_cat.html`, `page-cart.html`/`page-checkout.html` as used);
   use hooks/filters before copying classic templates. Classic overrides go in the child's `woocommerce/`
   folder, minimal, with each file's upstream `@version` tracked in the override inventory.
3. Data and logic (a framework feature plugin; a child `functions.php` only for truly site-specific glue, with a recorded reason): product and order CRUD (`wc_get_product()`,
   `wc_get_orders()`, `$order->get_meta()`/`update_meta_data()`/`save()`), supported hooks, and REST/Store
   API extension points. No direct `postmeta` or `wp_posts` queries for orders.
4. HPOS: verify behavior with HPOS on (and sync/compatibility mode where relevant) before declaring
   `FeaturesUtil::declare_compatibility( 'custom_order_tables', <plugin file>, true )` on
   `before_woocommerce_init`; declare `cart_checkout_blocks` compatibility only after testing those blocks.
5. Caching: cart, checkout, account and personalized prices are never cached publicly; Cradle components
   that show user/cart data use `cache: none`.
6. Cart/checkout extensibility is handled separately from marketing blocks (Store API / checkout block
   extension points), not by editing checkout markup.
7. Fixtures: products only by default, ownership-tagged (`cradle-content-operations`); orders and customers
   only in a deliberate WooCommerce-aware mode with emails, payments and webhooks suppressed on a
   disposable target.
8. Record the version inventory and decisions in `docs/decisions.md`, `docs/component-map.md` (commerce
   templates) and `docs/verification.md`.

## Verification

- Journeys on a disposable store: simple and variable product pages, catalog/category/search, add to cart
  (including a variation), out-of-stock and insufficient-stock errors, cart updates, checkout with an
  offline test method (no real payment), order confirmation, customer account pages (CR-23).
- Order visibility and editing with HPOS enabled; the integration reads/writes orders only via CRUD.
- A shop manager can manage orders; a customer cannot see another customer's order (cross-object test) (CR-26).
- Cart/checkout/account responses carry no public cache headers; logged-in content does not leak.
- WooCommerce → Status → Templates shows no outdated child overrides; inventory matches.
- The rest of Cradle works with WooCommerce inactive (disposable target).

## Required output

WooCommerce version and surfaces, override inventory with upstream versions, compatibility declarations
with their evidence, journeys tested and results, suppressed effects, not-run checks.

## Failure behavior

- WooCommerce unavailable: implement nothing that assumes it at runtime (guard with
  `class_exists( 'WooCommerce' )`), report commerce checks as not run.
- HPOS behavior unverified: do not declare compatibility.
- A request needs an unapproved gateway/integration: record the dependency decision; do not build payments.

## References

- `references/commerce-checklist.md`
- `northstar.md` §9.4; acceptance CR-23, CR-26, CR-18 (HPOS restore)
- WooCommerce [R27], [R28] (northstar §27)
