<?php
/**
 * One moderation run (PLAN.md §5b steps 2–6): connect, look at every IONOS
 * notification in INBOX, decide, act, file it into a folder, then record
 * the run summary and send alerts. bin/cron-runner.php (via
 * Dav_Mlm_Cron_Command) handles the bootstrap and the lock around it.
 *
 * Per message, in this order — the first check that fails decides:
 *
 *  1. Already actioned in an earlier run (done-list) → just finish the move.
 *  2. A known IONOS mail that isn't an approval request (new subscriber,
 *     delivery failure) → `Info`, quietly: nothing to decide, no alert.
 *     Anything else whose subject isn't the approval template →
 *     `Unrecognized`.
 *  3. Notification not genuinely from IONOS → `Suspicious`.
 *  4. Can't be parsed → `Manual` (`Unrecognized` without a [list address]).
 *  5. Confirm URL fails the allowlist → `Suspicious`.
 *  6. List unknown or inactive → `Manual`.
 *  7. From: doesn't match the envelope sender (Absender:) → `Manual`.
 *  8. DKIM, if the list requires it, doesn't pass → `Manual`.
 *  9. Sender regex: 1 → approve, 0 → reject, false → `Manual`.
 *
 * Nothing is ever rejected because something went wrong: every doubt
 * ends in `Manual` or `Suspicious`, and those (plus `Unrecognized` and
 * `Error`) go into the alert digest. A transient failure (IMAP, DNS, HTTP,
 * SMTP — or any unexpected exception) leaves the message in INBOX and
 * counts an attempt; after Dav_Mlm_Attempts_Store::MAX_ATTEMPTS it goes to
 * `Error`. One message failing never stops the others.
 *
 * A dry run decides exactly the same way but only logs what it WOULD do:
 * no folders created, no approval request, no rejection mail, no moves,
 * no attempt/done/status changes, no alerts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DavMlm\Vendor\ZBateson\MailMimeParser\MailMimeParser;

final class Dav_Mlm_Cron_Runner {

	/**
	 * Start of every approval notification's subject (PLAN.md §4); IONOS
	 * sends other mails from the same address (new subscriber, delivery
	 * failure, ...), and a changed template must not go unnoticed.
	 */
	public const SUBJECT_PREFIX = 'Freigabe einer neuen E-Mail an die Mailingliste';

	/**
	 * Subjects of IONOS mails from the same sender that are known and need
	 * nobody's attention (seen in samples/). Filed under `Info` without an
	 * alert; any other non-approval subject still goes to `Unrecognized`.
	 * Making this switchable per type is issue #24.
	 */
	public const INFORMATIONAL_SUBJECT_PREFIXES = array(
		'Neuer Teilnehmer in die Mailingliste',
		'Fehler bei der Zustellung einer E-Mail an die Mailingliste',
	);

	private const COUNT_FOR_FOLDER = array(
		Dav_Mlm_Mailbox_Interface::FOLDER_APPROVED     => 'approved',
		Dav_Mlm_Mailbox_Interface::FOLDER_REJECTED     => 'rejected',
		Dav_Mlm_Mailbox_Interface::FOLDER_MANUAL       => 'manual',
		Dav_Mlm_Mailbox_Interface::FOLDER_SUSPICIOUS   => 'suspicious',
		Dav_Mlm_Mailbox_Interface::FOLDER_UNRECOGNIZED => 'unrecognized',
		Dav_Mlm_Mailbox_Interface::FOLDER_ERROR        => 'errored',
		Dav_Mlm_Mailbox_Interface::FOLDER_INFO         => 'informational',
	);

	/** Folders whose new arrivals need a human, i.e. go into the alert digest. */
	private const FOLDERS_FOR_A_HUMAN = array(
		Dav_Mlm_Mailbox_Interface::FOLDER_MANUAL,
		Dav_Mlm_Mailbox_Interface::FOLDER_SUSPICIOUS,
		Dav_Mlm_Mailbox_Interface::FOLDER_UNRECOGNIZED,
		Dav_Mlm_Mailbox_Interface::FOLDER_ERROR,
	);

	private const DONE_APPROVED = 'approved';
	private const DONE_REJECTED = 'rejected';

	private Dav_Mlm_Mailbox_Interface $mailbox;
	private Dav_Mlm_Notification_Verifier $verifier;
	private Dav_Mlm_Message_Parser $parser;
	private Dav_Mlm_Approver $approver;
	private Dav_Mlm_Dkim_Verifier $dkim_verifier;
	private Dav_Mlm_Rejector $rejector;
	private Dav_Mlm_List_Repository $lists;
	private Dav_Mlm_Attempts_Store $attempts;
	private Dav_Mlm_Done_List $done;
	private Dav_Mlm_Run_Status $run_status;
	private ?Dav_Mlm_Alerter $alerter;
	private Dav_Mlm_Logger $logger;
	private bool $dry_run;
	private ?string $only_message_id;
	private Closure $clock;
	private MailMimeParser $mime_parser;

	/** @var array<string, int> */
	private array $counts = array();
	/** @var list<string> */
	private array $errors = array();
	/** @var list<string> */
	private array $warnings = array();
	/** @var list<array{folder: string, identifier: string, reason: string}> */
	private array $digest = array();

	/**
	 * @param Dav_Mlm_Alerter|null $alerter         Null when no alert address is configured.
	 * @param string|null          $only_message_id Only process the notification with this outer Message-ID.
	 * @param Closure|null         $clock           Returns "now" as a DateTimeImmutable; for tests.
	 */
	public function __construct(
		Dav_Mlm_Mailbox_Interface $mailbox,
		Dav_Mlm_Notification_Verifier $verifier,
		Dav_Mlm_Message_Parser $parser,
		Dav_Mlm_Approver $approver,
		Dav_Mlm_Dkim_Verifier $dkim_verifier,
		Dav_Mlm_Rejector $rejector,
		Dav_Mlm_List_Repository $lists,
		Dav_Mlm_Attempts_Store $attempts,
		Dav_Mlm_Done_List $done,
		Dav_Mlm_Run_Status $run_status,
		?Dav_Mlm_Alerter $alerter,
		Dav_Mlm_Logger $logger,
		bool $dry_run = false,
		?string $only_message_id = null,
		?Closure $clock = null
	) {
		$this->mailbox         = $mailbox;
		$this->verifier        = $verifier;
		$this->parser          = $parser;
		$this->approver        = $approver;
		$this->dkim_verifier   = $dkim_verifier;
		$this->rejector        = $rejector;
		$this->lists           = $lists;
		$this->attempts        = $attempts;
		$this->done            = $done;
		$this->run_status      = $run_status;
		$this->alerter         = $alerter;
		$this->logger          = $logger;
		$this->dry_run         = $dry_run;
		$this->only_message_id = $only_message_id;
		$this->clock           = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
		$this->mime_parser     = new MailMimeParser();
	}

	/**
	 * The production object graph, from the wp-config.php constants.
	 */
	public static function from_config( Dav_Mlm_Config $config, Dav_Mlm_Logger $logger, bool $dry_run = false, ?string $only_message_id = null ): self {
		$mailer = new Dav_Mlm_Smtp_Mailer(
			$config->smtp_host(),
			$config->smtp_port(),
			$config->smtp_encryption(),
			$config->smtp_user(),
			$config->smtp_pass()
		);

		$alert_email = $config->alert_email();

		return new self(
			new Dav_Mlm_Mailbox( $config ),
			new Dav_Mlm_Notification_Verifier( $config->notify_from(), $config->trusted_authserv() ),
			new Dav_Mlm_Message_Parser(),
			new Dav_Mlm_Approver( $config->confirm_host() ),
			new Dav_Mlm_Dkim_Verifier(),
			new Dav_Mlm_Rejector( $config->mail_from(), $config->mail_from_name(), $mailer ),
			new Dav_Mlm_List_Repository(),
			new Dav_Mlm_Attempts_Store(),
			new Dav_Mlm_Done_List(),
			new Dav_Mlm_Run_Status(),
			null === $alert_email ? null : new Dav_Mlm_Alerter( $alert_email, $config->mail_from(), $config->mail_from_name(), $mailer ),
			$logger,
			$dry_run,
			$only_message_id
		);
	}

	/**
	 * A run fails only when the mailbox itself can't be used (or something
	 * outside the per-message handling breaks); problems with single
	 * messages are handled per message and don't fail the run.
	 *
	 * @return array{success: bool, counts: array<string, int>}
	 */
	public function run(): array {
		$this->counts   = array_fill_keys( Dav_Mlm_Run_Status::COUNT_KEYS, 0 );
		$this->errors   = array();
		$this->warnings = array();
		$this->digest   = array();

		$this->logger->info( $this->dry_run ? 'Run started (dry run — nothing will be changed).' : 'Run started.' );

		$run_error = null;
		try {
			$this->mailbox->connect();
			if ( ! $this->dry_run ) {
				$this->mailbox->ensure_folders();
			}

			$uids = $this->mailbox->search_notifications();
			$this->logger->debug( 'Found notifications in INBOX.', array( 'count' => count( $uids ) ) );

			foreach ( $uids as $uid ) {
				$this->process_safely( $uid );
			}

			if ( ! $this->dry_run ) {
				$this->mailbox->expunge();
			}
		} catch ( Throwable $error ) {
			$run_error = get_class( $error ) . ': ' . $error->getMessage();
			$this->error( 'Run failed: ' . $run_error );
		} finally {
			$this->mailbox->close();
		}

		$success = null === $run_error;
		$this->finish( $success, $run_error ?? '' );

		return array(
			'success' => $success,
			'counts'  => $this->counts,
		);
	}

	private function process_safely( int $uid ): void {
		$key = 'uid:' . $uid;

		try {
			$raw     = $this->mailbox->fetch_raw( $uid );
			$headers = $this->outer_headers( $raw );
			// The outer Message-ID keys the attempt counter and the done-list;
			// a hash of the source is a stable stand-in if IONOS ever omits it.
			$key = $headers['message_id'] ?? 'sha1:' . sha1( $raw );

			if ( null !== $this->only_message_id && ! $this->same_message_id( $key, $this->only_message_id ) ) {
				++$this->counts['skipped'];

				return;
			}

			++$this->counts['processed'];
			$this->process( $uid, $key, $raw, $headers['subject'] );
		} catch ( Throwable $error ) {
			$this->transient_failure( $uid, $key, get_class( $error ) . ': ' . $error->getMessage(), $key );
		}
	}

	private function process( int $uid, string $key, string $raw, ?string $subject ): void {
		$done = $this->done->get( $key );
		if ( null !== $done ) {
			$folder = self::DONE_APPROVED === $done['action'] ? Dav_Mlm_Mailbox_Interface::FOLDER_APPROVED : Dav_Mlm_Mailbox_Interface::FOLDER_REJECTED;
			$this->file( $uid, $key, $folder, sprintf( 'Already %s on %s; only the move was left to do.', $done['action'], $done['time'] ), $key );

			return;
		}

		if ( null !== $subject && $this->is_informational( $subject ) ) {
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_INFO, 'Known IONOS info mail: ' . $subject, $key );

			return;
		}

		if ( null === $subject || ! str_starts_with( $subject, self::SUBJECT_PREFIX ) ) {
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_UNRECOGNIZED, 'Subject is not the IONOS approval template: ' . ( $subject ?? '(none)' ), $key );

			return;
		}

		$verification = $this->verifier->verify( $raw );
		if ( ! $verification->is_genuine() ) {
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_SUSPICIOUS, sprintf( 'Notification is not verifiably from IONOS (%s): %s', $verification->failure_reason(), $verification->detail() ), $key );

			return;
		}

		$parsed = $this->parser->parse( $raw );
		if ( ! $parsed->is_success() ) {
			$folder = Dav_Mlm_Parse_Result::NO_LIST_ADDRESS === $parsed->failure_reason()
				? Dav_Mlm_Mailbox_Interface::FOLDER_UNRECOGNIZED
				: Dav_Mlm_Mailbox_Interface::FOLDER_MANUAL;
			$this->file( $uid, $key, $folder, sprintf( 'Could not parse the notification (%s): %s', $parsed->failure_reason(), $parsed->detail() ), $key );

			return;
		}

		$notification = $parsed->notification();
		$who          = $notification->list_address . ' / ' . $notification->nested_from;

		if ( ! $this->approver->is_allowlisted( $notification->confirm_url, $notification->list_address ) ) {
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_SUSPICIOUS, 'The confirm link failed the allowlist.', $who );

			return;
		}

		$list = $this->lists->find_by_address( $notification->list_address );
		if ( null === $list ) {
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_MANUAL, 'No configuration for this list — add it on the settings page.', $who );

			return;
		}
		if ( ! $list['active'] ) {
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_MANUAL, 'The list is configured but inactive.', $who );

			return;
		}

		$envelope_sender = $notification->absender ?? $notification->nested_return_path;
		if ( null === $envelope_sender || strtolower( trim( $envelope_sender ) ) !== strtolower( $notification->nested_from ) ) {
			$this->file(
				$uid,
				$key,
				Dav_Mlm_Mailbox_Interface::FOLDER_MANUAL,
				sprintf( 'The From: address does not match the envelope sender (%s).', $envelope_sender ?? 'none' ),
				$who
			);

			return;
		}

		if ( 'off' !== $list['dkim_policy'] ) {
			$dkim = $this->dkim_verifier->verify( $notification->nested_raw, $notification->nested_from, ( $this->clock )() );

			if ( Dav_Mlm_Dkim_Result::DNS_ERROR === $dkim->failure_reason() ) {
				$this->transient_failure( $uid, $key, 'DKIM key lookup failed: ' . $dkim->detail(), $who );

				return;
			}
			if ( ! $dkim->is_pass() ) {
				$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_MANUAL, sprintf( 'DKIM check failed (%s): %s', $dkim->failure_reason(), $dkim->detail() ), $who );

				return;
			}

			// PLAN.md §4a: worth knowing how often the QP reconstruction is needed.
			$this->logger->info(
				'DKIM passed.',
				array(
					'post'   => $who,
					'path'   => $dkim->path(),
					'domain' => $dkim->signing_domain(),
				)
			);
		}

		// The regex compiled when it was saved; false here is a runtime
		// failure (e.g. backtrack limit) and must never count as "no match".
		$match = @preg_match( $list['regex'], $notification->nested_from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $match ) {
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_MANUAL, 'The sender regex failed: ' . preg_last_error_msg(), $who );

			return;
		}

		if ( 1 === $match ) {
			$this->approve( $uid, $key, $notification, $who );
		} else {
			$this->reject( $uid, $key, $notification, $list, $who );
		}
	}

	private function approve( int $uid, string $key, Dav_Mlm_Parsed_Notification $notification, string $who ): void {
		if ( $this->dry_run ) {
			$this->logger->info( 'WOULD approve.', array( 'post' => $who ) );
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_APPROVED, 'Approved.', $who );

			return;
		}

		$result = $this->approver->approve( $notification->confirm_url, $notification->list_address );

		if ( $result->is_success() ) {
			$this->done->record( $key, self::DONE_APPROVED, ( $this->clock )() );
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_APPROVED, 'Approved.', $who );

			return;
		}

		switch ( $result->failure_reason() ) {
			case Dav_Mlm_Approve_Result::NETWORK_ERROR:
				$this->transient_failure( $uid, $key, 'Approval request failed: ' . $result->detail(), $who );
				break;
			case Dav_Mlm_Approve_Result::SUSPICIOUS_URL:
				$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_SUSPICIOUS, 'The confirm link failed the allowlist.', $who );
				break;
			default:
				// IONOS answers an already-used and an invalid token with the
				// same error page (issue #8), so a human has to look.
				$this->file(
					$uid,
					$key,
					Dav_Mlm_Mailbox_Interface::FOLDER_MANUAL,
					sprintf( 'IONOS did not confirm the approval (%s); it may already have been approved or rejected by hand.', $result->detail() ),
					$who
				);
		}
	}

	/**
	 * @param array<string, mixed> $list
	 */
	private function reject( int $uid, string $key, Dav_Mlm_Parsed_Notification $notification, array $list, string $who ): void {
		if ( $this->dry_run ) {
			$this->logger->info( sprintf( 'WOULD reject (%s).', $list['reject_mode'] ), array( 'post' => $who ) );
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_REJECTED, 'Rejected.', $who );

			return;
		}

		$result = $this->rejector->reject(
			$notification,
			(string) $list['reject_mode'],
			(string) $list['reject_subject'],
			(string) $list['reject_body'],
			$list['reply_to'],
			$this->lists->active_addresses()
		);

		if ( $result->is_transient_failure() ) {
			$this->transient_failure( $uid, $key, 'Rejection mail could not be sent: ' . $result->detail(), $who );

			return;
		}

		$this->done->record( $key, self::DONE_REJECTED, ( $this->clock )() );

		$reason = $result->mail_was_sent()
			? 'Rejected; rejection mail sent.'
			: sprintf( 'Rejected without a mail (%s%s).', $result->skip_reason(), '' === $result->detail() ? '' : ': ' . $result->detail() );
		$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_REJECTED, $reason, $who );
	}

	/**
	 * Moves the message into $folder (or, in a dry run, logs that it
	 * would), counts it, and queues it for the alert digest if a human
	 * has to look at it.
	 *
	 * @throws Dav_Mlm_Mailbox_Exception When the move fails; the caller
	 *         treats that as a transient failure.
	 */
	private function file( int $uid, string $key, string $folder, string $reason, string $who ): void {
		$context = array(
			'message_id' => $key,
			'post'       => $who,
		);

		if ( $this->dry_run ) {
			$this->logger->info( sprintf( 'WOULD move to %s: %s', $folder, $reason ), $context );
		} else {
			$this->mailbox->move( $uid, $folder );
			$this->attempts->clear( $key, ( $this->clock )() );

			if ( in_array( $folder, self::FOLDERS_FOR_A_HUMAN, true ) ) {
				$this->digest[] = array(
					'folder'     => $folder,
					'identifier' => $who,
					'reason'     => $reason,
				);

				$message = sprintf( '%s → %s: %s', $who, $folder, $reason );
				if ( Dav_Mlm_Mailbox_Interface::FOLDER_ERROR === $folder ) {
					$this->error( $message, $context );
				} else {
					$this->warning( $message, $context );
				}
			} else {
				$this->logger->info( sprintf( 'Moved to %s: %s', $folder, $reason ), $context );
			}
		}

		++$this->counts[ self::COUNT_FOR_FOLDER[ $folder ] ];
	}

	/**
	 * Leaves the message in INBOX for the next run, or files it under
	 * `Error` once it has failed MAX_ATTEMPTS times.
	 */
	private function transient_failure( int $uid, string $key, string $detail, string $who ): void {
		$context = array(
			'message_id' => $key,
			'post'       => $who,
		);

		if ( $this->dry_run ) {
			$this->logger->warning( 'WOULD count a failed attempt and retry next run: ' . $detail, $context );
			++$this->counts['errored'];

			return;
		}

		$attempt = $this->attempts->record_failure( $key, ( $this->clock )() );

		if ( $attempt < Dav_Mlm_Attempts_Store::MAX_ATTEMPTS ) {
			$this->warning( sprintf( '%s: attempt %d of %d failed, retrying next run: %s', $who, $attempt, Dav_Mlm_Attempts_Store::MAX_ATTEMPTS, $detail ), $context );
			++$this->counts['errored'];

			return;
		}

		try {
			$this->file( $uid, $key, Dav_Mlm_Mailbox_Interface::FOLDER_ERROR, sprintf( 'Gave up after %d failed attempts. Last error: %s', $attempt, $detail ), $who );
		} catch ( Throwable $error ) {
			$this->error( sprintf( '%s: could not move to %s either: %s', $who, Dav_Mlm_Mailbox_Interface::FOLDER_ERROR, $error->getMessage() ), $context );
			++$this->counts['errored'];
		}
	}

	private function finish( bool $success, string $run_error ): void {
		$this->logger->info( $success ? 'Run finished.' : 'Run failed.', $this->counts );

		if ( $this->dry_run ) {
			return;
		}

		$this->run_status->record_run( $success, $this->counts, $this->errors, $this->warnings, ( $this->clock )() );
		$this->logger->prune_old_logs();

		if ( null === $this->alerter ) {
			if ( array() !== $this->digest || ! $success ) {
				$this->logger->warning( 'No alert address configured (DAV_MLM_ALERT_EMAIL / admin email) — nobody was notified.' );
			}

			return;
		}

		if ( null !== $this->alerter->send_digest( $this->digest ) ) {
			$this->logger->info( 'Alert digest sent.', array( 'items' => count( $this->digest ) ) );
		}

		$sent = $this->alerter->handle_run_outcome( $success, $this->run_status->consecutive_failures(), $run_error, ( $this->clock )() );
		if ( null !== $sent ) {
			$this->logger->info( 'Run outcome alert sent.', array( 'kind' => $sent ) );
		}
	}

	/**
	 * @return array{message_id: ?string, subject: ?string} The Message-ID
	 *         without angle brackets.
	 */
	private function outer_headers( string $raw ): array {
		$message    = $this->mime_parser->parse( $raw, false );
		$message_id = $this->bare_message_id( Dav_Mlm_Header_Lookup::first_value( $message, 'Message-ID' ) ?? '' );

		return array(
			'message_id' => '' === $message_id ? null : $message_id,
			'subject'    => Dav_Mlm_Header_Lookup::first_value( $message, 'Subject' ),
		);
	}

	private function is_informational( string $subject ): bool {
		foreach ( self::INFORMATIONAL_SUBJECT_PREFIXES as $prefix ) {
			if ( str_starts_with( $subject, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private function same_message_id( string $a, string $b ): bool {
		return $this->bare_message_id( $a ) === $this->bare_message_id( $b );
	}

	private function bare_message_id( string $message_id ): string {
		return trim( $message_id, " \t<>" );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function warning( string $message, array $context = array() ): void {
		$this->logger->warning( $message, $context );
		$this->warnings[] = $message;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function error( string $message, array $context = array() ): void {
		$this->logger->error( $message, $context );
		$this->errors[] = $message;
	}
}
