<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class RunStatusTest extends TestCase {

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	public function test_defaults_before_any_run_is_recorded(): void {
		$status = ( new Dav_Mlm_Run_Status() )->status();

		self::assertNull( $status['last_run'] );
		self::assertNull( $status['last_successful_run'] );
		self::assertSame( 0, $status['consecutive_failures'] );
		self::assertSame( array(), $status['counts'] );
		self::assertSame( array(), $status['errors'] );
	}

	public function test_a_successful_run_sets_last_run_and_last_successful_run_and_resets_failures(): void {
		$run_status = new Dav_Mlm_Run_Status();
		$time       = new DateTimeImmutable( '2026-09-29 12:00:00' );

		$run_status->record_run( false, array(), array( 'boom' ), $time );
		$run_status->record_run( true, array( 'processed' => 3 ), array(), $time->modify( '+5 minutes' ) );

		$status = $run_status->status();
		self::assertSame( $time->modify( '+5 minutes' )->format( DateTimeInterface::ATOM ), $status['last_run'] );
		self::assertSame( $status['last_run'], $status['last_successful_run'] );
		self::assertSame( 0, $status['consecutive_failures'] );
		self::assertSame( array( 'processed' => 3 ), $status['counts'] );
	}

	public function test_consecutive_failures_increment_on_each_failed_run(): void {
		$run_status = new Dav_Mlm_Run_Status();

		$run_status->record_run( false, array() );
		$run_status->record_run( false, array() );
		$run_status->record_run( false, array() );

		self::assertSame( 3, $run_status->consecutive_failures() );
	}

	public function test_a_failed_run_does_not_update_last_successful_run(): void {
		$run_status = new Dav_Mlm_Run_Status();
		$success_time = new DateTimeImmutable( '2026-09-29 12:00:00' );

		$run_status->record_run( true, array(), array(), $success_time );
		$run_status->record_run( false, array(), array(), $success_time->modify( '+5 minutes' ) );

		self::assertSame( $success_time->format( DateTimeInterface::ATOM ), $run_status->status()['last_successful_run'] );
	}

	public function test_errors_accumulate_across_runs_with_timestamps(): void {
		$run_status = new Dav_Mlm_Run_Status();
		$time       = new DateTimeImmutable( '2026-09-29 12:00:00' );

		$run_status->record_run( false, array(), array( 'first error' ), $time );
		$run_status->record_run( false, array(), array( 'second error' ), $time->modify( '+5 minutes' ) );

		$errors = $run_status->status()['errors'];
		self::assertCount( 2, $errors );
		self::assertSame( 'first error', $errors[0]['message'] );
		self::assertSame( 'second error', $errors[1]['message'] );
		self::assertSame( $time->format( DateTimeInterface::ATOM ), $errors[0]['time'] );
	}

	public function test_errors_are_trimmed_to_max_errors_dropping_the_oldest_first(): void {
		$run_status = new Dav_Mlm_Run_Status();

		for ( $i = 0; $i < Dav_Mlm_Run_Status::MAX_ERRORS + 5; $i++ ) {
			$run_status->record_run( false, array(), array( "error {$i}" ) );
		}

		$errors = $run_status->status()['errors'];
		self::assertCount( Dav_Mlm_Run_Status::MAX_ERRORS, $errors );
		self::assertSame( 'error 5', $errors[0]['message'] );
		self::assertSame( 'error ' . ( Dav_Mlm_Run_Status::MAX_ERRORS + 4 ), $errors[ count( $errors ) - 1 ]['message'] );
	}
}
