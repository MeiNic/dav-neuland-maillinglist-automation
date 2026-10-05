<?php
/**
 * In-memory Dav_Mlm_Mailbox_Interface for runner tests: INBOX messages
 * keyed by UID, plus per-folder lists of what was moved where. Moves only
 * disappear from the INBOX after expunge(), like on a real IMAP server.
 * The `fail_*` properties make the matching call throw, to exercise the
 * runner's failure paths.
 */

declare(strict_types=1);

final class Dav_Mlm_Fake_Mailbox implements Dav_Mlm_Mailbox_Interface {

	/** @var array<int, string> */
	public array $inbox = array();

	/** @var array<string, list<int>> */
	public array $folders = array();

	/** @var array<int, string> */
	public array $moved_raw = array();

	public bool $connected       = false;
	public bool $folders_ensured = false;
	public int $expunge_calls    = 0;

	public bool $fail_connect = false;

	/** @var list<int> */
	public array $fail_fetch = array();

	/** @var list<int> */
	public array $fail_move = array();

	/** @var list<int> */
	private array $pending = array();

	public function add_message( int $uid, string $raw ): void {
		$this->inbox[ $uid ] = $raw;
	}

	public function connect(): void {
		if ( $this->fail_connect ) {
			throw new Dav_Mlm_Mailbox_Exception( 'IMAP connect failed: [AUTHENTICATIONFAILED] Authentication failed.' );
		}
		$this->connected = true;
	}

	public function ensure_folders(): void {
		$this->folders_ensured = true;
		foreach ( self::FOLDERS as $folder ) {
			$this->folders[ $folder ] ??= array();
		}
	}

	public function search_notifications(): array {
		$uids = array_keys( $this->inbox );
		sort( $uids );

		return $uids;
	}

	public function fetch_raw( int $uid ): string {
		if ( ! isset( $this->inbox[ $uid ] ) || in_array( $uid, $this->fail_fetch, true ) ) {
			throw new Dav_Mlm_Mailbox_Exception( "No message with UID $uid." );
		}

		return $this->inbox[ $uid ];
	}

	public function move( int $uid, string $folder ): void {
		if ( ! in_array( $folder, self::FOLDERS, true ) || ! isset( $this->inbox[ $uid ] ) || in_array( $uid, $this->fail_move, true ) ) {
			throw new Dav_Mlm_Mailbox_Exception( "Cannot move UID $uid to $folder." );
		}

		$this->folders[ $folder ][] = $uid;
		$this->moved_raw[ $uid ]    = $this->inbox[ $uid ];
		$this->pending[]            = $uid;
	}

	public function expunge(): void {
		++$this->expunge_calls;
		foreach ( $this->pending as $uid ) {
			unset( $this->inbox[ $uid ] );
		}
		$this->pending = array();
	}

	public function close(): void {
		$this->connected = false;
	}
}
