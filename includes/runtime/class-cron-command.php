<?php
/**
 * What bin/cron-runner.php does once WordPress is loaded (PLAN.md §5b
 * steps 0–1): read the flags, load the config, protect the data
 * directory, take the run lock, and hand over to Dav_Mlm_Cron_Runner.
 * Kept out of the bin script so it has no top-level logic of its own
 * besides the CLI guard and the WordPress bootstrap.
 *
 * Exit codes: 0 = run finished (or another run holds the lock), 1 = the
 * run failed or couldn't start. A missing wp-config.php constant is
 * recorded as a failed run too, so the admin page's banner and failure
 * counter pick it up even though no alert mail can be built without the
 * config.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Cron_Command {

	public const USAGE = <<<'TXT'
Usage: php8.3-cli bin/cron-runner.php [--dry-run] [--verbose] [--message-id=<id>]

  --dry-run          Decide and log what WOULD happen; change nothing.
  --verbose          Also log debug lines, and print a summary when done.
  --message-id=<id>  Only process the notification with this outer Message-ID.
  --help             Show this help.
TXT;

	private const LOCK_FILE = 'cron.lock';

	private const HTACCESS = "# Logs and the run lock; never served over the web (PLAN.md §8).\n"
		. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	/** @var resource */
	private $stderr;

	/**
	 * @param resource|null $stderr Where startup errors go before a log
	 *        file exists (default STDERR, which cron appends to cron.log).
	 */
	public function __construct( $stderr = null ) {
		$this->stderr = $stderr ?? STDERR;
	}

	/**
	 * @param array<string, string|false|list<string|false>> $options As returned by getopt().
	 */
	public function run( array $options ): int {
		if ( isset( $options['help'] ) ) {
			echo self::USAGE, PHP_EOL;

			return 0;
		}

		$dry_run    = isset( $options['dry-run'] );
		$verbose    = isset( $options['verbose'] );
		$message_id = isset( $options['message-id'] ) && is_string( $options['message-id'] ) && '' !== trim( $options['message-id'] )
			? trim( $options['message-id'] )
			: null;

		try {
			$config = new Dav_Mlm_Config();
		} catch ( Dav_Mlm_Config_Exception $error ) {
			// No data dir known yet, so no log file.
			fwrite( $this->stderr, '[dav-mlm] ' . $error->getMessage() . PHP_EOL );
			if ( ! $dry_run ) {
				( new Dav_Mlm_Run_Status() )->record_run( false, array(), array( $error->getMessage() ) );
			}

			return 1;
		}

		$this->protect_data_dir( $config->data_dir() );
		$logger = new Dav_Mlm_Logger( $config, $verbose );

		$lock = new Dav_Mlm_Run_Lock( $config->data_dir() . '/' . self::LOCK_FILE );
		try {
			if ( ! $lock->acquire() ) {
				$logger->info( 'Another run is still in progress; not starting a second one.' );

				return 0;
			}
		} catch ( RuntimeException $error ) {
			$logger->error( $error->getMessage() );

			return 1;
		}

		try {
			$result = Dav_Mlm_Cron_Runner::from_config( $config, $logger, $dry_run, $message_id )->run();
		} finally {
			$lock->release();
		}

		if ( $verbose || $dry_run ) {
			$counts = array();
			foreach ( $result['counts'] as $name => $count ) {
				$counts[] = $name . '=' . $count;
			}
			printf( "%s%s: %s\n", $result['success'] ? 'Run finished' : 'Run FAILED', $dry_run ? ' (dry run)' : '', implode( ' ', $counts ) );
		}

		return $result['success'] ? 0 : 1;
	}

	/**
	 * The data dir holds logs with email addresses (GDPR/DSGVO) and may sit
	 * below the web root on IONOS webspace, so it gets 0700 and its own
	 * deny-all .htaccess before anything is written there (PLAN.md §8).
	 */
	private function protect_data_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0700, true );
		}

		$htaccess = $dir . '/.htaccess';
		if ( is_dir( $dir ) && ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, self::HTACCESS );
		}
	}
}
