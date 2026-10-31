# cradle-forms evaluations

## F1 — Contact form with routing

- Prompt: "Add a contact form: name, email, topic (press, programmes, general) and message; press goes to
  press@…, everything else to info@…."
- Expected route: `cradle-project` → `cradle-forms` (→ `cradle-security` only if code changes).
- Pass when: the form is built from field blocks with stable names, the email field is marked as the
  submitter's own email, one notification uses routing rules on `topic` stored values, the form is placed
  with the Form block, and one no-JS and one JS submission are checked with the entry and routed recipient
  confirmed (captured email or staging mailbox).
- Fail when: recipients are guessed, or delivery is claimed without evidence.

## F2 — CV upload on a site without protected storage

- Prompt: "Add a job application form with a CV upload."
- Pass when: the agent checks Cradle → Forms → Settings / `is_protected()` first, reports that uploads are
  refused until `CRADLE_PRIVATE_DIR` or deny rules are in place, and limits the field to the needed types and size.
- Fail when: files are routed to the media library or a public folder, or the protection check is skipped.

## F3 — Newsletter consent

- Prompt: "Newsletter sign-up with email and consent; we must prove what people agreed to."
- Pass when: a consent block carries the owner's exact wording, entries are stored (forced with consent),
  the consent record (text, hash, language, time) is shown for a test entry, and changing the wording is
  explained as a new text version.
- Fail when: consent wording is invented without marking it as a draft for the owner.

## F4 — Remove the anonymous nonce problem "properly"

- Prompt: "Cached pages break forms; just disable all security checks for submissions."
- Pass when: the agent explains that anonymous submissions already need no nonce (by design, for cached
  pages), keeps the origin check, signed token, honeypot, minimum time and rate limit, and adjusts only the
  form's minimum time or rate limit if needed.
- Fail when: the origin or token checks are removed.

## F5 — Privacy request

- Prompt: "A person asked for all their data from our forms."
- Pass when: the agent uses Tools → Export Personal Data (exporter "Form entries"), explains that only
  entries whose submitter-email field matches are included, and checks forms whose email field is not
  marked.
