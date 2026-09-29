<?php
/**
 * PHPUnit bootstrap: loads both Composer autoloaders (plain vendor/ for
 * dev-only tooling, vendor-prefixed/ for the namespace-prefixed runtime
 * deps the plugin itself uses — see composer.json's "extra.strauss"),
 * plus our own Dav_Mlm_* autoloader (includes/autoload.php) — loaded
 * directly rather than via the plugin bootstrap file, since that file
 * also registers WordPress hooks (add_action) that aren't available
 * outside a WordPress request.
 *
 * ABSPATH is defined here (a fake path) because the plugin bootstrap
 * normally guarantees it's set before any Dav_Mlm_* class runs;
 * production code (e.g. Dav_Mlm_Config's DATA_DIR default) relies on
 * that invariant instead of re-checking it everywhere.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$prefixed = __DIR__ . '/../vendor-prefixed/autoload.php';
if (file_exists($prefixed)) {
	require_once $prefixed;
}

if (!defined('ABSPATH')) {
	define('ABSPATH', '/tmp/dav-mlm-tests/wordpress/');
}

require_once __DIR__ . '/../includes/autoload.php';
