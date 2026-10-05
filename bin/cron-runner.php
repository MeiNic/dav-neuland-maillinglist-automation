<?php if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; } // phpcs:ignore -- must stay the very first line (PLAN.md §5b step 0).
/**
 * CLI entrypoint for the moderation run, called by the system crontab
 * every 5 minutes as `/usr/bin/php8.3-cli <plugin dir>/bin/cron-runner.php
 * >> <data dir>/cron.log 2>&1` (full crontab line: PLAN.md §5b). Never
 * invoke it with the bare `php` on the IONOS webspace — that is a PHP 4
 * binary (PLAN.md §2). Run with --help for the flags.
 *
 * Loads WordPress so get_option(), wp_mail() and wp_safe_remote_get() are
 * available; everything after that is Dav_Mlm_Cron_Command. The plugin
 * must be active — a deactivated plugin means "stop moderating".
 */

$dav_mlm_options = getopt( '', array( 'dry-run', 'verbose', 'message-id:', 'help' ) );
if ( false === $dav_mlm_options ) {
	fwrite( STDERR, "Could not parse the command line options.\n" );
	exit( 1 );
}

// WordPress expects a web request; a single-site install doesn't route
// by host, and every mail we send sets its own From: header.
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'];
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

define( 'WP_USE_THEMES', false );

// bin/ → plugin dir → plugins/ → wp-content/ → WordPress root.
$dav_mlm_wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_file( $dav_mlm_wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found at $dav_mlm_wp_load — is the plugin installed in wp-content/plugins/?\n" );
	exit( 1 );
}

// Must run at the top level: WordPress relies on its globals being global.
require $dav_mlm_wp_load;

if ( ! class_exists( 'Dav_Mlm_Cron_Command' ) ) {
	fwrite( STDERR, "The DAV Mailinglist Moderation plugin is not active; nothing to do.\n" );
	exit( 0 );
}

exit( ( new Dav_Mlm_Cron_Command() )->run( $dav_mlm_options ) );
