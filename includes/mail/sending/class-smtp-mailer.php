<?php
/**
 * Sends one mail through the `noreply@` mailbox's own SMTP credentials,
 * rather than however WordPress would otherwise send mail (PLAN.md §5b
 * step 4j) — so it's DKIM-signed by IONOS and passes SPF/DMARC, and so
 * the sender is never `wordpress@<empty SERVER_NAME>`.
 *
 * The `phpmailer_init` hook is added only around this one wp_mail() call
 * and removed right after, so it can't affect any *other* mail WordPress
 * (core, another plugin) sends through its normal transport. It runs at
 * the highest priority, so an SMTP plugin's own `phpmailer_init` handler
 * can't overwrite our settings afterwards. Shared by Dav_Mlm_Rejector and
 * Dav_Mlm_Alerter — both need exactly this, and duplicating the
 * hook-scoping dance in two places is how one of them eventually forgets
 * to remove it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Smtp_Mailer {

	private const HOOK_PRIORITY = PHP_INT_MAX;

	private string $smtp_host;
	private int $smtp_port;
	private string $smtp_encryption;
	private string $smtp_user;
	private string $smtp_pass;
	private ?string $last_error = null;

	public function __construct( string $smtp_host, int $smtp_port, string $smtp_encryption, string $smtp_user, string $smtp_pass ) {
		$this->smtp_host       = $smtp_host;
		$this->smtp_port       = $smtp_port;
		$this->smtp_encryption = $smtp_encryption;
		$this->smtp_user       = $smtp_user;
		$this->smtp_pass       = $smtp_pass;
	}

	/**
	 * @param list<string> $headers
	 */
	public function send( string $to, string $subject, string $body, array $headers ): bool {
		$configure_smtp = function ( $phpmailer ): void {
			$phpmailer->isSMTP();
			$phpmailer->Host       = $this->smtp_host;
			$phpmailer->Port       = $this->smtp_port;
			$phpmailer->SMTPAuth   = true;
			$phpmailer->Username   = $this->smtp_user;
			$phpmailer->Password   = $this->smtp_pass;
			$phpmailer->SMTPSecure = $this->smtp_encryption;
		};

		add_action( 'phpmailer_init', $configure_smtp, self::HOOK_PRIORITY );
		try {
			return $this->wp_mail_capturing_error( $to, $subject, $body, $headers );
		} finally {
			remove_action( 'phpmailer_init', $configure_smtp, self::HOOK_PRIORITY );
		}
	}

	/**
	 * Sends through whatever transport WordPress uses by default (usually
	 * PHP mail() on the webspace), without our SMTP settings. Only for the
	 * alerter's fallback: when the SMTP login itself is what broke (it
	 * shares the IMAP password by default), this is the one remaining way
	 * to tell someone. Not for rejection mails — without the mailbox's
	 * SMTP they'd fail SPF/DKIM at the recipient.
	 *
	 * @param list<string> $headers
	 */
	public function send_via_default_transport( string $to, string $subject, string $body, array $headers ): bool {
		return $this->wp_mail_capturing_error( $to, $subject, $body, $headers );
	}

	/**
	 * The error message WordPress reported (`wp_mail_failed`) for the most
	 * recent send that failed, or null if the most recent send succeeded
	 * or failed without a message.
	 */
	public function last_error(): ?string {
		return $this->last_error;
	}

	/**
	 * @param list<string> $headers
	 */
	private function wp_mail_capturing_error( string $to, string $subject, string $body, array $headers ): bool {
		$this->last_error = null;

		$capture_error = function ( $error ): void {
			if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
				$this->last_error = (string) $error->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $capture_error );
		try {
			return (bool) wp_mail( $to, $subject, $body, $headers );
		} finally {
			remove_action( 'wp_mail_failed', $capture_error );
		}
	}
}
