<?php
/**
 * File logger for DAV_MLM_DATA_DIR (outside the plugin folder, so it
 * survives `rsync --delete`). Masking (Dav_Mlm_Log_Masker) and rotation
 * (Dav_Mlm_Log_Rotator) mechanics live in their own classes; this class
 * only wires them together and picks the fallback when the file can't be
 * written.
 *
 * GDPR/DSGVO (PLAN.md §8): callers should log addresses, list, decision,
 * reason — never a message body. The masker only catches the confirm
 * token; it can't enforce "no bodies" since that's a semantic judgement
 * this class has no way to make.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Logger {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	private Dav_Mlm_Log_Config $config;
	private bool $verbose;
	private Dav_Mlm_Log_Masker $masker;
	private Dav_Mlm_Log_Rotator $rotator;
	private DateTimeImmutable $now;

	public function __construct(
		Dav_Mlm_Log_Config $config,
		bool $verbose = false,
		?Dav_Mlm_Log_Masker $masker = null,
		?Dav_Mlm_Log_Rotator $rotator = null,
		?DateTimeImmutable $now = null
	) {
		$this->config  = $config;
		$this->verbose = $verbose;
		$this->masker  = $masker ?? new Dav_Mlm_Log_Masker();
		$this->rotator = $rotator ?? new Dav_Mlm_Log_Rotator();
		$this->now     = $now ?? new DateTimeImmutable();
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function debug( string $message, array $context = array() ): void {
		if ( $this->verbose ) {
			$this->write( self::DEBUG, $message, $context );
		}
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function info( string $message, array $context = array() ): void {
		$this->write( self::INFO, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->write( self::WARNING, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( self::ERROR, $message, $context );
	}

	/**
	 * Deletes rotated log files past the retention window. Called once
	 * per cron run, not on every write.
	 *
	 * @return string[] Paths of the files that were deleted.
	 */
	public function prune_old_logs(): array {
		return $this->rotator->prune( $this->config->data_dir(), $this->config->log_retention_days(), $this->now );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function write( string $level, string $message, array $context ): void {
		$line = $this->format_line( $level, $message, $context );
		$file = $this->rotator->current_file_path( $this->config->data_dir(), $this->now );

		if ( ! $this->append( $file, $line ) ) {
			error_log( '[dav-mlm] ' . $line );
		}
	}

	private function append( string $file, string $line ): bool {
		$dir = dirname( $file );

		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			return false;
		}

		return false !== @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function format_line( string $level, string $message, array $context ): string {
		$line = sprintf(
			'[%s] [%s] %s',
			$this->now->format( DateTimeInterface::ATOM ),
			strtoupper( $level ),
			$this->masker->mask_message( $message )
		);

		$masked_context = $this->masker->mask_context( $context );

		if ( array() !== $masked_context ) {
			$line .= ' ' . (string) json_encode( $masked_context, JSON_UNESCAPED_SLASHES );
		}

		return $line . PHP_EOL;
	}
}
