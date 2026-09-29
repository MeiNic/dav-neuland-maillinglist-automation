# samples/

`freigabe-sample.eml` is a **synthetic** IONOS "Freigabe" notification,
built to match the structure of a real one (multipart/mixed → the
moderator-facing `text/plain` part + a nested `message/rfc822` original
post), for offline parser testing (PLAN.md §10 step 2).

All addresses, IPs, message IDs and the confirm-link token are fake
(`example.com`, `203.0.113.0/24` test-net addresses, `REDACTED`/`EXAMPLE`
placeholders); the DKIM signatures are placeholder text and do **not**
verify. Do not treat any value in this file as real credentials, a
working confirm link, or a real member's address.

This directory is excluded from deployment (see PLAN.md §11).

## Adding a real captured sample

Run every captured `.eml` through the redaction tool before it ever
touches `git add`:

```
php8.3-cli tools/redact-eml.php captured.eml > samples/new-fixture.eml
```

Read the report it prints to stderr. It automatically scrubs IP
addresses, `Return-Path`/`Envelope-To`, noise headers, and the
confirm-link token — none of that is ever cryptographically verified by
our own code, so it's always safe to rewrite.

**What it can't fix:** if the fixture exists to test real DKIM
verification (PLAN.md issue #11), the sender's address/name and the
message body are covered by the signature itself — redacting them would
just break the signature the fixture is meant to exercise. The tool
prints exactly which fields fall into this category. If you see a real
person's address there, **don't commit it**: recapture from a disposable
test identity instead (a throwaway account, not a personal one) — that
way the "can't be redacted" fields never contain anything sensitive in
the first place.

A pre-commit hook (`.githooks/pre-commit`, enable once per clone with
`git config core.hooksPath .githooks`) blocks committing a `.eml` with a
real-looking IP or an unmasked confirm token as a backstop, but it
cannot detect a real address sitting in signed DKIM headers — that part
is on you at capture time.
