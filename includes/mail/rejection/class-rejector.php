<?php
/**
 * Sends (or intentionally skips) the rejection mail for a post that
 * failed a list's regex check (PLAN.md §5b step 4j). `reject_mode =
 * silent` and the per-list templates come from the list's admin
 * configuration (Dav_Mlm_List_Repository, PLAN.md §5a) — the caller
 * passes them in directly, so this class doesn't depend on how lists are
 * stored.
 *
 * Loop/abuse guards run before anything is sent, so a reply never goes to
 * a bounce, another mailing list, or our own infrastructure — replying to
 * those is exactly how a mail loop starts. `List-Id`/`List-Unsubscribe`
 * are treated as a guard whenever present at all, not just when they
 * don't match one of ours: the *original post* awaiting moderation is
 * never itself list-processed mail, so either header being present means
 * it isn't the personal post it claims to be (a forwarded newsletter, a
 * loop, ...), regardless of which list it names.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Rejector {

	/**
	 * Every placeholder reject() substitutes; the admin page lists these
	 * as help text and warns about any other `{{...}}` in a template.
	 */
	public const PLACEHOLDERS = array( '{{sender}}', '{{list}}', '{{original_subject}}' );

	private string $mail_from;
	private string $mail_from_name;
	private Dav_Mlm_Smtp_Mailer $mailer;
	private Dav_Mlm_Rate_Limiter $rate_limiter;

	public function __construct(
		string $mail_from,
		string $mail_from_name,
		Dav_Mlm_Smtp_Mailer $mailer,
		?Dav_Mlm_Rate_Limiter $rate_limiter = null
	) {
		$this->mail_from      = strtolower( trim( $mail_from ) );
		$this->mail_from_name = $mail_from_name;
		$this->mailer         = $mailer;
		$this->rate_limiter   = $rate_limiter ?? new Dav_Mlm_Rate_Limiter();
	}

	/**
	 * @param list<string> $configured_list_addresses Every active list's
	 *        address (any case), so a post claiming to be from one of
	 *        them never gets an auto-reply either.
	 */
	public function reject(
		Dav_Mlm_Parsed_Notification $notification,
		string $reject_mode,
		string $subject_template,
		string $body_template,
		?string $reply_to,
		array $configured_list_addresses
	): Dav_Mlm_Reject_Result {
		if ( 'silent' === $reject_mode ) {
			return Dav_Mlm_Reject_Result::skipped( Dav_Mlm_Reject_Result::SILENT_MODE );
		}

		$sender = strtolower( trim( $notification->nested_from ) );

		$guard_reason = $this->loop_guard_reason( $notification, $sender, $configured_list_addresses );
		if ( null !== $guard_reason ) {
			return Dav_Mlm_Reject_Result::skipped( Dav_Mlm_Reject_Result::LOOP_GUARD, $guard_reason );
		}

		if ( $this->rate_limiter->has_exceeded( $sender ) ) {
			return Dav_Mlm_Reject_Result::skipped(
				Dav_Mlm_Reject_Result::RATE_LIMITED,
				sprintf( '%s already received %d rejection mail(s) in the last 24h.', $sender, Dav_Mlm_Rate_Limiter::MAX_PER_SENDER_PER_DAY )
			);
		}

		if ( false === is_email( $sender ) ) {
			return Dav_Mlm_Reject_Result::skipped( Dav_Mlm_Reject_Result::INVALID_TO, 'Sender address failed is_email(): ' . $sender );
		}

		$placeholders = array(
			'{{sender}}'           => $this->sanitize_placeholder( $notification->nested_from ),
			'{{list}}'             => $this->sanitize_placeholder( $notification->list_address ),
			// mb_substr: a byte cut could split an umlaut into invalid UTF-8.
			'{{original_subject}}' => mb_substr( $this->sanitize_placeholder( $notification->nested_subject ?? '' ), 0, 200, 'UTF-8' ),
		);

		$subject = strtr( $subject_template, $placeholders );
		$body    = strtr( $body_template, $placeholders );

		$headers   = array();
		$headers[] = sprintf( 'From: %s <%s>', $this->mail_from_name, $this->mail_from );
		if ( null !== $reply_to && '' !== trim( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . trim( $reply_to );
		}
		$headers[] = 'Auto-Submitted: auto-replied'; // RFC 3834: marks this as an automatic response.

		if ( ! $this->mailer->send( $sender, $subject, $body, $headers ) ) {
			$error = $this->mailer->last_error();

			return Dav_Mlm_Reject_Result::transient_failure( 'wp_mail() returned false' . ( null === $error ? '.' : ': ' . $error ) );
		}

		$this->rate_limiter->register_sent( $sender );

		return Dav_Mlm_Reject_Result::sent();
	}

	private function loop_guard_reason( Dav_Mlm_Parsed_Notification $notification, string $sender, array $configured_list_addresses ): ?string {
		$auto_submitted = $notification->nested_auto_submitted;
		if ( null !== $auto_submitted && 'no' !== strtolower( trim( $auto_submitted ) ) ) {
			return 'Auto-Submitted: ' . $auto_submitted;
		}

		$precedence = null !== $notification->nested_precedence ? strtolower( trim( $notification->nested_precedence ) ) : null;
		if ( null !== $precedence && in_array( $precedence, array( 'bulk', 'list', 'junk' ), true ) ) {
			return 'Precedence: ' . $precedence;
		}

		if ( null !== $notification->nested_list_id ) {
			return 'List-Id present: ' . $notification->nested_list_id;
		}
		if ( null !== $notification->nested_list_unsubscribe ) {
			return 'List-Unsubscribe present: ' . $notification->nested_list_unsubscribe;
		}

		$at         = strrpos( $sender, '@' );
		$local_part = false === $at ? $sender : substr( $sender, 0, $at );
		if ( in_array( $local_part, array( 'mailer-daemon', 'postmaster' ), true ) ) {
			return 'Sender local part is ' . $local_part;
		}
		if ( false !== strpos( $sender, 'noreply' ) || false !== strpos( $sender, 'no-reply' ) ) {
			return 'Sender looks like a noreply address: ' . $sender;
		}

		if ( $sender === $this->mail_from ) {
			return 'Sender is our own mail-from address.';
		}

		if ( in_array( $sender, array_map( static fn ( string $a ): string => strtolower( trim( $a ) ), $configured_list_addresses ), true ) ) {
			return 'Sender is a configured list address.';
		}

		return null;
	}

	/**
	 * Strips CR/LF and other control characters from a placeholder value
	 * before it's substituted into subject/body text. The subject *is* a
	 * header, so this is what stands between an attacker-controlled
	 * `original_subject` and a header-injected extra recipient.
	 */
	private function sanitize_placeholder( string $value ): string {
		return (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', $value );
	}
}
