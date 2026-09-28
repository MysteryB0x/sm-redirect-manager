<?php
/**
 * Pure matching logic: request path + query => MatchResult.
 *
 * No headers are sent here, which keeps the matcher reusable for loop
 * simulation, previews and unit tests.
 *
 * Evaluation order:
 *   1. Exact rules (single indexed lookup). A rule with the "exact parameter"
 *      strategy wins over a path-only rule for the same path.
 *   2. Regex and wildcard rules, in ascending ID order; first match wins.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Engine;

use SEOmarketeer\RedirectManager\Contracts\RedirectRepositoryInterface;
use SEOmarketeer\RedirectManager\Enum\MatchType;
use SEOmarketeer\RedirectManager\Enum\QueryStrategy;
use SEOmarketeer\RedirectManager\Model\MatchResult;
use SEOmarketeer\RedirectManager\Model\Redirect;
use SEOmarketeer\RedirectManager\Support\Url;

defined( 'ABSPATH' ) || exit;

final class RuleMatcher {

	/** @var array<int, string|null> Compiled regex per rule ID (null = invalid). */
	private array $compiled = array();

	public function __construct( private readonly RedirectRepositoryInterface $repository ) {}

	/**
	 * @param string $path     Home-relative, normalised path.
	 * @param string $rawQuery Raw query string without leading '?'.
	 */
	public function match( string $path, string $rawQuery ): ?MatchResult {
		$normalizedQuery = Url::normalizeQuery( $rawQuery );

		$exact = $this->matchExact( $path, $normalizedQuery );
		if ( null !== $exact ) {
			return new MatchResult( $exact, $this->buildTarget( $exact, $exact->urlTo, $rawQuery ) );
		}

		foreach ( $this->repository->getActivePatterns() as $rule ) {
			$regex = $this->compile( $rule );
			if ( null === $regex ) {
				continue;
			}

			$subject = ( QueryStrategy::Exact === $rule->queryStrategy && '' !== $rawQuery )
				? $path . '?' . rawurldecode( $rawQuery )
				: $path;

			$matches = array();
			// Suppress warnings from pathological patterns; they simply do not match.
			if ( 1 === @preg_match( $regex, $subject, $matches ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$target = self::substitute( $rule->urlTo, $matches );
				return new MatchResult( $rule, $this->buildTarget( $rule, $target, $rawQuery ) );
			}
		}

		return null;
	}

	/**
	 * Convert a user regex (without delimiters) into a PCRE pattern.
	 */
	public static function regexFromPattern( string $pattern ): string {
		// Escape unescaped delimiter characters.
		$escaped = (string) preg_replace( '/(?<!\\\\)~/', '\\~', $pattern );
		return '~' . $escaped . '~iu';
	}

	/**
	 * Convert a wildcard source ("/blog/*") into an anchored regex; each "*" becomes a capture group.
	 */
	public static function wildcardToRegex( string $pattern ): string {
		$quoted = array_map(
			static fn( string $part ): string => preg_quote( $part, '~' ),
			explode( '*', $pattern )
		);

		return '~^' . implode( '(.*)', $quoted ) . '$~iu';
	}

	/**
	 * Replace $1 / ${1} backreferences in a target with captured groups.
	 *
	 * @param array<int|string, string> $matches
	 */
	public static function substitute( string $target, array $matches ): string {
		if ( ! str_contains( $target, '$' ) ) {
			return $target;
		}

		$result = preg_replace_callback(
			'/\$(\d{1,2})|\$\{(\d{1,2})\}/',
			static function ( array $m ) use ( $matches ): string {
				$index = (int) ( '' !== $m[1] ? $m[1] : $m[2] );
				return isset( $matches[ $index ] ) ? (string) $matches[ $index ] : '';
			},
			$target
		);

		return is_string( $result ) ? $result : $target;
	}

	private function matchExact( string $path, string $normalizedQuery ): ?Redirect {
		$candidates = array( $path );
		if ( '' !== $normalizedQuery ) {
			$candidates[] = $path . '?' . $normalizedQuery;
		}

		$fullRequest = strtolower( '' !== $normalizedQuery ? $path . '?' . $normalizedQuery : $path );
		$pathOnly    = strtolower( $path );
		$fallback    = null;

		foreach ( $this->repository->findActiveExact( $candidates ) as $rule ) {
			$source = strtolower( $rule->urlFrom );

			if ( QueryStrategy::Exact === $rule->queryStrategy ) {
				if ( $source === $fullRequest ) {
					return $rule; // Most specific possible match.
				}
				continue;
			}

			if ( $source === $pathOnly ) {
				$fallback ??= $rule;
			}
		}

		return $fallback;
	}

	private function compile( Redirect $rule ): ?string {
		if ( array_key_exists( $rule->id, $this->compiled ) ) {
			return $this->compiled[ $rule->id ];
		}

		$regex = MatchType::Wildcard === $rule->matchType
			? self::wildcardToRegex( $rule->urlFrom )
			: self::regexFromPattern( $rule->urlFrom );

		// Validate once per request; invalid patterns are skipped, not fatal.
		$valid = false !== @preg_match( $regex, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $this->compiled[ $rule->id ] = $valid ? $regex : null;
	}

	private function buildTarget( Redirect $rule, string $target, string $rawQuery ): string {
		if ( ! $rule->actionCode->isRedirect() ) {
			return '';
		}

		$absolute = Url::toAbsolute( $target );

		if ( QueryStrategy::Pass === $rule->queryStrategy && '' !== $rawQuery ) {
			$absolute = Url::appendQuery( $absolute, $rawQuery );
		}

		return $absolute;
	}
}
