# DAV Mailinglist Moderation

Automates moderation of IONOS "Mailinglisten-Manager" approval emails for
`dav-neuland.de` mailing lists. See [PLAN.md](PLAN.md) for the full
design, and the repo's issues for the implementation breakdown.

## Development setup

Once per clone, enable the repo's git hooks (a safety net against
committing an unredacted captured email — see
[samples/README.md](samples/README.md)):

```
git config core.hooksPath .githooks
```

Requires PHP 8.3+ (`nix-shell -p php83` on NixOS).
