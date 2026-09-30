<?php
/**
 * Sends one mail through the `noreply@` mailbox's own SMTP credentials,
 * rather than however WordPress would otherwise send mail (PLAN.md §5b
 * step 4j) — so it's DKIM-signed by IONOS and passes SPF/DMARC, and so
 * the sender is never `wordpress@<empty SERVER_NAME>`.
 *
 * The `phpmailer_init` hook is added only around this one wp_mail() call
 * and removed right after, so it can't affect any *other* mail WordPress
 * (core, another plugin) sends through its normal transport. Shared by
 * Dav_Mlm_Rejector and Dav_Mlm_Alerter — both need exactly this, and
 * duplicating the hook-scoping dance in two places is how one of them
 * eventually forgets to remove it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Smtp_Mailer {

	private string $smtp_host;
	private int $smtp_port;
	private string $smtp_encryption;
	private string $smtp_user;
	private string $smtp_pass;

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

		add_action( 'phpmailer_init', $configure_smtp );
		try {
			return (bool) wp_mail( $to, $subject, $body, $headers );
		} finally {
			remove_action( 'phpmailer_init', $configure_smtp );
		}
	}
}
