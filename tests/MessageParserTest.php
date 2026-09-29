<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MessageParserTest extends TestCase {

	private const SAMPLE = __DIR__ . '/../samples/freigabe-sample.eml';

	private function sample(): string {
		return (string) file_get_contents( self::SAMPLE );
	}

	private function parse( string $raw ): Dav_Mlm_Parse_Result {
		return ( new Dav_Mlm_Message_Parser() )->parse( $raw );
	}

	private function replace_once( string $raw, string $search, string $replace ): string {
		self::assertStringContainsString( $search, $raw, 'Test setup: the sample no longer contains the string to replace.' );

		return (string) preg_replace( '/' . preg_quote( $search, '/' ) . '/', addcslashes( $replace, '\\$' ), $raw, 1 );
	}

	public function test_sample_yields_list_address_url_absender_and_nested_from(): void {
		$result = $this->parse( $this->sample() );

		self::assertTrue( $result->is_success(), $result->detail() );

		$notification = $result->notification();
		self::assertSame( 'test.mailingliste@dav-neuland.de', $notification->list_address );
		self::assertSame( 'sender@example.com', $notification->absender );
		self::assertSame( 'sender@example.com', $notification->nested_from );
		self::assertSame( 'sender@example.com', $notification->nested_return_path );
		self::assertSame( 'Test-Mail', $notification->nested_subject );
		self::assertNull( $notification->nested_auto_submitted );
		self::assertNull( $notification->nested_precedence );
		self::assertNull( $notification->nested_list_id );
	}

	public function test_confirm_url_is_unwrapped_and_the_id_token_stays_percent_encoded(): void {
		$raw = $this->replace_once( $this->sample(), 'id=3DEXAMPLE_TOKEN_REDACTED_DO_NOT_USE', 'id=3DAB%3Acd%2Fef' );

		self::assertSame(
			'https://ml.kundenserver.de/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=AB%3Acd%2Fef',
			$this->parse( $raw )->notification()->confirm_url
		);
	}

	public function test_sample_url_is_joined_across_the_soft_wrapped_lines(): void {
		self::assertSame(
			'https://ml.kundenserver.de/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=EXAMPLE_TOKEN_REDACTED_DO_NOT_USE',
			$this->parse( $this->sample() )->notification()->confirm_url
		);
	}

	public function test_list_address_is_lowercased_from_the_folded_subject(): void {
		$raw = $this->replace_once( $this->sample(), '[test.mailingliste@dav-neuland.de]', '[Test.Mailingliste@DAV-Neuland.de]' );

		self::assertSame( 'test.mailingliste@dav-neuland.de', $this->parse( $raw )->notification()->list_address );
	}

	public function test_nested_raw_is_the_original_post_including_its_headers(): void {
		$nested_raw = $this->parse( $this->sample() )->notification()->nested_raw;

		self::assertStringStartsWith( 'Received: from', $nested_raw );
		self::assertStringContainsString( "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=gmail.com;", $nested_raw );
		self::assertStringContainsString( "From: Test Sender <sender@example.com>\r\n", $nested_raw );
		self::assertStringContainsString( 'Kurzer Test f=C3=BCr die Mailingliste', $nested_raw, 'The nested body must stay as sent (still quoted-printable) for DKIM.' );
		self::assertStringNotContainsString( 'Part_EXAMPLE1', $nested_raw, 'The MIME boundary is not part of the post.' );
	}

	public function test_nested_loop_guard_headers_are_extracted(): void {
		$raw = $this->replace_once(
			$this->sample(),
			"Content-Language: de-DE, en-US\r\n",
			"Content-Language: de-DE, en-US\r\nAuto-Submitted: auto-generated\r\nPrecedence: bulk\r\nList-Id: <news.example.org>\r\n"
		);

		$notification = $this->parse( $raw )->notification();

		self::assertSame( 'auto-generated', $notification->nested_auto_submitted );
		self::assertSame( 'bulk', $notification->nested_precedence );
		self::assertNotNull( $notification->nested_list_id );
		self::assertStringContainsString( 'news.example.org', $notification->nested_list_id );
	}

	public function test_missing_absender_line_is_not_a_failure(): void {
		$raw = $this->replace_once( $this->sample(), "    Absender: sender@example.com\r\n", '' );

		$result = $this->parse( $raw );

		self::assertTrue( $result->is_success() );
		self::assertNull( $result->notification()->absender );
	}

	public function test_missing_nested_message_fails_with_a_typed_result(): void {
		$raw = (string) preg_replace( '/------=_Part_EXAMPLE1\.0000000000000\r\nContent-Type: message\/rfc822.*?(?=------=_Part_EXAMPLE1\.0000000000000--)/s', '', $this->sample() );

		$this->assert_failure( Dav_Mlm_Parse_Result::NO_NESTED_MESSAGE, $raw );
	}

	public function test_missing_confirm_url_fails(): void {
		$raw = $this->replace_once( $this->sample(), 'Mail/Confirm', 'Mail/Other' );

		$this->assert_failure( Dav_Mlm_Parse_Result::NO_CONFIRM_URL, $raw );
	}

	public function test_subject_without_a_bracketed_list_address_fails(): void {
		$raw = $this->replace_once( $this->sample(), 'Mailingliste' . "\r\n [test.mailingliste@dav-neuland.de]", 'Mailingliste' );

		$this->assert_failure( Dav_Mlm_Parse_Result::NO_LIST_ADDRESS, $raw );
	}

	public function test_missing_nested_from_fails(): void {
		$raw = $this->replace_once( $this->sample(), "From: Test Sender <sender@example.com>\r\n", '' );

		$this->assert_failure( Dav_Mlm_Parse_Result::NO_FROM, $raw );
	}

	public function test_multiple_addresses_in_nested_from_fail(): void {
		$raw = $this->replace_once( $this->sample(), 'From: Test Sender <sender@example.com>', 'From: Test Sender <sender@example.com>, Other <other@example.org>' );

		$this->assert_failure( Dav_Mlm_Parse_Result::MULTIPLE_FROM, $raw );
	}

	public function test_multiple_nested_from_headers_fail(): void {
		$raw = $this->replace_once( $this->sample(), "Return-Path: <sender@example.com>\r\n", "From: Other <other@example.org>\r\nReturn-Path: <sender@example.com>\r\n" );

		$this->assert_failure( Dav_Mlm_Parse_Result::MULTIPLE_FROM, $raw );
	}

	public function test_group_syntax_in_nested_from_fails(): void {
		$raw = $this->replace_once( $this->sample(), 'From: Test Sender <sender@example.com>', 'From: Team: sender@example.com;' );

		$this->assert_failure( Dav_Mlm_Parse_Result::GROUP_FROM, $raw );
	}

	public function test_unparseable_nested_from_fails(): void {
		$raw = $this->replace_once( $this->sample(), 'From: Test Sender <sender@example.com>', 'From: not-an-address' );

		$this->assert_failure( Dav_Mlm_Parse_Result::INVALID_FROM, $raw );
	}

	public function test_unicode_domain_in_nested_from_fails_as_idn(): void {
		$raw = $this->replace_once( $this->sample(), 'From: Test Sender <sender@example.com>', 'From: Test <sender@münchen.example>' );

		$this->assert_failure( Dav_Mlm_Parse_Result::IDN_FROM, $raw );
	}

	public function test_punycode_domain_in_nested_from_fails_as_idn(): void {
		$raw = $this->replace_once( $this->sample(), 'From: Test Sender <sender@example.com>', 'From: Test <sender@xn--mnchen-3ya.example>' );

		$this->assert_failure( Dav_Mlm_Parse_Result::IDN_FROM, $raw );
	}

	public function test_an_empty_source_is_a_failure_not_an_exception(): void {
		self::assertFalse( $this->parse( '' )->is_success() );
	}

	public function test_notification_of_a_failed_result_throws(): void {
		$this->expectException( LogicException::class );

		$this->parse( '' )->notification();
	}

	private function assert_failure( string $expected_reason, string $raw ): void {
		$result = $this->parse( $raw );

		self::assertFalse( $result->is_success(), 'Expected a parse failure.' );
		self::assertSame( $expected_reason, $result->failure_reason(), $result->detail() );
	}
}
