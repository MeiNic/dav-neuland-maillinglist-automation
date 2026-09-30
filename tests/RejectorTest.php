<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class RejectorTest extends TestCase {

	private const MAIL_FROM      = 'noreply@dav-neuland.de';
	private const MAIL_FROM_NAME = 'DAV Neuland Mailinglisten';
	private const SMTP_HOST      = 'smtp.ionos.de';
	private const SMTP_PORT      = 587;
	private const SMTP_ENC       = 'tls';
	private const SMTP_USER      = 'noreply@dav-neuland.de';
	private const SMTP_PASS      = 'secret';

	private const SUBJECT_TEMPLATE = 'Re: {{original_subject}} an {{list}}';
	private const BODY_TEMPLATE    = "Hallo,\n\nIhre Nachricht \"{{original_subject}}\" an {{list}} (von {{sender}}) wurde nicht freigegeben.";

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	private function rejector(): Dav_Mlm_Rejector {
		return new Dav_Mlm_Rejector(
			self::MAIL_FROM,
			self::MAIL_FROM_NAME,
			self::SMTP_HOST,
			self::SMTP_PORT,
			self::SMTP_ENC,
			self::SMTP_USER,
			self::SMTP_PASS
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function notification( array $overrides = array() ): Dav_Mlm_Parsed_Notification {
		return new Dav_Mlm_Parsed_Notification(
			$overrides['list_address'] ?? 'test.mailingliste@dav-neuland.de',
			$overrides['confirm_url'] ?? 'https://ml.kundenserver.de/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=x',
			$overrides['absender'] ?? 'sender@example.com',
			$overrides['nested_raw'] ?? "From: sender@example.com\r\n\r\nBody.\r\n",
			$overrides['nested_from'] ?? 'sender@example.com',
			$overrides['nested_subject'] ?? 'Original subject',
			$overrides['nested_return_path'] ?? 'sender@example.com',
			$overrides['nested_auto_submitted'] ?? null,
			$overrides['nested_precedence'] ?? null,
			$overrides['nested_list_id'] ?? null,
			$overrides['nested_list_unsubscribe'] ?? null
		);
	}

	private function reject( Dav_Mlm_Parsed_Notification $notification, array $list_addresses = array(), ?string $reply_to = null ): Dav_Mlm_Reject_Result {
		return $this->rejector()->reject(
			$notification,
			'email',
			self::SUBJECT_TEMPLATE,
			self::BODY_TEMPLATE,
			$reply_to,
			$list_addresses
		);
	}

	private function assert_no_mail_was_sent(): void {
		self::assertSame( array(), $GLOBALS['dav_mlm_test_wp_mail_calls'] );
	}

	// -- reject_mode = silent -------------------------------------------------

	public function test_silent_mode_sends_no_mail(): void {
		$result = $this->rejector()->reject( $this->notification(), 'silent', self::SUBJECT_TEMPLATE, self::BODY_TEMPLATE, null, array() );

		self::assertFalse( $result->mail_was_sent() );
		self::assertFalse( $result->is_transient_failure() );
		self::assertSame( Dav_Mlm_Reject_Result::SILENT_MODE, $result->skip_reason() );
		$this->assert_no_mail_was_sent();
	}

	// -- happy path -------------------------------------------------------------

	public function test_a_genuine_rejection_is_sent(): void {
		$result = $this->reject( $this->notification() );

		self::assertTrue( $result->mail_was_sent(), $result->detail() );
		self::assertFalse( $result->is_transient_failure() );
		self::assertNull( $result->skip_reason() );
		self::assertCount( 1, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
	}

	public function test_the_to_address_is_the_bare_sender_address(): void {
		$this->reject( $this->notification( array( 'nested_from' => 'Sender@Example.com' ) ) );

		self::assertSame( 'sender@example.com', $GLOBALS['dav_mlm_test_wp_mail_calls'][0]['to'] );
	}

	public function test_placeholders_are_substituted_into_subject_and_body(): void {
		$this->reject( $this->notification( array( 'nested_subject' => 'Hallo Liste' ) ) );

		$call = $GLOBALS['dav_mlm_test_wp_mail_calls'][0];
		self::assertSame( 'Re: Hallo Liste an test.mailingliste@dav-neuland.de', $call['subject'] );
		self::assertStringContainsString( 'Hallo Liste', $call['message'] );
		self::assertStringContainsString( 'test.mailingliste@dav-neuland.de', $call['message'] );
		self::assertStringContainsString( 'sender@example.com', $call['message'] );
	}

	public function test_crlf_and_control_characters_are_stripped_from_placeholders(): void {
		$this->reject( $this->notification( array( 'nested_subject' => "Hi\r\nBcc: evil@attacker.example" ) ) );

		$call = $GLOBALS['dav_mlm_test_wp_mail_calls'][0];
		self::assertStringNotContainsString( "\r", $call['subject'] );
		self::assertStringNotContainsString( "\n", $call['subject'] );
		self::assertStringContainsString( 'HiBcc: evil@attacker.example', $call['subject'] );
	}

	public function test_original_subject_is_truncated_to_200_characters(): void {
		$this->reject( $this->notification( array( 'nested_subject' => str_repeat( 'x', 500 ) ) ) );

		$call = $GLOBALS['dav_mlm_test_wp_mail_calls'][0];
		self::assertSame( 'Re: ' . str_repeat( 'x', 200 ) . ' an test.mailingliste@dav-neuland.de', $call['subject'] );
	}

	public function test_headers_include_from_auto_submitted_and_optional_reply_to(): void {
		$this->reject( $this->notification(), array(), 'vorstand@dav-neuland.de' );

		$headers = $GLOBALS['dav_mlm_test_wp_mail_calls'][0]['headers'];
		self::assertContains( 'From: DAV Neuland Mailinglisten <noreply@dav-neuland.de>', $headers );
		self::assertContains( 'Reply-To: vorstand@dav-neuland.de', $headers );
		self::assertContains( 'Auto-Submitted: auto-replied', $headers );
	}

	public function test_reply_to_header_is_omitted_when_not_configured(): void {
		$this->reject( $this->notification() );

		$headers = $GLOBALS['dav_mlm_test_wp_mail_calls'][0]['headers'];
		foreach ( $headers as $header ) {
			self::assertStringStartsNotWith( 'Reply-To:', $header );
		}
	}

	public function test_smtp_of_the_noreply_mailbox_is_used_and_the_hook_is_removed_afterwards(): void {
		$this->reject( $this->notification() );

		$mailer = $GLOBALS['dav_mlm_test_wp_mail_calls'][0]['mailer'];
		self::assertTrue( $mailer->smtp_enabled );
		self::assertSame( self::SMTP_HOST, $mailer->Host );
		self::assertSame( self::SMTP_PORT, $mailer->Port );
		self::assertTrue( $mailer->SMTPAuth );
		self::assertSame( self::SMTP_USER, $mailer->Username );
		self::assertSame( self::SMTP_PASS, $mailer->Password );
		self::assertSame( self::SMTP_ENC, $mailer->SMTPSecure );

		self::assertSame( array(), $GLOBALS['dav_mlm_test_actions']['phpmailer_init'] ?? array(), 'The phpmailer_init hook must not stay registered after the call.' );
	}

	// -- wp_mail() failure is transient ------------------------------------------

	public function test_wp_mail_returning_false_is_a_transient_failure(): void {
		dav_mlm_test_set_wp_mail_result( false );

		$result = $this->reject( $this->notification() );

		self::assertTrue( $result->is_transient_failure() );
		self::assertFalse( $result->mail_was_sent() );
	}

	public function test_a_transient_failure_does_not_count_against_the_rate_limit(): void {
		dav_mlm_test_set_wp_mail_result( false );
		$this->reject( $this->notification() );

		dav_mlm_test_set_wp_mail_result( true );
		$result = $this->reject( $this->notification() );

		self::assertTrue( $result->mail_was_sent() );
	}

	// -- invalid recipient --------------------------------------------------

	public function test_an_invalid_sender_address_is_not_mailed(): void {
		$result = $this->reject( $this->notification( array( 'nested_from' => 'not-an-address' ) ) );

		self::assertSame( Dav_Mlm_Reject_Result::INVALID_TO, $result->skip_reason() );
		$this->assert_no_mail_was_sent();
	}

	// -- loop guards ----------------------------------------------------------

	public function test_auto_submitted_other_than_no_skips_the_mail(): void {
		$result = $this->reject( $this->notification( array( 'nested_auto_submitted' => 'auto-generated' ) ) );

		self::assertSame( Dav_Mlm_Reject_Result::LOOP_GUARD, $result->skip_reason() );
		self::assertStringContainsString( 'Auto-Submitted', $result->detail() );
		$this->assert_no_mail_was_sent();
	}

	public function test_auto_submitted_no_does_not_skip(): void {
		$result = $this->reject( $this->notification( array( 'nested_auto_submitted' => 'no' ) ) );

		self::assertTrue( $result->mail_was_sent() );
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function bulk_precedences(): iterable {
		yield 'bulk' => array( 'bulk' );
		yield 'list' => array( 'list' );
		yield 'junk' => array( 'junk' );
		yield 'uppercase' => array( 'BULK' );
	}

	/**
	 * @dataProvider bulk_precedences
	 */
	public function test_bulk_list_or_junk_precedence_skips_the_mail( string $precedence ): void {
		$result = $this->reject( $this->notification( array( 'nested_precedence' => $precedence ) ) );

		self::assertSame( Dav_Mlm_Reject_Result::LOOP_GUARD, $result->skip_reason() );
		$this->assert_no_mail_was_sent();
	}

	public function test_precedence_first_class_does_not_skip(): void {
		$result = $this->reject( $this->notification( array( 'nested_precedence' => 'first-class' ) ) );

		self::assertTrue( $result->mail_was_sent() );
	}

	public function test_a_list_id_on_the_original_post_skips_the_mail(): void {
		$result = $this->reject( $this->notification( array( 'nested_list_id' => '<some.other.list.example.org>' ) ) );

		self::assertSame( Dav_Mlm_Reject_Result::LOOP_GUARD, $result->skip_reason() );
		$this->assert_no_mail_was_sent();
	}

	public function test_a_list_unsubscribe_on_the_original_post_skips_the_mail(): void {
		$result = $this->reject( $this->notification( array( 'nested_list_unsubscribe' => '<mailto:leave@example.org>' ) ) );

		self::assertSame( Dav_Mlm_Reject_Result::LOOP_GUARD, $result->skip_reason() );
		$this->assert_no_mail_was_sent();
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function bounce_like_senders(): iterable {
		yield 'mailer-daemon' => array( 'MAILER-DAEMON@example.com' );
		yield 'postmaster' => array( 'postmaster@example.com' );
		yield 'noreply' => array( 'noreply@example.com' );
		yield 'no-reply' => array( 'no-reply@example.com' );
		yield 'noreply substring' => array( 'donotreply.noreply@example.com' );
	}

	/**
	 * @dataProvider bounce_like_senders
	 */
	public function test_bounce_like_senders_skip_the_mail( string $sender ): void {
		$result = $this->reject( $this->notification( array( 'nested_from' => $sender ) ) );

		self::assertSame( Dav_Mlm_Reject_Result::LOOP_GUARD, $result->skip_reason() );
		$this->assert_no_mail_was_sent();
	}

	public function test_our_own_mail_from_address_skips_the_mail(): void {
		$result = $this->reject( $this->notification( array( 'nested_from' => self::MAIL_FROM ) ) );

		self::assertSame( Dav_Mlm_Reject_Result::LOOP_GUARD, $result->skip_reason() );
		$this->assert_no_mail_was_sent();
	}

	public function test_a_configured_list_address_as_sender_skips_the_mail(): void {
		$result = $this->reject(
			$this->notification( array( 'nested_from' => 'other.list@dav-neuland.de' ) ),
			array( 'Other.List@dav-neuland.de' )
		);

		self::assertSame( Dav_Mlm_Reject_Result::LOOP_GUARD, $result->skip_reason() );
		$this->assert_no_mail_was_sent();
	}

	// -- rate limit ------------------------------------------------------------

	public function test_the_sixth_rejection_to_the_same_sender_within_a_day_is_rate_limited(): void {
		$rejector = $this->rejector();

		for ( $i = 0; $i < Dav_Mlm_Rate_Limiter::MAX_PER_SENDER_PER_DAY; $i++ ) {
			$result = $rejector->reject( $this->notification(), 'email', self::SUBJECT_TEMPLATE, self::BODY_TEMPLATE, null, array() );
			self::assertTrue( $result->mail_was_sent(), "Rejection $i should have been sent." );
		}

		$result = $rejector->reject( $this->notification(), 'email', self::SUBJECT_TEMPLATE, self::BODY_TEMPLATE, null, array() );

		self::assertSame( Dav_Mlm_Reject_Result::RATE_LIMITED, $result->skip_reason() );
		self::assertCount( Dav_Mlm_Rate_Limiter::MAX_PER_SENDER_PER_DAY, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
	}

	public function test_the_rate_limit_is_per_sender(): void {
		$rejector = $this->rejector();

		for ( $i = 0; $i < Dav_Mlm_Rate_Limiter::MAX_PER_SENDER_PER_DAY; $i++ ) {
			$rejector->reject( $this->notification( array( 'nested_from' => 'alice@example.com' ) ), 'email', self::SUBJECT_TEMPLATE, self::BODY_TEMPLATE, null, array() );
		}

		$result = $rejector->reject( $this->notification( array( 'nested_from' => 'bob@example.com' ) ), 'email', self::SUBJECT_TEMPLATE, self::BODY_TEMPLATE, null, array() );

		self::assertTrue( $result->mail_was_sent() );
	}
}
