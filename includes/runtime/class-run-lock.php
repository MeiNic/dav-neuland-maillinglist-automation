<?php
/**
 * Keeps two cron runs from overlapping (PLAN.md §5b step 1): an exclusive,
 * non-blocking flock() on a file in DAV_MLM_DATA_DIR. If a run takes
 * longer than the 5-minute cron interval, the next one sees the lock and
 * exits instead of approving or rejecting the same messages twice.
 *
 * The OS drops the lock when the process ends, however it ends, so a
 * crashed run never leaves a stale lock behind. The file itself is never
 * deleted — deleting it would let a second run lock a fresh file while
 * the first still holds the old one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Run_Lock {

	private string $path;

	/** @var resource|null */
	private $handle = null;

	public function __construct( string $path ) {
		$this->path = $path;
	}

	/**
	 * @return bool False when another run holds the lock.
	 * @throws RuntimeException When the lock file can't be opened at all.
	 */
	public function acquire(): bool {
		$dir = dirname( $this->path );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new RuntimeException( 'Could not create the lock directory ' . $dir );
		}

		$handle = @fopen( $this->path, 'c' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Could not open the lock file ' . $this->path );
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );

			return false;
		}

		$this->handle = $handle;

		return true;
	}

	public function release(): void {
		if ( null === $this->handle ) {
			return;
		}

		flock( $this->handle, LOCK_UN );
		fclose( $this->handle );
		$this->handle = null;
	}
}
