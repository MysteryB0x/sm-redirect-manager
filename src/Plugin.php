<?php
/**
 * Composition root: wires services together and registers their hooks.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager;

use SEOmarketeer\RedirectManager\Admin\AdminController;
use SEOmarketeer\RedirectManager\Contracts\HookableInterface;
use SEOmarketeer\RedirectManager\Contracts\LogRepositoryInterface;
use SEOmarketeer\RedirectManager\Contracts\RedirectRepositoryInterface;
use SEOmarketeer\RedirectManager\Database\Installer;
use SEOmarketeer\RedirectManager\Database\LogRepository;
use SEOmarketeer\RedirectManager\Database\RedirectRepository;
use SEOmarketeer\RedirectManager\Engine\RedirectEngine;
use SEOmarketeer\RedirectManager\Engine\RuleMatcher;
use SEOmarketeer\RedirectManager\Hooks\SlugMonitor;
use SEOmarketeer\RedirectManager\Hooks\Tracker404;
use SEOmarketeer\RedirectManager\Service\CsvService;
use SEOmarketeer\RedirectManager\Service\RedirectValidator;
use SEOmarketeer\RedirectManager\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	private RedirectRepositoryInterface $redirects;

	private LogRepositoryInterface $logs;

	private Settings $settings;

	private function __construct() {}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		global $wpdb;

		Installer::maybeUpgrade();

		$this->settings  = new Settings();
		$this->redirects = new RedirectRepository( $wpdb );
		$this->logs      = new LogRepository( $wpdb );

		$validator = new RedirectValidator();
		$tracker   = new Tracker404( $this->logs, $this->redirects, $validator, $this->settings );

		/** @var list<HookableInterface> $services */
		$services = array(
			new RedirectEngine( new RuleMatcher( $this->redirects ), $this->redirects ),
			new SlugMonitor( $this->redirects, $this->settings ),
			$tracker,
		);

		if ( is_admin() ) {
			$services[] = new AdminController(
				$this->redirects,
				$this->logs,
				$validator,
				new CsvService( $this->redirects, $validator ),
				$tracker,
				$this->settings
			);
		}

		foreach ( $services as $service ) {
			$service->register();
		}

		// Multisite lifecycle.
		add_action( 'wp_initialize_site', array( Installer::class, 'onNewSite' ), 20 );
		add_filter( 'wpmu_drop_tables', array( Installer::class, 'filterDropTables' ), 10, 2 );

		/**
		 * Fires once all SM Redirect Manager services are registered.
		 *
		 * @param Plugin $plugin
		 */
		do_action( 'sm_redirect_manager_booted', $this );
	}

	public function redirects(): RedirectRepositoryInterface {
		return $this->redirects;
	}

	public function logs(): LogRepositoryInterface {
		return $this->logs;
	}

	public function settings(): Settings {
		return $this->settings;
	}
}
