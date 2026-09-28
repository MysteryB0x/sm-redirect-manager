<?php
/**
 * Supported HTTP response codes.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Enum;

defined( 'ABSPATH' ) || exit;

enum StatusCode: int {

	case MovedPermanently           = 301;
	case Found                      = 302;
	case TemporaryRedirect          = 307;
	case PermanentRedirect          = 308;
	case Gone                       = 410;
	case UnavailableForLegalReasons = 451;

	/**
	 * Whether this code sends a Location header (3xx) or a terminal status (4xx).
	 */
	public function isRedirect(): bool {
		return $this->value >= 300 && $this->value < 400;
	}

	public function isTemporary(): bool {
		return self::Found === $this || self::TemporaryRedirect === $this;
	}

	public function label(): string {
		$text = match ( $this ) {
			self::MovedPermanently           => __( 'Moved Permanently', 'sm-redirect-manager' ),
			self::Found                      => __( 'Found (temporary)', 'sm-redirect-manager' ),
			self::TemporaryRedirect          => __( 'Temporary Redirect', 'sm-redirect-manager' ),
			self::PermanentRedirect          => __( 'Permanent Redirect', 'sm-redirect-manager' ),
			self::Gone                       => __( 'Gone', 'sm-redirect-manager' ),
			self::UnavailableForLegalReasons => __( 'Unavailable For Legal Reasons', 'sm-redirect-manager' ),
		};

		return sprintf( '%d %s', $this->value, $text );
	}

	/**
	 * @return array<int, string>
	 */
	public static function options(): array {
		$options = array();
		foreach ( self::cases() as $case ) {
			$options[ $case->value ] = $case->label();
		}
		return $options;
	}
}
