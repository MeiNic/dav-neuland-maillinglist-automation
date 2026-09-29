<?php
/**
 * Maps Dav_Mlm_Foo_Bar to includes/class-foo-bar.php (WordPress classic
 * plugin convention; our own code is not PSR-4, only the vendored
 * dependencies prefixed by Strauss are). Split out from the plugin
 * bootstrap so PHPUnit's bootstrap can load just the autoloader without
 * pulling in WordPress-only calls (add_action, ABSPATH guard, ...).
 */

spl_autoload_register(
	function ( string $class ): void {
		if ( strpos( $class, 'Dav_Mlm_' ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( 'Dav_Mlm_' ) );
		$file     = __DIR__ . '/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
