<?php
/**
 * How query-string parameters are treated when matching and redirecting.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Enum;

defined( 'ABSPATH' ) || exit;

enum QueryStrategy: string {

	/** Match on path only; the request's query string is dropped. */
	case Ignore = 'ignore';

	/** Path and query string must match exactly (parameter order is irrelevant). */
	case Exact = 'exact';

	/** Match on path only; the request's query string is appended to the target. */
	case Pass = 'pass';

	public function label(): string {
		return match ( $this ) {
			self::Ignore => __( 'Ignore parameters', 'sm-redirect-manager' ),
			self::Exact  => __( 'Exact parameter match', 'sm-redirect-manager' ),
			self::Pass   => __( 'Pass parameters through', 'sm-redirect-manager' ),
		};
	}

	/**
	 * @return array<string, string>
	 */
	public static function options(): array {
		$options = array();
		foreach ( self::cases() as $case ) {
			$options[ $case->value ] = $case->label();
		}
		return $options;
	}
}
