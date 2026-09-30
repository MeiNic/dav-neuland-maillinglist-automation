<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/FakeMailbox.php';

use PHPUnit\Framework\TestCase;

final class MailboxTest extends TestCase {

	private function config( string $encryption = 'ssl', string $prefix = 'Moderation' ): Dav_Mlm_Mailbox_Config {
		return new class( $encryption, $prefix ) implements Dav_Mlm_Mailbox_Config {

			public function __construct( private string $encryption, private string $prefix ) {}

			public function imap_host(): string {
				return 'imap.example.com';
			}

			public function imap_port(): int {
				return 993;
			}

			public function imap_encryption(): string {
				return $this->encryption;
			}

			public function imap_user(): string {
				return 'user';
			}

			public function imap_pass(): string {
				return 'secret';
			}

			public function notify_from(): string {
				return 'no.reply@oneandone.com';
			}

			public function folder_prefix(): string {
				return $this->prefix;
			}
		};
	}

	public function test_server_reference_validates_certificates_for_ssl(): void {
		self::assertSame( '{imap.example.com:993/imap/ssl/validate-cert}', ( new Dav_Mlm_Mailbox( $this->config( 'ssl' ) ) )->server_reference() );
	}

	public function test_server_reference_supports_starttls_and_plain(): void {
		self::assertSame( '{imap.example.com:993/imap/tls/validate-cert}', ( new Dav_Mlm_Mailbox( $this->config( 'TLS' ) ) )->server_reference() );
		self::assertSame( '{imap.example.com:993/imap/notls/validate-cert}', ( new Dav_Mlm_Mailbox( $this->config( 'none' ) ) )->server_reference() );
	}

	public function test_server_reference_never_disables_certificate_validation(): void {
		foreach ( array( 'ssl', 'tls', 'none' ) as $encryption ) {
			self::assertStringNotContainsString( 'novalidate-cert', ( new Dav_Mlm_Mailbox( $this->config( $encryption ) ) )->server_reference() );
		}
	}

	public function test_server_reference_rejects_unknown_encryption(): void {
		$this->expectException( Dav_Mlm_Mailbox_Exception::class );

		( new Dav_Mlm_Mailbox( $this->config( 'starttls-ish' ) ) )->server_reference();
	}

	public function test_folder_path_uses_the_servers_hierarchy_delimiter(): void {
		$mailbox = new Dav_Mlm_Mailbox( $this->config() );

		self::assertSame( 'Moderation.Approved', $mailbox->folder_path( 'Approved', '.' ) );
		self::assertSame( 'Moderation/Approved', $mailbox->folder_path( 'Approved', '/' ) );
	}

	public function test_folder_path_honours_a_nested_prefix(): void {
		self::assertSame( 'INBOX.Moderation.Error', ( new Dav_Mlm_Mailbox( $this->config( 'ssl', 'INBOX.Moderation' ) ) )->folder_path( 'Error', '.' ) );
	}

	public function test_a_utf8_folder_name_is_encoded_as_modified_utf7(): void {
		if ( ! function_exists( 'imap_utf8_to_mutf7' ) ) {
			self::markTestSkipped( 'ext-imap is not loaded.' );
		}

		$mailbox = new Dav_Mlm_Mailbox( $this->config() );

		self::assertSame( 'Moderation/Approved', $mailbox->encode_folder_name( 'Moderation/Approved' ) );
		self::assertSame( 'Pr&APw-fung/Approved', $mailbox->encode_folder_name( 'Prüfung/Approved' ) );
	}

	public function test_folder_name_from_listing_strips_the_servers_own_reference(): void {
		$mailbox = new Dav_Mlm_Mailbox( $this->config() );

		self::assertSame( 'INBOX.Moderation.Approved', $mailbox->folder_name_from_listing( '{imap.example.com:993/imap/ssl}INBOX.Moderation.Approved' ) );
		self::assertSame( 'Moderation/Manual', $mailbox->folder_name_from_listing( '{imap.example.com:993/imap/ssl/validate-cert}Moderation/Manual' ) );
		self::assertSame( 'INBOX', $mailbox->folder_name_from_listing( 'INBOX' ) );
	}

	public function test_operations_before_connect_fail_cleanly(): void {
		$this->expectException( Dav_Mlm_Mailbox_Exception::class );

		( new Dav_Mlm_Mailbox( $this->config() ) )->fetch_raw( 1 );
	}

	public function test_interface_lists_all_six_target_folders(): void {
		self::assertSame(
			array( 'Approved', 'Rejected', 'Manual', 'Suspicious', 'Unrecognized', 'Error' ),
			Dav_Mlm_Mailbox_Interface::FOLDERS
		);
	}

	public function test_fake_mailbox_keeps_moved_messages_until_expunge(): void {
		$fake = new Dav_Mlm_Fake_Mailbox();
		$fake->ensure_folders();
		$fake->add_message( 7, 'raw seven' );
		$fake->add_message( 3, 'raw three' );

		self::assertSame( array( 3, 7 ), $fake->search_notifications() );
		self::assertSame( 'raw three', $fake->fetch_raw( 3 ) );

		$fake->move( 3, Dav_Mlm_Mailbox_Interface::FOLDER_APPROVED );
		self::assertSame( array( 3, 7 ), $fake->search_notifications() );

		$fake->expunge();
		self::assertSame( array( 7 ), $fake->search_notifications() );
		self::assertSame( array( 3 ), $fake->folders[ Dav_Mlm_Mailbox_Interface::FOLDER_APPROVED ] );
	}
}
