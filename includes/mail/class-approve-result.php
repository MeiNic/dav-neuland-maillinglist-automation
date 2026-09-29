<?php
/**
 * Outcome of Dav_Mlm_Approver::approve() (PLAN.md §5b step 4i).
 * SUSPICIOUS_URL means the runner should file the message under
 * `Suspicious`; NETWORK_ERROR is transient (leave in INBOX, count the
 * attempt, PLAN.md §5b step k); NOT_CONFIRMED covers every other
 * non-success response. Per issue #8, IONOS returns an identical generic
 * error page for an already-used token and for an invalid one, so those
 * two cases can't be told apart here — NOT_CONFIRMED is deliberately one
 * outcome, not two. (Re-approving a message we already succeeded on is
 * instead avoided by the runner's done-list, PLAN.md §5b step l.)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Approve_Result {

	public const SUSPICIOUS_URL = 'suspicious_url';
	public const NETWORK_ERROR  = 'network_error';
	public const NOT_CONFIRMED  = 'not_confirmed';

	private ?string $failure_reason;
	private string $detail;

	private function __construct( ?string $failure_reason, string $detail ) {
		$this->failure_reason = $failure_reason;
		$this->detail         = $detail;
	}

	public static function success(): self {
		return new self( null, '' );
	}

	public static function failure( string $reason, string $detail = '' ): self {
		return new self( $reason, $detail );
	}

	public function is_success(): bool {
		return null === $this->failure_reason;
	}

	/**
	 * One of the failure constants, or null on success.
	 */
	public function failure_reason(): ?string {
		return $this->failure_reason;
	}

	/**
	 * Human-readable context for the log; contains no confirm-URL token.
	 */
	public function detail(): string {
		return $this->detail;
	}
}
