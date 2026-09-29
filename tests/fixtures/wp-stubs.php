<?php
/**
 * Minimal WordPress function stubs for the handful of tests that exercise
 * a WP-dependent default or the option/transient-backed state classes.
 * Not a WordPress test suite — just enough in-memory storage to keep
 * those specific behaviours testable without one. Real expiry of
 * transients is WP's own mechanism, not ours, so it's not modelled here;
 * these stubs only need to round-trip values within a single test.
 */

declare(strict_types=1);

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['dav_mlm_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['dav_mlm_test_options'][ $name ]                = $value;
		$GLOBALS['dav_mlm_test_update_option_autoload'][ $name ] = $autoload;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['dav_mlm_test_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['dav_mlm_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['dav_mlm_test_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['dav_mlm_test_transients'][ $key ] );

		return true;
	}
}

function dav_mlm_test_set_option( string $name, $value ): void {
	$GLOBALS['dav_mlm_test_options'][ $name ] = $value;
}

/**
 * Resets the in-memory options/transients stores. Call from setUp() in
 * any test that uses these stubs, since $GLOBALS otherwise leaks state
 * between test methods in the same process.
 */
function dav_mlm_test_reset_wp_state(): void {
	$GLOBALS['dav_mlm_test_options']                = array();
	$GLOBALS['dav_mlm_test_transients']             = array();
	$GLOBALS['dav_mlm_test_update_option_autoload'] = array();
}
