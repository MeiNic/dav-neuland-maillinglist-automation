<?php
/**
 * check-eml-staged.php — pre-commit safety net for .eml fixtures.
 *
 * Reads one file's content from STDIN (as `git show ":<path>"` provides it)
 * and fails (exit 1) if it looks like an unredacted live capture:
 *
 *   - a bracketed IPv4 literal (the form mail headers actually use, e.g.
 *     `[203.0.113.1]`) outside the RFC 5737 documentation ranges — real
 *     network info that `tools/redact-eml.php` would have scrubbed
 *   - what looks like a live Mailinglisten-Manager confirm-link token
 *     (an `id=` value in a `.../Mail/Confirm?...` URL) that isn't the
 *     tool's own placeholder
 *
 * This is a blunt, low-false-positive safety net, not a PII scanner: it
 * cannot detect a real member's address/name sitting in DKIM-signed
 * headers of a genuine capture, because those can't be redacted without
 * breaking the signature the fixture exists to test (see the long comment
 * in redact-eml.php). It only catches the two concrete things that leaked
 * before automatic redaction existed — real IPs and a live-ish token.
 */

declare(strict_types=1);

$path = $argv[1] ?? '(unknown file)';
$raw = stream_get_contents(STDIN);
if ($raw === false || $raw === '') {
    exit(0);
}

$problems = [];

$docRanges = [
    fn(array $o) => $o[0] === 203 && $o[1] === 0 && $o[2] === 113,
    fn(array $o) => $o[0] === 198 && $o[1] === 51 && $o[2] === 100,
    fn(array $o) => $o[0] === 192 && $o[1] === 0 && $o[2] === 2,
    fn(array $o) => $o[0] === 127 && $o[1] === 0 && $o[2] === 0 && $o[3] === 1,
];
if (preg_match_all('/\[(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\]/', $raw, $m, PREG_SET_ORDER)) {
    foreach ($m as $match) {
        $octets = array_map('intval', array_slice($match, 1));
        $isDoc = false;
        foreach ($docRanges as $check) {
            if ($check($octets)) {
                $isDoc = true;
                break;
            }
        }
        if (!$isDoc) {
            $problems[] = "real-looking IP address {$match[0]} (only RFC 5737 doc-range "
                . "addresses — 203.0.113.0/24, 198.51.100.0/24, 192.0.2.0/24 — or 127.0.0.1 are allowed)";
        }
    }
}

$decoded = @quoted_printable_decode($raw) ?: $raw;
if (preg_match('#/Mail/Confirm\?[^\s"\'<>]*\bid=([A-Za-z0-9%_]+)#i', $decoded, $m)) {
    if ($m[1] !== 'EXAMPLE_TOKEN_REDACTED_DO_NOT_USE') {
        $problems[] = "an approval-link id= token that isn't the placeholder value "
            . "(found: " . substr($m[1], 0, 16) . "...)";
    }
}

if (empty($problems)) {
    if (str_contains($raw, 'message/rfc822')) {
        fwrite(STDERR, "check-eml-staged: $path — reminder: if this fixture carries a real "
            . "DKIM signature, its From/Subject/body can't be scrubbed without breaking that "
            . "signature (see tools/redact-eml.php). Capture DKIM-test fixtures from a "
            . "throwaway identity, not a personal one.\n");
    }
    exit(0);
}

fwrite(STDERR, "check-eml-staged: refusing to commit $path — looks like an unredacted capture:\n");
foreach ($problems as $p) {
    fwrite(STDERR, "  - $p\n");
}
fwrite(STDERR, "  Run: php8.3-cli tools/redact-eml.php $path > /tmp/redacted.eml, review the diff, then replace it.\n");
fwrite(STDERR, "  (Force through anyway: git commit --no-verify — only if you've checked this by hand.)\n");
exit(1);
