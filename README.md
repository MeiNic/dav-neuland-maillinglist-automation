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

Requires PHP 8.3 and Composer, matching the production server (see
PLAN.md §2, §9). On NixOS, `nix-shell` picks these up automatically via
[`shell.nix`](shell.nix); everywhere else, install PHP 8.3 (with
`ext-imap`, `ext-openssl`) and Composer yourself.

Install dependencies (pulls in dev tooling like PHPUnit, then runs
[Strauss](https://github.com/BrianHenryIE/strauss) to build the
namespace-prefixed copies of the vendored libraries in
`vendor-prefixed/`, downloading `tools/strauss.phar` on first use):

```
composer install
composer run strauss
```

Before committing dependency changes, rebuild the deployable `vendor/`
tree the same way the (Composer-less) production server expects it —
`--no-dev --optimize-autoloader`, then re-run Strauss so
`vendor-prefixed/` matches — and commit both directories:

```
composer install --no-dev --optimize-autoloader
composer run strauss
```

## Running tests

```
vendor/bin/phpunit
```

(PHPUnit is a dev dependency; run `composer install` without `--no-dev`
first if `vendor/bin/phpunit` is missing.) Fixtures live in
`tests/fixtures/` — see [samples/README.md](samples/README.md) for the
redaction workflow before adding a real captured email there.
