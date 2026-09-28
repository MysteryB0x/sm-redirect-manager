<?php
/**
 * Turns untrusted input (admin form, CSV row, AJAX) into a valid Redirect.
 *
 * Sanitisation notes:
 * - Enum fields: sanitize_text_field() + strict enum lookup (whitelist).
 * - Targets: esc_url_raw() restricted to http/https, or a site-relative path.
 * - Sources: NOT passed through sanitize_text_field(), because it strips
 *   percent-encoded octets and "<" which legitimately occur in paths and
 *   regex (e.g. lookbehinds "(?<=…)"). Instead sources are UTF-8 validated,
 *   stripped of control characters, length-limited and normalised; regexes
 *   are compiled to prove validity. All output is escaped at render time.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Service;

use SEOmarketeer\RedirectManager\Engine\RuleMatcher;
use SEOmarketeer\RedirectManager\Enum\MatchType;
use SEOmarketeer\RedirectManager\Enum\QueryStrategy;
use SEOmarketeer\RedirectManager\Enum\RedirectStatus;
use SEOmarketeer\RedirectManager\Enum\StatusCode;
use SEOmarketeer\RedirectManager\Model\Redirect;
use SEOmarketeer\RedirectManager\Support\Url;

defined( 'ABSPATH' ) || exit;

final class RedirectValidator {

	/**
	 * @param array<string, mixed> $input Unslashed raw input.
	 */
	public function fromInput( array $input, int $id = 0 ): Redirect|\WP_Error {
		$rawType = $this->enumValue( $input['match_type'] ?? MatchType::Exact->value );
		if ( 'auto' === $rawType ) {
			$rawType = self::detectMatchType( (string) ( $input['url_from'] ?? '' ) )->value;
		}

		$matchType = MatchType::tryFrom( $rawType );
		if ( null === $matchType ) {
			return new \WP_Error( 'sm_redirect_manager_match_type', __( 'Invalid match type.', 'sm-redirect-manager' ) );
		}

		$code = StatusCode::tryFrom( (int) $this->enumValue( $input['action_code'] ?? StatusCode::MovedPermanently->value ) );
		if ( null === $code ) {
			return new \WP_Error( 'sm_redirect_manager_action_code', __( 'Invalid HTTP status code.', 'sm-redirect-manager' ) );
		}

		$strategy = QueryStrategy::tryFrom( $this->enumValue( $input['query_strategy'] ?? QueryStrategy::Ignore->value ) );
		if ( null === $strategy ) {
			return new \WP_Error( 'sm_redirect_manager_query_strategy', __( 'Invalid query parameter strategy.', 'sm-redirect-manager' ) );
		}

		$status = RedirectStatus::tryFrom( $this->enumValue( $input['status'] ?? RedirectStatus::Active->value ) );
		if ( null === $status ) {
			return new \WP_Error( 'sm_redirect_manager_status', __( 'Invalid status.', 'sm-redirect-manager' ) );
		}

		$rawFrom = $this->cleanSource( (string) ( $input['url_from'] ?? '' ) );
		if ( '' === $rawFrom ) {
			return new \WP_Error( 'sm_redirect_manager_url_from', __( 'The source URL is required.', 'sm-redirect-manager' ) );
		}

		$from = $this->normalizeFrom( $rawFrom, $matchType, $strategy );
		if ( $from instanceof \WP_Error ) {
			return $from;
		}

		$to = '';
		if ( $code->isRedirect() ) {
			$to = $this->sanitizeTarget( (string) ( $input['url_to'] ?? '' ) );
			if ( '' === $to ) {
				return new \WP_Error( 'sm_redirect_manager_url_to', __( 'A valid target URL (http/https or a path starting with "/") is required for 3xx redirects.', 'sm-redirect-manager' ) );
			}

			if ( MatchType::Exact === $matchType && $this->isSelfRedirect( $from, $to, $strategy ) ) {
				return new \WP_Error( 'sm_redirect_manager_loop', __( 'The target resolves to the source URL, which would create a redirect loop.', 'sm-redirect-manager' ) );
			}
		}

		return new Redirect(
			urlFrom: $from,
			urlTo: $to,
			matchType: $matchType,
			actionCode: $code,
			queryStrategy: $strategy,
			status: $status,
			id: max( 0, $id ),
		);
	}

	/**
	 * Infer the match type from the source (used by Quick Add, match_type = "auto"):
	 * "^…" or "…$" => regex, contains "*" => wildcard, otherwise exact.
	 * Mirrored client-side in admin.js for the live "detected" hint.
	 */
	public static function detectMatchType( string $source ): MatchType {
		$source = trim( $source );

		if ( str_starts_with( $source, '^' ) || ( str_ends_with( $source, '$' ) && ! str_ends_with( $source, '\\$' ) ) ) {
			return MatchType::Regex;
		}

		return str_contains( $source, '*' ) ? MatchType::Wildcard : MatchType::Exact;
	}

	/**
	 * Compile-check a regex and return the PCRE error message, or null if valid.
	 */
	public static function regexError( string $pattern ): ?string {
		$error = null;

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static function ( int $errno, string $message ) use ( &$error ): bool {
				$error = (string) preg_replace( '/^preg_match\(\):\s*/', '', $message );
				return true;
			}
		);

		try {
			$result = preg_match( RuleMatcher::regexFromPattern( $pattern ), '' );
		} finally {
			restore_error_handler();
		}

		if ( false === $result ) {
			return $error ?? preg_last_error_msg();
		}

		return null;
	}

	private function normalizeFrom( string $raw, MatchType $matchType, QueryStrategy $strategy ): string|\WP_Error {
		switch ( $matchType ) {
			case MatchType::Regex:
				$error = self::regexError( $raw );
				if ( null !== $error ) {
					return new \WP_Error(
						'sm_redirect_manager_regex',
						/* translators: %s: PCRE error message. */
						sprintf( __( 'Invalid regular expression: %s', 'sm-redirect-manager' ), $error )
					);
				}
				return $raw;

			case MatchType::Wildcard:
				if ( ! str_contains( $raw, '*' ) ) {
					return new \WP_Error( 'sm_redirect_manager_wildcard', __( 'Wildcard sources must contain at least one "*".', 'sm-redirect-manager' ) );
				}
				// Keep the raw query for wildcards: normalizeQuery() would encode "*".
				[ $path, $query ] = array_pad( explode( '?', $raw, 2 ), 2, '' );
				$path             = Url::normalizeSource( $path, QueryStrategy::Ignore );
				return ( QueryStrategy::Exact === $strategy && '' !== $query ) ? $path . '?' . $query : $path;

			case MatchType::Exact:
			default:
				return Url::normalizeSource( $raw, $strategy );
		}
	}

	private function sanitizeTarget( string $raw ): string {
		$value = trim( wp_check_invalid_utf8( $raw, true ) );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );

		if ( '' === $value ) {
			return '';
		}

		// Site-relative path (keeps $1 backreferences intact).
		if ( str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' ) ) {
			return mb_substr( esc_url_raw( $value ), 0, Url::MAX_LENGTH );
		}

		return mb_substr( esc_url_raw( $value, array( 'http', 'https' ) ), 0, Url::MAX_LENGTH );
	}

	private function isSelfRedirect( string $from, string $to, QueryStrategy $strategy ): bool {
		$internal = Url::internalPathQuery( Url::toAbsolute( $to ) );
		if ( null === $internal ) {
			return false;
		}

		$target = $internal['path'] . ( '' !== $internal['query'] ? '?' . $internal['query'] : '' );

		return 0 === strcasecmp( Url::normalizeSource( $target, $strategy ), $from );
	}

	private function cleanSource( string $value ): string {
		$value = wp_check_invalid_utf8( $value, true );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );

		return mb_substr( trim( $value ), 0, Url::MAX_LENGTH );
	}

	private function enumValue( mixed $value ): string {
		return strtolower( sanitize_text_field( (string) $value ) );
	}
}
