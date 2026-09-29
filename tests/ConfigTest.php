<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * DAV_MLM_* constants are global and immutable for the lifetime of a PHP
 * process, so every test that needs its own combination of them runs in a
 * separate process (#[RunInSeparateProcess]) — otherwise a constant defined
 * by an earlier test would leak into a later one.
 */
final class ConfigTest extends TestCase {

	private function define_required_constants(): void {
		define( 'DAV_MLM_IMAP_HOST', 'imap.example.com' );
		define( 'DAV_MLM_IMAP_PORT', '993' );
		define( 'DAV_MLM_IMAP_ENCRYPTION', 'ssl' );
		define( 'DAV_MLM_IMAP_USER', 'noreply@dav-neuland.de' );
		define( 'DAV_MLM_IMAP_PASS', 's3cret' );
	}

	#[RunInSeparateProcess]
	public function test_missing_required_constant_throws(): void {
		$this->expectException( Dav_Mlm_Config_Exception::class );
		$this->expectExceptionMessage( 'DAV_MLM_IMAP_HOST' );

		new Dav_Mlm_Config();
	}

	#[RunInSeparateProcess]
	public function test_missing_required_constant_lists_the_first_one_missing(): void {
		define( 'DAV_MLM_IMAP_HOST', 'imap.example.com' );
		// IMAP_PORT intentionally left undefined.

		$this->expectException( Dav_Mlm_Config_Exception::class );
		$this->expectExceptionMessage( 'DAV_MLM_IMAP_PORT' );

		new Dav_Mlm_Config();
	}

	#[RunInSeparateProcess]
	public function test_defaults_apply_when_only_required_constants_are_set(): void {
		$this->define_required_constants();

		$config = new Dav_Mlm_Config();

		self::assertSame( 993, $config->imap_port() );
		self::assertSame( 'noreply@dav-neuland.de', $config->imap_user() );

		self::assertSame( 'smtp.ionos.de', $config->smtp_host() );
		self::assertSame( 587, $config->smtp_port() );
		self::assertSame( 'tls', $config->smtp_encryption() );
		self::assertSame( $config->imap_user(), $config->smtp_user() );
		self::assertSame( $config->imap_pass(), $config->smtp_pass() );

		self::assertSame( 'noreply@dav-neuland.de', $config->mail_from() );
		self::assertSame( 'DAV Neuland Mailinglisten', $config->mail_from_name() );
		self::assertNull( $config->alert_email() );
		self::assertSame( 'no.reply@oneandone.com', $config->notify_from() );
		self::assertSame( 'kundenserver.de', $config->trusted_authserv() );
		self::assertSame( 'ml.kundenserver.de', $config->confirm_host() );
		self::assertSame( 'Moderation', $config->folder_prefix() );
		self::assertSame( 30, $config->log_retention_days() );
		self::assertSame( dirname( rtrim( ABSPATH, '/\\' ) ) . '/.dav-mlm-logs', $config->data_dir() );
	}

	#[RunInSeparateProcess]
	public function test_explicit_constants_override_every_default(): void {
		$this->define_required_constants();

		define( 'DAV_MLM_SMTP_HOST', 'smtp.other.example' );
		define( 'DAV_MLM_SMTP_PORT', '465' );
		define( 'DAV_MLM_SMTP_ENCRYPTION', 'ssl' );
		define( 'DAV_MLM_SMTP_USER', 'smtp-user@example.com' );
		define( 'DAV_MLM_SMTP_PASS', 'smtp-pass' );
		define( 'DAV_MLM_MAIL_FROM', 'custom-from@example.com' );
		define( 'DAV_MLM_MAIL_FROM_NAME', 'Custom Sender' );
		define( 'DAV_MLM_ALERT_EMAIL', 'alerts@example.com' );
		define( 'DAV_MLM_NOTIFY_FROM', 'notify@example.com' );
		define( 'DAV_MLM_TRUSTED_AUTHSERV', 'trusted.example' );
		define( 'DAV_MLM_CONFIRM_HOST', 'confirm.example' );
		define( 'DAV_MLM_FOLDER_PREFIX', 'CustomFolder' );
		define( 'DAV_MLM_DATA_DIR', '/var/data/dav-mlm/' );
		define( 'DAV_MLM_LOG_RETENTION_DAYS', '90' );

		$config = new Dav_Mlm_Config();

		self::assertSame( 'smtp.other.example', $config->smtp_host() );
		self::assertSame( 465, $config->smtp_port() );
		self::assertSame( 'ssl', $config->smtp_encryption() );
		self::assertSame( 'smtp-user@example.com', $config->smtp_user() );
		self::assertSame( 'smtp-pass', $config->smtp_pass() );
		self::assertSame( 'custom-from@example.com', $config->mail_from() );
		self::assertSame( 'Custom Sender', $config->mail_from_name() );
		self::assertSame( 'alerts@example.com', $config->alert_email() );
		self::assertSame( 'notify@example.com', $config->notify_from() );
		self::assertSame( 'trusted.example', $config->trusted_authserv() );
		self::assertSame( 'confirm.example', $config->confirm_host() );
		self::assertSame( 'CustomFolder', $config->folder_prefix() );
		// Trailing slash stripped for consistent path joining by callers.
		self::assertSame( '/var/data/dav-mlm', $config->data_dir() );
		self::assertSame( 90, $config->log_retention_days() );
	}

	#[RunInSeparateProcess]
	public function test_alert_email_falls_back_to_wp_admin_email_option(): void {
		$this->define_required_constants();

		require_once __DIR__ . '/fixtures/wp-stubs.php';
		dav_mlm_test_set_option( 'admin_email', 'admin@example.com' );

		$config = new Dav_Mlm_Config();

		self::assertSame( 'admin@example.com', $config->alert_email() );
	}

	#[RunInSeparateProcess]
	public function test_debug_info_masks_passwords(): void {
		$this->define_required_constants();

		$config = new Dav_Mlm_Config();
		$dump   = $config->__debugInfo();

		self::assertSame( '***REDACTED***', $dump['imap_pass'] );
		self::assertSame( '***REDACTED***', $dump['smtp_pass'] );
		self::assertStringNotContainsString( 's3cret', print_r( $dump, true ) );
	}
}
