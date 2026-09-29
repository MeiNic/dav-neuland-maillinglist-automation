<?php
/**
 * Per-message failure counter (option `dav_mlm_attempts`): a transient
 * failure (IMAP hiccup, DNS failure, HTTP timeout, wp_mail() false)
 * leaves the message in INBOX and increments its counter; after
 * MAX_ATTEMPTS failed runs the cron runner moves it to `Error` and
 * clears the counter (PLAN.md §5b step 4k).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Attempts_Store {

	public const MAX_ATTEMPTS = 3;

	private const OPTION = 'dav_mlm_attempts';

	private Dav_Mlm_Option_Store $options;

	public function __construct( ?Dav_Mlm_Option_Store $options = null ) {
		$this->options = $options ?? new Dav_Mlm_Option_Store();
	}

	/**
	 * @return int The message's new failure count.
	 */
	public function record_failure( string $message_id ): int {
		$attempts                 = $this->all();
		$attempts[ $message_id ]  = $this->count_for( $message_id ) + 1;
		$this->options->set( self::OPTION, $attempts );

		return $attempts[ $message_id ];
	}

	public function has_exceeded_max_attempts( string $message_id ): bool {
		return $this->count_for( $message_id ) >= self::MAX_ATTEMPTS;
	}

	public function count_for( string $message_id ): int {
		return $this->all()[ $message_id ] ?? 0;
	}

	public function clear( string $message_id ): void {
		$attempts = $this->all();

		if ( isset( $attempts[ $message_id ] ) ) {
			unset( $attempts[ $message_id ] );
			$this->options->set( self::OPTION, $attempts );
		}
	}

	/**
	 * @return array<string, int>
	 */
	private function all(): array {
		return $this->options->get( self::OPTION, array() );
	}
}
