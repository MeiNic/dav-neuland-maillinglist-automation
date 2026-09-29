<?php
/**
 * Outcome of Dav_Mlm_Notification_Verifier::verify(). Anything but
 * `genuine()` means the runner files the message under `Suspicious`,
 * alerts, and takes no action (PLAN.md §5b step 4b).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Verification_Result {

	public const UNPARSEABLE               = 'unparseable';
	public const NO_AUTHENTICATION_RESULTS = 'no_authentication_results';
	public const UNTRUSTED_AUTHSERV        = 'untrusted_authserv';
	public const NO_TRUSTED_DKIM_PASS      = 'no_trusted_dkim_pass';
	public const WRONG_FROM                = 'wrong_from';

	private ?string $failure_reason;
	private string $detail;

	private function __construct( ?string $failure_reason, string $detail ) {
		$this->failure_reason = $failure_reason;
		$this->detail         = $detail;
	}

	public static function genuine(): self {
		return new self( null, '' );
	}

	public static function failure( string $reason, string $detail = '' ): self {
		return new self( $reason, $detail );
	}

	public function is_genuine(): bool {
		return null === $this->failure_reason;
	}

	/**
	 * One of the failure constants, or null when the notification is genuine.
	 */
	public function failure_reason(): ?string {
		return $this->failure_reason;
	}

	/**
	 * Human-readable context for the log; contains no message body.
	 */
	public function detail(): string {
		return $this->detail;
	}
}
