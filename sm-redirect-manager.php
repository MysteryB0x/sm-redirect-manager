<?php
/**
 * Plugin Name:       SEOmarketeer Redirect Manager
 * Description:       Advanced redirect management, auto-slug tracking, and 404 monitoring.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            SEOmarketeer
 * Author URI:        https://seomarketeer.eu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sm-redirect-manager
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SM_REDIRECT_MANAGER_VERSION', '1.0.2' );
define( 'SM_REDIRECT_MANAGER_DB_VERSION', '1.0.0' );
define( 'SM_REDIRECT_MANAGER_MIN_PHP', '8.1' );
define( 'SM_REDIRECT_MANAGER_FILE', __FILE__ );
define( 'SM_REDIRECT_MANAGER_PATH', plugin_dir_path( __FILE__ ) );
define( 'SM_REDIRECT_MANAGER_URL', plugin_dir_url( __FILE__ ) );

/*
 * Bail out gracefully on unsupported PHP versions. This file is intentionally
 * kept parseable by older PHP so the notice can be shown instead of a fatal.
 */
if ( version_compare( PHP_VERSION, SM_REDIRECT_MANAGER_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'SEOmarketeer Redirect Manager requires PHP %1$s or higher. You are running PHP %2$s.', 'sm-redirect-manager' ),
						SM_REDIRECT_MANAGER_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

/*
 * PSR-4 autoloading: prefer Composer when present, otherwise fall back to the
 * bundled lightweight autoloader (SEOmarketeer\RedirectManager\ => src/).
 */
if ( is_readable( SM_REDIRECT_MANAGER_PATH . 'vendor/autoload.php' ) ) {
	require_once SM_REDIRECT_MANAGER_PATH . 'vendor/autoload.php';
} else {
	require_once SM_REDIRECT_MANAGER_PATH . 'src/Autoloader.php';
	Autoloader::register( __NAMESPACE__ . '\\', SM_REDIRECT_MANAGER_PATH . 'src/' );
}

register_activation_hook( __FILE__, array( Database\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Database\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	},
	5
);
