<?php
/**
 * Outcome of Dav_Mlm_Rejector::reject() (PLAN.md §5b step 4j).
 *
 * `is_transient_failure()` is the only outcome the runner retries
 * (PLAN.md §5b step 4k: leave in INBOX, count the attempt) — every other
 * outcome, sent or not, means the post was handled and the message moves
 * to `Rejected`. A skip is not a failure: `reject_mode = silent`, a loop
 * guard, and the rate limit are all intentional, expected reasons not to
 * send a mail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Reject_Result {

	public const SILENT_MODE  = 'silent_mode';
	public const LOOP_GUARD   = 'loop_guard';
	public const RATE_LIMITED = 'rate_limited';
	public const INVALID_TO   = 'invalid_to';

	private bool $mail_sent;
	private bool $transient_failure;
	private ?string $skip_reason;
	private string $detail;

	private function __construct( bool $mail_sent, bool $transient_failure, ?string $skip_reason, string $detail ) {
		$this->mail_sent         = $mail_sent;
		$this->transient_failure = $transient_failure;
		$this->skip_reason       = $skip_reason;
		$this->detail            = $detail;
	}

	public static function sent(): self {
		return new self( true, false, null, '' );
	}

	/**
	 * @param string $reason One of the class constants.
	 */
	public static function skipped( string $reason, string $detail = '' ): self {
		return new self( false, false, $reason, $detail );
	}

	public static function transient_failure( string $detail = '' ): self {
		return new self( false, true, null, $detail );
	}

	/**
	 * True only for wp_mail() returning false: the runner leaves the
	 * message in INBOX and counts the attempt instead of filing it.
	 */
	public function is_transient_failure(): bool {
		return $this->transient_failure;
	}

	public function mail_was_sent(): bool {
		return $this->mail_sent;
	}

	/**
	 * One of the class constants when the mail was intentionally not
	 * sent, or null when it was sent (or the failure was transient).
	 */
	public function skip_reason(): ?string {
		return $this->skip_reason;
	}

	/**
	 * Human-readable context for the log; contains no message body.
	 */
	public function detail(): string {
		return $this->detail;
	}
}
