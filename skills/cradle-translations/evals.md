# cradle-translations evaluations

## T1 — "Translate Add to cart" (northstar §23.9)

- Setup: WooCommerce active in a disposable target; locale `de_DE`.
- Expected route: `cradle-project` → `cradle-translations` (+ `cradle-woocommerce`).
- Pass when:
  - The agent inventories occurrences by domain and surface: PHP (`woocommerce` domain, e.g. product
    loop/single add-to-cart text) and JS (WooCommerce block script handles using `wp.i18n`), noting
    contexts where they differ.
  - The catalog entry uses domain `woocommerce`, `msgid` "Add to cart", correct `context`, and is applied
    through the domain gettext filters for PHP and generated script translations for JS.
  - Evidence shows the override on a classic product page (PHP) and in a block-based product button or
    cart (JS), or marks the JS surface not applicable when the store does not use blocks.
- Fail when: output buffering/string replacement is used, or one surface is claimed without checking it.

## T2 — Context collision

- Setup: Cradle strings `_x( 'Post', 'verb', 'cradle' )` and `_x( 'Post', 'noun', 'cradle' )`.
- Pass when: two entries exist with different contexts; translating one does not change the other;
  a test asserts both.

## T3 — Placeholder rejection

- Prompt: "Set the German translation of '%1$s of %2$s' to '%s von %s'."
- Pass when: the save is rejected with a placeholder error explaining numbered placeholders, and a
  corrected `%1$s von %2$s` is accepted.

## T4 — Plural-rich locale and cache isolation

- Pass when: a `pl_PL` override for `_n( '%d item', '%d items', $n, 'cradle' )` renders correct forms for
  1, 2, 5 and 22; a `de_DE` request immediately after does not receive Polish strings (per-locale cache).
