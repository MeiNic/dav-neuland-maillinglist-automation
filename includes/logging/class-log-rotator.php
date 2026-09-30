<?php
/**
 * File-naming and retention mechanics for the daily log rotation (PLAN.md
 * §8 "Logs"): one file per day, named by date, old ones deleted once
 * they're past DAV_MLM_LOG_RETENTION_DAYS. Deletion decisions are based on
 * the date embedded in the filename rather than filesystem mtime, so
 * behaviour doesn't depend on when a file happens to have been touched
 * and stays easy to test with an injected clock.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Log_Rotator {

	private const FILE_PREFIX = 'dav-mlm-';
	private const FILE_SUFFIX = '.log';

	public function current_file_path( string $dir, DateTimeImmutable $now ): string {
		return rtrim( $dir, '/\\' ) . '/' . self::FILE_PREFIX . $now->format( 'Y-m-d' ) . self::FILE_SUFFIX;
	}

	/**
	 * @return string[] Paths of the files that were deleted.
	 */
	public function prune( string $dir, int $retention_days, DateTimeImmutable $now ): array {
		// Whole days only: file dates are midnight, so the cutoff is too.
		$cutoff  = $now->setTime( 0, 0 )->modify( "-{$retention_days} days" );
		$deleted = array();

		$paths = glob( rtrim( $dir, '/\\' ) . '/' . self::FILE_PREFIX . '*' . self::FILE_SUFFIX );

		foreach ( false !== $paths ? $paths : array() as $path ) {
			$date = $this->date_from_filename( $path, $now->getTimezone() );

			if ( null !== $date && $date < $cutoff && @unlink( $path ) ) {
				$deleted[] = $path;
			}
		}

		return $deleted;
	}

	private function date_from_filename( string $path, DateTimeZone $now_timezone ): ?DateTimeImmutable {
		$pattern = '/^' . preg_quote( self::FILE_PREFIX, '/' ) . '(\d{4}-\d{2}-\d{2})' . preg_quote( self::FILE_SUFFIX, '/' ) . '$/';

		if ( ! preg_match( $pattern, basename( $path ), $matches ) ) {
			return null;
		}

		// `!` resets the unparsed fields (time of day) to zero instead of
		// filling them in from the wall clock.
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $matches[1], $now_timezone );

		return false !== $date ? $date : null;
	}
}
