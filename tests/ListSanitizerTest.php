<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class ListSanitizerTest extends TestCase {

	private Dav_Mlm_List_Sanitizer $sanitizer;

	protected function setUp(): void {
		$next_id         = 0;
		$this->sanitizer = new Dav_Mlm_List_Sanitizer(
			static function () use ( &$next_id ): string {
				return 'generated-' . ( ++$next_id );
			}
		);
	}

	public function test_saving_a_new_list_appends_it_with_a_generated_id(): void {
		$existing = $this->stored_list( 'existing', 'other@dav-neuland.de' );

		$result = $this->save( $this->form_row(), array( $existing ) );

		self::assertSame( array(), $result->errors() );
		self::assertCount( 2, $result->lists() );
		self::assertSame( $existing, $result->lists()[0] );
		self::assertSame( 'generated-1', $result->lists()[1]['id'] );
	}

	public function test_saving_an_existing_list_replaces_it_in_place(): void {
		$first  = $this->stored_list( 'a', 'a@dav-neuland.de' );
		$second = $this->stored_list( 'b', 'b@dav-neuland.de' );

		$result = $this->save( array( 'id' => 'a', 'list_address' => 'renamed@dav-neuland.de' ) + $this->form_row(), array( $first, $second ) );

		self::assertSame( array(), $result->errors() );
		self::assertSame( array( 'a', 'b' ), array_column( $result->lists(), 'id' ) );
		self::assertSame( 'renamed@dav-neuland.de', $result->lists()[0]['list_address'] );
	}

	public function test_saving_normalises_every_field(): void {
		$result = $this->save(
			array(
				'list_address'   => '  Test.Mailingliste@DAV-Neuland.de ',
				'regex'          => ' /@dav-neuland\.de\z/i ',
				'dkim_policy'    => 'off',
				'reject_mode'    => 'email',
				'reject_subject' => " Nicht zugestellt:\r\n{{list}}\t ",
				'reject_body'    => "Hallo,\r\n\r\ndas ging nicht.\x07\n\n",
				'reply_to'       => ' vorstand@dav-neuland.de ',
				'active'         => '1',
			)
		);

		self::assertSame( array(), $result->errors() );
		self::assertSame(
			array(
				'id'             => 'generated-1',
				'list_address'   => 'test.mailingliste@dav-neuland.de',
				'regex'          => '/@dav-neuland\.de\z/i',
				'dkim_policy'    => 'off',
				'reject_mode'    => 'email',
				'reject_subject' => 'Nicht zugestellt: {{list}}',
				'reject_body'    => "Hallo,\n\ndas ging nicht.",
				'reply_to'       => 'vorstand@dav-neuland.de',
				'active'         => true,
			),
			$result->lists()[0]
		);
	}

	public function test_the_body_keeps_angle_brackets(): void {
		$result = $this->save( array( 'reject_body' => 'Fragen an <vorstand@dav-neuland.de>' ) + $this->form_row() );

		self::assertSame( 'Fragen an <vorstand@dav-neuland.de>', $result->lists()[0]['reject_body'] );
	}

	public function test_a_missing_active_checkbox_means_inactive(): void {
		$row = $this->form_row();
		unset( $row['active'] );

		self::assertFalse( $this->save( $row )->lists()[0]['active'] );
	}

	public function test_an_empty_reply_to_is_stored_as_null(): void {
		self::assertNull( $this->save( array( 'reply_to' => '  ' ) + $this->form_row() )->lists()[0]['reply_to'] );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public static function invalid_rows(): array {
		return array(
			'invalid list address'         => array( array( 'list_address' => 'not-an-address' ), 'list address is not a valid' ),
			'empty list address'           => array( array( 'list_address' => '' ), 'list address is not a valid' ),
			'empty regex'                  => array( array( 'regex' => '' ), 'sender regex is empty' ),
			'regex without end delimiter'  => array( array( 'regex' => '/@dav-neuland\.de' ), "No ending delimiter '/' found" ),
			'regex with unknown modifier'  => array( array( 'regex' => '/x/q' ), "Unknown modifier 'q'" ),
			'unknown dkim policy'          => array( array( 'dkim_policy' => 'maybe' ), 'unknown DKIM policy' ),
			'unknown reject mode'          => array( array( 'reject_mode' => 'bounce' ), 'unknown rejection mode' ),
			'invalid reply-to'             => array( array( 'reply_to' => 'vorstand' ), 'Reply-To address is not a valid' ),
			'email mode without a subject' => array( array( 'reject_subject' => " \r\n" ), 'needs a subject and a body' ),
			'email mode without a body'    => array( array( 'reject_body' => "\n\n" ), 'needs a subject and a body' ),
			'body is not utf-8'            => array( array( 'reject_body' => "Gr\xFC\xDFe" ), 'not valid UTF-8' ),
		);
	}

	/**
	 * @dataProvider invalid_rows
	 */
	public function test_an_invalid_row_is_rejected_and_the_configuration_left_unchanged( array $override, string $expected_error ): void {
		$existing = array( $this->stored_list( 'existing', 'other@dav-neuland.de' ) );

		$result = $this->save( $override + $this->form_row(), $existing );

		self::assertTrue( $result->has_errors() );
		self::assertStringContainsString( $expected_error, implode( "\n", $result->errors() ) );
		self::assertSame( $existing, $result->lists() );
	}

	public function test_silent_mode_does_not_need_a_subject_or_body(): void {
		$result = $this->save( array( 'reject_mode' => 'silent', 'reject_subject' => '', 'reject_body' => '' ) + $this->form_row() );

		self::assertFalse( $result->has_errors() );
	}

	public function test_a_duplicate_list_address_is_rejected_regardless_of_case(): void {
		$existing = array( $this->stored_list( 'existing', 'test.mailingliste@dav-neuland.de' ) );

		$result = $this->save( array( 'list_address' => 'TEST.Mailingliste@dav-neuland.de' ) + $this->form_row(), $existing );

		self::assertStringContainsString( 'another list already uses this address', implode( "\n", $result->errors() ) );
	}

	public function test_a_list_keeps_its_own_address_when_edited(): void {
		$existing = array( $this->stored_list( 'existing', 'test.mailingliste@dav-neuland.de' ) );

		$result = $this->save( array( 'id' => 'existing', 'list_address' => 'test.mailingliste@dav-neuland.de' ) + $this->form_row(), $existing );

		self::assertFalse( $result->has_errors() );
	}

	public function test_saving_a_list_that_no_longer_exists_is_an_error(): void {
		$result = $this->save( array( 'id' => 'gone' ) + $this->form_row() );

		self::assertStringContainsString( 'no longer exists', implode( "\n", $result->errors() ) );
		self::assertSame( array(), $result->lists() );
	}

	public function test_unknown_placeholders_are_a_warning_not_an_error(): void {
		$result = $this->save( array( 'reject_body' => 'Hallo {{sendr}}, {{list}}' ) + $this->form_row() );

		self::assertFalse( $result->has_errors() );
		self::assertCount( 1, $result->lists() );
		self::assertStringContainsString( '{{sendr}}', implode( "\n", $result->warnings() ) );
		self::assertStringNotContainsString( '{{list}} will', implode( "\n", $result->warnings() ) );
	}

	public function test_delete_removes_the_list_and_reindexes(): void {
		$lists = array(
			$this->stored_list( 'a', 'a@dav-neuland.de' ),
			$this->stored_list( 'b', 'b@dav-neuland.de' ),
		);

		$result = $this->sanitizer->apply( array( 'op' => 'delete', 'id' => 'a' ), $lists );

		self::assertSame( array( $lists[1] ), $result->lists() );
	}

	public function test_deleting_an_unknown_id_is_an_error(): void {
		$lists = array( $this->stored_list( 'a', 'a@dav-neuland.de' ) );

		$result = $this->sanitizer->apply( array( 'op' => 'delete', 'id' => 'gone' ), $lists );

		self::assertTrue( $result->has_errors() );
		self::assertSame( $lists, $result->lists() );
	}

	public function test_unknown_operations_and_non_array_input_are_errors(): void {
		$lists = array( $this->stored_list( 'a', 'a@dav-neuland.de' ) );

		foreach ( array( array( 'op' => 'truncate' ), 'garbage', null ) as $input ) {
			$result = $this->sanitizer->apply( $input, $lists );

			self::assertTrue( $result->has_errors() );
			self::assertSame( $lists, $result->lists() );
		}
	}

	/**
	 * WordPress's second pass through the callback (add_option() after a
	 * first update_option()) hands over the already-sanitized full array.
	 */
	public function test_an_already_sanitized_full_array_passes_through_unchanged(): void {
		$lists = array(
			$this->stored_list( 'a', 'a@dav-neuland.de' ),
			$this->stored_list( 'b', 'b@dav-neuland.de' ),
		);

		$result = $this->sanitizer->apply( $lists, array() );

		self::assertFalse( $result->has_errors() );
		self::assertSame( $lists, $result->lists() );
	}

	public function test_a_full_array_with_duplicate_addresses_is_rejected(): void {
		$lists = array(
			$this->stored_list( 'a', 'a@dav-neuland.de' ),
			$this->stored_list( 'b', 'a@dav-neuland.de' ),
		);

		self::assertTrue( $this->sanitizer->apply( $lists, array() )->has_errors() );
	}

	public function test_messages_follow_the_given_language(): void {
		$sanitizer = new Dav_Mlm_List_Sanitizer( static fn (): string => 'generated', new Dav_Mlm_Admin_Language( Dav_Mlm_Admin_Language::DE ) );

		$result = $sanitizer->apply(
			array(
				'op'   => 'save',
				'list' => array( 'list_address' => 'kaputt', 'regex' => '/x' ) + $this->form_row(),
			),
			array()
		);

		self::assertSame(
			array(
				'kaputt: Die Listenadresse ist keine gültige E-Mail-Adresse.',
				"kaputt: Der Absender-Regex ist ungültig (No ending delimiter '/' found).",
			),
			$result->errors()
		);
	}

	public function test_regex_error_is_null_for_a_valid_pattern(): void {
		self::assertNull( Dav_Mlm_List_Sanitizer::regex_error( '/@dav-neuland\.de$/D' ) );
	}

	private function save( array $row, array $current = array() ): Dav_Mlm_List_Sanitize_Result {
		return $this->sanitizer->apply(
			array(
				'op'   => 'save',
				'list' => $row,
			),
			$current
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

	private function stored_list( string $id, string $address ): array {
		return array(
			'id'             => $id,
			'list_address'   => $address,
			'regex'          => '/@dav-neuland\.de\z/i',
			'dkim_policy'    => 'require',
			'reject_mode'    => 'email',
			'reject_subject' => 'Nicht zugestellt: {{list}}',
			'reject_body'    => 'Hallo {{sender}}',
			'reply_to'       => null,
			'active'         => true,
		);
	}
}
