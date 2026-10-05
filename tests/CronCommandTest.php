<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';
require_once __DIR__ . '/fixtures/TempDirectoryTrait.php';

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The CLI wrapper around the runner. Tests that need DAV_MLM_* constants
 * run in a separate process, like ConfigTest, since constants can't be
 * undefined again.
 */
final class CronCommandTest extends TestCase {

	use TempDirectoryTrait;

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	protected function tearDown(): void {
		$this->remove_temp_dir();
	}

	public function test_help_prints_the_usage(): void {
		ob_start();
		$exit = ( new Dav_Mlm_Cron_Command() )->run( array( 'help' => false ) );
		$output = (string) ob_get_clean();

		self::assertSame( 0, $exit );
		self::assertStringContainsString( '--dry-run', $output );
		self::assertStringContainsString( '--message-id=<id>', $output );
	}

	#[RunInSeparateProcess]
	public function test_missing_config_fails_and_is_recorded_as_a_failed_run(): void {
		$stderr = fopen( 'php://memory', 'w+' );

		$exit = ( new Dav_Mlm_Cron_Command( $stderr ) )->run( array() );

		self::assertSame( 1, $exit );
		rewind( $stderr );
		self::assertStringContainsString( 'DAV_MLM_IMAP_HOST', (string) stream_get_contents( $stderr ) );
		$status = ( new Dav_Mlm_Run_Status() )->status();
		self::assertSame( 1, $status['consecutive_failures'] );
		self::assertStringContainsString( 'DAV_MLM_IMAP_HOST', $status['messages'][0]['message'] );
	}

	#[RunInSeparateProcess]
	public function test_a_held_lock_exits_cleanly_and_the_data_dir_is_protected(): void {
		$data_dir = $this->make_temp_dir();
		$this->define_config( $data_dir );

		$held = new Dav_Mlm_Run_Lock( $data_dir . '/cron.lock' );
		self::assertTrue( $held->acquire() );

		$exit = ( new Dav_Mlm_Cron_Command() )->run( array() );

		self::assertSame( 0, $exit );
		self::assertStringContainsString( 'Require all denied', (string) file_get_contents( $data_dir . '/.htaccess' ) );
		self::assertStringContainsString( 'Another run is still in progress', implode( '', array_map( 'file_get_contents', glob( $data_dir . '/*.log' ) ) ) );
		self::assertNull( ( new Dav_Mlm_Run_Status() )->status()['last_run'], 'The runner never started.' );

		$held->release();
	}

	private function define_config( string $data_dir ): void {
		define( 'DAV_MLM_IMAP_HOST', 'imap.example.com' );
		define( 'DAV_MLM_IMAP_PORT', '993' );
		define( 'DAV_MLM_IMAP_ENCRYPTION', 'ssl' );
		define( 'DAV_MLM_IMAP_USER', 'noreply@dav-neuland.de' );
		define( 'DAV_MLM_IMAP_PASS', 's3cret' );
		define( 'DAV_MLM_DATA_DIR', $data_dir );
	}
}
