<?php
/**
 * What the cron runner needs from a mailbox (PLAN.md §5b steps 2–4, 4a),
 * independent of the transport: Dav_Mlm_Mailbox implements it on ext-imap,
 * tests use a fake, and a webklex/php-imap implementation can replace the
 * ext-imap one once the webspace moves to PHP 8.4+ (PLAN.md §9).
 *
 * Messages are addressed by IMAP UID (stable across moves/expunges within
 * a run, unlike message sequence numbers).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Dav_Mlm_Mailbox_Interface {

	public const FOLDER_APPROVED     = 'Approved';
	public const FOLDER_REJECTED     = 'Rejected';
	public const FOLDER_MANUAL       = 'Manual';
	public const FOLDER_SUSPICIOUS   = 'Suspicious';
	public const FOLDER_UNRECOGNIZED = 'Unrecognized';
	public const FOLDER_ERROR        = 'Error';
	public const FOLDER_INFO         = 'Info';

	public const FOLDERS = array(
		self::FOLDER_APPROVED,
		self::FOLDER_REJECTED,
		self::FOLDER_MANUAL,
		self::FOLDER_SUSPICIOUS,
		self::FOLDER_UNRECOGNIZED,
		self::FOLDER_ERROR,
		self::FOLDER_INFO,
	);

	/**
	 * @throws Dav_Mlm_Mailbox_Exception
	 */
	public function connect(): void;

	/**
	 * Creates every self::FOLDERS entry under the configured prefix that
	 * doesn't exist yet.
	 *
	 * @throws Dav_Mlm_Mailbox_Exception
	 */
	public function ensure_folders(): void;

	/**
	 * UIDs of the INBOX messages sent by the configured notification
	 * sender, regardless of \Seen state ("still in INBOX" is the only
	 * state we track).
	 *
	 * @return list<int>
	 * @throws Dav_Mlm_Mailbox_Exception
	 */
	public function search_notifications(): array;

	/**
	 * Raw RFC 822 source (headers + body) without touching \Seen.
	 *
	 * @throws Dav_Mlm_Mailbox_Exception
	 */
	public function fetch_raw( int $uid ): string;

	/**
	 * Moves an INBOX message into one of self::FOLDERS. Takes effect for
	 * other clients only after expunge().
	 *
	 * @throws Dav_Mlm_Mailbox_Exception
	 */
	public function move( int $uid, string $folder ): void;

	/**
	 * Permanently removes the moved messages from INBOX; call once at the
	 * end of the run.
	 *
	 * @throws Dav_Mlm_Mailbox_Exception
	 */
	public function expunge(): void;

	public function close(): void;
}
