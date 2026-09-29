<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class OptionStoreTest extends TestCase {

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	public function test_get_returns_the_default_when_option_is_unset(): void {
		$store = new Dav_Mlm_Option_Store();

		self::assertSame( array( 'x' => 1 ), $store->get( 'dav_mlm_missing', array( 'x' => 1 ) ) );
	}

	public function test_set_then_get_round_trips_the_value(): void {
		$store = new Dav_Mlm_Option_Store();

		$store->set( 'dav_mlm_test_option', array( 'a' => 'b' ) );

		self::assertSame( array( 'a' => 'b' ), $store->get( 'dav_mlm_test_option', array() ) );
	}

	public function test_set_always_disables_autoload(): void {
		$store = new Dav_Mlm_Option_Store();

		$store->set( 'dav_mlm_test_option', 'value' );

		self::assertFalse( $GLOBALS['dav_mlm_test_update_option_autoload']['dav_mlm_test_option'] );
	}
}
