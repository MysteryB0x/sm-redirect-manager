<?php
/**
 * Persistence contract for 404 log entries.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Contracts;

use SEOmarketeer\RedirectManager\Model\LogEntry;

defined( 'ABSPATH' ) || exit;

interface LogRepositoryInterface {

	/**
	 * Insert a 404 hit, or increment the counter of an existing entry for the same URL.
	 */
	public function record( string $url, string $referrer, string $userAgent, string $ipAddress ): void;

	public function findById( int $id ): ?LogEntry;

	public function delete( int $id ): bool;

	/**
	 * @param list<int> $ids
	 */
	public function deleteMany( array $ids ): int;

	/**
	 * Remove every entry for a path, including variants with a query string.
	 */
	public function deleteByPath( string $path ): int;

	public function truncate(): void;

	public function purgeOlderThan( int $days ): int;

	/**
	 * @return array{items: list<LogEntry>, total: int}
	 */
	public function paginate(
		int $page,
		int $perPage,
		string $search = '',
		string $orderBy = 'last_seen',
		string $order = 'DESC'
	): array;
}
