<?php
/**
 * How a rule's source is compared against the request.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Enum;

defined( 'ABSPATH' ) || exit;

enum MatchType: string {

	case Exact    = 'exact';
	case Regex    = 'regex';
	case Wildcard = 'wildcard';

	public function label(): string {
		return match ( $this ) {
			self::Exact    => __( 'Exact path', 'sm-redirect-manager' ),
			self::Regex    => __( 'Regular expression', 'sm-redirect-manager' ),
			self::Wildcard => __( 'Wildcard (*)', 'sm-redirect-manager' ),
		};
	}

	/**
	 * @return array<string, string> value => label
	 */
	public static function options(): array {
		$options = array();
		foreach ( self::cases() as $case ) {
			$options[ $case->value ] = $case->label();
		}
		return $options;
	}
}
