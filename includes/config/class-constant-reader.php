<?php
/**
 * Generic wp-config.php constant lookups: "required, or error" / "optional,
 * with a default". Kept separate from Dav_Mlm_Config so the mechanics of
 * reading a PHP constant don't get tangled up with the domain knowledge of
 * which DAV_MLM_* constants exist and what their defaults are.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Constant_Reader {

	/**
	 * @throws Dav_Mlm_Config_Exception When the constant is not defined.
	 */
	public function require( string $name ): mixed {
		if ( ! defined( $name ) ) {
			throw new Dav_Mlm_Config_Exception(
				sprintf( 'Required constant %s is not defined in wp-config.php.', $name )
			);
		}

		return constant( $name );
	}

	public function or_default( string $name, mixed $default ): mixed {
		return defined( $name ) ? constant( $name ) : $default;
	}

	public function nullable_string( mixed $value ): ?string {
		return null === $value || '' === $value ? null : (string) $value;
	}
}
