<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/TempDirectoryTrait.php';

use PHPUnit\Framework\TestCase;

/**
 * flock() locks belong to the open file description, so two lock objects
 * in the same process contend exactly like two cron processes would.
 */
final class RunLockTest extends TestCase {

	use TempDirectoryTrait;

	private string $path;

	protected function setUp(): void {
		$this->path = $this->make_temp_dir() . '/cron.lock';
	}

	protected function tearDown(): void {
		$this->remove_temp_dir();
	}

	public function test_a_second_run_cannot_take_the_lock_while_the_first_holds_it(): void {
		$first  = new Dav_Mlm_Run_Lock( $this->path );
		$second = new Dav_Mlm_Run_Lock( $this->path );

		self::assertTrue( $first->acquire() );
		self::assertFalse( $second->acquire() );

		$first->release();

		self::assertTrue( $second->acquire() );
		$second->release();
	}

	public function test_the_lock_file_is_kept_after_release(): void {
		$lock = new Dav_Mlm_Run_Lock( $this->path );
		$lock->acquire();
		$lock->release();

		self::assertFileExists( $this->path );
	}

	public function test_a_missing_directory_is_created(): void {
		$path = dirname( $this->path ) . '/sub/cron.lock';
		$lock = new Dav_Mlm_Run_Lock( $path );

		self::assertTrue( $lock->acquire() );
		$lock->release();

		@unlink( $path );
		@rmdir( dirname( $path ) );
	}
}
