<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class StatusPanelTest extends TestCase {

	private DateTimeImmutable $now;

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
		$this->now = new DateTimeImmutable( '2026-10-01 12:00:00+00:00' );
	}

	public function test_no_banner_before_any_run(): void {
		self::assertSame( '', $this->banner() );
	}

	/**
	 * A mail or two a week is normal: successful runs with nothing to do
	 * never raise the banner, however long ago the last success was.
	 */
	public function test_no_banner_after_a_success_however_long_ago(): void {
		$this->record( true, '-30 days' );

		self::assertSame( '', $this->banner() );
	}

	public function test_no_banner_below_the_alert_threshold(): void {
		$this->record( true, '-20 minutes' );
		for ( $i = Dav_Mlm_Alerter::DEFAULT_FAILURE_THRESHOLD - 1; $i > 0; $i-- ) {
			$this->record( false, '-' . ( 5 * $i ) . ' minutes' );
		}

		self::assertSame( '', $this->banner() );
	}

	public function test_banner_once_the_last_runs_failed_in_a_row(): void {
		$this->record( true, '-20 minutes' );
		$this->record( false, '-15 minutes' );
		$this->record( false, '-10 minutes' );
		$this->record( false, '-5 minutes' );

		$banner = $this->banner();

		self::assertStringContainsString( 'notice-warning', $banner );
		self::assertStringContainsString( 'The last 3 runs failed (last successful run: 2026-10-01 11:40 (20 min ago))', $banner );
	}

	public function test_banner_when_no_run_ever_succeeded(): void {
		for ( $i = Dav_Mlm_Alerter::DEFAULT_FAILURE_THRESHOLD; $i > 0; $i-- ) {
			$this->record( false, '-' . ( 5 * $i ) . ' minutes' );
		}

		self::assertStringContainsString( '(last successful run: never)', $this->banner() );
	}

	public function test_panel_before_any_run(): void {
		$html = $this->panel();

		self::assertStringContainsString( '<th scope="row">Last run</th><td>never</td>', $html );
		self::assertStringContainsString( 'No run recorded yet.', $html );
		self::assertStringContainsString( 'None.', $html );
	}

	public function test_panel_shows_times_failures_and_every_count_in_order(): void {
		$this->record( true, '-3 hours', array( 'processed' => 4, 'approved' => 3, 'manual' => 1 ) );
		$this->record( false, '-10 minutes', array( 'processed' => 2, 'errored' => 2 ) );
		$this->record( false, '-5 minutes', array( 'processed' => 1, 'skipped' => 1 ) );

		$html = $this->panel();

		self::assertStringContainsString( '<th scope="row">Last run</th><td>2026-10-01 11:55 (5 min ago)</td>', $html );
		self::assertStringContainsString( '<th scope="row">Last successful run</th><td>2026-10-01 09:00 (3 h ago)</td>', $html );
		self::assertStringContainsString( '<th scope="row">Consecutive failed runs</th><td>2</td>', $html );
		self::assertStringContainsString( '<td>1</td><td>0</td><td>0</td><td>0</td><td>0</td><td>0</td><td>0</td><td>0</td><td>1</td>', $html );
	}

	public function test_messages_are_listed_newest_first_with_their_level_and_escaped(): void {
		$this->record( false, '-10 minutes', array(), array( 'IMAP login failed' ) );
		$this->record( true, '-5 minutes', array(), array(), array( 'Unknown list <script>alert(1)</script>@example.org' ) );

		$html = $this->panel();

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
		self::assertLessThan( strpos( $html, 'IMAP login failed' ), strpos( $html, 'Unknown list' ) );
		self::assertMatchesRegularExpression( '#<td>Warning</td><td>Unknown list#', $html );
		self::assertMatchesRegularExpression( '#<td>Error</td><td>IMAP login failed#', $html );
	}

	public function test_panel_follows_the_language(): void {
		$this->record( true, '-3 days' );
		$this->record( false, '-15 minutes' );
		$this->record( false, '-10 minutes' );
		$this->record( false, '-5 minutes' );

		$panel = new Dav_Mlm_Status_Panel( new Dav_Mlm_Admin_Language( Dav_Mlm_Admin_Language::DE ) );
		ob_start();
		$panel->render( $this->now );
		$panel->render_banner( $this->now );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<th scope="row">Letzter erfolgreicher Lauf</th><td>2026-09-28 12:00 (vor 3 Tagen)</td>', $html );
		self::assertStringContainsString( 'Die letzten 3 Läufe sind fehlgeschlagen (letzter erfolgreicher Lauf: 2026-09-28 12:00 (vor 3 Tagen))', $html );
	}

	private function record( bool $success, string $offset, array $counts = array(), array $errors = array(), array $warnings = array() ): void {
		( new Dav_Mlm_Run_Status() )->record_run( $success, $counts, $errors, $warnings, $this->now->modify( $offset ) );
	}

	private function panel(): string {
		ob_start();
		( new Dav_Mlm_Status_Panel( new Dav_Mlm_Admin_Language() ) )->render( $this->now );

		return (string) ob_get_clean();
	}

	private function banner(): string {
		ob_start();
		( new Dav_Mlm_Status_Panel( new Dav_Mlm_Admin_Language() ) )->render_banner( $this->now );

		return (string) ob_get_clean();
	}
}
