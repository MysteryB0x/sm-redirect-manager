<?php
/**
 * Rule lifecycle status.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Enum;

defined( 'ABSPATH' ) || exit;

enum RedirectStatus: string {

	case Active   = 'active';
	case Inactive = 'inactive';

	public function label(): string {
		return match ( $this ) {
			self::Active   => __( 'Active', 'sm-redirect-manager' ),
			self::Inactive => __( 'Inactive', 'sm-redirect-manager' ),
		};
	}

	public function toggled(): self {
		return self::Active === $this ? self::Inactive : self::Active;
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
