<?php
/**
 * Verifies the DKIM signature(s) on the nested message (the original post
 * to the list), so a forged `From:` of a member who is allowed to post
 * can't slip through (PLAN.md §4a and §5b step 4g). Works on raw bytes
 * only — no MailMimeParser dependency — so it can be exercised directly
 * against the `nested_raw` bytes Dav_Mlm_Message_Parser already extracts.
 *
 * `phpmailer/dkimvalidator` (vendored, see composer.json) was the planned
 * first choice, but its DKIM-Signature expiry check calls PHP's global
 * `time()` directly with no override point, so tests can never pretend
 * it's an earlier "now" — and every real signature in a fixture (e.g.
 * Gmail's `x=` = send time + 7 days) is long expired by the time a test
 * runs it. This is the small own verifier PLAN.md §4a names as the
 * fallback for exactly that case: RFC 6376 relaxed/simple canonicalisation
 * plus `openssl_verify`, with clock and DNS lookup both passed in instead
 * of called globally.
 *
 * Pass requires at least one signature that is cryptographically valid
 * *and* whose `d=` is aligned with the caller-supplied From address
 * (DMARC-relaxed alignment: equal, or From is a subdomain of `d=`).
 * A merely-valid-but-unaligned signature does not count — see
 * is_aligned(). Verification is tried on the raw bytes as-is first; if
 * that fails and the message is single-part with
 * `Content-Transfer-Encoding: quoted-printable` or `base64`, it is retried
 * once against a reconstruction (decode body, normalise to CRLF, put the
 * CTE header back to `8bit`) that undoes IONOS's re-encoding of the
 * original post (PLAN.md §4a). Multipart originals are not reconstructed —
 * out of scope for now, see PLAN.md §12.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Dkim_Verifier {

	private const SUPPORTED_DIGESTS = array( 'sha1', 'sha256' );

	/** @var callable(string $domain, string $selector): (list<string>|false) */
	private $dns_lookup;

	/**
	 * @param callable(string $domain, string $selector): (list<string>|false)|null $dns_lookup
	 *        Returns the TXT record value(s) for `<selector>._domainkey.<domain>`
	 *        (already reassembled if a record was split into multiple
	 *        strings), or false on a transient DNS failure. Defaults to a
	 *        wrapper around dns_get_record(); tests must supply their own
	 *        so they never depend on live DNS/keys.
	 */
	public function __construct( ?callable $dns_lookup = null ) {
		$this->dns_lookup = $dns_lookup ?? self::default_dns_lookup();
	}

	public function verify( string $raw, string $from_address, DateTimeImmutable $now ): Dav_Mlm_Dkim_Result {
		try {
			return $this->verify_or_throw( $raw, $from_address, $now );
		} catch ( Throwable $error ) {
			return Dav_Mlm_Dkim_Result::failure( Dav_Mlm_Dkim_Result::FAIL, get_class( $error ) . ': ' . $error->getMessage() );
		}
	}

	private function verify_or_throw( string $raw, string $from_address, DateTimeImmutable $now ): Dav_Mlm_Dkim_Result {
		$from_domain = $this->domain_of( $from_address );
		if ( null === $from_domain ) {
			return Dav_Mlm_Dkim_Result::failure( Dav_Mlm_Dkim_Result::FAIL, 'The From address has no domain: ' . $from_address );
		}

		$result = $this->attempt( $raw, $from_domain, $now, Dav_Mlm_Dkim_Result::PATH_AS_IS );
		if ( $result->is_pass()
			|| Dav_Mlm_Dkim_Result::NO_SIGNATURE === $result->failure_reason()
			|| Dav_Mlm_Dkim_Result::DNS_ERROR === $result->failure_reason()
		) {
			return $result;
		}

		$reconstructed = $this->reconstruct( $raw );
		if ( null === $reconstructed ) {
			return $result;
		}

		$retry = $this->attempt( $reconstructed, $from_domain, $now, Dav_Mlm_Dkim_Result::PATH_RECONSTRUCTED );

		return $retry->is_pass() ? $retry : $result;
	}

	/**
	 * Verifies every DKIM-Signature header found in $raw and returns the
	 * first aligned pass, or the most informative failure otherwise: a DNS
	 * error takes priority over a plain failure (it's the transient one),
	 * and "no signature at all" is reported as such rather than as a
	 * generic failure.
	 */
	private function attempt( string $raw, string $from_domain, DateTimeImmutable $now, string $path ): Dav_Mlm_Dkim_Result {
		$split = $this->split_message( $raw );
		if ( null === $split ) {
			return Dav_Mlm_Dkim_Result::failure( Dav_Mlm_Dkim_Result::FAIL, 'Message has no header/body separator.' );
		}

		$signatures = $this->named_headers( $split['headers'], 'DKIM-Signature' );
		if ( empty( $signatures ) ) {
			return Dav_Mlm_Dkim_Result::failure( Dav_Mlm_Dkim_Result::NO_SIGNATURE, 'No DKIM-Signature header.' );
		}

		$evaluations = array();
		foreach ( $signatures as $signature ) {
			$evaluations[] = $this->evaluate_signature( $split['headers'], $split['body'], $signature, $now );
		}

		foreach ( $evaluations as $evaluation ) {
			if ( 'pass' === $evaluation['status'] && null !== $evaluation['domain'] && $this->is_aligned( $evaluation['domain'], $from_domain ) ) {
				return Dav_Mlm_Dkim_Result::pass( $path, $evaluation['domain'] );
			}
		}

		foreach ( $evaluations as $evaluation ) {
			if ( 'dns_error' === $evaluation['status'] ) {
				return Dav_Mlm_Dkim_Result::failure( Dav_Mlm_Dkim_Result::DNS_ERROR, $evaluation['reason'] );
			}
		}

		$reasons = array_map(
			static fn ( array $e ): string => ( $e['domain'] ?? '?' ) . ': ' . $e['reason'],
			$evaluations
		);

		return Dav_Mlm_Dkim_Result::failure( Dav_Mlm_Dkim_Result::FAIL, implode( ' | ', $reasons ) );
	}

	/**
	 * @param list<array{name: string, raw: string, value: string}> $headers
	 * @param array{name: string, raw: string, value: string}       $signature
	 * @return array{status: string, domain: string|null, reason: string}
	 */
	private function evaluate_signature( array $headers, string $body, array $signature, DateTimeImmutable $now ): array {
		$tags = $this->parse_tag_list( $signature['value'], ';' );
		if ( null === $tags ) {
			return array( 'status' => 'fail', 'domain' => null, 'reason' => 'Malformed DKIM-Signature header.' );
		}

		foreach ( array( 'v', 'a', 'b', 'bh', 'd', 'h', 's' ) as $required ) {
			if ( empty( $tags[ $required ] ) ) {
				return array( 'status' => 'fail', 'domain' => $tags['d'] ?? null, 'reason' => "Missing or empty tag: $required" );
			}
		}

		$domain = strtolower( $tags['d'] );

		if ( '1' !== $tags['v'] ) {
			return array( 'status' => 'fail', 'domain' => $domain, 'reason' => 'Unsupported DKIM version: ' . $tags['v'] );
		}

		if ( isset( $tags['x'] ) && '' !== $tags['x'] && ctype_digit( $tags['x'] ) && (int) $tags['x'] < $now->getTimestamp() ) {
			return array( 'status' => 'fail', 'domain' => $domain, 'reason' => 'Signature expired at ' . gmdate( 'c', (int) $tags['x'] ) . '.' );
		}

		$signed_names = array_values( array_filter( array_map( 'trim', explode( ':', $tags['h'] ) ), static fn ( string $n ): bool => '' !== $n ) );
		if ( ! in_array( 'from', array_map( 'strtolower', $signed_names ), true ) ) {
			return array( 'status' => 'fail', 'domain' => $domain, 'reason' => 'From header not in the signed header list.' );
		}

		$canon = explode( '/', $tags['c'] ?? 'simple/simple', 2 );
		$header_style = $canon[0] ?? 'simple';
		$body_style   = $canon[1] ?? 'simple';
		if ( ! in_array( $header_style, array( 'relaxed', 'simple' ), true ) || ! in_array( $body_style, array( 'relaxed', 'simple' ), true ) ) {
			return array( 'status' => 'fail', 'domain' => $domain, 'reason' => 'Unknown canonicalisation: ' . ( $tags['c'] ?? '' ) );
		}

		$alg_parts = explode( '-', $tags['a'], 2 );
		if ( 2 !== count( $alg_parts ) || 'rsa' !== $alg_parts[0] || ! in_array( $alg_parts[1], self::SUPPORTED_DIGESTS, true ) ) {
			return array( 'status' => 'fail', 'domain' => $domain, 'reason' => 'Unsupported signature algorithm: ' . $tags['a'] );
		}
		$digest = $alg_parts[1];

		$canonical_body = $this->canonicalize_body( $body, $body_style );
		if ( isset( $tags['l'] ) && '' !== $tags['l'] && ctype_digit( $tags['l'] ) ) {
			$canonical_body = substr( $canonical_body, 0, (int) $tags['l'] );
		}

		if ( ! hash_equals( $tags['bh'], base64_encode( hash( $digest, $canonical_body, true ) ) ) ) {
			return array( 'status' => 'fail', 'domain' => $domain, 'reason' => 'Body hash mismatch.' );
		}

		$selected = $this->select_signed_headers( $headers, $signed_names );
		$canonical_lines = array();
		foreach ( $selected as $header ) {
			$canonical_lines[] = $this->canonicalize_header( $header, $header_style );
		}
		$canonical_lines[] = $this->canonicalize_header(
			array(
				'name'  => $signature['name'],
				// Blank out the `b=` value before hashing, in both the raw
				// (for "simple") and unfolded (for "relaxed") forms — same
				// bytes otherwise, original folding/whitespace untouched.
				'raw'   => preg_replace( '/b=(.*?)(;|$)/s', 'b=$2', $signature['raw'], 1 ),
				'value' => preg_replace( '/b=(.*?)(;|$)/s', 'b=$2', $signature['value'], 1 ),
			),
			$header_style
		);
		$signed_data = implode( "\r\n", $canonical_lines );

		$keys = ( $this->dns_lookup )( $domain, $tags['s'] );
		if ( false === $keys ) {
			return array( 'status' => 'dns_error', 'domain' => $domain, 'reason' => 'DNS lookup failed for ' . $tags['s'] . '._domainkey.' . $domain . '.' );
		}
		if ( empty( $keys ) ) {
			return array( 'status' => 'fail', 'domain' => $domain, 'reason' => 'No DKIM key published for ' . $tags['s'] . '._domainkey.' . $domain . '.' );
		}

		foreach ( $keys as $record ) {
			if ( $this->verify_against_key( $record, $digest, $tags['b'], $signed_data ) ) {
				return array( 'status' => 'pass', 'domain' => $domain, 'reason' => '' );
			}
		}

		return array( 'status' => 'fail', 'domain' => $domain, 'reason' => 'Signature did not verify against the published key(s).' );
	}

	private function verify_against_key( string $record, string $digest, string $signature_b64, string $signed_data ): bool {
		$key_tags = $this->parse_tag_list( $record, ';' );
		if ( null === $key_tags || empty( $key_tags['p'] ) ) {
			return false; // Absent/empty p= means the key was revoked.
		}
		if ( isset( $key_tags['k'] ) && '' !== $key_tags['k'] && 'rsa' !== strtolower( $key_tags['k'] ) ) {
			return false;
		}
		if ( isset( $key_tags['h'] ) && '' !== $key_tags['h']
			&& ! in_array( $digest, array_map( 'strtolower', array_map( 'trim', explode( ':', $key_tags['h'] ) ) ), true )
		) {
			return false;
		}

		$public_key_body = preg_replace( '/\s+/', '', $key_tags['p'] );
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( $public_key_body, 64, "\n" ) . "-----END PUBLIC KEY-----\n";

		$signature = base64_decode( $signature_b64, true );
		if ( false === $signature ) {
			return false;
		}

		return 1 === @openssl_verify( $signed_data, $signature, $pem, $digest );
	}

	/**
	 * Undoes IONOS's re-encoding of a single-part original post (PLAN.md
	 * §4a): decode the body, normalise it to CRLF, and put the
	 * Content-Transfer-Encoding header back to `8bit`. Returns null when
	 * the message isn't eligible (multipart, or no qp/base64 CTE to undo).
	 */
	private function reconstruct( string $raw ): ?string {
		$split = $this->split_message( $raw );
		if ( null === $split ) {
			return null;
		}
		$headers = $split['headers'];

		if ( $this->is_multipart( $this->header_value( $headers, 'Content-Type' ) ) ) {
			return null;
		}

		$cte = $this->header_value( $headers, 'Content-Transfer-Encoding' );
		$cte = null === $cte ? null : strtolower( $cte );

		if ( 'quoted-printable' === $cte ) {
			$decoded = quoted_printable_decode( $split['body'] );
		} elseif ( 'base64' === $cte ) {
			$decoded = base64_decode( $split['body'], true );
			if ( false === $decoded ) {
				return null;
			}
		} else {
			return null;
		}

		$decoded = str_replace( array( "\r\n", "\r" ), array( "\n", "\n" ), $decoded );
		$decoded = str_replace( "\n", "\r\n", $decoded );

		$found_cte  = false;
		$new_lines  = array();
		foreach ( $headers as $header ) {
			if ( 0 === strcasecmp( trim( $header['name'] ), 'Content-Transfer-Encoding' ) ) {
				$new_lines[] = 'Content-Transfer-Encoding: 8bit';
				$found_cte   = true;
				continue;
			}
			$new_lines[] = $header['raw'];
		}
		if ( ! $found_cte ) {
			return null;
		}

		return implode( "\r\n", $new_lines ) . "\r\n\r\n" . $decoded;
	}

	private function is_multipart( ?string $content_type ): bool {
		if ( null === $content_type ) {
			return false;
		}

		return str_starts_with( strtolower( trim( explode( ';', $content_type, 2 )[0] ) ), 'multipart/' );
	}

	/**
	 * DMARC-relaxed alignment: the From domain equals the signing domain,
	 * or is a subdomain of it.
	 */
	private function is_aligned( string $signing_domain, string $from_domain ): bool {
		$signing_domain = strtolower( $signing_domain );

		return $signing_domain === $from_domain || str_ends_with( $from_domain, '.' . $signing_domain );
	}

	private function domain_of( string $address ): ?string {
		$at = strrpos( $address, '@' );
		if ( false === $at ) {
			return null;
		}

		$domain = strtolower( substr( $address, $at + 1 ) );

		return '' === $domain ? null : $domain;
	}

	/**
	 * Splits a raw message into its headers (parsed) and body, after
	 * normalising every line ending to CRLF.
	 *
	 * @return array{headers: list<array{name: string, raw: string, value: string}>, body: string}|null
	 */
	private function split_message( string $raw ): ?array {
		$normalized = str_replace( array( "\r\n", "\r" ), array( "\n", "\n" ), $raw );
		$normalized = str_replace( "\n", "\r\n", $normalized );

		$parts = explode( "\r\n\r\n", $normalized, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		return array(
			'headers' => $this->parse_headers( $parts[0] ),
			'body'    => $parts[1],
		);
	}

	/**
	 * @return list<array{name: string, raw: string, value: string}>
	 */
	private function parse_headers( string $header_block ): array {
		if ( '' === $header_block ) {
			return array();
		}

		$headers = array();
		$lines   = array();
		$name    = null;

		foreach ( explode( "\r\n", $header_block ) as $line ) {
			if ( '' !== $line && ( ' ' === $line[0] || "\t" === $line[0] ) && null !== $name ) {
				$lines[] = $line;
				continue;
			}

			if ( null !== $name ) {
				$headers[] = $this->finish_header( $name, $lines );
			}

			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				$name = null;
				continue;
			}

			$name  = substr( $line, 0, $colon );
			$lines = array( $line );
		}

		if ( null !== $name ) {
			$headers[] = $this->finish_header( $name, $lines );
		}

		return $headers;
	}

	/**
	 * @param list<string> $lines
	 * @return array{name: string, raw: string, value: string}
	 */
	private function finish_header( string $name, array $lines ): array {
		$raw = implode( "\r\n", $lines );

		return array(
			'name'  => $name,
			'raw'   => $raw,
			'value' => substr( $raw, strlen( $name ) + 1 ),
		);
	}

	/**
	 * @param list<array{name: string, raw: string, value: string}> $headers
	 * @return list<array{name: string, raw: string, value: string}>
	 */
	private function named_headers( array $headers, string $name ): array {
		return array_values( array_filter( $headers, static fn ( array $h ): bool => 0 === strcasecmp( trim( $h['name'] ), $name ) ) );
	}

	/**
	 * Unfolded, trimmed value of the first header with this name, or null.
	 *
	 * @param list<array{name: string, raw: string, value: string}> $headers
	 */
	private function header_value( array $headers, string $name ): ?string {
		$matches = $this->named_headers( $headers, $name );
		if ( empty( $matches ) ) {
			return null;
		}

		return trim( (string) preg_replace( '/\r\n[ \t]+/', ' ', $matches[0]['value'] ) );
	}

	/**
	 * Selects, for each name in $names (order and repeats as given), the
	 * next not-yet-used header with that name counting from the bottom of
	 * the message upward (RFC 6376 §5.4.2) — so `h=from:from` on a message
	 * with two From headers signs the last one, then the second-to-last.
	 * A name with no (remaining) header is simply skipped, per spec.
	 *
	 * @param list<array{name: string, raw: string, value: string}> $headers
	 * @param list<string>                                          $names
	 * @return list<array{name: string, raw: string, value: string}>
	 */
	private function select_signed_headers( array $headers, array $names ): array {
		$by_name = array();
		foreach ( $headers as $i => $header ) {
			$by_name[ strtolower( trim( $header['name'] ) ) ][] = $i;
		}
		foreach ( $by_name as &$indices ) {
			$indices = array_reverse( $indices );
		}

		$selected = array();
		foreach ( $names as $name ) {
			$key = strtolower( trim( $name ) );
			if ( empty( $by_name[ $key ] ) ) {
				continue;
			}
			$selected[] = $headers[ array_shift( $by_name[ $key ] ) ];
		}

		return $selected;
	}

	/**
	 * @param array{name: string, raw: string, value: string} $header
	 */
	private function canonicalize_header( array $header, string $style ): string {
		if ( 'simple' === $style ) {
			return $header['raw'];
		}

		$name  = strtolower( trim( $header['name'] ) );
		$value = preg_replace( '/\r\n[ \t]+/', ' ', $header['value'] ); // Unfold.
		$value = trim( (string) preg_replace( '/[ \t]+/', ' ', $value ) ); // Collapse + trim.

		return $name . ': ' . $value;
	}

	private function canonicalize_body( string $body, string $style ): string {
		$body = str_replace( array( "\r\n", "\r" ), array( "\n", "\n" ), $body );
		$body = str_replace( "\n", "\r\n", $body );

		if ( '' === $body ) {
			return "\r\n";
		}

		if ( 'relaxed' === $style ) {
			$body = (string) preg_replace( '/[ \t]+/', ' ', $body );
			$body = (string) preg_replace( '/ (\r\n)/', '$1', $body );
		}

		$body = (string) preg_replace( '/(\r\n)+\z/', '', $body );

		return $body . "\r\n";
	}

	/**
	 * Splits a `;`-separated `tag=value` list (a DKIM-Signature header
	 * value, or a `_domainkey` TXT record) into a name => value map. All
	 * whitespace is stripped first, since none of the defined tags carry
	 * meaningful internal whitespace once RFC 5322 folding is removed.
	 *
	 * @return array<string, string>|null Null on a malformed tag.
	 */
	private function parse_tag_list( string $value, string $separator ): ?array {
		$stripped = preg_replace( '/\s+/', '', $value );
		if ( null === $stripped ) {
			return null;
		}
		$stripped = rtrim( $stripped, $separator );

		$tags = array();
		foreach ( '' === $stripped ? array() : explode( $separator, $stripped ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$eq = strpos( $pair, '=' );
			if ( false === $eq ) {
				return null;
			}
			$tags[ substr( $pair, 0, $eq ) ] = substr( $pair, $eq + 1 );
		}

		return $tags;
	}

	private static function default_dns_lookup(): callable {
		return static function ( string $domain, string $selector ) {
			$host    = sprintf( '%s._domainkey.%s', $selector, $domain );
			$records = @dns_get_record( $host, DNS_TXT );
			if ( false === $records ) {
				return false;
			}

			$result = array();
			foreach ( $records as $record ) {
				if ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
					$result[] = implode( '', $record['entries'] );
				} elseif ( isset( $record['txt'] ) ) {
					$result[] = $record['txt'];
				}
			}

			return $result;
		};
	}
}
