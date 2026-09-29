<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase {

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	public function test_a_sender_who_never_sent_has_not_exceeded_the_limit(): void {
		self::assertFalse( ( new Dav_Mlm_Rate_Limiter() )->has_exceeded( 'someone@example.com' ) );
	}

	public function test_register_sent_increments_and_returns_the_new_count(): void {
		$limiter = new Dav_Mlm_Rate_Limiter();

		self::assertSame( 1, $limiter->register_sent( 'someone@example.com' ) );
		self::assertSame( 2, $limiter->register_sent( 'someone@example.com' ) );
	}

	public function test_has_exceeded_is_false_below_the_limit_and_true_at_it(): void {
		$limiter = new Dav_Mlm_Rate_Limiter();

		for ( $i = 0; $i < Dav_Mlm_Rate_Limiter::MAX_PER_SENDER_PER_DAY - 1; $i++ ) {
			$limiter->register_sent( 'someone@example.com' );
		}
		self::assertFalse( $limiter->has_exceeded( 'someone@example.com' ) );

		$limiter->register_sent( 'someone@example.com' );
		self::assertTrue( $limiter->has_exceeded( 'someone@example.com' ) );
	}

	public function test_senders_are_tracked_independently(): void {
		$limiter = new Dav_Mlm_Rate_Limiter();

		for ( $i = 0; $i < Dav_Mlm_Rate_Limiter::MAX_PER_SENDER_PER_DAY; $i++ ) {
			$limiter->register_sent( 'a@example.com' );
		}

		self::assertTrue( $limiter->has_exceeded( 'a@example.com' ) );
		self::assertFalse( $limiter->has_exceeded( 'b@example.com' ) );
	}

	public function test_sender_address_case_and_whitespace_do_not_bypass_the_limit(): void {
		$limiter = new Dav_Mlm_Rate_Limiter();

		$limiter->register_sent( 'Someone@Example.com' );

		self::assertSame( 2, $limiter->register_sent( '  someone@example.com  ' ) );
	}

	public function test_the_transient_key_never_contains_the_raw_email_address(): void {
		( new Dav_Mlm_Rate_Limiter() )->register_sent( 'someone@example.com' );

		foreach ( array_keys( $GLOBALS['dav_mlm_test_transients'] ) as $key ) {
			self::assertStringNotContainsString( 'someone', $key );
			self::assertStringNotContainsString( '@', $key );
		}
	}
}
