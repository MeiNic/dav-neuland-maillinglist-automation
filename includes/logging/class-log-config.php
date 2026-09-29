<?php
/**
 * The slice of configuration Dav_Mlm_Logger actually needs — where to
 * write and how long to keep files — so it doesn't depend on the full
 * Dav_Mlm_Config (IMAP/SMTP credentials and all) just to be constructed,
 * including in tests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Dav_Mlm_Log_Config {

	public function data_dir(): string;

	public function log_retention_days(): int;
}
