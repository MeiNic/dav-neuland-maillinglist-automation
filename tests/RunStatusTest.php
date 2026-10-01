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
		self::assertSame( array(), $status['messages'] );
	}

	public function test_a_successful_run_sets_last_run_and_last_successful_run_and_resets_failures(): void {
		$run_status = new Dav_Mlm_Run_Status();
		$time       = new DateTimeImmutable( '2026-09-29 12:00:00' );

		$run_status->record_run( false, array(), array( 'boom' ), array(), $time );
		$run_status->record_run( true, array( 'processed' => 3 ), array(), array(), $time->modify( '+5 minutes' ) );

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

		$run_status->record_run( true, array(), array(), array(), $success_time );
		$run_status->record_run( false, array(), array(), array(), $success_time->modify( '+5 minutes' ) );

		self::assertSame( $success_time->format( DateTimeInterface::ATOM ), $run_status->status()['last_successful_run'] );
	}

	public function test_messages_accumulate_across_runs_with_timestamps_and_levels(): void {
		$run_status = new Dav_Mlm_Run_Status();
		$time       = new DateTimeImmutable( '2026-09-29 12:00:00' );

		$run_status->record_run( false, array(), array( 'first error' ), array(), $time );
		$run_status->record_run( true, array(), array( 'second error' ), array( 'a warning' ), $time->modify( '+5 minutes' ) );

		$messages = $run_status->status()['messages'];
		self::assertSame( array( 'first error', 'second error', 'a warning' ), array_column( $messages, 'message' ) );
		self::assertSame( array( 'error', 'error', 'warning' ), array_column( $messages, 'level' ) );
		self::assertSame( $time->format( DateTimeInterface::ATOM ), $messages[0]['time'] );
	}

	public function test_messages_are_trimmed_to_max_messages_dropping_the_oldest_first(): void {
		$run_status = new Dav_Mlm_Run_Status();

		for ( $i = 0; $i < Dav_Mlm_Run_Status::MAX_MESSAGES + 5; $i++ ) {
			$run_status->record_run( false, array(), array( "error {$i}" ) );
		}

		$messages = $run_status->status()['messages'];
		self::assertCount( Dav_Mlm_Run_Status::MAX_MESSAGES, $messages );
		self::assertSame( 'error 5', $messages[0]['message'] );
		self::assertSame( 'error ' . ( Dav_Mlm_Run_Status::MAX_MESSAGES + 4 ), $messages[ count( $messages ) - 1 ]['message'] );
	}

	public function test_a_partial_or_corrupt_stored_status_falls_back_to_defaults(): void {
		dav_mlm_test_set_option( 'dav_mlm_status', array( 'last_run' => '2026-09-29T12:00:00+00:00' ) );
		self::assertSame( 0, ( new Dav_Mlm_Run_Status() )->consecutive_failures() );
		self::assertSame( '2026-09-29T12:00:00+00:00', ( new Dav_Mlm_Run_Status() )->status()['last_run'] );

		dav_mlm_test_set_option( 'dav_mlm_status', 'garbage' );
		self::assertSame( array(), ( new Dav_Mlm_Run_Status() )->status()['messages'] );
	}

}
