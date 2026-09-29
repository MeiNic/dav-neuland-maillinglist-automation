<?php
/**
 * The slice of configuration Dav_Mlm_Mailbox needs (IMAP connection
 * details, the notification sender to search for, and the folder
 * prefix), so it can be constructed in tests without the full
 * Dav_Mlm_Config.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Dav_Mlm_Mailbox_Config {

	public function imap_host(): string;

	public function imap_port(): int;

	public function imap_encryption(): string;

	public function imap_user(): string;

	public function imap_pass(): string;

	public function notify_from(): string;

	public function folder_prefix(): string;
}
