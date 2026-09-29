<?php
/**
 * Per-sender rate limit for rejection mails (transients `dav_mlm_rl_<hash>`,
 * PLAN.md §5b step 4j: "max 5 rejection mails / sender / 24h"). The
 * sender address is hashed rather than used directly in the transient
 * name, so it never appears in the wp_options table's option_name column.
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
	public function register_sent( string $sender_email ): int {
		$key   = $this->key_for( $sender_email );
		$count = $this->count_for( $sender_email ) + 1;

		set_transient( $key, $count, $this->window_seconds() );

		return $count;
	}

	public function has_exceeded( string $sender_email ): bool {
		return $this->count_for( $sender_email ) >= self::MAX_PER_SENDER_PER_DAY;
	}

	private function count_for( string $sender_email ): int {
		return (int) get_transient( $this->key_for( $sender_email ) );
	}

	private function key_for( string $sender_email ): string {
		return self::KEY_PREFIX . substr( hash( 'sha256', strtolower( trim( $sender_email ) ) ), 0, 32 );
	}

	private function window_seconds(): int {
		return defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
	}
}
