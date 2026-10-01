<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

/**
 * Covers the sanitize callback's settings-API side (capability check,
 * messages, form stash); validation itself is in ListSanitizerTest.
 * Page rendering needs real WordPress and isn't covered here.
 */
final class AdminSettingsTest extends TestCase {

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	public function test_a_valid_save_returns_the_merged_lists_and_adds_no_messages(): void {
		$lists = $this->settings()->sanitize_option( $this->save_input( $this->form_row() ) );

		self::assertCount( 1, $lists );
		self::assertSame( 'test.mailingliste@dav-neuland.de', $lists[0]['list_address'] );
		self::assertSame( array(), $GLOBALS['dav_mlm_test_settings_errors'] );
	}

	public function test_a_form_submission_without_manage_options_changes_nothing(): void {
		dav_mlm_test_set_option( 'dav_mlm_lists', array() );
		dav_mlm_test_set_user_can( false );

		$lists = $this->settings()->sanitize_option( $this->save_input( $this->form_row() ) );

		self::assertSame( array(), $lists );
		self::assertSame( 'dav_mlm_forbidden', $GLOBALS['dav_mlm_test_settings_errors'][0]['code'] );
	}

	public function test_errors_are_reported_and_the_submitted_form_is_stashed_for_the_user(): void {
		$row = array( 'regex' => '/unterminated' ) + $this->form_row();

		$lists = $this->settings()->sanitize_option( $this->save_input( $row ) );

		self::assertSame( array(), $lists );
		self::assertSame( array( 'error' ), array_column( $GLOBALS['dav_mlm_test_settings_errors'], 'type' ) );
		self::assertSame( $row, get_transient( 'dav_mlm_form_1' ) );
	}

	public function test_warnings_come_with_an_explicit_saved_message(): void {
		$lists = $this->settings()->sanitize_option( $this->save_input( array( 'reject_body' => 'Hallo {{name}}' ) + $this->form_row() ) );

		self::assertCount( 1, $lists );
		self::assertSame( array( 'success', 'warning' ), array_column( $GLOBALS['dav_mlm_test_settings_errors'], 'type' ) );
		self::assertFalse( get_transient( 'dav_mlm_form_1' ) );
	}

	public function test_messages_follow_the_user_language(): void {
		dav_mlm_test_set_user_can( false );
		$settings = new Dav_Mlm_Admin_Settings( null, null, new Dav_Mlm_Admin_Language( Dav_Mlm_Admin_Language::DE ) );

		$settings->sanitize_option( $this->save_input( $this->form_row() ) );

		self::assertSame( 'Sie dürfen die Mailinglisten-Konfiguration nicht ändern.', $GLOBALS['dav_mlm_test_settings_errors'][0]['message'] );
	}

	private function settings(): Dav_Mlm_Admin_Settings {
		return new Dav_Mlm_Admin_Settings( null, new Dav_Mlm_List_Sanitizer( static fn (): string => 'generated' ) );
	}

	private function save_input( array $row ): array {
		return array(
			'op'   => 'save',
			'list' => $row,
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function form_row(): array {
		return array(
			'id'             => '',
			'list_address'   => 'test.mailingliste@dav-neuland.de',
			'regex'          => '/@dav-neuland\.de\z/i',
			'dkim_policy'    => 'require',
			'reject_mode'    => 'email',
			'reject_subject' => 'Nicht zugestellt: {{list}}',
			'reject_body'    => 'Hallo {{sender}}',
			'reply_to'       => '',
			'active'         => '1',
		);
	}
}
