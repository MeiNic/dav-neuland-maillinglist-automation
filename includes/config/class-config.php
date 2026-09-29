<?php
/**
 * Single place that reads the DAV_MLM_* wp-config.php constants and
 * applies the documented defaults. See PLAN.md §7 for the constants
 * table. Constant-reading mechanics live in Dav_Mlm_Constant_Reader; this
 * class only holds the domain knowledge of which constants exist and what
 * their defaults are.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Config implements Dav_Mlm_Log_Config {

	private string $imap_host;
	private int $imap_port;
	private string $imap_encryption;
	private string $imap_user;
	private string $imap_pass;

	private string $smtp_host;
	private int $smtp_port;
	private string $smtp_encryption;
	private string $smtp_user;
	private string $smtp_pass;

	private string $mail_from;
	private string $mail_from_name;
	private ?string $alert_email;
	private string $notify_from;
	private string $trusted_authserv;
	private string $confirm_host;
	private string $folder_prefix;
	private string $data_dir;
	private int $log_retention_days;

	/**
	 * @throws Dav_Mlm_Config_Exception When a required constant is missing.
	 */
	public function __construct( ?Dav_Mlm_Constant_Reader $constants = null ) {
		$constants = $constants ?? new Dav_Mlm_Constant_Reader();

		$this->imap_host       = (string) $constants->require( 'DAV_MLM_IMAP_HOST' );
		$this->imap_port       = (int) $constants->require( 'DAV_MLM_IMAP_PORT' );
		$this->imap_encryption = (string) $constants->require( 'DAV_MLM_IMAP_ENCRYPTION' );
		$this->imap_user       = (string) $constants->require( 'DAV_MLM_IMAP_USER' );
		$this->imap_pass       = (string) $constants->require( 'DAV_MLM_IMAP_PASS' );

		$this->smtp_host       = (string) $constants->or_default( 'DAV_MLM_SMTP_HOST', 'smtp.ionos.de' );
		$this->smtp_port       = (int) $constants->or_default( 'DAV_MLM_SMTP_PORT', 587 );
		$this->smtp_encryption = (string) $constants->or_default( 'DAV_MLM_SMTP_ENCRYPTION', 'tls' );
		$this->smtp_user       = (string) $constants->or_default( 'DAV_MLM_SMTP_USER', $this->imap_user );
		$this->smtp_pass       = (string) $constants->or_default( 'DAV_MLM_SMTP_PASS', $this->imap_pass );

		$this->mail_from          = (string) $constants->or_default( 'DAV_MLM_MAIL_FROM', 'noreply@dav-neuland.de' );
		$this->mail_from_name     = (string) $constants->or_default( 'DAV_MLM_MAIL_FROM_NAME', 'DAV Neuland Mailinglisten' );
		$this->alert_email        = $constants->nullable_string( $constants->or_default( 'DAV_MLM_ALERT_EMAIL', $this->default_alert_email() ) );
		$this->notify_from        = (string) $constants->or_default( 'DAV_MLM_NOTIFY_FROM', 'no.reply@oneandone.com' );
		$this->trusted_authserv   = (string) $constants->or_default( 'DAV_MLM_TRUSTED_AUTHSERV', 'kundenserver.de' );
		$this->confirm_host       = (string) $constants->or_default( 'DAV_MLM_CONFIRM_HOST', 'ml.kundenserver.de' );
		$this->folder_prefix      = (string) $constants->or_default( 'DAV_MLM_FOLDER_PREFIX', 'Moderation' );
		$this->data_dir           = rtrim( (string) $constants->or_default( 'DAV_MLM_DATA_DIR', $this->default_data_dir() ), '/\\' );
		$this->log_retention_days = (int) $constants->or_default( 'DAV_MLM_LOG_RETENTION_DAYS', 30 );
	}

	public function imap_host(): string {
		return $this->imap_host;
	}

	public function imap_port(): int {
		return $this->imap_port;
	}

	public function imap_encryption(): string {
		return $this->imap_encryption;
	}

	public function imap_user(): string {
		return $this->imap_user;
	}

	public function imap_pass(): string {
		return $this->imap_pass;
	}

	public function smtp_host(): string {
		return $this->smtp_host;
	}

	public function smtp_port(): int {
		return $this->smtp_port;
	}

	public function smtp_encryption(): string {
		return $this->smtp_encryption;
	}

	public function smtp_user(): string {
		return $this->smtp_user;
	}

	public function smtp_pass(): string {
		return $this->smtp_pass;
	}

	public function mail_from(): string {
		return $this->mail_from;
	}

	public function mail_from_name(): string {
		return $this->mail_from_name;
	}

	public function alert_email(): ?string {
		return $this->alert_email;
	}

	public function notify_from(): string {
		return $this->notify_from;
	}

	public function trusted_authserv(): string {
		return $this->trusted_authserv;
	}

	public function confirm_host(): string {
		return $this->confirm_host;
	}

	public function folder_prefix(): string {
		return $this->folder_prefix;
	}

	public function data_dir(): string {
		return $this->data_dir;
	}

	public function log_retention_days(): int {
		return $this->log_retention_days;
	}

	/**
	 * Guards against accidentally leaking credentials through var_dump(),
	 * print_r(), or a logger that dumps arbitrary objects.
	 */
	public function __debugInfo(): array {
		$masked = '***REDACTED***';

		return array(
			'imap_host'          => $this->imap_host,
			'imap_port'          => $this->imap_port,
			'imap_encryption'    => $this->imap_encryption,
			'imap_user'          => $this->imap_user,
			'imap_pass'          => $masked,
			'smtp_host'          => $this->smtp_host,
			'smtp_port'          => $this->smtp_port,
			'smtp_encryption'    => $this->smtp_encryption,
			'smtp_user'          => $this->smtp_user,
			'smtp_pass'          => $masked,
			'mail_from'          => $this->mail_from,
			'mail_from_name'     => $this->mail_from_name,
			'alert_email'        => $this->alert_email,
			'notify_from'        => $this->notify_from,
			'trusted_authserv'   => $this->trusted_authserv,
			'confirm_host'       => $this->confirm_host,
			'folder_prefix'      => $this->folder_prefix,
			'data_dir'           => $this->data_dir,
			'log_retention_days' => $this->log_retention_days,
		);
	}

	private function default_alert_email(): ?string {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$admin_email = get_option( 'admin_email' );

		return is_string( $admin_email ) && '' !== $admin_email ? $admin_email : null;
	}

	private function default_data_dir(): string {
		// ABSPATH is guaranteed to be defined by the time any Dav_Mlm_*
		// class runs (see the guard at the top of this file and of the
		// plugin bootstrap) — mirrors the production layout, where the
		// log dir sits next to the WordPress install, not inside it.
		return rtrim( dirname( rtrim( ABSPATH, '/\\' ) ), '/\\' ) . '/.dav-mlm-logs';
	}
}
