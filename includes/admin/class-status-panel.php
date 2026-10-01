<?php
/**
 * Status panel on the settings page (PLAN.md §5a, issue #15): what the
 * cron runner recorded in option `dav_mlm_status` (Dav_Mlm_Run_Status),
 * plus a warning banner once the last runs failed in a row — the visible
 * counterpart to the alerter's failure mail, at the same threshold
 * (Dav_Mlm_Alerter::DEFAULT_FAILURE_THRESHOLD), so a single transient
 * hiccup doesn't raise it. Deliberately not based on the age of the last
 * success or of the last processed mail: the lists only get a mail or
 * two a week, and quiet periods are normal.
 *
 * Read-only; every value (including error messages, which can contain
 * addresses or subjects from incoming mail) goes through esc_html().
 * Times are shown in the site's timezone with a numeric format and a
 * relative age built here, so nothing depends on the site's WordPress
 * locale instead of the page's language toggle.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Status_Panel {

	private Dav_Mlm_Admin_Language $language;
	private Dav_Mlm_Run_Status $run_status;

	public function __construct( Dav_Mlm_Admin_Language $language, ?Dav_Mlm_Run_Status $run_status = null ) {
		$this->language   = $language;
		$this->run_status = $run_status ?? new Dav_Mlm_Run_Status();
	}

	/**
	 * The warning banner, if any; meant for the top of the page.
	 */
	public function render_banner( ?DateTimeImmutable $now = null ): void {
		$status = $this->run_status->status();
		if ( $status['consecutive_failures'] < Dav_Mlm_Alerter::DEFAULT_FAILURE_THRESHOLD ) {
			return;
		}

		$message = sprintf(
			$this->t( 'The last %1$d runs failed (last successful run: %2$s). Moderation is not working; check the errors below and the log.' ),
			$status['consecutive_failures'],
			$this->format_time( $status['last_successful_run'], $now ?? new DateTimeImmutable() )
		);

		printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $message ) );
	}

	public function render( ?DateTimeImmutable $now = null ): void {
		$now    = $now ?? new DateTimeImmutable();
		$status = $this->run_status->status();

		printf( '<h2>%s</h2>', esc_html( $this->t( 'Status' ) ) );

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_row( $this->t( 'Last run' ), $this->format_time( $status['last_run'], $now ) );
		$this->render_row( $this->t( 'Last successful run' ), $this->format_time( $status['last_successful_run'], $now ) );
		$this->render_row( $this->t( 'Consecutive failed runs' ), (string) (int) $status['consecutive_failures'] );
		echo '</tbody></table>';

		printf( '<h3>%s</h3>', esc_html( $this->t( 'Last run counts' ) ) );
		if ( null === $status['last_run'] ) {
			printf( '<p>%s</p>', esc_html( $this->t( 'No run recorded yet.' ) ) );
		} else {
			$labels = array(
				'processed'  => $this->t( 'Processed' ),
				'approved'   => $this->t( 'Approved' ),
				'rejected'   => $this->t( 'Rejected' ),
				'manual'     => $this->t( 'Manual review' ),
				'suspicious' => $this->t( 'Suspicious' ),
				'errored'    => $this->t( 'Errored' ),
				'skipped'    => $this->t( 'Skipped' ),
			);

			echo '<table class="widefat striped" style="width:auto"><thead><tr>';
			foreach ( Dav_Mlm_Run_Status::COUNT_KEYS as $key ) {
				printf( '<th scope="col">%s</th>', esc_html( $labels[ $key ] ) );
			}
			echo '</tr></thead><tbody><tr>';
			foreach ( Dav_Mlm_Run_Status::COUNT_KEYS as $key ) {
				printf( '<td>%d</td>', (int) ( $status['counts'][ $key ] ?? 0 ) );
			}
			echo '</tr></tbody></table>';
		}

		printf( '<h3>%s</h3>', esc_html( $this->t( 'Recent errors and warnings' ) ) );
		if ( array() === $status['messages'] ) {
			printf( '<p>%s</p>', esc_html( $this->t( 'None.' ) ) );

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( $this->t( 'Time' ), $this->t( 'Level' ), $this->t( 'Message' ) ) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';

		foreach ( array_reverse( $status['messages'] ) as $entry ) {
			$is_warning = Dav_Mlm_Run_Status::LEVEL_WARNING === ( $entry['level'] ?? '' );

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $this->format_time( (string) ( $entry['time'] ?? '' ), $now ) ),
				esc_html( $is_warning ? $this->t( 'Warning' ) : $this->t( 'Error' ) ),
				esc_html( (string) ( $entry['message'] ?? '' ) )
			);
		}

		echo '</tbody></table>';
	}

	private function render_row( string $label, string $value ): void {
		printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * "2026-09-30 14:05 (12 min ago)" in the site's timezone, or "never".
	 */
	private function format_time( ?string $time, DateTimeImmutable $now ): string {
		if ( null === $time || '' === $time ) {
			return $this->t( 'never' );
		}

		try {
			$timestamp = ( new DateTimeImmutable( $time ) )->getTimestamp();
		} catch ( Exception $e ) {
			return $time;
		}

		return sprintf( '%s (%s)', wp_date( 'Y-m-d H:i', $timestamp ), $this->format_age( $now->getTimestamp() - $timestamp ) );
	}

	private function format_age( int $seconds ): string {
		$minutes = intdiv( max( 0, $seconds ), 60 );

		if ( $minutes < 120 ) {
			return sprintf( $this->t( '%d min ago' ), $minutes );
		}
		if ( $minutes < 48 * 60 ) {
			return sprintf( $this->t( '%d h ago' ), intdiv( $minutes, 60 ) );
		}

		return sprintf( $this->t( '%d days ago' ), intdiv( $minutes, 24 * 60 ) );
	}

	private function t( string $text ): string {
		return $this->language->t( $text );
	}
}
