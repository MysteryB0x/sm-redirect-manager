<?php
/**
 * Typed access to the plugin's option array.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Support;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'sm_redirect_manager_settings';
	public const GROUP  = 'sm_redirect_manager_settings';

	public const DEFAULTS = array(
		'enable_404_log'           => true,
		'auto_slug_redirects'      => true,
		'anonymize_ip'             => true,
		'ignore_bots'              => false,
		'log_retention_days'       => 30,
		'remove_data_on_uninstall' => false,
	);

	/**
	 * @return array<string, bool|int>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::DEFAULTS );
	}

	public function bool( string $key ): bool {
		return (bool) ( $this->all()[ $key ] ?? false );
	}

	public function int( string $key ): int {
		return (int) ( $this->all()[ $key ] ?? 0 );
	}

	/**
	 * Settings API sanitize callback.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, bool|int>
	 */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();

		return array(
			'enable_404_log'           => ! empty( $input['enable_404_log'] ),
			'auto_slug_redirects'      => ! empty( $input['auto_slug_redirects'] ),
			'anonymize_ip'             => ! empty( $input['anonymize_ip'] ),
			'ignore_bots'              => ! empty( $input['ignore_bots'] ),
			'log_retention_days'       => min( 3650, absint( $input['log_retention_days'] ?? self::DEFAULTS['log_retention_days'] ) ),
			'remove_data_on_uninstall' => ! empty( $input['remove_data_on_uninstall'] ),
		);
	}
}
