<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/DkimSigner.php';

use PHPUnit\Framework\TestCase;

final class DkimVerifierTest extends TestCase {

	private const DOMAIN   = 'example.com';
	private const SELECTOR = 'sel1';
	private const FROM     = 'alice@example.com';

	private function dns_lookup_for( string $key_record, string $domain = self::DOMAIN, string $selector = self::SELECTOR ): callable {
		return static function ( string $lookup_domain, string $lookup_selector ) use ( $key_record, $domain, $selector ): array {
			return $lookup_domain === $domain && $lookup_selector === $selector ? array( $key_record ) : array();
		};
	}

	/**
	 * @return array{raw: string, key_record: string, signed_at: int, expires_at: int}
	 */
	private function signed_message( array $overrides = array() ): array {
		$signed_at  = $overrides['signed_at'] ?? 1_000_000_000;
		$expires_at = $overrides['expires_at'] ?? $signed_at + 7 * 24 * 3600;

		$signed = Dav_Mlm_Test_Dkim_Signer::sign(
			$overrides['domain'] ?? self::DOMAIN,
			$overrides['selector'] ?? self::SELECTOR,
			$overrides['headers'] ?? array(
				array( 'From', self::FROM ),
				array( 'To', 'list@dav-neuland.de' ),
				array( 'Subject', 'Test post' ),
			),
			$overrides['body'] ?? "Hello list.\r\n",
			$overrides['signed_header_names'] ?? array( 'From', 'To', 'Subject' ),
			$signed_at,
			$expires_at,
			$overrides['header_canon'] ?? 'relaxed',
			$overrides['body_canon'] ?? 'relaxed',
			$overrides['digest'] ?? 'sha256'
		);

		return $signed + array(
			'signed_at'  => $signed_at,
			'expires_at' => $expires_at,
		);
	}

	private function now( int $timestamp ): DateTimeImmutable {
		return ( new DateTimeImmutable() )->setTimestamp( $timestamp );
	}

	public function test_a_genuine_signature_passes_as_is(): void {
		$message  = $this->signed_message();
		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( $message['key_record'] ) );

		$result = $verifier->verify( $message['raw'], self::FROM, $this->now( $message['signed_at'] + 10 ) );

		self::assertTrue( $result->is_pass(), $result->detail() );
		self::assertSame( Dav_Mlm_Dkim_Result::PATH_AS_IS, $result->path() );
		self::assertSame( self::DOMAIN, $result->signing_domain() );
	}

	public function test_simple_canonicalisation_also_passes(): void {
		$message  = $this->signed_message(
			array(
				'header_canon' => 'simple',
				'body_canon'   => 'simple',
			)
		);
		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( $message['key_record'] ) );

		$result = $verifier->verify( $message['raw'], self::FROM, $this->now( $message['signed_at'] + 10 ) );

		self::assertTrue( $result->is_pass(), $result->detail() );
	}

	public function test_qp_requoting_fails_as_is_but_passes_after_reconstruction(): void {
		$message = $this->signed_message(
			array(
				'headers' => array(
					array( 'From', self::FROM ),
					array( 'To', 'list@dav-neuland.de' ),
					array( 'Subject', 'Test post' ),
					array( 'Content-Transfer-Encoding', '8bit' ),
				),
				'signed_header_names' => array( 'From', 'To', 'Subject' ),
				// A non-ASCII byte forces quoted_printable_encode() to
				// actually change the body bytes, so the body hash really
				// does need the reconstruction to match again.
				'body' => "Gr\xc3\xbc\xc3\x9fe vom Vorstand!\r\n",
			)
		);
		$requoted = Dav_Mlm_Test_Dkim_Signer::simulate_ionos_requoting( $message['raw'] );
		self::assertStringContainsString( 'Content-Transfer-Encoding: quoted-printable', $requoted, 'Test setup: requoting should have rewritten the CTE header.' );

		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( $message['key_record'] ) );

		$result = $verifier->verify( $requoted, self::FROM, $this->now( $message['signed_at'] + 10 ) );

		self::assertTrue( $result->is_pass(), $result->detail() );
		self::assertSame( Dav_Mlm_Dkim_Result::PATH_RECONSTRUCTED, $result->path() );
	}

	public function test_base64_requoting_also_reconstructs(): void {
		$message = $this->signed_message(
			array(
				'headers' => array(
					array( 'From', self::FROM ),
					array( 'Content-Transfer-Encoding', '8bit' ),
				),
				'signed_header_names' => array( 'From' ),
				'body' => "Gr\xc3\xbc\xc3\x9fe!\r\n",
			)
		);

		[$headers, $body] = explode( "\r\n\r\n", $message['raw'], 2 );
		$headers  = preg_replace( '/^Content-Transfer-Encoding:.*$/mi', 'Content-Transfer-Encoding: base64', $headers );
		$requoted = $headers . "\r\n\r\n" . chunk_split( base64_encode( $body ) );

		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( $message['key_record'] ) );

		$result = $verifier->verify( $requoted, self::FROM, $this->now( $message['signed_at'] + 10 ) );

		self::assertTrue( $result->is_pass(), $result->detail() );
		self::assertSame( Dav_Mlm_Dkim_Result::PATH_RECONSTRUCTED, $result->path() );
	}

	public function test_multipart_originals_are_never_reconstructed(): void {
		$message = $this->signed_message(
			array(
				'headers' => array(
					array( 'From', self::FROM ),
					array( 'Content-Type', 'multipart/mixed; boundary="x"' ),
					array( 'Content-Transfer-Encoding', 'quoted-printable' ),
				),
				'signed_header_names' => array( 'From' ),
				'body' => "--x\r\nGr=C3=BC=C3=9Fe!\r\n--x--\r\n",
			)
		);
		// Corrupt the body so the as-is body hash fails, the way a real
		// multipart re-encoding would; reconstruction must not be tried.
		$tampered = str_replace( 'Gr=C3=BC', 'Gr=C3=BD', $message['raw'] );

		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( $message['key_record'] ) );

		$result = $verifier->verify( $tampered, self::FROM, $this->now( $message['signed_at'] + 10 ) );

		self::assertFalse( $result->is_pass() );
		self::assertSame( Dav_Mlm_Dkim_Result::FAIL, $result->failure_reason() );
	}

	public function test_a_tampered_from_header_fails(): void {
		$message = $this->signed_message();
		$raw     = str_replace( 'From: ' . self::FROM, 'From: mallory@attacker.example', $message['raw'] );
		self::assertStringContainsString( 'From: mallory@attacker.example', $raw, 'Test setup: the From header was not replaced.' );

		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( $message['key_record'] ) );

		$result = $verifier->verify( $raw, 'mallory@attacker.example', $this->now( $message['signed_at'] + 10 ) );

		self::assertFalse( $result->is_pass() );
		self::assertSame( Dav_Mlm_Dkim_Result::FAIL, $result->failure_reason() );
	}

	public function test_a_valid_signature_with_a_non_aligned_domain_fails(): void {
		$message  = $this->signed_message(); // signed for d=example.com
		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( $message['key_record'] ) );

		// The nested From is a different, unrelated domain: a valid
		// signature from example.com says nothing about attacker.example.
		$result = $verifier->verify( $message['raw'], 'someone@attacker.example', $this->now( $message['signed_at'] + 10 ) );

		self::assertFalse( $result->is_pass() );
		self::assertSame( Dav_Mlm_Dkim_Result::FAIL, $result->failure_reason() );
	}

	public function test_a_subdomain_of_the_signing_domain_is_aligned(): void {
		$message  = $this->signed_message();
		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( $message['key_record'] ) );

		$result = $verifier->verify( $message['raw'], 'alice@mail.' . self::DOMAIN, $this->now( $message['signed_at'] + 10 ) );

		self::assertTrue( $result->is_pass(), $result->detail() );
	}

	public function test_no_signature_is_reported_distinctly(): void {
		$raw      = "From: alice@example.com\r\nSubject: hi\r\n\r\nNo signature here.\r\n";
		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( 'unused' ) );

		$result = $verifier->verify( $raw, self::FROM, $this->now( 1_000_000_000 ) );

		self::assertFalse( $result->is_pass() );
		self::assertSame( Dav_Mlm_Dkim_Result::NO_SIGNATURE, $result->failure_reason() );
	}

	public function test_a_dns_failure_is_reported_as_transient(): void {
		$message  = $this->signed_message();
		$verifier = new Dav_Mlm_Dkim_Verifier( static fn (): bool => false );

		$result = $verifier->verify( $message['raw'], self::FROM, $this->now( $message['signed_at'] + 10 ) );

		self::assertFalse( $result->is_pass() );
		self::assertSame( Dav_Mlm_Dkim_Result::DNS_ERROR, $result->failure_reason() );
	}

	public function test_a_missing_dns_record_fails_but_is_not_a_dns_error(): void {
		$message  = $this->signed_message();
		$verifier = new Dav_Mlm_Dkim_Verifier( static fn (): array => array() );

		$result = $verifier->verify( $message['raw'], self::FROM, $this->now( $message['signed_at'] + 10 ) );

		self::assertFalse( $result->is_pass() );
		self::assertSame( Dav_Mlm_Dkim_Result::FAIL, $result->failure_reason() );
	}

	public function test_the_clock_decides_whether_an_expiring_signature_has_expired(): void {
		$message  = $this->signed_message(); // expires signed_at + 7 days
		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( $message['key_record'] ) );

		$before_expiry = $verifier->verify( $message['raw'], self::FROM, $this->now( $message['expires_at'] - 10 ) );
		$after_expiry   = $verifier->verify( $message['raw'], self::FROM, $this->now( $message['expires_at'] + 10 ) );

		self::assertTrue( $before_expiry->is_pass(), $before_expiry->detail() );
		self::assertFalse( $after_expiry->is_pass() );
		self::assertSame( Dav_Mlm_Dkim_Result::FAIL, $after_expiry->failure_reason() );
	}

	public function test_garbage_input_fails_closed(): void {
		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( 'unused' ) );

		self::assertFalse( $verifier->verify( '', self::FROM, $this->now( 1_000_000_000 ) )->is_pass() );
		self::assertFalse( $verifier->verify( 'not an email at all', self::FROM, $this->now( 1_000_000_000 ) )->is_pass() );
	}

	public function test_a_from_address_with_no_domain_fails_closed(): void {
		$message  = $this->signed_message();
		$verifier = new Dav_Mlm_Dkim_Verifier( $this->dns_lookup_for( $message['key_record'] ) );

		$result = $verifier->verify( $message['raw'], 'not-an-address', $this->now( $message['signed_at'] + 10 ) );

		self::assertFalse( $result->is_pass() );
		self::assertSame( Dav_Mlm_Dkim_Result::FAIL, $result->failure_reason() );
	}
}
