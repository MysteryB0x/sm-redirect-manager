<?php
/**
 * The outcome of matching a request against the rule set.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Model;

defined( 'ABSPATH' ) || exit;

final class MatchResult {

	/**
	 * @param Redirect $redirect The rule that matched.
	 * @param string   $target   Absolute, fully resolved Location (empty for 410/451).
	 */
	public function __construct(
		public readonly Redirect $redirect,
		public readonly string $target,
	) {}
}
