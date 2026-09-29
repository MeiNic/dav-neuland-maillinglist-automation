<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/TempDirectoryTrait.php';

use PHPUnit\Framework\TestCase;

final class LogRotatorTest extends TestCase {

	use TempDirectoryTrait;

	private Dav_Mlm_Log_Rotator $rotator;
	private DateTimeImmutable $now;

	protected function setUp(): void {
		$this->rotator = new Dav_Mlm_Log_Rotator();
		$this->now     = new DateTimeImmutable( '2026-09-29 12:00:00' );
		$this->make_temp_dir();
	}

	protected function tearDown(): void {
		$this->remove_temp_dir();
	}

	public function test_current_file_path_is_named_by_the_clocks_date(): void {
		$path = $this->rotator->current_file_path( $this->temp_dir, $this->now );

		self::assertSame( $this->temp_dir . '/dav-mlm-2026-09-29.log', $path );
	}

	public function test_prune_deletes_files_strictly_older_than_the_retention_window(): void {
		// Retention 30 days, "now" = 2026-09-29 → cutoff = 2026-08-30.
		$kept_recent   = $this->touch_log_file( '2026-09-29' );
		$kept_boundary = $this->touch_log_file( '2026-08-30' ); // exactly at cutoff, not "older than" it.
		$deleted_old   = $this->touch_log_file( '2026-08-29' );
		$deleted_older = $this->touch_log_file( '2020-01-01' );

		$deleted = $this->rotator->prune( $this->temp_dir, 30, $this->now );

		self::assertFileExists( $kept_recent );
		self::assertFileExists( $kept_boundary );
		self::assertFileDoesNotExist( $deleted_old );
		self::assertFileDoesNotExist( $deleted_older );
		self::assertContains( $deleted_old, $deleted );
		self::assertContains( $deleted_older, $deleted );
		self::assertCount( 2, $deleted );
	}

	public function test_prune_ignores_files_not_matching_the_naming_pattern(): void {
		$unrelated = $this->temp_dir . '/cron.log';
		file_put_contents( $unrelated, 'irrelevant' );

		$deleted = $this->rotator->prune( $this->temp_dir, 0, $this->now );

		self::assertFileExists( $unrelated );
		self::assertSame( array(), $deleted );
	}

	public function test_prune_on_a_directory_with_no_log_files_deletes_nothing(): void {
		self::assertSame( array(), $this->rotator->prune( $this->temp_dir, 30, $this->now ) );
	}

	private function touch_log_file( string $date ): string {
		$path = $this->temp_dir . '/dav-mlm-' . $date . '.log';
		file_put_contents( $path, "[{$date}] [INFO] test\n" );

		return $path;
	}
}
