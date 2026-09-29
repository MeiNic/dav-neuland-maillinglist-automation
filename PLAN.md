# DAV Mailinglist Moderation — Implementation Plan

## 1. Goal

Automate moderation of IONOS "Mailinglisten-Manager" approval emails for
`dav-neuland.de` mailing lists. When a post to a moderated list needs
approval, IONOS emails a fixed-template notification to the moderator
inbox. This project polls that inbox on a cron, decides per mailing list
whether the original sender is allowed to post (regex match against the
nested message's `From:`, **plus a DKIM check that the `From:` is not
forged**), and either approves the post (GET the confirmation link) or
rejects it (send a list-specific rejection email back to the sender, or
reject silently) — with no manual moderator action required for the
normal cases, and a clean "manual review" path for everything else.

Reference sample of the real notification email:
`samples/freigabe-sample.eml` (IONOS "Freigabe einer neuen E-Mail..."
format). **The sample is from a test setup** (sent to a personal
moderator inbox); production uses a dedicated mailbox, see §3.

## 2. Confirmed server environment (IONOS webspace, `ssh dav_wp_server`)

- Modern PHP is **8.3.33** at `/usr/bin/php8.3` / `/usr/bin/php8.3-cli`.
  The bare `php` CLI resolves to a legacy 4.4.9 build — **never invoke
  bare `php`** in any script or crontab entry.
- PHP 8.3 has `imap`, `curl`, `openssl`, `mbstring`, `dom`, `zip`, `json`
  loaded. No `open_basedir` / `disable_functions` restrictions.
- `dns_get_record()` works from PHP 8.3 CLI (verified 2026-09-28: TXT
  lookup of a `_domainkey` record succeeded) → DKIM key lookups are
  possible on the server.
- No global Composer. Outbound HTTPS works, so `composer.phar` can be
  bootstrapped locally (for building `vendor/`) — but since there's no
  Composer on the server itself, **`vendor/` must be committed / uploaded**
  rather than generated on the server.
- SSH crontab (`crontab -e`) is available and **empirically confirmed
  reliable at 5-minute granularity** (tested 2026-08-18: 3 firings, exactly
  5 minutes apart). This is the scheduling mechanism — no need for IONOS's
  control-panel Cron Jobs feature.
- Target WordPress install: `~/DAV-NEU` (htdocs root), i.e.
  `/homepages/23/d500008865/htdocs/DAV-NEU`.
- Home dir doubles as the web root (`/homepages/23/d500008865/htdocs`) —
  anything placed there is potentially web-accessible (Apache; `.htaccess`
  is honoured). Keep the plugin inside `wp-content/plugins/`, protect
  non-public subfolders with `.htaccess` (see §8), and verify with `curl`
  that they return 403.

### 2a. DNS / mail-auth state of `dav-neuland.de` (checked 2026-09-28)

- **DMARC is broken**: `_dmarc.dav-neuland.de` TXT is
  `"Der Verbesserte ist: v=DMARC1;p=none;rua=mailto:…;ri=604800"`. The
  record must *start* with `v=DMARC1`, so receivers ignore it entirely.
  Even if it were valid, `p=none` means forged `@dav-neuland.de` mail is
  not blocked. → Action item for whoever manages DNS (the `rua` address
  belongs to another club member): fix the record, then move towards
  `p=quarantine` / `p=reject` once reports look clean. This is good
  hygiene but **the plugin must not rely on it** (we don't know whether
  the IONOS mailing-list inbound path enforces DMARC at all).
- **DKIM is set up**: selectors `s1-ionos` / `s2-ionos` exist (CNAMEs to
  `s1/s2.dkim.ionos.com`), so mail sent through IONOS SMTP as
  `@dav-neuland.de` is DKIM-signed. Requiring DKIM for `@dav-neuland.de`
  senders is therefore feasible.
- **SPF**: `v=spf1 include:_spf.perfora.net include:_spf-eu.ionos.com
  include:_spf.kundenserver.de ~all`.

## 3. Production context

- **Moderator mailbox is configurable**; production will use
  **`noreply@dav-neuland.de`** (dedicated mailbox, not a personal inbox).
  Every production list must have this address set as its moderator in
  the IONOS Control-Center. Config via `wp-config.php` constants (§7).
- Since the mailbox is dedicated, no human reads it in normal operation —
  but the design still does **not** rely on `\Seen` (moves messages into
  folders instead, §5b), so a human opening it for debugging can't break
  processing.
- **IONOS already rejects posts from non-members.** Only posts from
  addresses subscribed to the list reach the moderation step. Consequences:
  - The moderation step is "which *members* may post to this list"
    (e.g. members may read, only board addresses may write).
  - Spam from random outside addresses doesn't arrive here → the
    backscatter risk of rejection mails is much smaller: rejections go
    to member addresses (or to member addresses someone forged).
  - Residual threat: someone forging the `From:` of a member *who is
    allowed to post*. That is what the DKIM check (§5b step 4g) is for.
  - To verify during testing: does IONOS check membership against the
    header `From:` or the envelope sender (`Return-Path`)? (§10 step 6)

## 4. Sample email structure (drives the parsing logic)

Outer message (from `Mailinglisten-Manager <no.reply@oneandone.com>`):

- **Authentication-Results** (added by IONOS inbound MX, authserv-id
  `kundenserver.de`): `dkim=pass header.i=no.reply@oneandone.com
  header.s=s1-ionos` — used to verify the notification is genuine.
- **Subject** (fixed template, only the bracketed part varies; header is
  *folded* across two lines in the raw source):
  `Freigabe einer neuen E-Mail an die Mailingliste [test.mailingliste@dav-neuland.de]`
- **Content-Type**: `multipart/mixed`, containing:
  1. A `multipart/alternative` → `text/plain` part (quoted-printable,
     URL is soft-wrapped across 3 lines) with human-readable
     `Mailingliste:`, `Moderator:`, `Absender:` lines and the approval link:
     `https://ml.kundenserver.de/MailingList/<list-address>/Mail/Confirm?lang=de&id=<token>`
     - `<list-address>` appears **literally** (`test.mailingliste@dav-neuland.de`,
       `@` not percent-encoded); the `id` token *is* percent-encoded
       (`%3A`, `%2F`, …) and must be passed through unchanged.
     - `Absender:` = the original envelope sender (matches the nested
       message's `Return-Path`).
  2. A `message/rfc822` part — the **original post** to the list, with
     its own `From:`, `To:`, `Subject:`, `DKIM-Signature`, body. Note:
     it carries **no `Authentication-Results`** header, so we can't
     reuse an upstream verdict — we must verify DKIM ourselves.

Confirmed manually: the confirm link works as a **plain unauthenticated
GET**, no cookies/session needed. Verified with `curl -sv` (issue #8,
2026-09-29): the bare GET approves without JS / a form POST. Always
`HTTP 200`, no redirects. Success marker: `<title>Der Vorgang war
erfolgreich.</title>`. A reused/expired token and a broken token both
return the *same* generic `<title>Fehler</title>` page — IONOS does not
distinguish "already confirmed" from "invalid"; the approver must treat
any non-success body as one "confirm failed" outcome (see §10 step 3).

### 4a. DKIM feasibility on the nested message (tested 2026-09-28)

Tested with `dkimpy` against the nested message from the sample:

1. **As-is: fails** with `body hash mismatch`. Cause: the original post
   was sent by Thunderbird as 8-bit UTF-8
   (`Content-Transfer-Encoding: 8bit`); **IONOS re-encoded the body to
   quoted-printable** (and rewrote the CTE header) before wrapping it.
2. **After reversing that transformation** — QP-decode the body,
   normalise to CRLF, set the header back to
   `Content-Transfer-Encoding: 8bit` — the Gmail signature (`d=gmail.com`)
   **verifies: `True`** (with the clock set to the send time; see 3).
3. The sample's signature has `x=` = send time + 7 days, so it is
   *expired today*. In production we check within minutes, so this is
   irrelevant live — but offline tests must inject a fake "now".

**Conclusion: option (b) is feasible** for the common single-part case.
Open risk: multipart originals (attachments, HTML+text) where IONOS may
re-encode individual parts — the reversal gets harder. We need more real
samples (§10 step 1) before deciding how far to go; anything we can't
verify goes to manual review (fail closed), never to auto-reject.

Library: `phpmailer/dkimvalidator` (Packagist, ~850k installs) does the
verification; we need the raw nested message bytes and a DNS lookup
(`dns_get_record`, available). If it doesn't allow overriding "now" /
canonicalising our reconstructed message cleanly, fall back to a small
own verifier (relaxed/simple canonicalisation + `openssl_verify` — the
algorithm is well-specified in RFC 6376).

## 5. Architecture

A single WordPress plugin, `dav-mailinglist-moderation`, with two halves.

### 5a. Admin settings (WP-admin UI, no code edits needed for config changes)

- New admin page (under Settings) listing configured mailing lists.
- Per list, editable fields:
  - `list_address` (e.g. `test.mailingliste@dav-neuland.de`; normalised
    to lowercase + trimmed on save)
  - `regex` (PHP PCRE pattern checked against the nested message's bare
    `From:` address; admin help text recommends `\z` or the `D` modifier
    instead of `$`, since `$` also matches before a trailing newline)
  - `dkim_policy`: `require` (default) — `From:` must be DKIM-verified
    with an aligned domain, else manual review; `off` — skip the check
    (explicit opt-out for lists where members use providers without DKIM)
  - `reject_mode`: `email` (send rejection mail) or `silent` (don't
    approve, don't email — just file it under `Rejected`)
  - `reject_subject` (template, supports placeholders)
  - `reject_body` (template, supports placeholders: `{{sender}}`,
    `{{list}}`, `{{original_subject}}`)
  - `reply_to` (optional; human contact for replies to rejection mails —
    otherwise replies land in the `noreply@` mailbox that nobody reads)
  - `active` (bool, so a list can be disabled without deleting config)
- Add / edit / delete rows; stored as a single option
  `dav_mlm_lists` (serialized array) via `register_setting()` with a
  sanitize callback — not raw `update_option()` calls.
- **Status panel** on the same page (read from option `dav_mlm_status`):
  last run time, last *successful* run time, counts of the last run,
  consecutive-failure counter, last N errors/warnings, and a warning
  banner if the last successful run is older than e.g. 30 minutes.
- IMAP / SMTP credentials and other deployment config are **not** stored
  in this UI → `wp-config.php` constants (§7), consistent with how WP
  treats DB credentials; keeps secrets out of the DB/admin UI.
- Security requirements for this page (see §8): capability check
  (`manage_options`), nonce on save/delete, `esc_html`/`esc_attr` on all
  rendered values, sanitize callback on the registered setting.

### 5b. Cron runner (CLI script, invoked by system cron every 5 min)

Entry point: `bin/cron-runner.php`, invoked as:
```
MAILTO=""
*/5 * * * * /usr/bin/php8.3-cli /homepages/23/d500008865/htdocs/DAV-NEU/wp-content/plugins/dav-mailinglist-moderation/bin/cron-runner.php >> /homepages/23/d500008865/htdocs/.dav-mlm-logs/cron.log 2>&1
```
(Log dir: see §8 "Logs". The cron redirect only catches fatal
output; normal logging goes through the plugin's logger.)

CLI flags: `--dry-run` (decide + log, no side effects at all),
`--verbose`, `--message-id=<id>` (process a single message, for
debugging).

Steps per run:

0. **Guard + bootstrap**: `if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }`
   as the very first line. Set `$_SERVER['HTTP_HOST']`, `SERVER_NAME`,
   `REQUEST_URI` (from a constant / the site URL), `define('WP_USE_THEMES', false)`,
   then `require` WP's `wp-load.php` so `get_option()` / `wp_mail()` /
   `wp_safe_remote_get()` are available. Test early that other active
   plugins (caching/security) don't misbehave in CLI context.
1. **Lock**: `flock()` on a lock file in the (protected) data dir,
   `LOCK_EX | LOCK_NB`; if already locked, log and exit. OS releases the
   lock automatically if the process dies — no stale-lock handling needed.
2. **Connect** via `ext-imap` (`imap_open`) using the `DAV_MLM_IMAP_*`
   constants, with certificate validation on (no `/novalidate-cert`),
   explicit `imap_timeout()` for open/read. Ensure the target folders
   exist (`imap_getmailboxes` / `imap_createmailbox`; respect the
   server's hierarchy delimiter, e.g. `INBOX.Moderation.Approved` vs
   `Moderation/Approved`).
3. **Search** INBOX for `FROM "no.reply@oneandone.com"` (configurable,
   `DAV_MLM_NOTIFY_FROM`). No `UNSEEN` criterion — state is "still in
   INBOX" vs "moved to a folder".
   - Messages from that sender whose subject does **not** match the
     template prefix → move to `Unrecognized`, alert admin (IONOS may
     have changed the template/language; otherwise we'd silently stop
     working).
   - Everything else in INBOX (bounces of our rejection mails, replies,
     spam) is left alone; optionally a later cleanup.
4. For each matching message (wrapped in try/catch — one bad message
   must never abort the run):
   a. **Fetch raw source** with `FT_PEEK` (don't set `\Seen`):
      `imap_fetchheader()` + `imap_body(..., FT_PEEK)`. Hand the whole
      raw blob to `ZBateson\MailMimeParser`, not ext-imap's
      bodystructure API. Record the outer `Message-ID` for logging/
      idempotency.
   b. **Verify the notification is genuine**: the *topmost*
      `Authentication-Results` header (only that one is trustworthy — lower
      ones could be forged by the sender) must have authserv-id
      `kundenserver.de` (`DAV_MLM_TRUSTED_AUTHSERV`) and `dkim=pass` with
      `header.i`/`header.d` in `oneandone.com`; outer `From:` address must
      equal `DAV_MLM_NOTIFY_FROM`. Fail → move to `Suspicious`, alert,
      no action. (This closes "forged notification triggers rejection
      mails to arbitrary addresses".)
   c. **Parse** — extract:
      - list address from the (unfolded) subject via
        `/\[([^\]]+)\]\s*$/`, lowercased
      - the confirm URL from the decoded `text/plain` part
      - the `Absender:` line from the same part
      - the nested `message/rfc822` part **as raw bytes** (for DKIM) and
        parsed again with MailMimeParser (for `From:`/`Subject:`)
      Missing nested part, no URL, no/multiple `From:` addresses,
      group syntax, unparseable/IDN-encoded address → move to `Manual`,
      log reason. **Never** reject because parsing failed.
   d. **Validate the confirm URL** against a strict allowlist before
      ever fetching it: scheme `https`, host == `DAV_MLM_CONFIRM_HOST`
      (default `ml.kundenserver.de`), path ==
      `/MailingList/<list-address>/Mail/Confirm` where `<list-address>`
      (compared after `rawurldecode`, case-insensitive, so both literal
      and percent-encoded forms are accepted) equals the subject's list
      address; query contains exactly `lang` and `id`. Fail → `Suspicious`.
   e. **Look up list config** in `dav_mlm_lists` (case-insensitive).
      Missing or `active=false` → move to `Manual`, log warning, **alert
      admin** (new/unconfigured list showed up). Never default to
      auto-approve or auto-reject.
   f. **Sender consistency**: nested `From:` address must be a single
      valid address (`is_email()`), and is compared (case-insensitive)
      with the `Absender:` line / nested `Return-Path`. Mismatch →
      `Manual` (legit cases exist, e.g. some forwarders/list software,
      but they are rare enough to review by hand; revisit if noisy).
   g. **DKIM check** (if list `dkim_policy = require`): verify the
      nested message's `DKIM-Signature`s:
      1. try verification on the raw bytes as-is;
      2. if the body hash fails and the message is single-part with
         `Content-Transfer-Encoding: quoted-printable` (or `base64`),
         retry with the reconstruction from §4a (decode body, CRLF,
         CTE header → `8bit`).
      Pass = at least one valid signature whose `d=` is aligned with
      the `From:` domain (equal, or `From:` is a subdomain of `d=` —
      DMARC relaxed alignment). No signature / no aligned pass / DNS
      failure → `Manual` (DNS failure: retry instead, see step i).
      Log which path (as-is / reconstructed) passed, to learn how often
      reconstruction is needed.
   h. **Regex**: `preg_match($regex, $fromAddress)`:
      - `1` → approve (step i)
      - `0` → reject (step j)
      - `false` (compile error / backtrack limit) → error, `Manual`,
        alert. **Never** treat `false` as "no match".
   i. **Approve**: `wp_safe_remote_get($url, ['redirection' => 0,
      'timeout' => 15])`. Success = HTTP 200 **and** the success marker
      from the curl test (§10 step 3) in the body. A 3xx is not followed;
      its `Location` is logged (re-validate against the allowlist if we
      ever need to follow it). Success → move to `Approved`.
   j. **Reject**:
      - `reject_mode = silent` → move to `Rejected`, no mail.
      - `reject_mode = email` → loop/abuse guards first; skip the mail
        (still move to `Rejected`, log) if: nested message has
        `Auto-Submitted` ≠ `no`, `Precedence: bulk|list|junk`,
        `List-Id`/`List-Unsubscribe` from another list, sender is
        `MAILER-DAEMON`/`postmaster`/`*noreply*`/`no-reply`, sender is
        our own `DAV_MLM_MAIL_FROM`, or sender is any configured list
        address; or the per-sender rate limit (e.g. max 5 rejection
        mails / sender / 24 h, stored in a transient) is exceeded.
      - Build the mail: placeholder values have CR/LF and control chars
        stripped and are truncated (`original_subject` ≤ 200 chars),
        substituted into subject/body text only. `To:` is the validated
        address only (no display name). Headers passed as an array to
        `wp_mail()`: `From: <DAV_MLM_MAIL_FROM_NAME> <DAV_MLM_MAIL_FROM>`,
        `Reply-To: <list reply_to>` if set, `Auto-Submitted: auto-replied`
        (RFC 3834). Send through **SMTP of the `noreply@` mailbox**
        (`phpmailer_init` hook added only around our own `wp_mail()`
        call and removed afterwards) so the mail is DKIM-signed by IONOS
        and passes SPF/DMARC — and so the sender is not
        `wordpress@<empty SERVER_NAME>`.
      - `wp_mail()` returns true → move to `Rejected`.
   k. **Transient failures** (IMAP hiccup, DNS failure, HTTP timeout,
      `wp_mail()` false): leave the message in INBOX, increment an
      attempt counter (option `dav_mlm_attempts`, keyed by outer
      Message-ID). After 3 failed runs → move to `Error`, alert.
   l. **Idempotency**: before an outward action, check a bounded
      "done" list (option `dav_mlm_done`, last ~500 outer Message-IDs
      with action + time). If the action already happened but the move
      failed last time, just retry the move — don't approve/email twice.
   m. **Dry run**: steps a–h run normally; i/j/k/l only log
      "WOULD approve / WOULD reject (mail|silent) / WOULD skip"; **no
      moves, no counters, no status updates**, so the first live run
      still sees everything.
   Moves use `imap_mail_move()` followed by one `imap_expunge()` at the
   end of the run.
5. **Finish**: close IMAP, release lock. Write run summary (processed /
   approved / rejected / manual / suspicious / errored / skipped) to the
   log and to option `dav_mlm_status` (shown in the admin page).
6. **Alerting** (`DAV_MLM_ALERT_EMAIL`, sent via the same SMTP path):
   on new items in `Manual`/`Suspicious`/`Unrecognized`/`Error` (one
   digest mail per run, not one per message), and when the run itself
   fails (IMAP login, fatal error) for N consecutive runs (default 3 →
   15 min; then at most once per 24 h until it recovers). Catches
   rotated passwords and IONOS template changes that would otherwise fail
   silently forever.

IMAP folder layout (prefix configurable, `DAV_MLM_FOLDER_PREFIX`,
default `Moderation`): `Approved`, `Rejected`, `Manual`, `Suspicious`,
`Unrecognized`, `Error`. Gives an audit trail and a clean inbox;
anything in `Manual` is handled by a human in the IONOS Control-Center
(or by moving it back to INBOX after fixing the config).

## 6. Directory layout (this repo → deployed as the plugin folder)

Our own classes are grouped into `includes/<concern>/` subfolders (not
PSR-4 — still the classic `Dav_Mlm_Foo_Bar` → `class-foo-bar.php` naming;
`includes/autoload.php` finds a file by name via a one-time recursive
scan, so which subfolder a class lives in never needs to be hardcoded
anywhere else). `includes/.htaccess`'s `Require all denied` applies to
every subfolder too — Apache inherits it, no per-subfolder copy needed.

```
dav-mailinglist-moderation/
  dav-mailinglist-moderation.php   # plugin bootstrap, registers admin menu
  uninstall.php                    # deletes dav_mlm_* options/transients
  includes/
    autoload.php                   # Dav_Mlm_Foo_Bar -> includes/**/class-foo-bar.php
    config/
      class-config.php             # reads/validates wp-config constants, defaults
      class-config-exception.php   # thrown when a required constant is missing
      class-constant-reader.php    # generic "required, or error" / "optional, with a default" lookups
    logging/
      class-logger.php             # file logger (+ error_log fallback)
      class-log-config.php         # the slice of config the logger needs (interface)
      class-log-masker.php         # masks the confirm-link token, sensitive context keys
      class-log-rotator.php        # daily file naming + retention pruning
    admin/
      class-admin-settings.php     # settings page, status panel, sanitization
      class-list-repository.php    # CRUD over the dav_mlm_lists option
    mail/
      class-mailbox.php            # ext-imap wrapper (search, fetch FT_PEEK, move, folders)
      class-message-parser.php     # wraps ZBateson: list address / confirm URL / Absender / nested raw+From
      class-notification-verifier.php # outer Authentication-Results + From check
      class-dkim-verifier.php      # nested DKIM verify incl. QP→8bit reconstruction, injectable clock
      class-approver.php           # URL allowlist + confirm GET + success check
      class-rejector.php           # loop guards, rate limit, template, wp_mail via SMTP
    runtime/
      class-state.php              # attempts counter, done-list, status option
      class-alerter.php            # digest + failure alerts
      class-cron-runner.php        # orchestrates one run (used by bin/cron-runner.php)
  bin/
    cron-runner.php                # CLI entrypoint: SAPI guard, bootstrap, parse flags, run
    .htaccess                      # Require all denied
  vendor/                          # committed, namespace-prefixed (see §9); + .htaccess deny
  composer.json / composer.lock    # zbateson/mail-mime-parser, phpmailer/dkimvalidator
  tests/                           # PHPUnit; NOT deployed
    fixtures/                      # sample .eml files (anonymise before committing more)
  samples/                         # NOT deployed (contains personal data + a confirm token)
  .htaccess                        # deny direct access to everything except what WP needs
  README.md
```

## 7. Data model / configuration

- Option `dav_mlm_lists`:
  ```php
  [
    [
      'id'             => 'uuid-or-slug',
      'list_address'   => 'test.mailingliste@dav-neuland.de',
      'regex'          => '/@dav-neuland\.de\z/i',
      'dkim_policy'    => 'require',          // require | off
      'reject_mode'    => 'email',            // email | silent
      'reject_subject' => 'Ihre Nachricht an {{list}} konnte nicht zugestellt werden',
      'reject_body'    => "Hallo,\n\nIhre Nachricht \"{{original_subject}}\" an {{list}} wurde nicht freigegeben...",
      'reply_to'       => 'vorstand@dav-neuland.de', // optional
      'active'         => true,
    ],
    ...
  ]
  ```
- Options `dav_mlm_status`, `dav_mlm_attempts`, `dav_mlm_done`
  (autoload off); transients `dav_mlm_rl_<hash(sender)>` for rate limiting.
- `wp-config.php` constants (only the `IMAP_*`/`SMTP_*` credentials are
  required; the rest have defaults in `class-config.php`):

  | Constant | Default / example |
  |---|---|
  | `DAV_MLM_IMAP_HOST`, `_PORT`, `_ENCRYPTION` | `imap.ionos.de`, `993`, `ssl` |
  | `DAV_MLM_IMAP_USER`, `DAV_MLM_IMAP_PASS` | `noreply@dav-neuland.de`, *secret* |
  | `DAV_MLM_SMTP_HOST`, `_PORT`, `_ENCRYPTION` | `smtp.ionos.de`, `587`, `tls` |
  | `DAV_MLM_SMTP_USER`, `DAV_MLM_SMTP_PASS` | default to the IMAP credentials |
  | `DAV_MLM_MAIL_FROM`, `DAV_MLM_MAIL_FROM_NAME` | `noreply@dav-neuland.de`, `DAV Neuland Mailinglisten` |
  | `DAV_MLM_ALERT_EMAIL` | admin address |
  | `DAV_MLM_NOTIFY_FROM` | `no.reply@oneandone.com` |
  | `DAV_MLM_TRUSTED_AUTHSERV` | `kundenserver.de` |
  | `DAV_MLM_CONFIRM_HOST` | `ml.kundenserver.de` |
  | `DAV_MLM_FOLDER_PREFIX` | `Moderation` |
  | `DAV_MLM_DATA_DIR` | `/homepages/23/d500008865/htdocs/.dav-mlm-logs` (logs + lock) |
  | `DAV_MLM_LOG_RETENTION_DAYS` | `30` |

## 8. Security & privacy checklist

- [ ] Admin page: `current_user_can('manage_options')` + nonce on every
      save/delete action.
- [ ] All admin-page output escaped (`esc_html`, `esc_attr`, `esc_textarea`).
- [ ] `register_setting()` with a real sanitize callback (validate regex
      compiles via `@preg_match` probe, `is_email()` on list address and
      reply_to, enum check on `dkim_policy`/`reject_mode`, strip/validate
      template fields).
- [ ] `bin/cron-runner.php` refuses non-CLI SAPI (first line), **and**
      `bin/`, `vendor/`, `includes/` are denied via `.htaccess`
      (`Require all denied`). Verify with `curl` → 403.
- [ ] `samples/` and `tests/` are never deployed (rsync excludes).
- [ ] Notification authenticity: topmost `Authentication-Results`
      from `kundenserver.de` with `dkim=pass` for `oneandone.com` +
      exact outer `From:`.
- [ ] Confirm-URL allowlist (scheme + host + path + list-address match +
      query keys) **before** any outbound GET; `wp_safe_remote_get`,
      no redirects followed.
- [ ] Nested `From:` authenticity: DKIM with aligned domain (per-list
      policy), consistency with `Absender:`; unverifiable → manual, never
      reject.
- [ ] Rejection mail: recipient validated with `is_email()`, placeholder
      values CR/LF-stripped and length-limited (the subject *is* a
      header, so "subject text only" isn't enough on its own); loop
      guards; `Auto-Submitted: auto-replied`; per-sender rate limit;
      explicit `From:` via authenticated SMTP.
- [ ] Regexes are admin-authored only; `preg_match() === false` is an
      error, not a non-match; `pcre.backtrack_limit` stays default.
- [ ] Unconfigured / inactive lists are never auto-approved or
      auto-rejected — fail closed, `Manual`, alert.
- [ ] IMAP with certificate validation; credentials only in
      `wp-config.php` (never in the repo, DB, or logs).
- [ ] **Logs** (GDPR/DSGVO: they contain email addresses): written to
      `DAV_MLM_DATA_DIR`, outside the plugin folder (survives redeploys
      with `rsync --delete`), protected by its own `.htaccess`
      (`Require all denied`) — verify 403 with `curl`. Log only what's
      needed (addresses, list, decision, reason; never bodies or tokens —
      mask the confirm `id`). Daily rotation, delete after
      `DAV_MLM_LOG_RETENTION_DAYS`.
- [ ] `uninstall.php` removes all `dav_mlm_*` options/transients.

## 9. Build & dependencies

- `composer.json` with `"config": {"platform": {"php": "8.3"}}` so the
  lock file matches the server; build with `composer install --no-dev
  --optimize-autoloader`.
- **Namespace-prefix vendored dependencies** with Strauss (or
  PHP-Scoper). `mail-mime-parser` pulls in `guzzlehttp/psr7`, `php-di`,
  `psr/*`; since the runner loads all of WordPress (and all active
  plugins), a different plugin bundling another version of these would
  otherwise clash at autoload time.
- Local dev environment on NixOS: `nix-shell -p php83 php83Packages.composer`
  (plus `php83Extensions.imap` if running the mailbox class locally).
  For DKIM experiments, `python3.withPackages(p: [p.dkimpy p.dnspython])`
  worked for the §4a test.
- **`ext-imap` has no future**: removed from PHP core in 8.4 (PECL only).
  Fine on 8.3 now, but keep all IMAP calls behind `class-mailbox.php` so
  it can be swapped for `webklex/php-imap` (pure-PHP sockets) when IONOS
  moves the webspace to 8.4+ — the rest of the code doesn't change.

## 10. Testing plan

Tooling: PHPUnit (dev dependency, not deployed), run locally with
PHP 8.3 via Nix.

1. **Collect more samples first** (test list, anonymise before
   committing to `tests/fixtures/`): plain-text post from Gmail
   (have it), post from `@dav-neuland.de` via IONOS webmail and via a
   mail client, HTML+text multipart post, post with an attachment, post
   from GMX/web.de/T-Online/Outlook, a German umlaut subject, and a
   post from a member sending via a provider without DKIM.
2. **Offline parser tests**: feed each fixture through
   `class-message-parser.php` (no IMAP) and assert list address,
   confirm URL (incl. soft-wrapped QP), `Absender:`, nested `From:`.
3. **Confirm-link behaviour** — done (issue #8, 2026-09-29): bare
   `curl -sv` GET approves, always `HTTP 200`, no redirects. Success
   marker `<title>Der Vorgang war erfolgreich.</title>`. Reused token
   and broken token both return an identical generic
   `<title>Fehler</title>` page (not distinguishable). `class-approver.php`
   should check the body for the success marker and treat anything else
   as a single "confirm failed" outcome.
4. **DKIM tests** with injectable clock (signatures expire, e.g.
   Gmail `x=` = +7 days) and a stubbed DNS resolver returning the
   recorded key (so tests stay stable after key rotation): pass as-is,
   pass after QP→8bit reconstruction (sample), fail on tampered `From:`,
   fail on non-aligned `d=`, behaviour on multipart fixtures. Record
   how many real fixtures need reconstruction / can't be verified —
   that decides whether `dkim_policy=require` is practical per list.
5. **Unit checks**: URL allowlist accept/reject table (wrong host,
   http, other list address, encoded vs literal `@`, extra query
   params, `@` tricks like `https://ml.kundenserver.de@evil/`);
   notification verifier (forged lower `Authentication-Results`,
   wrong authserv-id); regex `1/0/false` handling; rejector placeholder
   substitution + CR/LF stripping + loop guards + rate limit.
6. **Spoofing test** (answers §12 membership question): send a post
   to the test list with `From:` = an allowed member address but from a
   different envelope/server (e.g. `swaks` from a machine not in the
   domain's SPF). Check whether IONOS forwards it to moderation and that
   the plugin puts it into `Manual` (DKIM fail).
7. **Live mailbox dry run**: `--dry-run` against `noreply@` for a few
   days of real traffic; review the log decisions.
8. **Live end-to-end test**: enable live actions for
   `test.mailingliste@dav-neuland.de` only; verify approve, reject
   (email + silent), manual (unconfigured list, DKIM fail), alert mails,
   folder moves, idempotency (kill the run between action and move).
9. **Deployment checks**: `curl` → 403 for `bin/cron-runner.php`,
   `vendor/…`, the log dir; cron fires every 5 min; status panel
   updates; induce an IMAP login failure and confirm the alert arrives.

## 11. Deployment steps

1. Locally: `composer install --no-dev -o` + Strauss prefixing →
   `vendor/` (committed, since the server has no Composer).
2. `rsync -av --delete --exclude samples/ --exclude tests/ --exclude .git/`
   the plugin folder to `~/DAV-NEU/wp-content/plugins/dav-mailinglist-moderation/`.
3. Create `~/.dav-mlm-logs/` (= `DAV_MLM_DATA_DIR`) with an
   `.htaccess` `Require all denied`; `chmod 700`.
4. Add the `DAV_MLM_*` constants to `~/DAV-NEU/wp-config.php` manually
   (not committed).
5. In the IONOS Control-Center: create/verify the `noreply@dav-neuland.de`
   mailbox and set it as moderator of each list to automate.
6. Activate the plugin in wp-admin; configure the lists.
7. Install the crontab entry (§5b), initially with `--dry-run`.
8. Run §10 steps 7–9, then drop `--dry-run`.
9. Ask the DNS admin to fix the DMARC record (§2a).

## 12. Open questions

Decided (2026-09-28):
- ~~Processed-message strategy~~ → move to folders (§5b), no `\Seen`.
- ~~Unconfigured-list handling~~ → `Manual` folder + digest alert mail.
- ~~Logging surface~~ → all three: log file in protected data dir,
  `error_log()` fallback if the file isn't writable, status panel in
  the admin page.
- ~~Moderator mailbox~~ → configurable, production `noreply@dav-neuland.de`.

Still open:
- **Membership check basis**: does IONOS check list membership against
  the header `From:` or the envelope sender? (Answered by §10 step 6.)
- **Multipart DKIM reconstruction**: how far to go beyond single-part
  QP→8bit — decide after §10 step 4 shows how often it's needed.
- **Default `dkim_policy` for lists with many non-DKIM senders** —
  decide per list once real data exists; `off` is an explicit, logged
  opt-out.
- **Multiple mailing lists now vs later**: is
  `test.mailingliste@dav-neuland.de` the only list to configure
  initially, or are there other real lists to add from day one?
- **Regex scope**: bare email address only, or optionally the display
  name too? (Display names are trivially forgeable and not covered by
  alignment in any useful way — recommendation: address only.)
- **Rejection mail wording / reply contact** per list (`reply_to`).
- **Pending posts at IONOS**: rejecting here only sends an email;
  the post stays in the IONOS queue. Find out whether pending posts
  expire on their own or have to be deleted in the Control-Center
  (and whether the notification offers a reject/delete link we could use).
- **Co-moderators**: can a list have more than one moderator in IONOS
  (e.g. `noreply@` + a human)? If so, a human could approve in parallel —
  harmless for approvals, but worth knowing.
