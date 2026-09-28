<?php
/**
 * Creates 301 redirects automatically when a published post's URL changes
 * (slug edit, parent change for hierarchical types).
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Hooks;

use SEOmarketeer\RedirectManager\Contracts\HookableInterface;
use SEOmarketeer\RedirectManager\Contracts\RedirectRepositoryInterface;
use SEOmarketeer\RedirectManager\Enum\MatchType;
use SEOmarketeer\RedirectManager\Enum\QueryStrategy;
use SEOmarketeer\RedirectManager\Enum\RedirectStatus;
use SEOmarketeer\RedirectManager\Enum\StatusCode;
use SEOmarketeer\RedirectManager\Model\Redirect;
use SEOmarketeer\RedirectManager\Support\Settings;
use SEOmarketeer\RedirectManager\Support\Url;

defined( 'ABSPATH' ) || exit;

final class SlugMonitor implements HookableInterface {

	public function __construct(
		private readonly RedirectRepositoryInterface $repository,
		private readonly Settings $settings,
	) {}

	public function register(): void {
		if ( ! $this->settings->bool( 'auto_slug_redirects' ) ) {
			return;
		}

		add_action( 'post_updated', array( $this, 'onPostUpdated' ), 10, 3 );
	}

	/**
	 * `post_updated` fires after the row is written, with full before/after objects.
	 */
	public function onPostUpdated( int $postId, \WP_Post $after, \WP_Post $before ): void {
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}

		// Only URLs that were live before and are still live now.
		if ( 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}

		if ( ! is_post_type_viewable( $after->post_type ) ) {
			return;
		}

		/**
		 * Whether to create automatic redirects for this post.
		 *
		 * @param bool     $track
		 * @param string   $postType
		 * @param \WP_Post $post
		 */
		if ( ! apply_filters( 'sm_redirect_manager_track_slug_change', true, $after->post_type, $after ) ) {
			return;
		}

		$oldUrl = get_permalink( $before );
		$newUrl = get_permalink( $after );

		if ( ! is_string( $oldUrl ) || ! is_string( $newUrl ) || $oldUrl === $newUrl ) {
			return;
		}

		$old = Url::internalPathQuery( $oldUrl );
		$new = Url::internalPathQuery( $newUrl );

		// Plain permalinks (?p=123) have no path to redirect from.
		if ( null === $old || null === $new || '/' === $old['path'] ) {
			return;
		}

		if ( 0 === strcasecmp( $old['path'], $new['path'] ) ) {
			return;
		}

		// Target keeps the permalink's canonical trailing slash to avoid an extra canonical hop.
		$newTarget = (string) wp_parse_url( $newUrl, PHP_URL_PATH );
		$newTarget = Url::stripHomePath( '' !== $newTarget ? $newTarget : '/' );

		$this->createRedirect( $old['path'], $new['path'], $newTarget, MatchType::Exact, $oldUrl );

		// Descendants of hierarchical content move too: one wildcard rule covers them all.
		if ( is_post_type_hierarchical( $after->post_type ) && $this->hasChildren( $postId, $after->post_type ) ) {
			$this->createRedirect(
				$old['path'] . '/*',
				$new['path'] . '/*',
				// Keep the permalink structure's trailing slash so children need no extra canonical hop.
				untrailingslashit( $newTarget ) . '/$1' . ( str_ends_with( $newTarget, '/' ) ? '/' : '' ),
				MatchType::Wildcard,
				''
			);
		}
	}

	/**
	 * @param string $from      Normalised old path (or wildcard pattern).
	 * @param string $newSource Normalised new path (used for conflict resolution).
	 * @param string $target    Stored target.
	 * @param string $oldUrl    Old absolute URL (used for chain flattening).
	 */
	private function createRedirect( string $from, string $newSource, string $target, MatchType $type, string $oldUrl ): void {
		// 1. The new URL now serves live content: disable rules that would hijack it (prevents A→B→A loops).
		$conflict = $this->repository->findBySource( $newSource, $type );
		if ( null !== $conflict && RedirectStatus::Active === $conflict->status ) {
			$this->repository->setStatus( $conflict->id, RedirectStatus::Inactive );
		}

		// 2. Flatten chains: anything that pointed at the old URL now points straight at the new one.
		if ( MatchType::Exact === $type ) {
			$this->repository->retarget(
				array( $from, trailingslashit( $from ), $oldUrl ),
				$target,
				array( $from, $newSource )
			);
		}

		// 3. Upsert old => new.
		$existing = $this->repository->findBySource( $from, $type );
		$redirect = new Redirect(
			urlFrom: $from,
			urlTo: $target,
			matchType: $type,
			actionCode: StatusCode::MovedPermanently,
			queryStrategy: QueryStrategy::Pass,
			status: RedirectStatus::Active,
			id: null !== $existing ? $existing->id : 0,
		);

		$id = null !== $existing
			? ( $this->repository->update( $redirect ) ? $existing->id : 0 )
			: $this->repository->insert( $redirect );

		if ( $id > 0 ) {
			/**
			 * Fires after an automatic slug-change redirect was created or updated.
			 *
			 * @param Redirect $redirect
			 */
			do_action( 'sm_redirect_manager_slug_redirect_saved', $redirect->withId( $id ) );
		}
	}

	private function hasChildren( int $postId, string $postType ): bool {
		$children = get_posts(
			array(
				'post_type'        => $postType,
				'post_parent'      => $postId,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
			)
		);

		return array() !== $children;
	}
}
