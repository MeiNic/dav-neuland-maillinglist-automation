<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class ApproverTest extends TestCase {

	private const HOST = 'ml.kundenserver.de';
	private const LIST = 'test.mailingliste@dav-neuland.de';
	private const VALID_URL = 'https://ml.kundenserver.de/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=V02%3AK0%3Atoken';

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	private function approver(): Dav_Mlm_Approver {
		return new Dav_Mlm_Approver( self::HOST );
	}

	public function test_a_correctly_shaped_url_is_allowlisted(): void {
		self::assertTrue( $this->approver()->is_allowlisted( self::VALID_URL, self::LIST ) );
	}

	public function test_the_host_is_compared_case_insensitively(): void {
		$url = 'https://ML.KUNDENSERVER.DE/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=x';

		self::assertTrue( $this->approver()->is_allowlisted( $url, self::LIST ) );
	}

	public function test_the_list_address_accepts_both_literal_and_percent_encoded_at_sign(): void {
		$encoded = 'https://ml.kundenserver.de/MailingList/test.mailingliste%40dav-neuland.de/Mail/Confirm?lang=de&id=x';

		self::assertTrue( $this->approver()->is_allowlisted( $encoded, self::LIST ) );
	}

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function rejected_urls(): iterable {
		yield 'wrong host' => array( 'https://evil.example/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=x', self::LIST );
		yield 'http instead of https' => array( 'http://ml.kundenserver.de/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=x', self::LIST );
		yield 'a different list address' => array( 'https://ml.kundenserver.de/MailingList/other.list@dav-neuland.de/Mail/Confirm?lang=de&id=x', self::LIST );
		yield 'extra query parameter' => array( 'https://ml.kundenserver.de/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=x&extra=1', self::LIST );
		yield 'missing id parameter' => array( 'https://ml.kundenserver.de/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de', self::LIST );
		yield 'userinfo host-spoofing trick' => array( 'https://ml.kundenserver.de@evil.example/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=x', self::LIST );
		yield 'wrong path' => array( 'https://ml.kundenserver.de/MailingList/test.mailingliste@dav-neuland.de/Mail/Reject?lang=de&id=x', self::LIST );
		yield 'unparseable url' => array( 'https:///MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=x', self::LIST );
	}

	/**
	 * @dataProvider rejected_urls
	 */
	public function test_urls_failing_the_allowlist_are_rejected( string $url, string $list_address ): void {
		self::assertFalse( $this->approver()->is_allowlisted( $url, $list_address ) );
	}

	public function test_approve_fails_closed_as_suspicious_without_making_a_request(): void {
		$result = $this->approver()->approve( 'https://evil.example/MailingList/test.mailingliste@dav-neuland.de/Mail/Confirm?lang=de&id=x', self::LIST );

		self::assertFalse( $result->is_success() );
		self::assertSame( Dav_Mlm_Approve_Result::SUSPICIOUS_URL, $result->failure_reason() );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_http_requests'] );
	}

	public function test_approve_succeeds_on_http_200_with_the_success_marker(): void {
		dav_mlm_test_set_http_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => "<title>Der Vorgang war erfolgreich.</title>\nDer Vorgang war erfolgreich.",
			)
		);

		$result = $this->approver()->approve( self::VALID_URL, self::LIST );

		self::assertTrue( $result->is_success() );
	}

	public function test_approve_sends_a_bare_get_with_no_redirection_and_a_15s_timeout(): void {
		dav_mlm_test_set_http_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'Der Vorgang war erfolgreich.',
			)
		);

		$this->approver()->approve( self::VALID_URL, self::LIST );

		self::assertCount( 1, $GLOBALS['dav_mlm_test_http_requests'] );
		self::assertSame( self::VALID_URL, $GLOBALS['dav_mlm_test_http_requests'][0]['url'] );
		self::assertSame(
			array(
				'redirection' => 0,
				'timeout'     => 15,
			),
			$GLOBALS['dav_mlm_test_http_requests'][0]['args']
		);
	}

	public function test_approve_treats_200_without_the_marker_as_not_confirmed(): void {
		dav_mlm_test_set_http_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<title>Fehler</title>',
			)
		);

		$result = $this->approver()->approve( self::VALID_URL, self::LIST );

		self::assertFalse( $result->is_success() );
		self::assertSame( Dav_Mlm_Approve_Result::NOT_CONFIRMED, $result->failure_reason() );
	}

	public function test_approve_treats_a_reused_token_the_same_as_an_invalid_one(): void {
		// Per issue #8, IONOS returns byte-identical bodies for both, so
		// both land on the same NOT_CONFIRMED outcome.
		dav_mlm_test_set_http_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<title>Fehler</title>',
			)
		);
		$reused = $this->approver()->approve( self::VALID_URL, self::LIST );

		dav_mlm_test_set_http_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<title>Fehler</title>',
			)
		);
		$invalid = $this->approver()->approve( self::VALID_URL, self::LIST );

		self::assertSame( $reused->failure_reason(), $invalid->failure_reason() );
	}

	public function test_approve_does_not_follow_a_redirect_but_logs_the_location(): void {
		dav_mlm_test_set_http_response(
			array(
				'response' => array( 'code' => 302 ),
				'body'     => '',
				'headers'  => array( 'location' => 'https://ml.kundenserver.de/somewhere-else' ),
			)
		);

		$result = $this->approver()->approve( self::VALID_URL, self::LIST );

		self::assertFalse( $result->is_success() );
		self::assertSame( Dav_Mlm_Approve_Result::NOT_CONFIRMED, $result->failure_reason() );
		self::assertStringContainsString( 'https://ml.kundenserver.de/somewhere-else', $result->detail() );
	}

	public function test_approve_reports_a_network_error_as_transient(): void {
		dav_mlm_test_set_http_response( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$result = $this->approver()->approve( self::VALID_URL, self::LIST );

		self::assertFalse( $result->is_success() );
		self::assertSame( Dav_Mlm_Approve_Result::NETWORK_ERROR, $result->failure_reason() );
		self::assertStringContainsString( 'Connection timed out', $result->detail() );
	}
}
