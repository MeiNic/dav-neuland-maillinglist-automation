<?php
/**
 * Run summary (option `dav_mlm_status`) shown on the admin status panel:
 * last run, last successful run, this run's counts, a consecutive-failure
 * counter (drives the alerter's escalation, issue #13, and the admin
 * page's warning banner), and the last N errors and warnings (PLAN.md
 * §5a, §5b step 5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Run_Status {

	public const MAX_MESSAGES = 20;

	/**
	 * The run summary's counters (PLAN.md §5b step 5), in display order.
	 */
	public const COUNT_KEYS = array( 'processed', 'approved', 'rejected', 'manual', 'suspicious', 'errored', 'skipped' );

	public const LEVEL_ERROR   = 'error';
	public const LEVEL_WARNING = 'warning';

	private const OPTION = 'dav_mlm_status';

	private Dav_Mlm_Option_Store $options;

	public function __construct( ?Dav_Mlm_Option_Store $options = null ) {
		$this->options = $options ?? new Dav_Mlm_Option_Store();
	}

	/**
	 * @param array<string, int> $counts
	 * @param string[]           $errors
	 * @param string[]           $warnings
	 */
	public function record_run( bool $success, array $counts, array $errors = array(), array $warnings = array(), ?DateTimeImmutable $time = null ): void {
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

		foreach ( array( self::LEVEL_ERROR => $errors, self::LEVEL_WARNING => $warnings ) as $level => $messages ) {
			foreach ( $messages as $message ) {
				$status['messages'][] = array(
					'time'    => $now,
					'level'   => $level,
					'message' => $message,
				);
			}
		}

		if ( count( $status['messages'] ) > self::MAX_MESSAGES ) {
			$status['messages'] = array_slice( $status['messages'], -self::MAX_MESSAGES );
		}

		$this->options->set( self::OPTION, $status );
	}

	/**
	 * @return array{
	 *     last_run: ?string,
	 *     last_successful_run: ?string,
	 *     consecutive_failures: int,
	 *     counts: array<string, int>,
	 *     messages: array<int, array{time: string, level: string, message: string}>
	 * }
	 */
	public function status(): array {
		$stored = $this->options->get( self::OPTION, array() );

		return array_merge( $this->defaults(), is_array( $stored ) ? $stored : array() );
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
	 *     messages: array<int, array{time: string, level: string, message: string}>
	 * }
	 */
	private function defaults(): array {
		return array(
			'last_run'             => null,
			'last_successful_run'  => null,
			'consecutive_failures' => 0,
			'counts'               => array(),
			'messages'             => array(),
		);
	}
}
