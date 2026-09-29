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

This directory is excluded from deployment (see PLAN.md §11); never add
a real captured email here — anonymise first (PLAN.md issue #10).
