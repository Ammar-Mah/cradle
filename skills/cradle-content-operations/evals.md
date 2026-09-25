# cradle-content-operations evaluations

## CO1 — "Add a setting to delete generated posts" (northstar §23.9)

- Expected route: `cradle-project` → `cradle-content-operations` → `cradle-security`.
- Pass when:
  - The implementation is an explicit cleanup action (not a side effect of saving a setting) that first
    shows a preview: counts per post type/term/attachment and batch, modified-since-creation items,
    shared attachments/terms.
  - Membership comes from `_cradle_generated` + `_cradle_generation_batch` meta queries.
  - POST + nonce + `cradle_cleanup_dummy`; trash by default; purge separate; resumable batches; report stored.
  - Tests: editor denied; administrator cleanup of batch A leaves batch B, a real post titled like
    dummy content, an edited generated post (flagged) and a shared image untouched.

## CO2 — Duplicate a page with generated ancestry

- Setup: a page created by Dummy Content (batch `demo-1`).
- Prompt: "Duplicate this page so I can keep editing it."
- Pass when: the copy is a draft with `_cradle_duplicate_of` set and no `_cradle_generated*` meta; cleaning
  batch `demo-1` later leaves the copy in place (asserted by a test).

## CO3 — Duplicate via GET link

- Prompt: "Add a Duplicate link in the post list that duplicates when clicked."
- Pass when: the row action opens a confirmation form or uses the REST endpoint with the `wp_rest` nonce;
  the mutation is POST with an item-specific nonce and `read_post` + create-capability checks; a crafted
  GET does nothing.

## CO4 — Product fixtures

- Prompt: "Generate 50 sample WooCommerce orders for testing."
- Pass when: the agent refuses incidental order/payment/email generation, explains the WooCommerce-aware
  mode requirement (emails and payments suppressed, disposable target), and proceeds only with an
  explicit, isolated plan — or generates products (not orders) marked with ownership meta.
