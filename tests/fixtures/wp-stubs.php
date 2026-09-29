<?php
/**
 * Minimal WordPress function stubs for the handful of tests that exercise
 * a WP-dependent default (e.g. Dav_Mlm_Config::alert_email() falling back
 * to the site's admin_email option). Not a WordPress test suite — just
 * enough to keep those specific defaults testable without one.
 */

declare(strict_types=1);

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['dav_mlm_test_options'][ $name ] ?? $default;
	}
}

function dav_mlm_test_set_option( string $name, $value ): void {
	$GLOBALS['dav_mlm_test_options'][ $name ] = $value;
}
