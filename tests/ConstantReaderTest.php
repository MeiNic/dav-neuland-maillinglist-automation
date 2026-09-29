<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Covers Dav_Mlm_Constant_Reader's own mechanics in isolation from
 * Dav_Mlm_Config's domain mapping (which constants exist, their
 * defaults) — see ConfigTest for that side.
 */
final class ConstantReaderTest extends TestCase {

	public function test_or_default_returns_the_default_when_undefined(): void {
		$reader = new Dav_Mlm_Constant_Reader();

		self::assertSame( 'fallback', $reader->or_default( 'DAV_MLM_TEST_UNDEFINED_CONST', 'fallback' ) );
	}

	#[RunInSeparateProcess]
	public function test_or_default_returns_the_constant_when_defined(): void {
		define( 'DAV_MLM_TEST_DEFINED_CONST', 'actual-value' );
		$reader = new Dav_Mlm_Constant_Reader();

		self::assertSame( 'actual-value', $reader->or_default( 'DAV_MLM_TEST_DEFINED_CONST', 'fallback' ) );
	}

	public function test_require_throws_when_undefined(): void {
		$reader = new Dav_Mlm_Constant_Reader();

		$this->expectException( Dav_Mlm_Config_Exception::class );
		$this->expectExceptionMessage( 'DAV_MLM_TEST_STILL_UNDEFINED' );

		$reader->require( 'DAV_MLM_TEST_STILL_UNDEFINED' );
	}

	#[RunInSeparateProcess]
	public function test_require_returns_the_constant_when_defined(): void {
		define( 'DAV_MLM_TEST_REQUIRED_CONST', 'present' );
		$reader = new Dav_Mlm_Constant_Reader();

		self::assertSame( 'present', $reader->require( 'DAV_MLM_TEST_REQUIRED_CONST' ) );
	}

	public function test_nullable_string_treats_null_and_empty_string_as_null(): void {
		$reader = new Dav_Mlm_Constant_Reader();

		self::assertNull( $reader->nullable_string( null ) );
		self::assertNull( $reader->nullable_string( '' ) );
		self::assertSame( '0', $reader->nullable_string( '0' ) );
		self::assertSame( 'value', $reader->nullable_string( 'value' ) );
	}
}
