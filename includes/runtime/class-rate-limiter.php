<?php
/**
 * Per-sender rate limit for rejection mails (transients `dav_mlm_rl_<hash>`,
 * PLAN.md §5b step 4j: "max 5 rejection mails / sender / 24h"). The
 * sender address is hashed rather than used directly in the transient
 * name, so it never appears in the wp_options table's option_name column.
 *
 * The window is fixed: it starts with the sender's first rejection mail
 * and ends 24h later, however many mails follow. (Re-setting the transient
 * with a full 24h TTL on every mail would instead keep extending it.)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Rate_Limiter {

	public const MAX_PER_SENDER_PER_DAY = 5;

	private const KEY_PREFIX = 'dav_mlm_rl_';

	/**
	 * @return int The sender's new count for the current window.
	 */
	public function register_sent( string $sender_email, ?DateTimeImmutable $now = null ): int {
		$now    = ( $now ?? new DateTimeImmutable() )->getTimestamp();
		$window = $this->window( $sender_email, $now );

		$state = array(
			'count'   => ( null === $window ? 0 : $window['count'] ) + 1,
			'expires' => null === $window ? $now + $this->window_seconds() : $window['expires'],
		);

		set_transient( $this->key_for( $sender_email ), $state, max( 1, $state['expires'] - $now ) );

		return $state['count'];
	}

	public function has_exceeded( string $sender_email, ?DateTimeImmutable $now = null ): bool {
		$window = $this->window( $sender_email, ( $now ?? new DateTimeImmutable() )->getTimestamp() );

		return null !== $window && $window['count'] >= self::MAX_PER_SENDER_PER_DAY;
	}

	/**
	 * The sender's current window, or null if there is none (never sent, or
	 * expired — checked here too, not only by the transient's own TTL).
	 *
	 * @return array{count: int, expires: int}|null
	 */
	private function window( string $sender_email, int $now ): ?array {
		$state = get_transient( $this->key_for( $sender_email ) );

		if ( ! is_array( $state ) || ! isset( $state['count'], $state['expires'] ) || (int) $state['expires'] <= $now ) {
			return null;
		}

		return array(
			'count'   => (int) $state['count'],
			'expires' => (int) $state['expires'],
		);
	}

	private function key_for( string $sender_email ): string {
		return self::KEY_PREFIX . substr( hash( 'sha256', strtolower( trim( $sender_email ) ) ), 0, 32 );
	}

	private function window_seconds(): int {
		return defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
	}
}
