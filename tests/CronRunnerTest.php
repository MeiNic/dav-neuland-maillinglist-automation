<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';
require_once __DIR__ . '/fixtures/FakeMailbox.php';
require_once __DIR__ . '/fixtures/DkimSigner.php';
require_once __DIR__ . '/fixtures/TempDirectoryTrait.php';

use PHPUnit\Framework\TestCase;

/**
 * Integration test for one moderation run: real verifier, parser,
 * approver, DKIM verifier, rejector, alerter and state classes, with only
 * the edges faked — the mailbox (Dav_Mlm_Fake_Mailbox), DNS (a lookup
 * closure over $this->dns), HTTP and wp_mail() (wp-stubs.php). The
 * notifications are synthetic copies of the IONOS format (PLAN.md §4),
 * with the original post DKIM-signed by a throwaway key.
 */
final class CronRunnerTest extends TestCase {

	use TempDirectoryTrait;

	private const LIST_ADDRESS = 'test.mailingliste@dav-neuland.de';
	private const MEMBER       = 'max@dav-neuland.de';
	private const OUTSIDER     = 'erika@example.org';
	private const ALERT_EMAIL  = 'admin@dav-neuland.de';
	private const MAIL_FROM    = 'noreply@dav-neuland.de';

	private Dav_Mlm_Fake_Mailbox $mailbox;

	/** @var array<string, string|false> `<selector>._domainkey.<domain>` => TXT record, or false for a DNS failure. */
	private array $dns = array();

	private int $signed_posts = 0;

	private string $data_dir;

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
		$this->data_dir = $this->make_temp_dir();
		$this->mailbox  = new Dav_Mlm_Fake_Mailbox();
		$this->dns      = array();
		$this->configure_list();
		$this->signed_posts = 0;
	}

	protected function tearDown(): void {
		$this->remove_temp_dir();
	}

	public function test_an_allowed_sender_is_approved(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'message_id' => '<approve-1@ionos.example>' ) ) );
		$this->confirm_succeeds();

		$result = $this->runner()->run();

		self::assertTrue( $result['success'] );
		self::assertSame( array( 1 ), $this->mailbox->folders['Approved'] );
		self::assertSame( array(), $this->mailbox->inbox, 'Moved messages are expunged at the end of the run.' );
		self::assertCount( 1, $GLOBALS['dav_mlm_test_http_requests'] );
		self::assertStringContainsString( '/MailingList/' . self::LIST_ADDRESS . '/Mail/Confirm', $GLOBALS['dav_mlm_test_http_requests'][0]['url'] );
		self::assertSame( 'approved', ( new Dav_Mlm_Done_List() )->get( 'approve-1@ionos.example' )['action'] );
		self::assertSame( 1, $result['counts']['processed'] );
		self::assertSame( 1, $result['counts']['approved'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_wp_mail_calls'], 'Nothing to report, so no alert mail.' );
		self::assertStringContainsString( '"path":"as_is"', $this->log() );
	}

	public function test_a_sender_the_regex_does_not_allow_gets_a_rejection_mail(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'sender' => self::OUTSIDER ) ) );

		$result = $this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Rejected'] );
		self::assertSame( 1, $result['counts']['rejected'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_http_requests'] );
		self::assertCount( 1, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
		self::assertSame( self::OUTSIDER, $GLOBALS['dav_mlm_test_wp_mail_calls'][0]['to'] );
		self::assertSame( 'Nicht zugestellt: ' . self::LIST_ADDRESS, $GLOBALS['dav_mlm_test_wp_mail_calls'][0]['subject'] );
	}

	public function test_silent_mode_rejects_without_a_mail(): void {
		$this->configure_list( array( 'reject_mode' => 'silent' ) );
		$this->mailbox->add_message( 1, $this->notification( array( 'sender' => self::OUTSIDER ) ) );

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Rejected'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_wp_mail_calls'] );
		self::assertStringContainsString( 'Rejected without a mail (silent_mode)', $this->log() );
	}

	public function test_known_ionos_info_mails_are_filed_quietly(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'subject' => 'Neuer Teilnehmer in die Mailingliste [' . self::LIST_ADDRESS . '] aufgenommen' ) ) );
		$this->mailbox->add_message( 2, $this->notification( array( 'subject' => 'Fehler bei der Zustellung einer E-Mail an die Mailingliste [' . self::LIST_ADDRESS . ']' ) ) );

		$result = $this->runner()->run();

		self::assertSame( array( 1, 2 ), $this->mailbox->folders['Info'] );
		self::assertSame( 2, $result['counts']['informational'] );
		self::assertSame( 0, $result['counts']['unrecognized'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_wp_mail_calls'], 'No alert.' );
		self::assertSame( array(), ( new Dav_Mlm_Run_Status() )->status()['messages'], 'Nothing on the status panel either.' );
	}

	public function test_other_ionos_mails_are_unrecognized_and_reported(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'subject' => 'Willkommen auf der Mailing-Liste [' . self::LIST_ADDRESS . ']' ) ) );

		$result = $this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Unrecognized'] );
		self::assertSame( 1, $result['counts']['unrecognized'] );
		$this->assert_digest_mentions( '[Unrecognized]', 'Willkommen' );
		self::assertSame( 'warning', ( new Dav_Mlm_Run_Status() )->status()['messages'][0]['level'] );
	}

	public function test_a_forged_notification_is_suspicious_and_nothing_is_sent(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'auth' => 'evil.example; dkim=pass header.i=no.reply@oneandone.com' ) ) );
		$this->confirm_succeeds();

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Suspicious'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_http_requests'] );
		$this->assert_digest_mentions( '[Suspicious]', 'untrusted_authserv' );
	}

	public function test_a_notification_without_the_original_post_goes_to_manual(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'nested' => null ) ) );

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Manual'] );
		$this->assert_digest_mentions( '[Manual]', 'no_nested_message' );
	}

	public function test_a_confirm_link_to_another_host_is_suspicious(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'url' => 'https://evil.example/MailingList/' . self::LIST_ADDRESS . '/Mail/Confirm?lang=de&id=x' ) ) );
		$this->confirm_succeeds();

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Suspicious'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_http_requests'] );
	}

	public function test_unconfigured_and_inactive_lists_go_to_manual(): void {
		$this->configure_list( array( 'active' => false ) );
		$this->mailbox->add_message( 1, $this->notification() );
		$this->mailbox->add_message( 2, $this->notification( array( 'list' => 'neu@dav-neuland.de' ) ) );
		$this->confirm_succeeds();

		$this->runner()->run();

		self::assertSame( array( 1, 2 ), $this->mailbox->folders['Manual'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_http_requests'] );
		$this->assert_digest_mentions( 'inactive', 'neu@dav-neuland.de', 'No configuration for this list' );
	}

	public function test_a_from_address_that_differs_from_the_envelope_sender_goes_to_manual(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'absender' => 'bounce-123@dav-neuland.de' ) ) );
		$this->confirm_succeeds();

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Manual'] );
		$this->assert_digest_mentions( 'does not match the envelope sender (bounce-123@dav-neuland.de)' );
	}

	public function test_an_unsigned_post_goes_to_manual_when_the_list_requires_dkim(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'nested' => $this->unsigned_post( self::MEMBER ) ) ) );
		$this->confirm_succeeds();

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Manual'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_http_requests'] );
		$this->assert_digest_mentions( 'DKIM check failed (no_signature)' );
	}

	public function test_an_unsigned_post_from_an_outsider_is_never_rejected_when_dkim_is_required(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'nested' => $this->unsigned_post( self::OUTSIDER ), 'sender' => self::OUTSIDER ) ) );

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Manual'] );
		self::assertSame( array( self::ALERT_EMAIL ), array_column( $GLOBALS['dav_mlm_test_wp_mail_calls'], 'to' ), 'Only the digest — no rejection mail.' );
	}

	public function test_dkim_policy_off_approves_an_unsigned_post(): void {
		$this->configure_list( array( 'dkim_policy' => 'off' ) );
		$this->mailbox->add_message( 1, $this->notification( array( 'nested' => $this->unsigned_post( self::MEMBER ) ) ) );
		$this->confirm_succeeds();

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Approved'] );
	}

	public function test_a_dkim_dns_failure_is_retried_and_ends_in_error_after_the_last_attempt(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'message_id' => '<dns@ionos.example>' ) ) );
		$this->dns = array_map( static fn (): bool => false, $this->dns );
		$this->confirm_succeeds();

		for ( $run = 1; $run < Dav_Mlm_Attempts_Store::MAX_ATTEMPTS; $run++ ) {
			$result = $this->runner()->run();

			self::assertTrue( $result['success'], 'A failing message does not fail the run.' );
			self::assertSame( 1, $result['counts']['errored'] );
			self::assertArrayHasKey( 1, $this->mailbox->inbox, 'Still in INBOX for the next attempt.' );
			self::assertSame( $run, ( new Dav_Mlm_Attempts_Store() )->count_for( 'dns@ionos.example' ) );
		}
		self::assertSame( array(), $GLOBALS['dav_mlm_test_wp_mail_calls'], 'Retries are not reported yet.' );

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Error'] );
		self::assertSame( 0, ( new Dav_Mlm_Attempts_Store() )->count_for( 'dns@ionos.example' ) );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_http_requests'] );
		$this->assert_digest_mentions( '[Error]', 'Gave up after 3 failed attempts', 'DKIM key lookup failed' );
	}

	public function test_a_regex_that_fails_at_runtime_goes_to_manual_and_never_rejects(): void {
		// Catastrophic backtracking: exponential in the number of a's, so it
		// hits the default pcre.backtrack_limit (JIT off — it has its own limit).
		$this->configure_list( array( 'regex' => '/^(a|aa)+\z/' ) );
		$this->mailbox->add_message( 1, $this->notification( array( 'sender' => str_repeat( 'a', 40 ) . '@example.org' ) ) );

		$jit = ini_set( 'pcre.jit', '0' );
		try {
			$this->runner()->run();
		} finally {
			ini_set( 'pcre.jit', (string) $jit );
		}

		self::assertSame( array( 1 ), $this->mailbox->folders['Manual'] );
		$this->assert_digest_mentions( 'The sender regex failed: Backtrack limit exhausted' );
		self::assertCount( 1, $GLOBALS['dav_mlm_test_wp_mail_calls'], 'Only the digest — no rejection mail.' );
	}

	public function test_an_approval_ionos_does_not_confirm_goes_to_manual(): void {
		$this->mailbox->add_message( 1, $this->notification() );
		dav_mlm_test_set_http_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html><head><title>Fehler</title></head></html>',
			)
		);

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Manual'] );
		$this->assert_digest_mentions( 'IONOS did not confirm the approval' );
	}

	public function test_a_network_error_during_approval_is_retried(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'message_id' => '<net@ionos.example>' ) ) );
		// No HTTP response set: wp_safe_remote_get() returns a WP_Error.

		$result = $this->runner()->run();

		self::assertArrayHasKey( 1, $this->mailbox->inbox );
		self::assertSame( 1, $result['counts']['errored'] );
		self::assertNull( ( new Dav_Mlm_Done_List() )->get( 'net@ionos.example' ) );
		self::assertSame( 1, ( new Dav_Mlm_Attempts_Store() )->count_for( 'net@ionos.example' ) );
	}

	public function test_a_rejection_mail_that_cannot_be_sent_is_retried(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'sender' => self::OUTSIDER ) ) );
		dav_mlm_test_set_wp_mail_result( false );

		$result = $this->runner()->run();

		self::assertArrayHasKey( 1, $this->mailbox->inbox );
		self::assertSame( 1, $result['counts']['errored'] );
		self::assertSame( 0, $result['counts']['rejected'] );
	}

	public function test_an_already_actioned_message_is_only_moved(): void {
		( new Dav_Mlm_Done_List() )->record( 'done-1@ionos.example', 'rejected' );
		$this->mailbox->add_message( 1, $this->notification( array( 'message_id' => '<done-1@ionos.example>', 'sender' => self::OUTSIDER ) ) );

		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Rejected'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_wp_mail_calls'], 'No second rejection mail.' );
	}

	public function test_a_failed_move_after_approving_is_finished_next_run_without_approving_twice(): void {
		$this->mailbox->add_message( 1, $this->notification() );
		$this->confirm_succeeds();
		$this->mailbox->fail_move = array( 1 );

		$this->runner()->run();

		self::assertArrayHasKey( 1, $this->mailbox->inbox );
		self::assertCount( 1, $GLOBALS['dav_mlm_test_http_requests'] );

		$this->mailbox->fail_move = array();
		$this->runner()->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Approved'] );
		self::assertCount( 1, $GLOBALS['dav_mlm_test_http_requests'], 'Approved exactly once.' );
	}

	public function test_one_failing_message_does_not_stop_the_others(): void {
		$this->mailbox->add_message( 1, $this->notification() );
		$this->mailbox->add_message( 2, $this->notification() );
		$this->mailbox->fail_fetch = array( 1 );
		$this->confirm_succeeds();

		$result = $this->runner()->run();

		self::assertTrue( $result['success'] );
		self::assertSame( array( 2 ), $this->mailbox->folders['Approved'] );
		self::assertArrayHasKey( 1, $this->mailbox->inbox );
		self::assertSame( 1, $result['counts']['errored'] );
	}

	public function test_a_dry_run_decides_but_changes_nothing(): void {
		$this->mailbox->add_message( 1, $this->notification() );
		$this->mailbox->add_message( 2, $this->notification( array( 'sender' => self::OUTSIDER ) ) );
		$this->mailbox->add_message( 3, $this->notification( array( 'subject' => 'Something else' ) ) );
		$this->confirm_succeeds();
		$inbox_before = $this->mailbox->inbox;

		$result = $this->runner( array( 'dry_run' => true ) )->run();

		self::assertTrue( $result['success'] );
		self::assertSame( 1, $result['counts']['approved'] );
		self::assertSame( 1, $result['counts']['rejected'] );
		self::assertSame( 1, $result['counts']['unrecognized'] );
		self::assertFalse( $this->mailbox->folders_ensured );
		self::assertSame( array(), $this->mailbox->folders );
		self::assertSame( 0, $this->mailbox->expunge_calls );
		self::assertSame( $inbox_before, $this->mailbox->inbox );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_http_requests'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_wp_mail_calls'] );
		self::assertNull( ( new Dav_Mlm_Run_Status() )->status()['last_run'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_options']['dav_mlm_done'] ?? array() );

		$log = $this->log();
		self::assertStringContainsString( 'WOULD approve', $log );
		self::assertStringContainsString( 'WOULD reject (email)', $log );
		self::assertStringContainsString( 'WOULD move to Unrecognized', $log );
	}

	public function test_a_connection_failure_fails_the_run_and_alerts_after_three_runs(): void {
		$this->mailbox->fail_connect = true;

		for ( $run = 1; $run <= Dav_Mlm_Alerter::DEFAULT_FAILURE_THRESHOLD; $run++ ) {
			$result = $this->runner()->run();
			self::assertFalse( $result['success'] );
		}

		$status = ( new Dav_Mlm_Run_Status() )->status();
		self::assertSame( Dav_Mlm_Alerter::DEFAULT_FAILURE_THRESHOLD, $status['consecutive_failures'] );
		self::assertNull( $status['last_successful_run'] );
		self::assertStringContainsString( 'AUTHENTICATIONFAILED', $status['messages'][0]['message'] );
		self::assertFalse( $this->mailbox->connected );

		self::assertCount( 1, $GLOBALS['dav_mlm_test_wp_mail_calls'] );
		self::assertSame( self::ALERT_EMAIL, $GLOBALS['dav_mlm_test_wp_mail_calls'][0]['to'] );
		self::assertStringContainsString( 'Cronjob schlägt fehl', $GLOBALS['dav_mlm_test_wp_mail_calls'][0]['subject'] );
	}

	public function test_message_id_option_processes_only_that_notification(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'message_id' => '<a@ionos.example>' ) ) );
		$this->mailbox->add_message( 2, $this->notification( array( 'message_id' => '<b@ionos.example>' ) ) );
		$this->confirm_succeeds();

		$result = $this->runner( array( 'only' => 'b@ionos.example' ) )->run();

		self::assertSame( array( 2 ), $this->mailbox->folders['Approved'] );
		self::assertArrayHasKey( 1, $this->mailbox->inbox );
		self::assertSame( 1, $result['counts']['skipped'] );
		self::assertSame( 1, $result['counts']['processed'] );
	}

	public function test_the_run_summary_is_recorded(): void {
		$this->mailbox->add_message( 1, $this->notification() );
		$this->mailbox->add_message( 2, $this->notification( array( 'list' => 'neu@dav-neuland.de' ) ) );
		$this->confirm_succeeds();
		$now = new DateTimeImmutable( '2026-10-01 12:00:00+00:00' );

		$this->runner( array( 'clock' => static fn (): DateTimeImmutable => $now ) )->run();

		$status = ( new Dav_Mlm_Run_Status() )->status();
		self::assertSame( $now->format( DateTimeInterface::ATOM ), $status['last_successful_run'] );
		self::assertSame( 2, $status['counts']['processed'] );
		self::assertSame( 1, $status['counts']['approved'] );
		self::assertSame( 1, $status['counts']['manual'] );
		self::assertCount( 1, $status['messages'] );
		self::assertStringContainsString( 'neu@dav-neuland.de / max@dav-neuland.de → Manual', $status['messages'][0]['message'] );
	}

	public function test_without_an_alert_address_messages_are_still_filed(): void {
		$this->mailbox->add_message( 1, $this->notification( array( 'list' => 'neu@dav-neuland.de' ) ) );

		$this->runner( array( 'alerter' => false ) )->run();

		self::assertSame( array( 1 ), $this->mailbox->folders['Manual'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_wp_mail_calls'] );
		self::assertStringContainsString( 'No alert address configured', $this->log() );
	}

	/**
	 * @param array{dry_run?: bool, only?: string, alerter?: bool, clock?: Closure} $options
	 */
	private function runner( array $options = array() ): Dav_Mlm_Cron_Runner {
		$mailer = new Dav_Mlm_Smtp_Mailer( 'smtp.ionos.de', 587, 'tls', self::MAIL_FROM, 'secret' );

		$dns_lookup = function ( string $domain, string $selector ) {
			$record = $this->dns[ $selector . '._domainkey.' . $domain ] ?? null;
			if ( false === $record ) {
				return false;
			}

			return null === $record ? array() : array( $record );
		};

		$log_config = new class( $this->data_dir ) implements Dav_Mlm_Log_Config {
			public function __construct( private string $dir ) {}

			public function data_dir(): string {
				return $this->dir;
			}

			public function log_retention_days(): int {
				return 30;
			}
		};

		return new Dav_Mlm_Cron_Runner(
			$this->mailbox,
			new Dav_Mlm_Notification_Verifier( 'no.reply@oneandone.com', 'kundenserver.de' ),
			new Dav_Mlm_Message_Parser(),
			new Dav_Mlm_Approver( 'ml.kundenserver.de' ),
			new Dav_Mlm_Dkim_Verifier( $dns_lookup ),
			new Dav_Mlm_Rejector( self::MAIL_FROM, 'DAV Neuland Mailinglisten', $mailer ),
			new Dav_Mlm_List_Repository(),
			new Dav_Mlm_Attempts_Store(),
			new Dav_Mlm_Done_List(),
			new Dav_Mlm_Run_Status(),
			( $options['alerter'] ?? true ) ? new Dav_Mlm_Alerter( self::ALERT_EMAIL, self::MAIL_FROM, 'DAV Neuland Mailinglisten', $mailer ) : null,
			new Dav_Mlm_Logger( $log_config, true ),
			$options['dry_run'] ?? false,
			$options['only'] ?? null,
			$options['clock'] ?? null
		);
	}

	private function configure_list( array $overrides = array() ): void {
		dav_mlm_test_set_option(
			'dav_mlm_lists',
			array(
				$overrides + array(
					'id'             => 'list-1',
					'list_address'   => self::LIST_ADDRESS,
					'regex'          => '/@dav-neuland\.de\z/i',
					'dkim_policy'    => 'require',
					'reject_mode'    => 'email',
					'reject_subject' => 'Nicht zugestellt: {{list}}',
					'reject_body'    => 'Hallo {{sender}}, das ging nicht.',
					'reply_to'       => null,
					'active'         => true,
				),
			)
		);
	}

	private function confirm_succeeds(): void {
		dav_mlm_test_set_http_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html><head><title>Der Vorgang war erfolgreich.</title></head></html>',
			)
		);
	}

	/**
	 * An IONOS "Freigabe" notification (PLAN.md §4) around a post by
	 * `sender` (signed by default).
	 *
	 * @param array{sender?: string, list?: string, nested?: ?string, auth?: string, subject?: string, url?: string, absender?: string, message_id?: string} $options
	 *        `nested` => null leaves the message/rfc822 part out entirely.
	 */
	private function notification( array $options = array() ): string {
		$sender = $options['sender'] ?? self::MEMBER;
		$list   = $options['list'] ?? self::LIST_ADDRESS;
		$nested = array_key_exists( 'nested', $options ) ? $options['nested'] : $this->signed_post( $sender, $list );

		$lines = array(
			'Authentication-Results: ' . ( $options['auth'] ?? 'kundenserver.de; dkim=pass header.i=no.reply@oneandone.com header.s=s1-ionos' ),
			'Message-ID: ' . ( $options['message_id'] ?? '<' . bin2hex( random_bytes( 6 ) ) . '@ionos.example>' ),
			'From: Mailinglisten-Manager <no.reply@oneandone.com>',
			'To: ' . self::MAIL_FROM,
			'Subject: ' . ( $options['subject'] ?? Dav_Mlm_Cron_Runner::SUBJECT_PREFIX . ' [' . $list . ']' ),
			'MIME-Version: 1.0',
			'Content-Type: multipart/mixed; boundary="OUTER"',
			'',
			'--OUTER',
			'Content-Type: text/plain; charset=UTF-8',
			'',
			'Eine E-Mail an die Mailingliste ' . $list . ' benötigt eine Freigabe:',
			'',
			'    Mailingliste: ' . $list,
			'    Absender: ' . ( $options['absender'] ?? $sender ),
			'',
			'    ' . ( $options['url'] ?? 'https://ml.kundenserver.de/MailingList/' . $list . '/Mail/Confirm?lang=de&id=TOKEN123' ),
		);

		if ( null !== $nested ) {
			array_push( $lines, '--OUTER', 'Content-Type: message/rfc822', '', $nested );
		}
		array_push( $lines, '--OUTER--', '' );

		return implode( "\r\n", $lines );
	}

	private function signed_post( string $sender, string $list ): string {
		// A selector (i.e. key) per post, so two posts from one domain don't
		// overwrite each other's DNS record.
		$domain   = substr( (string) strrchr( $sender, '@' ), 1 );
		$selector = 's' . ( ++$this->signed_posts );
		$signed   = Dav_Mlm_Test_Dkim_Signer::sign(
			$domain,
			$selector,
			array(
				array( 'From', 'Absender <' . $sender . '>' ),
				array( 'To', $list ),
				array( 'Subject', 'Hallo Liste' ),
			),
			"Hallo Liste.\r\n",
			array( 'From', 'To', 'Subject' ),
			time() - 60,
			time() + 7 * 86400
		);

		$this->dns[ $selector . '._domainkey.' . $domain ] = $signed['key_record'];

		return $signed['raw'];
	}

	private function unsigned_post( string $sender ): string {
		return "From: <$sender>\r\nTo: " . self::LIST_ADDRESS . "\r\nSubject: Hallo Liste\r\n\r\nHallo Liste.\r\n";
	}

	private function assert_digest_mentions( string ...$needles ): void {
		$digests = array_values(
			array_filter(
				$GLOBALS['dav_mlm_test_wp_mail_calls'],
				static fn ( array $call ): bool => self::ALERT_EMAIL === $call['to'] && str_contains( $call['subject'], 'zur Prüfung' )
			)
		);

		self::assertCount( 1, $digests, 'Exactly one digest mail.' );
		foreach ( $needles as $needle ) {
			self::assertStringContainsString( $needle, $digests[0]['message'] );
		}
	}

	private function log(): string {
		$content = '';
		foreach ( glob( $this->data_dir . '/*.log' ) ?: array() as $file ) {
			$content .= (string) file_get_contents( $file );
		}

		return $content;
	}
}
