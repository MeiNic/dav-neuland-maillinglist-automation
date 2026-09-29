<?php
/**
 * ext-imap implementation of Dav_Mlm_Mailbox_Interface. This is the only
 * file that calls imap_*() (PLAN.md §9: ext-imap was removed from core in
 * PHP 8.4, so a webklex/php-imap implementation of the interface has to
 * be able to replace this class without touching the runner).
 *
 * The connection is opened with certificate validation (no
 * /novalidate-cert) and explicit socket timeouts, so a hanging server
 * can't wedge the cron run. Raw sources are fetched with FT_PEEK and
 * never set \Seen — the design does not rely on flags (PLAN.md §3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Mailbox implements Dav_Mlm_Mailbox_Interface {

	private const OPEN_TIMEOUT_SECONDS  = 15;
	private const READ_TIMEOUT_SECONDS  = 30;
	private const WRITE_TIMEOUT_SECONDS = 30;
	private const CLOSE_TIMEOUT_SECONDS = 15;

	private const FALLBACK_DELIMITER = '/';

	private Dav_Mlm_Mailbox_Config $config;
	private ?\IMAP\Connection $stream = null;
	private ?string $delimiter        = null;
	private int $pending_moves        = 0;

	public function __construct( Dav_Mlm_Mailbox_Config $config ) {
		$this->config = $config;
	}

	public function connect(): void {
		if ( ! function_exists( 'imap_open' ) ) {
			throw new Dav_Mlm_Mailbox_Exception( 'The PHP imap extension is not available.' );
		}

		imap_timeout( IMAP_OPENTIMEOUT, self::OPEN_TIMEOUT_SECONDS );
		imap_timeout( IMAP_READTIMEOUT, self::READ_TIMEOUT_SECONDS );
		imap_timeout( IMAP_WRITETIMEOUT, self::WRITE_TIMEOUT_SECONDS );
		imap_timeout( IMAP_CLOSETIMEOUT, self::CLOSE_TIMEOUT_SECONDS );

		// imap_open() raises an E_WARNING on failure; the reason is reported via the exception instead.
		$stream = @imap_open(
			$this->server_reference() . 'INBOX',
			$this->config->imap_user(),
			$this->config->imap_pass(),
			0,
			1,
			array( 'DISABLE_AUTHENTICATOR' => 'GSSAPI' )
		);

		if ( false === $stream ) {
			throw new Dav_Mlm_Mailbox_Exception( 'IMAP connect failed: ' . $this->last_error() );
		}

		$this->stream        = $stream;
		$this->delimiter     = null;
		$this->pending_moves = 0;
	}

	public function ensure_folders(): void {
		$stream    = $this->require_stream();
		$reference = $this->server_reference();
		$delimiter = $this->hierarchy_delimiter();

		$listed = imap_getmailboxes( $stream, $reference, '*' );
		if ( false === $listed ) {
			throw new Dav_Mlm_Mailbox_Exception( 'IMAP folder listing failed: ' . $this->last_error() );
		}

		$existing = array();
		foreach ( $listed as $mailbox ) {
			$existing[ $this->folder_name_from_listing( $mailbox->name ) ] = true;
		}

		$wanted = array( $this->config->folder_prefix() );
		foreach ( self::FOLDERS as $folder ) {
			$wanted[] = $this->folder_path( $folder, $delimiter );
		}

		foreach ( $wanted as $path ) {
			$encoded = imap_utf7_encode( $path );
			if ( isset( $existing[ $encoded ] ) ) {
				continue;
			}

			if ( ! imap_createmailbox( $stream, $reference . $encoded ) ) {
				throw new Dav_Mlm_Mailbox_Exception( sprintf( 'Could not create IMAP folder "%s": %s', $path, $this->last_error() ) );
			}
			imap_subscribe( $stream, $reference . $encoded );
		}
	}

	public function search_notifications(): array {
		$sender = str_replace( array( '\\', '"' ), '', $this->config->notify_from() );

		imap_errors(); // Start from a clean error stack, so a stale error isn't mistaken for a failed search.
		$found = imap_search( $this->require_stream(), 'FROM "' . $sender . '" UNDELETED', SE_UID );

		if ( false === $found ) {
			// ext-imap returns false both for "no match" and for an error;
			// the latter leaves a message on the error stack.
			$error = $this->last_error();
			if ( '' !== $error ) {
				throw new Dav_Mlm_Mailbox_Exception( 'IMAP search failed: ' . $error );
			}

			return array();
		}

		$uids = array_map( 'intval', $found );
		sort( $uids );

		return $uids;
	}

	public function fetch_raw( int $uid ): string {
		$stream = $this->require_stream();

		// imap_fetchheader() never sets \Seen; imap_body() needs FT_PEEK for that.
		$header = imap_fetchheader( $stream, $uid, FT_UID );
		$body   = imap_body( $stream, $uid, FT_UID | FT_PEEK );

		if ( false === $header || false === $body ) {
			throw new Dav_Mlm_Mailbox_Exception( sprintf( 'IMAP fetch of UID %d failed: %s', $uid, $this->last_error() ) );
		}

		return $header . $body;
	}

	public function move( int $uid, string $folder ): void {
		if ( ! in_array( $folder, self::FOLDERS, true ) ) {
			throw new Dav_Mlm_Mailbox_Exception( sprintf( 'Unknown target folder "%s".', $folder ) );
		}

		$path = imap_utf7_encode( $this->folder_path( $folder, $this->hierarchy_delimiter() ) );

		if ( ! imap_mail_move( $this->require_stream(), (string) $uid, $path, CP_UID ) ) {
			throw new Dav_Mlm_Mailbox_Exception( sprintf( 'IMAP move of UID %d to "%s" failed: %s', $uid, $folder, $this->last_error() ) );
		}

		++$this->pending_moves;
	}

	public function expunge(): void {
		if ( 0 === $this->pending_moves ) {
			return;
		}

		if ( ! imap_expunge( $this->require_stream() ) ) {
			throw new Dav_Mlm_Mailbox_Exception( 'IMAP expunge failed: ' . $this->last_error() );
		}

		$this->pending_moves = 0;
	}

	public function close(): void {
		if ( null === $this->stream ) {
			return;
		}

		imap_close( $this->stream );
		$this->stream = null;

		// Drop queued notices/alerts so PHP doesn't print them at shutdown.
		imap_errors();
		imap_alerts();
	}

	/**
	 * The `{host:port/flags}` part of a mailbox name, with certificate
	 * validation explicitly on.
	 */
	public function server_reference(): string {
		switch ( strtolower( $this->config->imap_encryption() ) ) {
			case 'ssl':
				$security = '/ssl';
				break;
			case 'tls':
				$security = '/tls';
				break;
			case 'none':
				$security = '/notls';
				break;
			default:
				throw new Dav_Mlm_Mailbox_Exception( sprintf( 'Unsupported IMAP encryption "%s" (use ssl, tls or none).', $this->config->imap_encryption() ) );
		}

		return sprintf( '{%s:%d/imap%s/validate-cert}', $this->config->imap_host(), $this->config->imap_port(), $security );
	}

	/**
	 * Full folder name below the configured prefix, joined with the
	 * server's hierarchy delimiter (e.g. `Moderation.Approved` vs
	 * `Moderation/Approved`).
	 */
	public function folder_path( string $folder, string $delimiter ): string {
		return $this->config->folder_prefix() . $delimiter . $folder;
	}

	/**
	 * `{host:993/imap/ssl}INBOX.Moderation` → `INBOX.Moderation`. The server
	 * reference in a listing is the server's own canonical form (typically
	 * without our /validate-cert flag), so it can't be stripped by length.
	 */
	public function folder_name_from_listing( string $listed_name ): string {
		$end = strpos( $listed_name, '}' );

		return false === $end ? $listed_name : substr( $listed_name, $end + 1 );
	}

	private function hierarchy_delimiter(): string {
		if ( null !== $this->delimiter ) {
			return $this->delimiter;
		}

		$inbox = imap_getmailboxes( $this->require_stream(), $this->server_reference(), 'INBOX' );
		if ( false === $inbox ) {
			throw new Dav_Mlm_Mailbox_Exception( 'IMAP could not determine the folder hierarchy delimiter: ' . $this->last_error() );
		}

		$found = isset( $inbox[0]->delimiter ) ? (string) $inbox[0]->delimiter : '';

		$this->delimiter = '' !== $found ? $found : self::FALLBACK_DELIMITER;

		return $this->delimiter;
	}

	private function require_stream(): \IMAP\Connection {
		if ( null === $this->stream ) {
			throw new Dav_Mlm_Mailbox_Exception( 'Not connected to IMAP.' );
		}

		return $this->stream;
	}

	private function last_error(): string {
		$error = imap_last_error();
		imap_errors();

		return false === $error ? '' : $error;
	}
}
