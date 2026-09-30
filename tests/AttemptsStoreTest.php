<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class AttemptsStoreTest extends TestCase {

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	public function test_count_for_an_unknown_message_is_zero(): void {
		self::assertSame( 0, ( new Dav_Mlm_Attempts_Store() )->count_for( '<unknown@example.com>' ) );
	}

	public function test_record_failure_increments_and_returns_the_new_count(): void {
		$store = new Dav_Mlm_Attempts_Store();

		self::assertSame( 1, $store->record_failure( '<a@example.com>' ) );
		self::assertSame( 2, $store->record_failure( '<a@example.com>' ) );
		self::assertSame( 2, $store->count_for( '<a@example.com>' ) );
	}

	public function test_failures_are_tracked_independently_per_message(): void {
		$store = new Dav_Mlm_Attempts_Store();

		$store->record_failure( '<a@example.com>' );
		$store->record_failure( '<b@example.com>' );
		$store->record_failure( '<b@example.com>' );

		self::assertSame( 1, $store->count_for( '<a@example.com>' ) );
		self::assertSame( 2, $store->count_for( '<b@example.com>' ) );
	}

	public function test_has_exceeded_max_attempts_is_false_below_the_threshold(): void {
		$store = new Dav_Mlm_Attempts_Store();

		for ( $i = 0; $i < Dav_Mlm_Attempts_Store::MAX_ATTEMPTS - 1; $i++ ) {
			$store->record_failure( '<a@example.com>' );
		}

		self::assertFalse( $store->has_exceeded_max_attempts( '<a@example.com>' ) );
	}

	public function test_has_exceeded_max_attempts_is_true_at_the_threshold(): void {
		$store = new Dav_Mlm_Attempts_Store();

		for ( $i = 0; $i < Dav_Mlm_Attempts_Store::MAX_ATTEMPTS; $i++ ) {
			$store->record_failure( '<a@example.com>' );
		}

		self::assertTrue( $store->has_exceeded_max_attempts( '<a@example.com>' ) );
	}

	public function test_clear_resets_the_counter(): void {
		$store = new Dav_Mlm_Attempts_Store();

		$store->record_failure( '<a@example.com>' );
		$store->clear( '<a@example.com>' );

		self::assertSame( 0, $store->count_for( '<a@example.com>' ) );
	}

	public function test_entries_without_a_failure_for_a_week_are_dropped_on_the_next_write(): void {
		$store = new Dav_Mlm_Attempts_Store();
		$start = ( new DateTimeImmutable() )->setTimestamp( 1_000_000_000 );

		$store->record_failure( '<gone@example.com>', $start );
		$store->record_failure( '<recent@example.com>', $start->modify( '+6 days' ) );

		$store->record_failure( '<new@example.com>', $start->modify( '+7 days' ) );

		self::assertSame( 0, $store->count_for( '<gone@example.com>' ) );
		self::assertSame( 1, $store->count_for( '<recent@example.com>' ) );
		self::assertSame( 1, $store->count_for( '<new@example.com>' ) );
	}

	public function test_a_repeated_failure_keeps_the_entry_fresh(): void {
		$store = new Dav_Mlm_Attempts_Store();
		$start = ( new DateTimeImmutable() )->setTimestamp( 1_000_000_000 );

		$store->record_failure( '<a@example.com>', $start );
		$store->record_failure( '<a@example.com>', $start->modify( '+6 days' ) );

		self::assertSame( 3, $store->record_failure( '<a@example.com>', $start->modify( '+12 days' ) ) );
	}

	public function test_clear_on_an_unknown_message_is_a_no_op(): void {
		$store = new Dav_Mlm_Attempts_Store();

		$store->clear( '<never-recorded@example.com>' );

		self::assertSame( 0, $store->count_for( '<never-recorded@example.com>' ) );
	}
}
