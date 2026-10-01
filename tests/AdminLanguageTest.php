<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class AdminLanguageTest extends TestCase {

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	public function test_english_returns_the_text_unchanged(): void {
		self::assertSame( 'Add list', ( new Dav_Mlm_Admin_Language( Dav_Mlm_Admin_Language::EN ) )->t( 'Add list' ) );
	}

	public function test_german_translates_known_text_and_falls_back_to_english(): void {
		$german = new Dav_Mlm_Admin_Language( Dav_Mlm_Admin_Language::DE );

		self::assertSame( 'Liste hinzufügen', $german->t( 'Add list' ) );
		self::assertSame( 'No such string', $german->t( 'No such string' ) );
	}

	public function test_an_unknown_code_means_english(): void {
		self::assertSame( Dav_Mlm_Admin_Language::EN, ( new Dav_Mlm_Admin_Language( 'fr' ) )->code() );
	}

	public function test_without_a_choice_the_user_locale_decides(): void {
		self::assertSame( Dav_Mlm_Admin_Language::EN, Dav_Mlm_Admin_Language::for_current_user()->code() );

		dav_mlm_test_set_user_locale( 'de_DE_formal' );
		self::assertSame( Dav_Mlm_Admin_Language::DE, Dav_Mlm_Admin_Language::for_current_user()->code() );
	}

	public function test_a_saved_choice_wins_over_the_locale(): void {
		dav_mlm_test_set_user_locale( 'de_DE' );

		Dav_Mlm_Admin_Language::save_for_current_user( Dav_Mlm_Admin_Language::EN );

		self::assertSame( Dav_Mlm_Admin_Language::EN, Dav_Mlm_Admin_Language::for_current_user()->code() );
	}

	public function test_saving_an_unknown_code_is_ignored(): void {
		Dav_Mlm_Admin_Language::save_for_current_user( 'fr' );

		self::assertSame( '', get_user_meta( get_current_user_id(), Dav_Mlm_Admin_Language::USER_META, true ) );
	}

	/**
	 * Every `t( '...' )` literal in the admin code needs a German entry,
	 * or it would silently show up in English on the German page.
	 */
	public function test_every_translated_literal_has_a_german_entry(): void {
		$missing = array();

		foreach ( glob( __DIR__ . '/../includes/admin/*.php' ) as $file ) {
			preg_match_all( "/->t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)/", (string) file_get_contents( $file ), $matches );

			foreach ( $matches[1] as $literal ) {
				// Single-quoted PHP: only \' and \\ are escapes.
				$text = preg_replace( "/\\\\(['\\\\])/", '$1', $literal );
				if ( ! array_key_exists( $text, Dav_Mlm_Admin_Language::GERMAN ) ) {
					$missing[] = basename( $file ) . ': ' . $text;
				}
			}
		}

		self::assertSame( array(), $missing );
	}

	public function test_german_entries_keep_the_same_sprintf_placeholders(): void {
		$conversions = static function ( string $text ): array {
			preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $matches );
			sort( $matches[0] );

			return $matches[0];
		};

		foreach ( Dav_Mlm_Admin_Language::GERMAN as $english => $german ) {
			self::assertSame( $conversions( $english ), $conversions( $german ), $english );
		}
	}
}
