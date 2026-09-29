<?php
/**
 * Thin wrapper around get_option()/update_option() that always sets
 * autoload=false (PLAN.md §8 checklist item for every dav_mlm_* option —
 * easy to forget if each state class called update_option() itself).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Option_Store {

	public function get( string $name, mixed $default ): mixed {
		$value = get_option( $name, $default );

		return false === $value ? $default : $value;
	}

	public function set( string $name, mixed $value ): void {
		update_option( $name, $value, false );
	}
}
