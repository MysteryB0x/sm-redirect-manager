<?php
/**
 * URL / path normalisation shared by the engine, validator, trackers and admin.
 *
 * Every rule source and every incoming request passes through the same
 * normalisation, so matching is a plain string comparison at runtime.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Support;

use SEOmarketeer\RedirectManager\Enum\QueryStrategy;

defined( 'ABSPATH' ) || exit;

final class Url {

	public const MAX_LENGTH = 2048;

	/**
	 * The current request as a home-relative, normalised path plus the raw query string.
	 *
	 * @return array{path: string, query: string}
	 */
	public static function currentRequest(): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalised below; never output raw.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		// Collapse leading slashes so "//evil.com/x" is never parsed as a host.
		$uri   = '/' . ltrim( $uri, '/' );
		$parts = wp_parse_url( $uri );

		$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$query = is_array( $parts ) && isset( $parts['query'] ) ? (string) $parts['query'] : '';

		return array(
			'path'  => self::stripHomePath( self::normalizePath( $path ) ),
			'query' => $query,
		);
	}

	/**
	 * Decode, strip control characters, collapse slashes, remove trailing slash.
	 */
	public static function normalizePath( string $path ): string {
		$path = rawurldecode( $path );
		$path = wp_check_invalid_utf8( $path, true );
		$path = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		$path = '/' . ltrim( $path, '/' );
		$path = (string) preg_replace( '#/{2,}#', '/', $path );

		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Parameter-order-insensitive, consistently encoded query string.
	 */
	public static function normalizeQuery( string $query ): string {
		$query = ltrim( $query, '?' );
		if ( '' === $query ) {
			return '';
		}

		$args = array();
		wp_parse_str( $query, $args );
		if ( array() === $args ) {
			return '';
		}

		self::ksortRecursive( $args );

		return http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Normalise a user-supplied rule source (exact / wildcard types).
	 *
	 * Accepts a full URL on this site or a path. The query string is only
	 * retained when the strategy requires an exact parameter match.
	 */
	public static function normalizeSource( string $input, QueryStrategy $strategy ): string {
		$input = trim( $input );

		if ( 1 === preg_match( '#^(https?:)?//#i', $input ) ) {
			$parts = wp_parse_url( $input );
			$input = ( is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/' )
				. ( is_array( $parts ) && isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}

		$input          = explode( '#', $input, 2 )[0];
		[ $path, $query ] = array_pad( explode( '?', $input, 2 ), 2, '' );

		$path = self::stripHomePath( self::normalizePath( $path ) );

		if ( QueryStrategy::Exact === $strategy ) {
			$query = self::normalizeQuery( $query );
			return '' !== $query ? $path . '?' . $query : $path;
		}

		return $path;
	}

	/**
	 * Path component of home_url(), without trailing slash ('' for root installs).
	 */
	public static function homePath(): string {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		return is_string( $path ) ? untrailingslashit( $path ) : '';
	}

	/**
	 * Remove the sub-directory install prefix so rules are stored home-relative.
	 */
	public static function stripHomePath( string $path ): string {
		$home = self::homePath();
		if ( '' === $home ) {
			return $path;
		}

		if ( 0 === strcasecmp( $path, $home ) ) {
			return '/';
		}

		if ( str_starts_with( strtolower( $path ), strtolower( $home ) . '/' ) ) {
			return substr( $path, strlen( $home ) );
		}

		return $path;
	}

	/**
	 * Resolve a rule target (relative path or URL) to an absolute URL.
	 */
	public static function toAbsolute( string $target ): string {
		$target = trim( $target );

		if ( '' === $target ) {
			return home_url( '/' );
		}

		if ( str_starts_with( $target, '//' ) ) {
			return set_url_scheme( $target );
		}

		if ( str_starts_with( $target, '/' ) ) {
			return home_url( $target );
		}

		if ( 1 === preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $target ) ) {
			return $target;
		}

		return home_url( '/' . $target );
	}

	/**
	 * If $url points at this site, return its home-relative path and raw query.
	 *
	 * The www. prefix is ignored so www/non-www variants count as the same site.
	 *
	 * @return array{path: string, query: string}|null
	 */
	public static function internalPathQuery( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		if ( isset( $parts['host'] ) ) {
			$homeHost = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			if ( 0 !== strcasecmp( self::bareHost( (string) $parts['host'] ), self::bareHost( $homeHost ) ) ) {
				return null;
			}
		}

		return array(
			'path'  => self::stripHomePath( self::normalizePath( (string) ( $parts['path'] ?? '/' ) ) ),
			'query' => (string) ( $parts['query'] ?? '' ),
		);
	}

	/**
	 * Append request parameters to a target without overriding the target's own parameters.
	 */
	public static function appendQuery( string $target, string $rawQuery ): string {
		$requestArgs = array();
		wp_parse_str( ltrim( $rawQuery, '?' ), $requestArgs );
		if ( array() === $requestArgs ) {
			return $target;
		}

		$targetArgs  = array();
		$targetQuery = wp_parse_url( $target, PHP_URL_QUERY );
		if ( is_string( $targetQuery ) && '' !== $targetQuery ) {
			wp_parse_str( $targetQuery, $targetArgs );
		}

		$extra = array_diff_key( $requestArgs, $targetArgs );
		if ( array() === $extra ) {
			return $target;
		}

		return add_query_arg( urlencode_deep( $extra ), $target );
	}

	/**
	 * Clean free text destined for storage (log fields): valid UTF-8, no tags/control chars, bounded length.
	 */
	public static function cleanText( string $value, int $maxLength = self::MAX_LENGTH ): string {
		$value = wp_check_invalid_utf8( $value, true );
		$value = wp_strip_all_tags( $value, true );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );

		return mb_substr( trim( $value ), 0, $maxLength );
	}

	private static function bareHost( string $host ): string {
		$host = strtolower( $host );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * @param array<array-key, mixed> $array
	 */
	private static function ksortRecursive( array &$array ): void {
		ksort( $array );
		foreach ( $array as &$value ) {
			if ( is_array( $value ) ) {
				self::ksortRecursive( $value );
			}
		}
	}
}
