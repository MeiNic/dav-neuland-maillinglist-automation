<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotificationVerifierTest extends TestCase {

	private const SAMPLE = __DIR__ . '/../samples/freigabe-sample.eml';

	private const GENUINE_HEADER = "Authentication-Results: kundenserver.de; dkim=pass\r\n header.i=no.reply@oneandone.com header.s=s1-ionos; spf=pass\r\n smtp.helo=mout-bounce.kundenserver.de; dmarc=none header.from=oneandone.com;\r\n iprev=pass policy.iprev=203.0.113.10\r\n";

	private function sample(): string {
		$raw = (string) file_get_contents( self::SAMPLE );
		self::assertStringContainsString( self::GENUINE_HEADER, $raw, 'Test setup: the sample no longer has the expected Authentication-Results header.' );

		return $raw;
	}

	private function verify( string $raw ): Dav_Mlm_Verification_Result {
		return ( new Dav_Mlm_Notification_Verifier( 'no.reply@oneandone.com', 'kundenserver.de' ) )->verify( $raw );
	}

	private function with_topmost_auth_results( string $header_line ): string {
		return str_replace( self::GENUINE_HEADER, $header_line . "\r\n", $this->sample() );
	}

	private function assert_fails( string $expected_reason, string $raw ): void {
		$result = $this->verify( $raw );

		self::assertFalse( $result->is_genuine(), 'Expected the notification to be rejected as not genuine.' );
		self::assertSame( $expected_reason, $result->failure_reason(), $result->detail() );
	}

	public function test_genuine_sample_passes(): void {
		$result = $this->verify( $this->sample() );

		self::assertTrue( $result->is_genuine(), $result->detail() );
		self::assertNull( $result->failure_reason() );
	}

	public function test_forged_lower_header_cannot_rescue_a_failing_topmost_one(): void {
		$raw = $this->with_topmost_auth_results( 'Authentication-Results: kundenserver.de; dkim=fail header.i=no.reply@oneandone.com' );
		$raw = str_replace( "MIME-Version: 1.0\r\nMessage-ID: <trinity", "Authentication-Results: kundenserver.de; dkim=pass header.i=no.reply@oneandone.com\r\nMIME-Version: 1.0\r\nMessage-ID: <trinity", $raw );
		self::assertSame( 2, substr_count( $raw, 'Authentication-Results:' ), 'Test setup: expected a topmost and a lower header.' );

		$this->assert_fails( Dav_Mlm_Verification_Result::NO_TRUSTED_DKIM_PASS, $raw );
	}

	public function test_forged_lower_header_is_ignored_when_a_foreign_one_is_on_top(): void {
		// The attacker's own server put its verdict on top of whatever the sender supplied.
		$raw = $this->with_topmost_auth_results( 'Authentication-Results: mx.attacker.example; dkim=pass header.i=no.reply@oneandone.com' );

		$this->assert_fails( Dav_Mlm_Verification_Result::UNTRUSTED_AUTHSERV, $raw );
	}

	public function test_a_failing_lower_header_does_not_affect_a_genuine_topmost_one(): void {
		$raw = str_replace(
			"MIME-Version: 1.0\r\nMessage-ID: <trinity",
			"Authentication-Results: other.example; dkim=fail\r\nMIME-Version: 1.0\r\nMessage-ID: <trinity",
			$this->sample()
		);

		self::assertTrue( $this->verify( $raw )->is_genuine() );
	}

	public function test_wrong_authserv_id_fails(): void {
		$raw = $this->with_topmost_auth_results( 'Authentication-Results: mx.other.example; dkim=pass header.i=no.reply@oneandone.com' );

		$this->assert_fails( Dav_Mlm_Verification_Result::UNTRUSTED_AUTHSERV, $raw );
	}

	public function test_authserv_id_is_compared_exactly_not_by_suffix_or_prefix(): void {
		foreach ( array( 'evil-kundenserver.de', 'kundenserver.de.evil.example', 'mx.kundenserver.de' ) as $authserv ) {
			$raw = $this->with_topmost_auth_results( "Authentication-Results: $authserv; dkim=pass header.i=no.reply@oneandone.com" );

			$this->assert_fails( Dav_Mlm_Verification_Result::UNTRUSTED_AUTHSERV, $raw );
		}
	}

	public function test_dkim_fail_fails(): void {
		$raw = $this->with_topmost_auth_results( 'Authentication-Results: kundenserver.de; dkim=fail header.i=no.reply@oneandone.com header.s=s1-ionos' );

		$this->assert_fails( Dav_Mlm_Verification_Result::NO_TRUSTED_DKIM_PASS, $raw );
	}

	public function test_dkim_none_or_missing_fails(): void {
		$this->assert_fails( Dav_Mlm_Verification_Result::NO_TRUSTED_DKIM_PASS, $this->with_topmost_auth_results( 'Authentication-Results: kundenserver.de; dkim=none' ) );
		$this->assert_fails( Dav_Mlm_Verification_Result::NO_TRUSTED_DKIM_PASS, $this->with_topmost_auth_results( 'Authentication-Results: kundenserver.de; spf=pass smtp.helo=mout-bounce.kundenserver.de' ) );
		$this->assert_fails( Dav_Mlm_Verification_Result::NO_TRUSTED_DKIM_PASS, $this->with_topmost_auth_results( 'Authentication-Results: kundenserver.de' ) );
	}

	public function test_dkim_pass_for_a_foreign_domain_fails(): void {
		$raw = $this->with_topmost_auth_results( 'Authentication-Results: kundenserver.de; dkim=pass header.i=no.reply@attacker.example header.d=attacker.example' );

		$this->assert_fails( Dav_Mlm_Verification_Result::NO_TRUSTED_DKIM_PASS, $raw );
	}

	public function test_lookalike_signing_domains_fail(): void {
		foreach ( array( 'oneandone.com.attacker.example', 'eviloneandone.com', 'oneandone.com@attacker.example', 'oneandone.co' ) as $domain ) {
			$raw = $this->with_topmost_auth_results( "Authentication-Results: kundenserver.de; dkim=pass header.i=no.reply@$domain" );

			$this->assert_fails( Dav_Mlm_Verification_Result::NO_TRUSTED_DKIM_PASS, $raw );
		}
	}

	public function test_a_pass_that_also_names_a_foreign_identity_fails(): void {
		$raw = $this->with_topmost_auth_results( 'Authentication-Results: kundenserver.de; dkim=pass header.d=oneandone.com header.i=@attacker.example' );

		$this->assert_fails( Dav_Mlm_Verification_Result::NO_TRUSTED_DKIM_PASS, $raw );
	}

	public function test_header_d_and_subdomains_of_the_signing_domain_are_accepted(): void {
		self::assertTrue( $this->verify( $this->with_topmost_auth_results( 'Authentication-Results: kundenserver.de; dkim=pass header.d=oneandone.com' ) )->is_genuine() );
		self::assertTrue( $this->verify( $this->with_topmost_auth_results( 'Authentication-Results: KUNDENSERVER.de; DKIM=Pass header.i=@mail.oneandone.com' ) )->is_genuine() );
	}

	public function test_comments_cannot_fake_a_pass(): void {
		$raw = $this->with_topmost_auth_results( 'Authentication-Results: kundenserver.de; dkim=fail (was dkim=pass; header.i=no.reply@oneandone.com) header.i=no.reply@oneandone.com' );

		$this->assert_fails( Dav_Mlm_Verification_Result::NO_TRUSTED_DKIM_PASS, $raw );
	}

	public function test_missing_authentication_results_fails(): void {
		$raw = str_replace( self::GENUINE_HEADER, '', $this->sample() );

		$this->assert_fails( Dav_Mlm_Verification_Result::NO_AUTHENTICATION_RESULTS, $raw );
	}

	public function test_wrong_outer_from_fails(): void {
		$raw = str_replace( 'From: Mailinglisten-Manager <no.reply@oneandone.com>', 'From: Mailinglisten-Manager <no.reply@attacker.example>', $this->sample() );

		$this->assert_fails( Dav_Mlm_Verification_Result::WRONG_FROM, $raw );
	}

	public function test_display_name_impersonation_with_another_address_fails(): void {
		$raw = str_replace( 'From: Mailinglisten-Manager <no.reply@oneandone.com>', 'From: "no.reply@oneandone.com" <evil@attacker.example>', $this->sample() );

		$this->assert_fails( Dav_Mlm_Verification_Result::WRONG_FROM, $raw );
	}

	public function test_outer_from_with_an_extra_address_fails(): void {
		$raw = str_replace( 'From: Mailinglisten-Manager <no.reply@oneandone.com>', 'From: Mailinglisten-Manager <no.reply@oneandone.com>, evil@attacker.example', $this->sample() );

		$this->assert_fails( Dav_Mlm_Verification_Result::WRONG_FROM, $raw );
	}

	public function test_outer_from_comparison_ignores_case(): void {
		$raw = str_replace( 'From: Mailinglisten-Manager <no.reply@oneandone.com>', 'From: Mailinglisten-Manager <No.Reply@OneAndOne.com>', $this->sample() );

		self::assertTrue( $this->verify( $raw )->is_genuine() );
	}

	public function test_configured_values_are_used(): void {
		$verifier = new Dav_Mlm_Notification_Verifier( 'other@example.org', 'kundenserver.de' );

		self::assertFalse( $verifier->verify( $this->sample() )->is_genuine() );
	}

	public function test_garbage_input_is_not_genuine(): void {
		self::assertFalse( $this->verify( '' )->is_genuine() );
		self::assertFalse( $this->verify( 'not an email at all' )->is_genuine() );
	}
}
