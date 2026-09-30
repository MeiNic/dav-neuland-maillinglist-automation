<?php
/**
 * Outcome of Dav_Mlm_Message_Parser::parse(): either a
 * Dav_Mlm_Parsed_Notification or a typed failure. The runner sends every
 * failure except NO_LIST_ADDRESS to the `Manual` folder and never rejects
 * because of one (PLAN.md §5b step 4c); NO_LIST_ADDRESS means the subject
 * doesn't match the IONOS template, i.e. `Unrecognized` (step 3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Parse_Result {

	public const UNPARSEABLE       = 'unparseable';
	public const NO_LIST_ADDRESS   = 'no_list_address';
	public const NO_NESTED_MESSAGE = 'no_nested_message';
	public const NO_CONFIRM_URL    = 'no_confirm_url';
	public const NO_FROM           = 'no_from';
	public const MULTIPLE_FROM     = 'multiple_from';
	public const GROUP_FROM        = 'group_from';
	public const INVALID_FROM      = 'invalid_from';
	public const IDN_FROM          = 'idn_from';

	private ?Dav_Mlm_Parsed_Notification $notification;
	private ?string $failure_reason;
	private string $detail;

	private function __construct( ?Dav_Mlm_Parsed_Notification $notification, ?string $failure_reason, string $detail ) {
		$this->notification   = $notification;
		$this->failure_reason = $failure_reason;
		$this->detail         = $detail;
	}

	public static function success( Dav_Mlm_Parsed_Notification $notification ): self {
		return new self( $notification, null, '' );
	}

	public static function failure( string $reason, string $detail = '' ): self {
		return new self( null, $reason, $detail );
	}

	public function is_success(): bool {
		return null !== $this->notification;
	}

	/**
	 * @throws LogicException When the parse failed; check is_success() first.
	 */
	public function notification(): Dav_Mlm_Parsed_Notification {
		if ( null === $this->notification ) {
			throw new LogicException( 'Parse failed (' . $this->failure_reason . '); there is no notification.' );
		}

		return $this->notification;
	}

	/**
	 * One of the failure constants, or null on success.
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
