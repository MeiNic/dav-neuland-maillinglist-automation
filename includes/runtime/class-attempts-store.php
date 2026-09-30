<?php
/**
 * Per-message failure counter (option `dav_mlm_attempts`): a transient
 * failure (IMAP hiccup, DNS failure, HTTP timeout, wp_mail() false)
 * leaves the message in INBOX and increments its counter; after
 * MAX_ATTEMPTS failed runs the cron runner moves it to `Error` and
 * clears the counter (PLAN.md §5b step 4k).
 *
 * A message can also leave INBOX without the runner clearing its counter
 * (a human moves or deletes it), so every write drops entries whose last
 * failure is older than STALE_AFTER_SECONDS — the option stays bounded
 * without the runner having to know which messages disappeared.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Attempts_Store {

	public const MAX_ATTEMPTS = 3;

	public const STALE_AFTER_SECONDS = 7 * 86400;

	private const OPTION = 'dav_mlm_attempts';

	private Dav_Mlm_Option_Store $options;

	public function __construct( ?Dav_Mlm_Option_Store $options = null ) {
		$this->options = $options ?? new Dav_Mlm_Option_Store();
	}

	/**
	 * @return int The message's new failure count.
	 */
	public function record_failure( string $message_id, ?DateTimeImmutable $now = null ): int {
		$now      = ( $now ?? new DateTimeImmutable() )->getTimestamp();
		$attempts = $this->without_stale( $this->all(), $now );

		$attempts[ $message_id ] = array(
			'count'        => ( $attempts[ $message_id ]['count'] ?? 0 ) + 1,
			'last_failure' => $now,
		);
		$this->options->set( self::OPTION, $attempts );

		return $attempts[ $message_id ]['count'];
	}

	public function has_exceeded_max_attempts( string $message_id ): bool {
		return $this->count_for( $message_id ) >= self::MAX_ATTEMPTS;
	}

	public function count_for( string $message_id ): int {
		return $this->all()[ $message_id ]['count'] ?? 0;
	}

	public function clear( string $message_id, ?DateTimeImmutable $now = null ): void {
		$all      = $this->all();
		$attempts = $this->without_stale( $all, ( $now ?? new DateTimeImmutable() )->getTimestamp() );
		unset( $attempts[ $message_id ] );

		if ( $attempts !== $all ) {
			$this->options->set( self::OPTION, $attempts );
		}
	}

	/**
	 * @param array<string, array{count: int, last_failure: int}> $attempts
	 * @return array<string, array{count: int, last_failure: int}>
	 */
	private function without_stale( array $attempts, int $now ): array {
		return array_filter(
			$attempts,
			static fn ( array $entry ): bool => $now - $entry['last_failure'] < self::STALE_AFTER_SECONDS
		);
	}

	/**
	 * @return array<string, array{count: int, last_failure: int}>
	 */
	private function all(): array {
		$stored = $this->options->get( self::OPTION, array() );

		// Drop anything not in the current shape (e.g. the earlier plain-int
		// counters) rather than let it break the array accesses above.
		return array_filter(
			is_array( $stored ) ? $stored : array(),
			static fn ( $entry ): bool => is_array( $entry ) && isset( $entry['count'], $entry['last_failure'] )
		);
	}
}
