<?php
/**
 * Contract for any service that attaches itself to WordPress hooks.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Contracts;

defined( 'ABSPATH' ) || exit;

interface HookableInterface {

	/**
	 * Attach actions and filters. Must be idempotent and side-effect free
	 * apart from hook registration.
	 */
	public function register(): void;
}
