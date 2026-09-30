<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class AlerterTest extends TestCase {

	private const ALERT_EMAIL    = 'vorstand@dav-neuland.de';
	private const MAIL_FROM      = 'noreply@dav-neuland.de';
	private const MAIL_FROM_NAME = 'DAV Neuland Mailinglisten';

	private ?string $previous_error_log = null;

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	private function mailer(): Dav_Mlm_Smtp_Mailer {
		return new Dav_Mlm_Smtp_Mailer( 'smtp.ionos.de', 587, 'tls', self::MAIL_FROM, 'secret' );
	}

	private function alerter( int $threshold = 3 ): Dav_Mlm_Alerter {
		return new Dav_Mlm_Alerter( self::ALERT_EMAIL, self::MAIL_FROM, self::MAIL_FROM_NAME, $this->mailer(), $threshold );
	}

	private function now( int $timestamp ): DateTimeImmutable {
		return ( new DateTimeImmutable() )->setTimestamp( $timestamp );
	}

	private function assert_no_mail_was_sent(): void {
		self::assertSame( array(), $GLOBALS['dav_mlm_test_wp_mail_calls'] );
	}

	// -- digest -----------------------------------------------------------------

	public function test_an_empty_digest_sends_nothing(): void {
		$result = $this->alerter()->send_digest( array() );

		self::assertNull( $result );
		$this->assert_no_mail_was_sent();
	}

	public function test_a_non_empty_digest_lists_every_item_with_its_reason(): void {
		$result = $this->alerter()->send_digest(
			array(
				array( 'folder' => 'Manual', 'identifier' => 'a@example.com -> l@dav-neuland.de', 'reason' => 'no aligned DKIM signature' ),
				array( 'folder' => 'Suspicious', 'identifier' => 'outer-msgid-123', 'reason' => 'untrusted Authentication-Results' ),
			)
		);

		self::assertSame( Dav_Mlm_Alerter::SENT_DIGEST, $result );
		self::assertCount( 1, $GLOBALS['dav_mlm_test_wp_mail_calls'] );

		$call = $GLOBALS['dav_mlm_test_wp_mail_calls'][0];
		self::assertSame( self::ALERT_EMAIL, $call['to'] );
		self::assertStringContainsString( '[Manual] a@example.com -> l@dav-neuland.de — no aligned DKIM signature', $call['message'] );
		self::assertStringContainsString( '[Suspicious] outer-msgid-123 — untrusted Authentication-Results', $call['message'] );
	}

	public function test_the_digest_is_sent_from_the_configured_mail_from(): void {
		$this->alerter()->send_digest( array( array( 'folder' => 'Manual', 'identifier' => 'x', 'reason' => 'y' ) ) );

		self::assertContains(
			sprintf( 'From: %s <%s>', self::MAIL_FROM_NAME, self::MAIL_FROM ),
			$GLOBALS['dav_mlm_test_wp_mail_calls'][0]['headers']
		);
	}

	// -- run outcome: below threshold / healthy ---------------------------------

	public function test_a_successful_run_with_no_prior_alert_sends_nothing(): void {
		$result = $this->alerter()->handle_run_outcome( true, 0 );

		self::assertNull( $result );
		$this->assert_no_mail_was_sent();
	}

	public function test_failures_below_the_threshold_send_nothing(): void {
		$alerter = $this->alerter( 3 );

		self::assertNull( $alerter->handle_run_outcome( false, 1 ) );
		self::assertNull( $alerter->handle_run_outcome( false, 2 ) );
		$this->assert_no_mail_was_sent();
	}

	// -- run outcome: failure alert + throttling --------------------------------

	public function test_reaching_the_threshold_sends_a_failure_alert(): void {
		$result = $this->alerter( 3 )->handle_run_outcome( false, 3, 'IMAP login failed.' );

		self::assertSame( Dav_Mlm_Alerter::SENT_FAILURE_ALERT, $result );
		self::assertCount( 1, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
		self::assertStringContainsString( 'IMAP login failed.', $GLOBALS['dav_mlm_test_wp_mail_calls'][0]['message'] );
	}

	public function test_further_failures_within_24h_are_throttled(): void {
		$alerter = $this->alerter( 3 );

		$first = $alerter->handle_run_outcome( false, 3, '', $this->now( 1_000_000_000 ) );
		self::assertSame( Dav_Mlm_Alerter::SENT_FAILURE_ALERT, $first );

		$second = $alerter->handle_run_outcome( false, 4, '', $this->now( 1_000_000_000 + 3600 ) );
		$third  = $alerter->handle_run_outcome( false, 10, '', $this->now( 1_000_000_000 + 86399 ) );

		self::assertNull( $second );
		self::assertNull( $third );
		self::assertCount( 1, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
	}

	public function test_a_reminder_is_sent_after_24h_of_continued_failure(): void {
		$alerter = $this->alerter( 3 );

		$alerter->handle_run_outcome( false, 3, '', $this->now( 1_000_000_000 ) );
		$reminder = $alerter->handle_run_outcome( false, 30, '', $this->now( 1_000_000_000 + 86400 ) );

		self::assertSame( Dav_Mlm_Alerter::SENT_FAILURE_ALERT, $reminder );
		self::assertCount( 2, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
	}

	public function test_a_custom_failure_threshold_is_honoured(): void {
		$alerter = $this->alerter( 1 );

		self::assertSame( Dav_Mlm_Alerter::SENT_FAILURE_ALERT, $alerter->handle_run_outcome( false, 1 ) );
	}

	// -- run outcome: recovery ---------------------------------------------------

	public function test_a_success_after_a_failure_alert_sends_a_recovered_mail(): void {
		$alerter = $this->alerter( 3 );
		$alerter->handle_run_outcome( false, 3, 'IMAP login failed.' );

		$result = $alerter->handle_run_outcome( true, 0 );

		self::assertSame( Dav_Mlm_Alerter::SENT_RECOVERED, $result );
		self::assertCount( 2, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
	}

	public function test_a_second_consecutive_success_sends_nothing_more(): void {
		$alerter = $this->alerter( 3 );
		$alerter->handle_run_outcome( false, 3 );
		$alerter->handle_run_outcome( true, 0 );

		$result = $alerter->handle_run_outcome( true, 0 );

		self::assertNull( $result );
		self::assertCount( 2, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
	}

	public function test_after_recovery_a_new_failure_streak_alerts_again(): void {
		$alerter = $this->alerter( 3 );
		$alerter->handle_run_outcome( false, 3 );
		$alerter->handle_run_outcome( true, 0 );

		$result = $alerter->handle_run_outcome( false, 3 );

		self::assertSame( Dav_Mlm_Alerter::SENT_FAILURE_ALERT, $result );
		self::assertCount( 3, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
	}

	// -- delivery failures ---------------------------------------------------------

	public function test_when_smtp_fails_the_default_transport_is_tried(): void {
		dav_mlm_test_queue_wp_mail_results( false, true );

		$result = $this->alerter( 3 )->handle_run_outcome( false, 3, 'IMAP login failed.' );

		self::assertSame( Dav_Mlm_Alerter::SENT_FAILURE_ALERT, $result );
		self::assertCount( 2, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
		self::assertTrue( $GLOBALS['dav_mlm_test_wp_mail_calls'][0]['mailer']->smtp_enabled );
		self::assertFalse( $GLOBALS['dav_mlm_test_wp_mail_calls'][1]['mailer']->smtp_enabled, 'The fallback must not use our SMTP settings.' );
	}

	public function test_an_undelivered_failure_alert_does_not_start_the_throttle(): void {
		$alerter = $this->alerter( 3 );

		$error_log = $this->silence_error_log();
		try {
			dav_mlm_test_set_wp_mail_result( false );
			$first = $alerter->handle_run_outcome( false, 3, '', $this->now( 1_000_000_000 ) );
			self::assertStringContainsString( 'Could not send alert', (string) file_get_contents( $error_log ) );
		} finally {
			$this->restore_error_log();
		}

		dav_mlm_test_set_wp_mail_result( true );
		$second = $alerter->handle_run_outcome( false, 4, '', $this->now( 1_000_000_000 + 300 ) );

		self::assertNull( $first );
		self::assertSame( Dav_Mlm_Alerter::SENT_FAILURE_ALERT, $second );
	}

	public function test_an_undelivered_recovered_mail_is_retried_on_the_next_success(): void {
		$alerter = $this->alerter( 3 );
		$alerter->handle_run_outcome( false, 3 );

		$this->silence_error_log();
		try {
			dav_mlm_test_set_wp_mail_result( false );
			$first = $alerter->handle_run_outcome( true, 0 );
		} finally {
			$this->restore_error_log();
		}

		dav_mlm_test_set_wp_mail_result( true );
		$second = $alerter->handle_run_outcome( true, 0 );

		self::assertNull( $first );
		self::assertSame( Dav_Mlm_Alerter::SENT_RECOVERED, $second );
	}

	/**
	 * Points error_log() at a temp file for the duration of a test, so the
	 * expected "could not send" line doesn't clutter PHPUnit's output.
	 */
	private function silence_error_log(): string {
		$path                     = (string) tempnam( sys_get_temp_dir(), 'dav-mlm-alerter-' );
		$previous                 = ini_set( 'error_log', $path );
		$this->previous_error_log = false !== $previous ? $previous : '';

		return $path;
	}

	private function restore_error_log(): void {
		$path = (string) ini_get( 'error_log' );
		ini_set( 'error_log', (string) $this->previous_error_log );
		@unlink( $path );
	}

	public function test_throttle_state_persists_across_alerter_instances(): void {
		$this->alerter( 3 )->handle_run_outcome( false, 3, '', $this->now( 1_000_000_000 ) );

		// A fresh instance (as the next cron run would construct) must see
		// the same throttle state, since it's kept in an option, not memory.
		$result = $this->alerter( 3 )->handle_run_outcome( false, 4, '', $this->now( 1_000_000_000 + 10 ) );

		self::assertNull( $result );
		self::assertCount( 1, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
	}
}
