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

require_once DAV_MLM_PLUGIN_DIR . '/includes/autoload.php';

add_action(
	'admin_menu',
	function (): void {
		if ( class_exists( 'Dav_Mlm_Admin_Settings' ) ) {
			( new \Dav_Mlm_Admin_Settings() )->register_menu();
		}
	}
);
