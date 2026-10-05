<?php
/**
 * Makes sure the system never fails silently (PLAN.md §5b step 6):
 * one digest mail per run listing anything new that needs a human, and a
 * throttled alert when the run itself keeps failing (rotated password,
 * IONOS changed its template, ...) — the one case where the plugin
 * otherwise couldn't tell anyone that it stopped working at all.
 *
 * Both kinds of mail are opt-in from the caller's side: this class never
 * reads IMAP state or Dav_Mlm_Run_Status itself, it just acts on the
 * `$new_items` / `$consecutive_failures` it's given (by
 * Dav_Mlm_Cron_Runner), so it's exercised the same way regardless of how
 * the runner tracks those. Only the failure-alert throttle is this class's own
 * state, kept in option `dav_mlm_alerter_state`: the last time a failure
 * alert was sent, so a still-broken run reminds at most once every 24h
 * instead of on every single run, and a null is what makes the next
 * success worth a "recovered" mail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Alerter {

	public const DEFAULT_FAILURE_THRESHOLD = 3;

	public const SENT_DIGEST        = 'digest';
	public const SENT_FAILURE_ALERT = 'failure_alert';
	public const SENT_RECOVERED     = 'recovered';

	private const OPTION           = 'dav_mlm_alerter_state';
	private const REMINDER_SECONDS = 86400; // 24h.

	private string $alert_email;
	private string $mail_from;
	private string $mail_from_name;
	private Dav_Mlm_Smtp_Mailer $mailer;
	private int $failure_threshold;
	private Dav_Mlm_Option_Store $options;

	public function __construct(
		string $alert_email,
		string $mail_from,
		string $mail_from_name,
		Dav_Mlm_Smtp_Mailer $mailer,
		int $failure_threshold = self::DEFAULT_FAILURE_THRESHOLD,
		?Dav_Mlm_Option_Store $options = null
	) {
		$this->alert_email       = $alert_email;
		$this->mail_from         = $mail_from;
		$this->mail_from_name    = $mail_from_name;
		$this->mailer            = $mailer;
		$this->failure_threshold = $failure_threshold;
		$this->options           = $options ?? new Dav_Mlm_Option_Store();
	}

	/**
	 * One digest mail listing everything new this run filed under
	 * Manual/Suspicious/Unrecognized/Error, with the reason for each.
	 * Nothing is sent when $new_items is empty — that's not a failure,
	 * there's just nothing to report.
	 *
	 * @param list<array{folder: string, identifier: string, reason: string}> $new_items
	 *        `identifier` is whatever the caller uses to tell messages
	 *        apart in the digest (sender/list, or the outer Message-ID);
	 *        this class doesn't interpret it, only prints it.
	 * @return string|null SENT_DIGEST when a mail went out, else null.
	 */
	public function send_digest( array $new_items ): ?string {
		if ( array() === $new_items ) {
			return null;
		}

		$lines = array();
		foreach ( $new_items as $item ) {
			$lines[] = sprintf( '[%s] %s — %s', $item['folder'], $item['identifier'], $item['reason'] );
		}

		$subject = sprintf( 'DAV Mailinglisten-Moderation: %d neue(r) Eintrag/Einträge zur Prüfung', count( $new_items ) );
		$body    = "Folgende Nachrichten benötigen eine manuelle Prüfung:\n\n" . implode( "\n", $lines ) . "\n";

		return $this->send( $subject, $body ) ? self::SENT_DIGEST : null;
	}

	/**
	 * Call once per run with its outcome. Sends (and throttles) the
	 * run-failure alert once $consecutive_failures reaches the
	 * threshold, and a one-off "recovered" mail the next time a run
	 * succeeds after one or more alerts were sent.
	 *
	 * @return string|null One of the SENT_* constants for what was sent,
	 *                      or null when nothing was (still healthy,
	 *                      below threshold, or throttled).
	 */
	public function handle_run_outcome( bool $success, int $consecutive_failures, string $detail = '', ?DateTimeImmutable $now = null ): ?string {
		$now   = $now ?? new DateTimeImmutable();
		$state = $this->state();

		if ( $success ) {
			if ( null === $state['last_failure_alert_sent_at'] ) {
				return null;
			}

			$sent = $this->send(
				'DAV Mailinglisten-Moderation: wieder normal',
				"Der Cronjob läuft seit dem letzten Lauf wieder ohne Fehler.\n"
			);
			if ( ! $sent ) {
				return null; // State kept: the next successful run tries again.
			}
			$this->save_state( array( 'last_failure_alert_sent_at' => null ) );

			return self::SENT_RECOVERED;
		}

		if ( $consecutive_failures < $this->failure_threshold ) {
			return null;
		}

		$last_sent_at = $state['last_failure_alert_sent_at'];
		if ( null !== $last_sent_at && ( $now->getTimestamp() - $last_sent_at ) < self::REMINDER_SECONDS ) {
			return null;
		}

		$body = sprintf( "Der Cronjob ist seit %d aufeinanderfolgenden Läufen fehlgeschlagen.\n", $consecutive_failures );
		if ( '' !== $detail ) {
			$body .= "\nLetzter Fehler:\n" . $detail . "\n";
		}

		// Only a delivered alert starts the 24h throttle; otherwise the next
		// failing run tries again instead of staying silent for a day.
		if ( ! $this->send( 'DAV Mailinglisten-Moderation: Cronjob schlägt fehl', $body ) ) {
			return null;
		}
		$this->save_state( array( 'last_failure_alert_sent_at' => $now->getTimestamp() ) );

		return self::SENT_FAILURE_ALERT;
	}

	/**
	 * Tries the noreply@ mailbox's SMTP first, then WordPress's default
	 * transport: SMTP shares the IMAP password by default, so a rotated
	 * password — the most likely reason runs start failing — breaks SMTP
	 * too, and the alert about it would otherwise never leave. If both
	 * fail, the error goes to error_log(), which the crontab entry
	 * redirects into cron.log.
	 */
	private function send( string $subject, string $body ): bool {
		$headers = array( sprintf( 'From: %s <%s>', $this->mail_from_name, $this->mail_from ) );

		if ( $this->mailer->send( $this->alert_email, $subject, $body, $headers ) ) {
			return true;
		}
		$smtp_error = $this->mailer->last_error();

		if ( $this->mailer->send_via_default_transport( $this->alert_email, $subject, $body, $headers ) ) {
			return true;
		}

		error_log(
			sprintf(
				'[dav-mlm] Could not send alert "%s" to %s (SMTP: %s; default transport: %s).',
				$subject,
				$this->alert_email,
				$smtp_error ?? 'no error message',
				$this->mailer->last_error() ?? 'no error message'
			)
		);

		return false;
	}

	/**
	 * @return array{last_failure_alert_sent_at: int|null}
	 */
	private function state(): array {
		return $this->options->get( self::OPTION, array( 'last_failure_alert_sent_at' => null ) );
	}

	/**
	 * @param array{last_failure_alert_sent_at: int|null} $state
	 */
	private function save_state( array $state ): void {
		$this->options->set( self::OPTION, $state );
	}
}
