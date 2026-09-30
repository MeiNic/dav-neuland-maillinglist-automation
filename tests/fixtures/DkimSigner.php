<?php
/**
 * Signs a synthetic message with a freshly generated RSA keypair, for
 * DkimVerifierTest. Deliberately independent of Dav_Mlm_Dkim_Verifier's
 * own canonicalisation code (written separately, not by calling it), so a
 * shared bug in one can't hide behind the other. No real domains/keys are
 * used — DkimVerifierTest injects its own DNS lookup returning the record
 * this class hands back, so tests never depend on live DNS.
 */

declare(strict_types=1);

final class Dav_Mlm_Test_Dkim_Signer {

	/**
	 * @param list<array{0: string, 1: string}> $headers Name/value pairs, in
	 *        message order; every name also appears in $signed_header_names.
	 * @param list<string> $signed_header_names The DKIM-Signature `h=` list.
	 * @return array{raw: string, key_record: string} `raw` is the full
	 *         signed message; `key_record` is the `_domainkey` TXT value.
	 */
	public static function sign(
		string $domain,
		string $selector,
		array $headers,
		string $body,
		array $signed_header_names,
		int $signed_at,
		int $expires_at,
		string $header_canon = 'relaxed',
		string $body_canon = 'relaxed',
		string $digest = 'sha256',
		?int $body_length = null,
		string $extra_tags = ''
	): array {
		[$private_key, $public_key_body] = self::generate_keypair();

		$canonical_body = self::canonicalize_body( $body, $body_canon );
		if ( null !== $body_length ) {
			$canonical_body = substr( $canonical_body, 0, $body_length );
		}
		$bh = base64_encode( hash( $digest, $canonical_body, true ) );

		$tags = sprintf(
			'v=1; a=rsa-%s; c=%s/%s; d=%s; s=%s; h=%s; bh=%s; t=%d; x=%d; %s%sb=',
			$digest,
			$header_canon,
			$body_canon,
			$domain,
			$selector,
			implode( ':', $signed_header_names ),
			$bh,
			$signed_at,
			$expires_at,
			null === $body_length ? '' : "l=$body_length; ",
			'' === $extra_tags ? '' : "$extra_tags; "
		);

		$canonical_lines = array();
		foreach ( $signed_header_names as $name ) {
			$value              = self::header( $headers, $name );
			$canonical_lines[] = self::canonicalize_header( $name, $value, $header_canon );
		}
		$canonical_lines[] = self::canonicalize_header( 'DKIM-Signature', $tags, $header_canon );
		$signed_data       = implode( "\r\n", $canonical_lines );

		openssl_sign( $signed_data, $signature, $private_key, self::openssl_algo( $digest ) );

		$raw_lines = array();
		foreach ( $headers as [$name, $value] ) {
			$raw_lines[] = "$name: $value";
		}
		$raw_lines[] = 'DKIM-Signature: ' . $tags . base64_encode( $signature );

		return array(
			'raw'        => implode( "\r\n", $raw_lines ) . "\r\n\r\n" . $body,
			'key_record' => 'v=DKIM1; k=rsa; p=' . $public_key_body,
		);
	}

	/**
	 * Re-encodes a signed message's body to quoted-printable and rewrites
	 * its Content-Transfer-Encoding header to match — simulating what
	 * IONOS does to an 8bit original before DKIM verification ever sees it
	 * (PLAN.md §4a). The signature is left untouched, so verifying the
	 * result as-is must fail, and only reconstructing it should pass.
	 */
	public static function simulate_ionos_requoting( string $raw ): string {
		[$headers, $body] = explode( "\r\n\r\n", $raw, 2 );

		$headers = preg_replace( '/^Content-Transfer-Encoding:.*$/mi', 'Content-Transfer-Encoding: quoted-printable', $headers );
		$body    = quoted_printable_encode( $body );

		return $headers . "\r\n\r\n" . $body;
	}

	/**
	 * @return array{0: OpenSSLAsymmetricKey, 1: string} Private key and the
	 *         base64 public key body (no PEM armor), as published in a
	 *         `_domainkey` TXT record's `p=` tag.
	 */
	private static function generate_keypair(): array {
		$private_key = openssl_pkey_new(
			array(
				'private_key_bits' => 1024,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		if ( false === $private_key ) {
			throw new RuntimeException( 'Could not generate a test RSA key.' );
		}

		$details = openssl_pkey_get_details( $private_key );
		$public_key_body = preg_replace( '/-----[^-]+-----|\s+/', '', $details['key'] );

		return array( $private_key, $public_key_body );
	}

	private static function openssl_algo( string $digest ): int {
		return 'sha1' === $digest ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
	}

	/**
	 * @param list<array{0: string, 1: string}> $headers
	 */
	private static function header( array $headers, string $name ): string {
		foreach ( $headers as [$header_name, $value] ) {
			if ( 0 === strcasecmp( $header_name, $name ) ) {
				return $value;
			}
		}

		throw new InvalidArgumentException( "No such header to sign: $name" );
	}

	private static function canonicalize_header( string $name, string $value, string $style ): string {
		if ( 'simple' === $style ) {
			return "$name: $value";
		}

		return strtolower( $name ) . ': ' . trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * RFC 6376 §3.4.3 / §3.4.4, done line by line (unlike the verifier's
	 * regex version): relaxed trims every line's trailing whitespace —
	 * the last line's too — and an empty relaxed body is the empty string.
	 */
	private static function canonicalize_body( string $body, string $style ): string {
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $body ) );

		if ( 'relaxed' === $style ) {
			$lines = array_map( static fn ( string $line ): string => rtrim( (string) preg_replace( '/[ \t]+/', ' ', $line ), ' ' ), $lines );
		}

		while ( array() !== $lines && '' === end( $lines ) ) {
			array_pop( $lines );
		}

		if ( array() === $lines ) {
			return 'relaxed' === $style ? '' : "\r\n";
		}

		return implode( "\r\n", $lines ) . "\r\n";
	}
}
