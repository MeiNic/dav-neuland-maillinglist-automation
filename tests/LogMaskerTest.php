<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LogMaskerTest extends TestCase {

	private Dav_Mlm_Log_Masker $masker;

	protected function setUp(): void {
		$this->masker = new Dav_Mlm_Log_Masker();
	}

	public function test_mask_message_masks_the_confirm_id_query_param(): void {
		$message = 'approved https://ml.kundenserver.de/MailingList/x%40y/Mail/Confirm?lang=de&id=ABC%3A123';

		$masked = $this->masker->mask_message( $message );

		self::assertStringNotContainsString( 'ABC%3A123', $masked );
		self::assertStringContainsString( '&id=***', $masked );
		self::assertStringContainsString( 'lang=de', $masked );
	}

	public function test_mask_message_is_case_insensitive_and_handles_a_leading_query_param(): void {
		$masked = $this->masker->mask_message( 'foo?ID=secret-token&next=1' );

		self::assertStringNotContainsString( 'secret-token', $masked );
		self::assertStringContainsString( 'next=1', $masked );
	}

	public function test_mask_message_also_masks_a_token_query_param(): void {
		$masked = $this->masker->mask_message( 'https://example.com/confirm?token=abcdef' );

		self::assertStringNotContainsString( 'abcdef', $masked );
	}

	public function test_mask_message_leaves_unrelated_id_looking_text_alone(): void {
		$message = 'nested Message-ID: <abc123@dav-neuland.de>';

		self::assertSame( $message, $this->masker->mask_message( $message ) );
	}

	public function test_mask_context_masks_sensitive_keys_outright(): void {
		$masked = $this->masker->mask_context(
			array(
				'token'         => 'abc',
				'confirm_token' => 'def',
				'pass'          => 'ghi',
				'list_id'       => 'test-list',
				'message_id'    => '<abc@dav-neuland.de>',
			)
		);

		self::assertSame( '***', $masked['token'] );
		self::assertSame( '***', $masked['confirm_token'] );
		self::assertSame( '***', $masked['pass'] );
		// "id" alone must never trigger masking — list/message ids are
		// meant to be logged (PLAN.md line 229).
		self::assertSame( 'test-list', $masked['list_id'] );
		self::assertSame( '<abc@dav-neuland.de>', $masked['message_id'] );
	}

	public function test_mask_context_masks_confirm_urls_embedded_in_string_values(): void {
		$masked = $this->masker->mask_context(
			array( 'confirm_url' => 'https://ml.kundenserver.de/MailingList/x/Mail/Confirm?lang=de&id=SECRET' )
		);

		self::assertStringNotContainsString( 'SECRET', $masked['confirm_url'] );
	}

	public function test_mask_context_recurses_into_nested_arrays(): void {
		$masked = $this->masker->mask_context(
			array( 'nested' => array( 'token' => 'abc' ) )
		);

		self::assertSame( '***', $masked['nested']['token'] );
	}
}
