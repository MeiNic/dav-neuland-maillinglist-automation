<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/TempDirectoryTrait.php';

use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase {

	use TempDirectoryTrait;

	private DateTimeImmutable $now;

	protected function setUp(): void {
		$this->now = new DateTimeImmutable( '2026-09-29 12:00:00' );
		$this->make_temp_dir();
	}

	protected function tearDown(): void {
		$this->remove_temp_dir();
	}

	public function test_info_writes_a_line_to_the_dated_log_file(): void {
		$logger = $this->make_logger();

		$logger->info( 'run started', array( 'list' => 'test.mailingliste@dav-neuland.de' ) );

		$contents = $this->read_log_file();
		self::assertStringContainsString( '[INFO]', $contents );
		self::assertStringContainsString( 'run started', $contents );
		self::assertStringContainsString( 'test.mailingliste@dav-neuland.de', $contents );
	}

	public function test_warning_and_error_levels_are_written(): void {
		$logger = $this->make_logger();

		$logger->warning( 'a warning' );
		$logger->error( 'an error' );

		$contents = $this->read_log_file();
		self::assertStringContainsString( '[WARNING] a warning', $contents );
		self::assertStringContainsString( '[ERROR] an error', $contents );
	}

	public function test_debug_is_suppressed_without_verbose(): void {
		$logger = $this->make_logger( verbose: false );

		$logger->debug( 'should not appear' );

		self::assertFileDoesNotExist( $this->expected_log_path() );
	}

	public function test_debug_is_written_with_verbose(): void {
		$logger = $this->make_logger( verbose: true );

		$logger->debug( 'should appear' );

		self::assertStringContainsString( '[DEBUG] should appear', $this->read_log_file() );
	}

	public function test_confirm_token_is_masked_before_being_written(): void {
		$logger = $this->make_logger();

		$logger->info( 'approved https://ml.kundenserver.de/x/Mail/Confirm?lang=de&id=SUPERSECRET' );

		$contents = $this->read_log_file();
		self::assertStringNotContainsString( 'SUPERSECRET', $contents );
	}

	public function test_prune_old_logs_delegates_to_the_rotator(): void {
		$old_file = $this->temp_dir . '/dav-mlm-2020-01-01.log';
		file_put_contents( $old_file, "stale\n" );

		$logger  = $this->make_logger();
		$deleted = $logger->prune_old_logs();

		self::assertSame( array( $old_file ), $deleted );
		self::assertFileDoesNotExist( $old_file );
	}

	public function test_falls_back_to_error_log_when_the_file_cannot_be_written(): void {
		// Make the "directory" a plain file, so is_dir()/mkdir() both fail
		// and the logger has no writable destination for the dated file.
		$blocked_dir = $this->temp_dir . '/blocked';
		file_put_contents( $blocked_dir, 'not a directory' );

		$fallback_log = $this->temp_dir . '/php-error.log';
		$previous     = ini_set( 'error_log', $fallback_log );

		try {
			$logger = new Dav_Mlm_Logger( $this->fake_config( $blocked_dir ), false, null, null, $this->now );
			$logger->error( 'unwritable destination' );

			self::assertFileExists( $fallback_log );
			self::assertStringContainsString( 'unwritable destination', (string) file_get_contents( $fallback_log ) );
		} finally {
			ini_set( 'error_log', false !== $previous ? $previous : '' );
		}
	}

	private function make_logger( bool $verbose = false ): Dav_Mlm_Logger {
		return new Dav_Mlm_Logger( $this->fake_config( $this->temp_dir ), $verbose, null, null, $this->now );
	}

	private function fake_config( string $dir, int $retention_days = 30 ): Dav_Mlm_Log_Config {
		return new class( $dir, $retention_days ) implements Dav_Mlm_Log_Config {
			public function __construct( private string $dir, private int $retention_days ) {}

			public function data_dir(): string {
				return $this->dir;
			}

			public function log_retention_days(): int {
				return $this->retention_days;
			}
		};
	}

	private function expected_log_path(): string {
		return $this->temp_dir . '/dav-mlm-2026-09-29.log';
	}

	private function read_log_file(): string {
		self::assertFileExists( $this->expected_log_path() );

		return (string) file_get_contents( $this->expected_log_path() );
	}
}
