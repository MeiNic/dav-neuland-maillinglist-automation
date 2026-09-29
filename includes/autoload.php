<?php
/**
 * Maps Dav_Mlm_Foo_Bar to includes/**\/class-foo-bar.php (WordPress
 * classic plugin convention; our own code is not PSR-4, only the
 * vendored dependencies prefixed by Strauss are). Classes are grouped
 * into subfolders by concern (config/, logging/, ...); this autoloader
 * finds them by filename via a one-time recursive scan, so adding a new
 * subfolder never means touching this file. Split out from the plugin
 * bootstrap so PHPUnit's bootstrap can load just the autoloader without
 * pulling in WordPress-only calls (add_action, ABSPATH guard, ...).
 */

spl_autoload_register(
	function ( string $class ): void {
		static $file_by_basename = null;

		if ( strpos( $class, 'Dav_Mlm_' ) !== 0 ) {
			return;
		}

		if ( null === $file_by_basename ) {
			$file_by_basename = array();
			$files            = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( __DIR__, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $files as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$file_by_basename[ $file->getFilename() ] = $file->getPathname();
				}
			}
		}

		$relative = substr( $class, strlen( 'Dav_Mlm_' ) );
		$basename = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

		if ( isset( $file_by_basename[ $basename ] ) ) {
			require_once $file_by_basename[ $basename ];
		}
	}
);
