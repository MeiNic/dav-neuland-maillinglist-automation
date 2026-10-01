<?php
/**
 * Fired when the plugin is deleted via wp-admin. Removes all options and
 * transients this plugin created; leaves wp-config.php constants and
 * DAV_MLM_DATA_DIR (logs) alone — those are not WordPress-managed state.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'dav_mlm_lists', 'dav_mlm_status', 'dav_mlm_attempts', 'dav_mlm_done', 'dav_mlm_alerter_state' ) as $option ) {
	delete_option( $option );
}

// Each user's admin page language choice (Dav_Mlm_Admin_Language::USER_META).
delete_metadata( 'user', 0, 'dav_mlm_admin_language', '', true );

$transient_like = $wpdb->esc_like( '_transient_dav_mlm_' ) . '%';
$timeout_like   = $wpdb->esc_like( '_transient_timeout_dav_mlm_' ) . '%';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- no dedicated API for a wildcard transient prefix delete.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$transient_like,
		$timeout_like
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery
