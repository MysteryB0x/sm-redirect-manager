<?php
/**
 * Minimal PSR-4 autoloader used when Composer is not available.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private static bool $registered = false;

	/**
	 * Register a PSR-4 prefix => directory mapping.
	 *
	 * @param string $prefix  Namespace prefix including trailing backslash.
	 * @param string $baseDir Absolute directory that maps to the prefix.
	 */
	public static function register( string $prefix, string $baseDir ): void {
		if ( self::$registered ) {
			return;
		}

		$baseDir = rtrim( $baseDir, '/\\' ) . DIRECTORY_SEPARATOR;

		spl_autoload_register(
			static function ( string $class ) use ( $prefix, $baseDir ): void {
				if ( ! str_starts_with( $class, $prefix ) ) {
					return;
				}

				$relative = substr( $class, strlen( $prefix ) );
				$file     = $baseDir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);

		self::$registered = true;
	}
}
