<?php
/**
 * Shared setUp/tearDown helper for tests that need a real, empty
 * directory on disk (log rotation, log writing) — created fresh per test
 * and removed afterwards so tests never depend on each other's leftovers.
 */

declare(strict_types=1);

trait TempDirectoryTrait {

	private string $temp_dir;

	protected function make_temp_dir(): string {
		$this->temp_dir = sys_get_temp_dir() . '/dav-mlm-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->temp_dir, 0700, true );

		return $this->temp_dir;
	}

	protected function remove_temp_dir(): void {
		if ( ! isset( $this->temp_dir ) || ! is_dir( $this->temp_dir ) ) {
			return;
		}

		foreach ( glob( $this->temp_dir . '/*' ) ?: array() as $file ) {
			@unlink( $file );
		}

		@rmdir( $this->temp_dir );
	}
}
