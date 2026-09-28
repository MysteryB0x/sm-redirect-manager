<?php
/**
 * 404 interceptor, aggregator, retention purge and "convert to redirect" helper.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Hooks;

use SEOmarketeer\RedirectManager\Contracts\HookableInterface;
use SEOmarketeer\RedirectManager\Contracts\LogRepositoryInterface;
use SEOmarketeer\RedirectManager\Contracts\RedirectRepositoryInterface;
use SEOmarketeer\RedirectManager\Database\Installer;
use SEOmarketeer\RedirectManager\Enum\MatchType;
use SEOmarketeer\RedirectManager\Enum\QueryStrategy;
use SEOmarketeer\RedirectManager\Enum\RedirectStatus;
use SEOmarketeer\RedirectManager\Enum\StatusCode;
use SEOmarketeer\RedirectManager\Model\Redirect;
use SEOmarketeer\RedirectManager\Service\RedirectValidator;
use SEOmarketeer\RedirectManager\Support\Settings;
use SEOmarketeer\RedirectManager\Support\Url;

defined( 'ABSPATH' ) || exit;

final class Tracker404 implements HookableInterface {

	private const BOT_PATTERN = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|headless|curl|wget|python-requests|go-http-client|httpclient|scrapy/i';

	public function __construct(
		private readonly LogRepositoryInterface $logs,
		private readonly RedirectRepositoryInterface $redirects,
		private readonly RedirectValidator $validator,
		private readonly Settings $settings,
	) {}

	public function register(): void {
		// Late priority: runs after the redirect engine and redirect_canonical() had their chance.
		add_action( 'template_redirect', array( $this, 'capture' ), 999 );
		add_action( Installer::CRON_HOOK, array( $this, 'purge' ) );
	}

	public function capture(): void {
		if ( ! is_404() || ! $this->settings->bool( 'enable_404_log' ) ) {
			return;
		}

		$request = Url::currentRequest();
		$url     = Url::cleanText(
			$request['path'] . ( '' !== $request['query'] ? '?' . $request['query'] : '' )
		);

		$userAgent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? Url::cleanText( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 512 )
			: '';

		if ( $this->settings->bool( 'ignore_bots' ) && '' !== $userAgent && 1 === preg_match( self::BOT_PATTERN, $userAgent ) ) {
			return;
		}

		/**
		 * Whether to log this 404. Use to exclude asset paths, probes, etc.
		 *
		 * @param bool   $log
		 * @param string $url Home-relative URL.
		 */
		if ( ! apply_filters( 'sm_redirect_manager_log_404', true, $url ) ) {
			return;
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] )
			? mb_substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, Url::MAX_LENGTH ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw sanitizes.
			: '';

		$this->logs->record( $url, $referrer, $userAgent, $this->clientIp() );
	}

	/**
	 * Daily cron: delete entries not seen within the retention window.
	 */
	public function purge(): void {
		$this->logs->purgeOlderThan( $this->settings->int( 'log_retention_days' ) );
	}

	/**
	 * Convert a logged 404 into an exact 3xx/410/451 rule and clear the related log entries.
	 */
	public function convertToRedirect( int $logId, string $target, int $actionCode = 301 ): Redirect|\WP_Error {
		$entry = $this->logs->findById( $logId );
		if ( null === $entry ) {
			return new \WP_Error( 'sm_redirect_manager_not_found', __( '404 log entry not found.', 'sm-redirect-manager' ) );
		}

		$candidate = $this->validator->fromInput(
			array(
				'url_from'       => $entry->path(),
				'url_to'         => $target,
				'match_type'     => MatchType::Exact->value,
				'action_code'    => $actionCode,
				'query_strategy' => QueryStrategy::Ignore->value,
				'status'         => RedirectStatus::Active->value,
			)
		);

		if ( $candidate instanceof \WP_Error ) {
			return $candidate;
		}

		$existing = $this->redirects->findBySource( $candidate->urlFrom, MatchType::Exact );
		if ( null !== $existing ) {
			return new \WP_Error(
				'sm_redirect_manager_duplicate',
				/* translators: %s: source path. */
				sprintf( __( 'A redirect for %s already exists.', 'sm-redirect-manager' ), $candidate->urlFrom )
			);
		}

		$id = $this->redirects->insert( $candidate );
		if ( $id <= 0 ) {
			return new \WP_Error( 'sm_redirect_manager_db', __( 'The redirect could not be saved.', 'sm-redirect-manager' ) );
		}

		$this->logs->deleteByPath( $candidate->urlFrom );

		$redirect = $candidate->withId( $id );

		/**
		 * Fires after a 404 log entry was converted into a redirect.
		 *
		 * @param Redirect $redirect
		 * @param int      $logId
		 */
		do_action( 'sm_redirect_manager_404_converted', $redirect, $logId );

		return $redirect;
	}

	/**
	 * Client IP from REMOTE_ADDR. Behind a trusted proxy/CDN, use the
	 * `sm_redirect_manager_client_ip` filter to read the forwarded header instead.
	 * Anonymised by default (GDPR): last IPv4 octet / last 80 IPv6 bits zeroed.
	 */
	private function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the detected client IP address.
		 *
		 * @param string $ip
		 */
		$ip = (string) apply_filters( 'sm_redirect_manager_client_ip', $ip );

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return $this->settings->bool( 'anonymize_ip' ) ? wp_privacy_anonymize_ip( $ip ) : $ip;
	}
}
