<?php
/**
 * Run summary (option `dav_mlm_status`) shown on the admin status panel:
 * last run, last successful run, this run's counts, a consecutive-failure
 * counter (drives the alerter's escalation, issue #13), and the last N
 * errors (PLAN.md §5a, §5b step 5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Run_Status {

	public const MAX_ERRORS = 20;

	private const OPTION = 'dav_mlm_status';

	private Dav_Mlm_Option_Store $options;

	public function __construct( ?Dav_Mlm_Option_Store $options = null ) {
		$this->options = $options ?? new Dav_Mlm_Option_Store();
	}

	/**
	 * @param array<string, int> $counts
	 * @param string[]           $errors
	 */
	public function record_run( bool $success, array $counts, array $errors = array(), ?DateTimeImmutable $time = null ): void {
		$status = $this->status();
		$now    = ( $time ?? new DateTimeImmutable() )->format( DateTimeInterface::ATOM );

		$status['last_run'] = $now;

		if ( $success ) {
			$status['last_successful_run']  = $now;
			$status['consecutive_failures'] = 0;
		} else {
			++$status['consecutive_failures'];
		}

		$status['counts'] = $counts;

		foreach ( $errors as $message ) {
			$status['errors'][] = array(
				'time'    => $now,
				'message' => $message,
			);
		}

		if ( count( $status['errors'] ) > self::MAX_ERRORS ) {
			$status['errors'] = array_slice( $status['errors'], -self::MAX_ERRORS );
		}

		$this->options->set( self::OPTION, $status );
	}

	/**
	 * @return array{
	 *     last_run: ?string,
	 *     last_successful_run: ?string,
	 *     consecutive_failures: int,
	 *     counts: array<string, int>,
	 *     errors: array<int, array{time: string, message: string}>
	 * }
	 */
	public function status(): array {
		return $this->options->get( self::OPTION, $this->defaults() );
	}

	public function consecutive_failures(): int {
		return $this->status()['consecutive_failures'];
	}

	/**
	 * @return array{
	 *     last_run: ?string,
	 *     last_successful_run: ?string,
	 *     consecutive_failures: int,
	 *     counts: array<string, int>,
	 *     errors: array<int, array{time: string, message: string}>
	 * }
	 */
	private function defaults(): array {
		return array(
			'last_run'             => null,
			'last_successful_run'  => null,
			'consecutive_failures' => 0,
			'counts'               => array(),
			'errors'               => array(),
		);
	}
}
