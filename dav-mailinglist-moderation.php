<?php
/**
 * Plugin Name:       DAV Mailinglist Moderation
 * Description:       Automates moderation of IONOS Mailinglisten-Manager approval emails for dav-neuland.de mailing lists. See PLAN.md.
 * Version:           0.1.0
 * Requires PHP:      8.3
 * Author:            DAV Neuland
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 */

namespace DavMlm;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access is not allowed.
}

define( 'DAV_MLM_VERSION', '0.1.0' );
define( 'DAV_MLM_PLUGIN_FILE', __FILE__ );
define( 'DAV_MLM_PLUGIN_DIR', __DIR__ );

foreach ( array( '/vendor/autoload.php', '/vendor-prefixed/autoload.php' ) as $autoload ) {
	$path = DAV_MLM_PLUGIN_DIR . $autoload;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

/**
 * Maps Dav_Mlm_Foo_Bar to includes/class-foo-bar.php (WordPress classic
 * plugin convention; our own code is not PSR-4, only the vendored
 * dependencies namespaced above are).
 */
spl_autoload_register(
	function ( string $class ): void {
		if ( strpos( $class, 'Dav_Mlm_' ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( 'Dav_Mlm_' ) );
		$file     = DAV_MLM_PLUGIN_DIR . '/includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'admin_menu',
	function (): void {
		if ( class_exists( 'Dav_Mlm_Admin_Settings' ) ) {
			( new \Dav_Mlm_Admin_Settings() )->register_menu();
		}
	}
);
