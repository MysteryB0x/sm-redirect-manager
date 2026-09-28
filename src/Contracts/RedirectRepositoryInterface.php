<?php
/**
 * Persistence contract for redirect rules.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Contracts;

use SEOmarketeer\RedirectManager\Enum\MatchType;
use SEOmarketeer\RedirectManager\Enum\RedirectStatus;
use SEOmarketeer\RedirectManager\Model\Redirect;

defined( 'ABSPATH' ) || exit;

interface RedirectRepositoryInterface {

	public function findById( int $id ): ?Redirect;

	/**
	 * Active exact-match rules whose source equals one of the candidates.
	 *
	 * @param list<string> $candidates Normalised source strings.
	 * @return list<Redirect>
	 */
	public function findActiveExact( array $candidates ): array;

	/**
	 * All active regex and wildcard rules, in evaluation order.
	 *
	 * @return list<Redirect>
	 */
	public function getActivePatterns(): array;

	public function findBySource( string $urlFrom, MatchType $matchType ): ?Redirect;

	public function insert( Redirect $redirect ): int;

	public function update( Redirect $redirect ): bool;

	public function delete( int $id ): bool;

	/**
	 * @param list<int> $ids
	 */
	public function deleteMany( array $ids ): int;

	public function setStatus( int $id, RedirectStatus $status ): bool;

	/**
	 * @param list<int> $ids
	 */
	public function setStatusMany( array $ids, RedirectStatus $status ): int;

	/**
	 * Point exact rules that target any of $oldTargets at $newTarget (chain flattening).
	 *
	 * @param list<string> $oldTargets
	 * @param list<string> $excludeSources Rules with these sources are left untouched (prevents self-targets).
	 */
	public function retarget( array $oldTargets, string $newTarget, array $excludeSources = array() ): int;

	public function recordHit( int $id ): void;

	/**
	 * @return array{items: list<Redirect>, total: int}
	 */
	public function paginate(
		int $page,
		int $perPage,
		string $search = '',
		string $orderBy = 'id',
		string $order = 'DESC',
		?RedirectStatus $status = null
	): array;

	/**
	 * @return array<string, int> Keyed by status value plus 'all'.
	 */
	public function countByStatus(): array;

	/**
	 * Memory-safe iteration over every rule (used by CSV export).
	 *
	 * @return \Generator<int, Redirect>
	 */
	public function iterateAll( int $batchSize = 500 ): \Generator;
}
