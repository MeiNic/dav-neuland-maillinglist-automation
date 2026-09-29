<?php
/**
 * PHPUnit bootstrap: loads both Composer autoloaders (plain vendor/ for
 * dev-only tooling, vendor-prefixed/ for the namespace-prefixed runtime
 * deps the plugin itself uses — see composer.json's "extra.strauss").
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$prefixed = __DIR__ . '/../vendor-prefixed/autoload.php';
if (file_exists($prefixed)) {
	require_once $prefixed;
}
