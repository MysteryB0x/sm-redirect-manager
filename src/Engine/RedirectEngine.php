<?php
/**
 * Front-end redirect execution.
 *
 * Runs on `init` (priority 0): after plugins/theme are loaded but before
 * WP::main() parses the request and runs the main query, so a matched
 * redirect costs one cached lookup and no WP_Query.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Engine;

use SEOmarketeer\RedirectManager\Contracts\HookableInterface;
use SEOmarketeer\RedirectManager\Contracts\RedirectRepositoryInterface;
use SEOmarketeer\RedirectManager\Model\MatchResult;
use SEOmarketeer\RedirectManager\Support\Url;

defined( 'ABSPATH' ) || exit;

final class RedirectEngine implements HookableInterface {

	public const X_REDIRECT_BY = 'SEOmarketeer Redirect Manager';

	/** Maximum internal hops simulated when checking for redirect loops. */
	public const MAX_CHAIN_DEPTH = 10;

	public function __construct(
		private readonly RuleMatcher $matcher,
		private readonly RedirectRepositoryInterface $repository,
	) {}

	public function register(): void {
		/**
		 * Filters the priority of the redirect engine on `init`.
		 *
		 * @param int $priority Default 0.
		 */
		$priority = (int) apply_filters( 'sm_redirect_manager_engine_priority', 0 );

		add_action( 'init', array( $this, 'handle' ), $priority );
	}

	public function handle(): void {
		$request = Url::currentRequest();

		if ( ! $this->shouldRun( $request['path'] ) ) {
			return;
		}

		$result = $this->matcher->match( $request['path'], $request['query'] );

		/**
		 * Filters the match result before it is executed. Return null to skip.
		 *
		 * @param MatchResult|null                   $result
		 * @param array{path: string, query: string} $request
		 */
		$result = apply_filters( 'sm_redirect_manager_match_result', $result, $request );

		if ( ! $result instanceof MatchResult ) {
			return;
		}

		if ( $result->redirect->actionCode->isRedirect() ) {
			if ( $this->createsLoop( $request, $result ) ) {
				/**
				 * Fires when a matched rule was skipped because it would cause a redirect loop.
				 *
				 * @param MatchResult                        $result
				 * @param array{path: string, query: string} $request
				 */
				do_action( 'sm_redirect_manager_loop_detected', $result, $request );
				return;
			}

			$this->deliverRedirect( $result );
			return;
		}

		$this->deliverStatus( $result );
	}

	/**
	 * Cheap guards that exclude admin, API, CLI and non-idempotent requests.
	 */
	private function shouldRun( string $path ): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return false;
		}

		$restPrefix = '/' . trim( rest_get_url_prefix(), '/' );
		if ( $path === $restPrefix || str_starts_with( $path, $restPrefix . '/' ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		/**
		 * HTTP methods the engine acts on. Default: GET and HEAD.
		 *
		 * @param list<string> $methods
		 */
		$allowed = (array) apply_filters( 'sm_redirect_manager_allowed_methods', array( 'GET', 'HEAD' ) );

		if ( ! in_array( $method, $allowed, true ) ) {
			return false;
		}

		/**
		 * Master switch for the redirect engine on the current request.
		 *
		 * @param bool   $run
		 * @param string $path
		 */
		return (bool) apply_filters( 'sm_redirect_manager_should_redirect', true, $path );
	}

	/**
	 * Simulate the redirect chain the visitor would follow and abort if it
	 * revisits a URL (self-reference, A→B→A, or query-string ping-pong) or
	 * exceeds MAX_CHAIN_DEPTH hops.
	 *
	 * @param array{path: string, query: string} $request
	 */
	private function createsLoop( array $request, MatchResult $result ): bool {
		$visited = array( $this->key( $request['path'], $request['query'] ) => true );
		$current = $result;

		for ( $depth = 0; $depth < self::MAX_CHAIN_DEPTH; $depth++ ) {
			$internal = Url::internalPathQuery( $current->target );
			if ( null === $internal ) {
				return false; // Leaves the site: chain terminates.
			}

			$key = $this->key( $internal['path'], $internal['query'] );
			if ( isset( $visited[ $key ] ) ) {
				return true;
			}
			$visited[ $key ] = true;

			$next = $this->matcher->match( $internal['path'], $internal['query'] );
			if ( null === $next || ! $next->redirect->actionCode->isRedirect() ) {
				return false; // Lands on real content (or a 410/451).
			}

			$current = $next;
		}

		return true; // Chain too long: treat as a loop.
	}

	private function key( string $path, string $query ): string {
		return strtolower( $path ) . '?' . Url::normalizeQuery( $query );
	}

	private function deliverRedirect( MatchResult $result ): void {
		$code = $result->redirect->actionCode;

		/**
		 * Filters the final Location header.
		 *
		 * @param string      $location
		 * @param MatchResult $result
		 */
		$location = (string) apply_filters( 'sm_redirect_manager_redirect_location', $result->target, $result );

		if ( $code->isTemporary() ) {
			nocache_headers();
		}

		$this->scheduleHit( $result->redirect->id );

		// wp_redirect (not wp_safe_redirect): admins may deliberately redirect off-site.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		if ( wp_redirect( $location, $code->value, self::X_REDIRECT_BY ) ) {
			exit;
		}
	}

	/**
	 * 410 Gone / 451 Unavailable For Legal Reasons.
	 *
	 * A theme template named "410.php" / "451.php" is rendered on
	 * template_redirect (after the main query, so template tags work).
	 * Otherwise a minimal wp_die() page is returned immediately.
	 */
	private function deliverStatus( MatchResult $result ): void {
		$code = $result->redirect->actionCode->value;
		$this->scheduleHit( $result->redirect->id );

		do_action( 'sm_redirect_manager_deliver_status', $code, $result );

		$template = locate_template( array( "{$code}.php" ) );

		if ( '' !== $template ) {
			add_action(
				'template_redirect',
				static function () use ( $code, $template ): void {
					status_header( $code );
					nocache_headers();
					include $template;
					exit;
				},
				0
			);
			return;
		}

		nocache_headers();

		$message = 410 === $code
			? __( 'The requested content has been permanently removed.', 'sm-redirect-manager' )
			: __( 'This content is unavailable for legal reasons.', 'sm-redirect-manager' );

		$title = 410 === $code
			? __( 'Gone', 'sm-redirect-manager' )
			: __( 'Unavailable For Legal Reasons', 'sm-redirect-manager' );

		wp_die( esc_html( $message ), esc_html( $title ), array( 'response' => (int) $code ) );
	}

	/**
	 * Record the hit on shutdown, after the response has been flushed to the
	 * client where the SAPI supports it (PHP-FPM), so it adds no latency.
	 */
	private function scheduleHit( int $redirectId ): void {
		if ( $redirectId <= 0 ) {
			return;
		}

		$repository = $this->repository;

		add_action(
			'shutdown',
			static function () use ( $repository, $redirectId ): void {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}
				$repository->recordHit( $redirectId );
			},
			0
		);
	}
}
