<?php
/**
 * redact-eml.php — best-effort automatic redaction for captured .eml fixtures.
 *
 * Usage: php8.3-cli tools/redact-eml.php input.eml > output.eml
 *
 * What this does and does NOT do (read this before trusting the output):
 *
 * DKIM signs a specific, named set of headers plus the whole body — that
 * list is right there in the DKIM-Signature's own `h=` tag. Anything in
 * that set (typically From/To/Subject/Date/Message-ID/Content-Type/... and
 * the body) MUST stay byte-identical, or the signature — the exact thing a
 * DKIM-verifier fixture exists to exercise — breaks. This tool therefore:
 *
 *   - Finds the nested `message/rfc822` part (the original list post) and
 *     reads its own DKIM-Signature `h=` list.
 *   - Inside that nested message, redacts IPv4 addresses and stray email
 *     addresses ONLY in headers that are NOT in `h=` (Received,
 *     Return-Path, Delivered-To, Envelope-To, X-Received, X-Originating-IP,
 *     X-Google-DKIM-Signature, X-Gm-*, ARC-*, Received-SPF).
 *   - Leaves every `h=`-listed header, the DKIM-Signature header itself,
 *     and the entire nested body byte-for-byte untouched.
 *   - Redacts IPs the same way in the outer (IONOS) message, drops known
 *     noise headers (UI-InboundReport, UI-OutboundReport, X-UI-Sender-Class,
 *     X-Spam-Flag), redacts addresses in Return-Path/Envelope-To/
 *     Delivered-To, and masks the confirm-link `id=` token — none of that
 *     is ever cryptographically verified by our own code, so it's always
 *     safe to rewrite.
 *   - Prints a report to stderr naming exactly which fields still carry
 *     original, unredacted content and why.
 *
 * The residual: a fixture built to test genuine DKIM verification will
 * still contain the real sender's address, name, and message body — that
 * is unavoidable by construction, not a bug in this tool. Capture future
 * DKIM-test fixtures from a disposable/throwaway sending identity rather
 * than a personal account, so nothing sensitive ends up in that residual.
 *
 * This is a developer tool with a hand-rolled MIME boundary scanner, not a
 * full RFC 5322/2045 parser — always look at the diff before committing.
 */

declare(strict_types=1);

function fail(string $msg): never
{
    fwrite(STDERR, "redact-eml: $msg\n");
    exit(1);
}

$argv0 = $argv[1] ?? null;
if ($argv0 === null) {
    fail("usage: php8.3-cli tools/redact-eml.php input.eml > output.eml");
}
$raw = @file_get_contents($argv0);
if ($raw === false) {
    fail("cannot read $argv0");
}

$NOISE_HEADERS = [
    'received', 'return-path', 'delivered-to', 'envelope-to',
    'x-original-to', 'x-received', 'x-originating-ip',
    'x-google-dkim-signature', 'x-gm-message-state', 'x-gm-gg',
    'arc-seal', 'arc-message-signature', 'arc-authentication-results',
    'received-spf',
];
$ADDRESS_BEARING_NOISE = [
    'return-path', 'delivered-to', 'envelope-to', 'x-original-to',
];
$DROP_ENTIRELY = [
    'ui-inboundreport', 'ui-outboundreport', 'x-ui-sender-class', 'x-spam-flag',
];

/** @return array{0:int,1:int} [headerBlockEnd, bodyStart] offsets of the first blank line */
function findHeaderEnd(string $s, int $from = 0): array
{
    $posCrlf = strpos($s, "\r\n\r\n", $from);
    $posLf = strpos($s, "\n\n", $from);
    if ($posCrlf !== false && ($posLf === false || $posCrlf <= $posLf)) {
        return [$posCrlf, $posCrlf + 4];
    }
    if ($posLf !== false) {
        return [$posLf, $posLf + 2];
    }
    return [strlen($s), strlen($s)];
}

/** @return list<array{name:string,start:int,end:int}> header chunks, offsets absolute into $s */
function splitHeaderChunks(string $s, int $start, int $end): array
{
    $chunks = [];
    $block = substr($s, $start, $end - $start);
    $lines = preg_split('/(?<=\n)/', $block); // keep line terminators
    $offset = $start;
    $curStart = null;
    $curName = null;
    foreach ($lines as $line) {
        $len = strlen($line);
        $isContinuation = $line !== '' && ($line[0] === ' ' || $line[0] === "\t");
        if (!$isContinuation) {
            if ($curStart !== null) {
                $chunks[] = ['name' => $curName, 'start' => $curStart, 'end' => $offset];
            }
            $curStart = $offset;
            $colon = strpos($line, ':');
            $curName = $colon !== false ? strtolower(trim(substr($line, 0, $colon))) : '';
        }
        $offset += $len;
    }
    if ($curStart !== null) {
        $chunks[] = ['name' => $curName, 'start' => $curStart, 'end' => $offset];
    }
    return $chunks;
}

function unfoldHeaderValue(string $s, array $chunk): string
{
    $text = substr($s, $chunk['start'], $chunk['end'] - $chunk['start']);
    $colon = strpos($text, ':');
    $value = $colon !== false ? substr($text, $colon + 1) : $text;
    $value = preg_replace('/\r?\n[ \t]+/', ' ', $value);
    return trim($value);
}

function extractBoundary(string $contentTypeValue): ?string
{
    if (preg_match('/boundary\s*=\s*"([^"]+)"/i', $contentTypeValue, $m)) {
        return $m[1];
    }
    if (preg_match('/boundary\s*=\s*([^;\s]+)/i', $contentTypeValue, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Recursively find every message/rfc822 payload's byte range plus a flat
 * list of "leaf" text/plain parts outside any such payload (candidates for
 * the confirm-URL text).
 *
 * @return array{nested: list<array{start:int,end:int}>, plainLeaves: list<array{headerEnd:int,bodyStart:int,bodyEnd:int}>}
 */
function walkMime(string $s, int $headerStart, int $headerEnd, int $bodyStart, int $bodyEnd, bool $insideNested): array
{
    $chunks = splitHeaderChunks($s, $headerStart, $headerEnd);
    $ctChunk = null;
    foreach ($chunks as $c) {
        if ($c['name'] === 'content-type') {
            $ctChunk = $c;
        }
    }
    $ctValue = $ctChunk ? unfoldHeaderValue($s, $ctChunk) : 'text/plain';
    $nested = [];
    $plainLeaves = [];

    if (preg_match('#^message/rfc822#i', $ctValue)) {
        // The body of this part IS a full raw email — protect it wholesale.
        if (!$insideNested) {
            $nested[] = ['start' => $bodyStart, 'end' => $bodyEnd];
        }
        return ['nested' => $nested, 'plainLeaves' => $plainLeaves];
    }

    if (preg_match('#^multipart/#i', $ctValue)) {
        $boundary = extractBoundary($ctValue);
        if ($boundary === null) {
            return ['nested' => $nested, 'plainLeaves' => $plainLeaves]; // give up gracefully
        }
        $quoted = preg_quote($boundary, '/');
        $body = substr($s, $bodyStart, $bodyEnd - $bodyStart);
        if (!preg_match_all('/(?:\A|\r\n|\n)--' . $quoted . '(--)?[ \t]*(?:\r\n|\n|\z)/', $body, $m, PREG_OFFSET_CAPTURE)) {
            return ['nested' => $nested, 'plainLeaves' => $plainLeaves];
        }
        $starts = [];
        foreach ($m[0] as $i => $match) {
            [$text, $off] = $match;
            $isTerminal = $m[1][$i][0] !== '';
            $partContentStart = $bodyStart + $off + strlen($text);
            $starts[] = ['pos' => $bodyStart + $off, 'contentStart' => $partContentStart, 'terminal' => $isTerminal];
        }
        for ($i = 0; $i < count($starts) - 1; $i++) {
            if ($starts[$i]['terminal']) {
                continue;
            }
            $partStart = $starts[$i]['contentStart'];
            $partEnd = $starts[$i + 1]['pos'];
            [$hEnd, $bStart] = findHeaderEnd($s, $partStart);
            $hEnd = min($hEnd, $partEnd);
            $bStart = min($bStart, $partEnd);
            $sub = walkMime($s, $partStart, $hEnd, $bStart, $partEnd, $insideNested);
            $nested = array_merge($nested, $sub['nested']);
            $plainLeaves = array_merge($plainLeaves, $sub['plainLeaves']);
        }
        return ['nested' => $nested, 'plainLeaves' => $plainLeaves];
    }

    if (!$insideNested && preg_match('#^text/plain#i', $ctValue)) {
        $plainLeaves[] = [
            'headerStart' => $headerStart,
            'headerEnd' => $headerEnd,
            'bodyStart' => $bodyStart,
            'bodyEnd' => $bodyEnd,
        ];
    }
    return ['nested' => $nested, 'plainLeaves' => $plainLeaves];
}

/** @var list<array{0:int,1:int,2:string}> $patches [start, end, replacement] against original $raw */
$patches = [];
$ipCounter = 0;
$ipMap = [];
function fakeIp(): string
{
    global $ipCounter, $ipMap;
    // RFC 5737 documentation ranges; cycle through all three /24s if needed.
    $blocks = ['203.0.113', '198.51.100', '192.0.2'];
    $block = $blocks[intdiv($ipCounter, 254) % count($blocks)];
    $host = ($ipCounter % 254) + 1;
    $ipCounter++;
    return "$block.$host";
}
function redactIpsIn(string $s, int $start, int $end, array &$patches): int
{
    global $ipMap;
    $text = substr($s, $start, $end - $start);
    // Mail headers always present IP literals bracketed — `[1.2.3.4]` (or
    // `([1.2.3.4])`) — per RFC 5321. Matching only inside brackets, rather
    // than a bare dotted-quad pattern, avoids false positives on unrelated
    // dotted numeric runs (SMTP session/timestamp-like ids such as
    // "...0.2026.08.16.06.26.14" can otherwise look like a dotted quad).
    $count = preg_match_all(
        '/\[(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\]/',
        $text,
        $m,
        PREG_OFFSET_CAPTURE
    );
    if (!$count) {
        return 0;
    }
    foreach ($m[1] as $match) {
        [$ip, $off] = $match;
        if (!isset($ipMap[$ip])) {
            $ipMap[$ip] = fakeIp();
        }
        $patches[] = [$start + $off, $start + $off + strlen($ip), $ipMap[$ip]];
    }
    return $count;
}
function redactAddressesIn(string $s, int $start, int $end, array &$patches): int
{
    $text = substr($s, $start, $end - $start);
    $count = preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m, PREG_OFFSET_CAPTURE);
    if (!$count) {
        return 0;
    }
    foreach ($m[0] as $match) {
        [$addr, $off] = $match;
        $patches[] = [$start + $off, $start + $off + strlen($addr), 'redacted@example.invalid'];
    }
    return $count;
}

function qpDecode(string $s): string
{
    return quoted_printable_decode($s);
}
function qpEncode(string $s): string
{
    return str_replace("\n", "\r\n", quoted_printable_encode($s));
}

[$outerHeaderEnd, $outerBodyStart] = findHeaderEnd($raw);
$outerChunks = splitHeaderChunks($raw, 0, $outerHeaderEnd);

$report = [];

// --- Outer message: drop pure-noise headers, redact IPs/addresses in
//     Received-like headers, everywhere — none of this is ever verified
//     cryptographically by our own code (we only read Authentication-Results
//     and From as plain strings), so it is always safe to rewrite.
foreach ($outerChunks as $c) {
    if (in_array($c['name'], $GLOBALS['DROP_ENTIRELY'], true)) {
        $patches[] = [$c['start'], $c['end'], ''];
        $report[] = "dropped outer header: {$c['name']}";
        continue;
    }
    if (in_array($c['name'], $GLOBALS['NOISE_HEADERS'], true)) {
        $n = redactIpsIn($raw, $c['start'], $c['end'], $patches);
        if (in_array($c['name'], $GLOBALS['ADDRESS_BEARING_NOISE'], true)) {
            $n += redactAddressesIn($raw, $c['start'], $c['end'], $patches);
        }
        if ($n > 0) {
            $report[] = "redacted {$n} value(s) in outer header: {$c['name']}";
        }
    }
}

// --- Walk the MIME tree to find the nested message/rfc822 payload and the
//     outer human-readable text/plain part (which carries the confirm URL).
$walk = walkMime($raw, 0, $outerHeaderEnd, $outerBodyStart, strlen($raw), false);

foreach ($walk['plainLeaves'] as $leaf) {
    $leafChunks = splitHeaderChunks($raw, $leaf['headerStart'], $leaf['headerEnd']);
    $cte = ''; // absent Content-Transfer-Encoding defaults to 7bit (RFC 2045 §6.1)
    foreach ($leafChunks as $c) {
        if ($c['name'] === 'content-transfer-encoding') {
            $cte = strtolower(unfoldHeaderValue($raw, $c));
        }
    }
    $bodyText = substr($raw, $leaf['bodyStart'], $leaf['bodyEnd'] - $leaf['bodyStart']);
    // Percent-encoded tokens seen so far use only [A-Za-z0-9%]; -_ included
    // defensively in case a future token uses literal base64url characters.
    $tokenPattern = '/(id=)[A-Za-z0-9%_-]+/';
    $tokenCount = 0;

    if (str_contains($cte, 'quoted-printable')) {
        $decoded = qpDecode($bodyText);
        $newDecoded = preg_replace($tokenPattern, '${1}EXAMPLE_TOKEN_REDACTED_DO_NOT_USE', $decoded, -1, $tokenCount);
        if ($tokenCount > 0) {
            $patches[] = [$leaf['bodyStart'], $leaf['bodyEnd'], qpEncode($newDecoded)];
        }
    } elseif (str_contains($cte, 'base64')) {
        $report[] = "confirm-link candidate part is base64-encoded — token NOT auto-masked "
            . "(decoding/re-encoding it blind risks corrupting the part); redact it by hand if it contains a real token.";
        continue;
    } else {
        // 7bit / 8bit / binary / absent: plain text, no transport encoding to round-trip.
        $newText = preg_replace($tokenPattern, '${1}EXAMPLE_TOKEN_REDACTED_DO_NOT_USE', $bodyText, -1, $tokenCount);
        if ($tokenCount > 0) {
            $patches[] = [$leaf['bodyStart'], $leaf['bodyEnd'], $newText];
        }
    }
    if ($tokenCount > 0) {
        $report[] = "masked confirm-link token in outer text/plain part ({$tokenCount} occurrence(s))";
    }
}

foreach ($walk['nested'] as $n) {
    [$nHeaderEnd, $nBodyStart] = findHeaderEnd($raw, $n['start']);
    $nHeaderEnd = min($nHeaderEnd, $n['end']);
    $nBodyStart = min($nBodyStart, $n['end']);
    $nChunks = splitHeaderChunks($raw, $n['start'], $nHeaderEnd);

    $signed = [];
    foreach ($nChunks as $c) {
        if ($c['name'] === 'dkim-signature') {
            $val = unfoldHeaderValue($raw, $c);
            if (preg_match('/(?:^|;)\s*h=([^;]+)/i', $val, $m)) {
                foreach (explode(':', $m[1]) as $h) {
                    $signed[strtolower(trim($h))] = true;
                }
            }
        }
    }

    if (empty($signed)) {
        $report[] = "WARNING: nested message has no parseable DKIM-Signature h= list — "
            . "treating ALL its headers as unprotected is unsafe, so none were touched. "
            . "Redact this fixture by hand if it needs to be shared.";
        continue;
    }

    $untouchedSignedNames = [];
    foreach ($nChunks as $c) {
        if ($c['name'] === 'dkim-signature') {
            $untouchedSignedNames[] = 'dkim-signature (signature itself)';
            continue; // never touch — this IS the thing under test
        }
        if (isset($signed[$c['name']])) {
            $untouchedSignedNames[] = $c['name'];
            continue; // DKIM-covered — touching it invalidates the signature
        }
        if (in_array($c['name'], $GLOBALS['DROP_ENTIRELY'], true)) {
            // Don't drop headers inside a signed message (would shift
            // nothing signature-relevant, but keep nested messages minimally
            // invasive); just redact if they're noise-with-addresses/IPs.
        }
        if (in_array($c['name'], $GLOBALS['NOISE_HEADERS'], true)) {
            $cnt = redactIpsIn($raw, $c['start'], $c['end'], $patches);
            if (in_array($c['name'], $GLOBALS['ADDRESS_BEARING_NOISE'], true)) {
                $cnt += redactAddressesIn($raw, $c['start'], $c['end'], $patches);
            }
            if ($cnt > 0) {
                $report[] = "redacted {$cnt} value(s) in nested header: {$c['name']} (not in DKIM h=)";
            }
        }
    }
    $report[] = "kept unmodified, REQUIRED for DKIM signature validity: "
        . implode(', ', array_unique($untouchedSignedNames)) . ", and the entire message body.";
    $report[] = "^ if any of those still contain a real person's address or name, "
        . "capture future DKIM-test fixtures from a disposable/throwaway sending "
        . "identity instead of a personal account — this cannot be fixed by redaction "
        . "without breaking the signature the fixture exists to test.";
}

// Apply patches back-to-front so earlier offsets stay valid.
usort($patches, fn($a, $b) => $b[0] <=> $a[0]);
$out = $raw;
foreach ($patches as [$start, $end, $replacement]) {
    $out = substr($out, 0, $start) . $replacement . substr($out, $end);
}

fwrite(STDOUT, $out);
fwrite(STDERR, "--- redact-eml report ---\n");
foreach ($report as $line) {
    fwrite(STDERR, "  $line\n");
}
if (empty($walk['nested'])) {
    fwrite(STDERR, "  no message/rfc822 part found — nothing DKIM-sensitive was protected because there was nothing to protect.\n");
}
