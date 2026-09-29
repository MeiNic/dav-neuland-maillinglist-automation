<?php
/**
 * Bounded list of already-actioned outer Message-IDs (option
 * `dav_mlm_done`): checked before every outward action so that if a
 * message was already approved/rejected but the subsequent IMAP move
 * failed, the retry just repeats the move instead of approving or
 * emailing a second time (PLAN.md §5b step 4l).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Done_List {

	public const MAX_ENTRIES = 500;

	private const OPTION = 'dav_mlm_done';

	private Dav_Mlm_Option_Store $options;

	public function __construct( ?Dav_Mlm_Option_Store $options = null ) {
		$this->options = $options ?? new Dav_Mlm_Option_Store();
	}

	public function record( string $message_id, string $action, ?DateTimeImmutable $time = null ): void {
		$done = $this->all();

		// Unset first so re-recording an existing id moves it to the end
		// (most-recently-actioned last), instead of pruning could drop it
		// earlier than a genuinely new entry.
		unset( $done[ $message_id ] );
		$done[ $message_id ] = array(
			'action' => $action,
			'time'   => ( $time ?? new DateTimeImmutable() )->format( DateTimeInterface::ATOM ),
		);

		if ( count( $done ) > self::MAX_ENTRIES ) {
			$done = array_slice( $done, -self::MAX_ENTRIES, null, true );
		}

		$this->options->set( self::OPTION, $done );
	}

	/**
	 * @return array{action: string, time: string}|null
	 */
	public function get( string $message_id ): ?array {
		return $this->all()[ $message_id ] ?? null;
	}

	public function is_done( string $message_id ): bool {
		return null !== $this->get( $message_id );
	}

	/**
	 * @return array<string, array{action: string, time: string}>
	 */
	private function all(): array {
		return $this->options->get( self::OPTION, array() );
	}
}
