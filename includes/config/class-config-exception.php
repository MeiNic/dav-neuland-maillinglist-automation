<?php
/**
 * Thrown by Dav_Mlm_Config when a required DAV_MLM_* wp-config constant is
 * missing. Callers (the cron runner, the admin settings page) catch this
 * specifically to abort the run / show a notice, rather than a fatal error.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Config_Exception extends \RuntimeException {
}
