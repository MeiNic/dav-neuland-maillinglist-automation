<?php
/**
 * Outcome of Dav_Mlm_Dkim_Verifier::verify() (PLAN.md §5b step 4g).
 * Anything but pass() means the runner files the message under `Manual`
 * and never rejects because of it — DNS_ERROR is transient (retry-worthy),
 * the others are not, but both land on the same manual-review path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Dkim_Result {

	public const NO_SIGNATURE = 'no_signature';
	public const FAIL         = 'fail';
	public const DNS_ERROR    = 'dns_error';

	public const PATH_AS_IS        = 'as_is';
	public const PATH_RECONSTRUCTED = 'reconstructed';

	private ?string $failure_reason;
	private ?string $path;
	private ?string $signing_domain;
	private string $detail;

	private function __construct( ?string $failure_reason, ?string $path, ?string $signing_domain, string $detail ) {
		$this->failure_reason = $failure_reason;
		$this->path           = $path;
		$this->signing_domain = $signing_domain;
		$this->detail         = $detail;
	}

	public static function pass( string $path, string $signing_domain ): self {
		return new self( null, $path, $signing_domain, '' );
	}

	public static function failure( string $reason, string $detail = '' ): self {
		return new self( $reason, null, null, $detail );
	}

	public function is_pass(): bool {
		return null === $this->failure_reason;
	}

	/**
	 * PATH_AS_IS or PATH_RECONSTRUCTED, or null on failure.
	 */
	public function path(): ?string {
		return $this->path;
	}

	/**
	 * The `d=` of the signature that passed, or null on failure.
	 */
	public function signing_domain(): ?string {
		return $this->signing_domain;
	}

	/**
	 * One of the failure constants, or null on a pass.
	 */
	public function failure_reason(): ?string {
		return $this->failure_reason;
	}

	/**
	 * Human-readable context for the log; contains no key material.
	 */
	public function detail(): string {
		return $this->detail;
	}
}
