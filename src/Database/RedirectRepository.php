<?php
/**
 * $wpdb-backed redirect storage with object-cache aware lookups.
 *
 * Cache strategy: every lookup key is salted with a "last_changed" stamp in
 * our cache group. Any write deletes the stamp, which atomically invalidates
 * every cached lookup. With a persistent object cache (Redis/Memcached) the
 * front-end hot path needs zero SQL queries for non-redirected URLs.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Database;

use SEOmarketeer\RedirectManager\Contracts\RedirectRepositoryInterface;
use SEOmarketeer\RedirectManager\Enum\MatchType;
use SEOmarketeer\RedirectManager\Enum\RedirectStatus;
use SEOmarketeer\RedirectManager\Model\Redirect;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; caching handled explicitly.

defined( 'ABSPATH' ) || exit;

final class RedirectRepository implements RedirectRepositoryInterface {

	private const CACHE_TTL = HOUR_IN_SECONDS;

	private const SORTABLE = array( 'id', 'url_from', 'url_to', 'action_code', 'hits', 'status', 'created_at', 'last_accessed' );

	public function __construct( private readonly \wpdb $db ) {}

	public function findById( int $id ): ?Redirect {
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id )
		);

		return is_object( $row ) ? Redirect::fromRow( $row ) : null;
	}

	public function findActiveExact( array $candidates ): array {
		$candidates = array_values( array_unique( array_filter( $candidates, 'is_string' ) ) );
		if ( array() === $candidates ) {
			return array();
		}

		$key  = 'exact:' . md5( strtolower( implode( "\n", $candidates ) ) ) . ':' . $this->salt();
		$rows = wp_cache_get( $key, Installer::CACHE_GROUP, false, $found );

		if ( ! $found || ! is_array( $rows ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );
			$rows         = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->table()} WHERE status = %s AND match_type = %s AND url_from IN ({$placeholders}) ORDER BY id ASC",
					RedirectStatus::Active->value,
					MatchType::Exact->value,
					...$candidates
				)
			);
			$rows = is_array( $rows ) ? $rows : array();
			// Negative results are cached too: a miss is the common case.
			wp_cache_set( $key, $rows, Installer::CACHE_GROUP, self::CACHE_TTL );
		}

		return array_values( array_map( Redirect::fromRow( ... ), $rows ) );
	}

	public function getActivePatterns(): array {
		$key  = 'patterns:' . $this->salt();
		$rows = wp_cache_get( $key, Installer::CACHE_GROUP, false, $found );

		if ( ! $found || ! is_array( $rows ) ) {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->table()} WHERE status = %s AND match_type IN (%s, %s) ORDER BY id ASC",
					RedirectStatus::Active->value,
					MatchType::Regex->value,
					MatchType::Wildcard->value
				)
			);
			$rows = is_array( $rows ) ? $rows : array();
			wp_cache_set( $key, $rows, Installer::CACHE_GROUP, self::CACHE_TTL );
		}

		return array_values( array_map( Redirect::fromRow( ... ), $rows ) );
	}

	public function findBySource( string $urlFrom, MatchType $matchType ): ?Redirect {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table()} WHERE url_from = %s AND match_type = %s ORDER BY id ASC LIMIT 1",
				$urlFrom,
				$matchType->value
			)
		);

		return is_object( $row ) ? Redirect::fromRow( $row ) : null;
	}

	public function insert( Redirect $redirect ): int {
		$data               = $redirect->toRow();
		$data['hits']       = 0;
		$data['created_at'] = current_time( 'mysql', true );

		$result = $this->db->insert(
			$this->table(),
			$data,
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( false === $result ) {
			return 0;
		}

		$this->flush();
		return (int) $this->db->insert_id;
	}

	public function update( Redirect $redirect ): bool {
		if ( $redirect->id <= 0 ) {
			return false;
		}

		$result = $this->db->update(
			$this->table(),
			$redirect->toRow(),
			array( 'id' => $redirect->id ),
			array( '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		$this->flush();
		return false !== $result;
	}

	public function delete( int $id ): bool {
		$result = $this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		$this->flush();
		return (bool) $result;
	}

	public function deleteMany( array $ids ): int {
		$ids = $this->cleanIds( $ids );
		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$result       = $this->db->query(
			$this->db->prepare( "DELETE FROM {$this->table()} WHERE id IN ({$placeholders})", ...$ids )
		);

		$this->flush();
		return (int) $result;
	}

	public function setStatus( int $id, RedirectStatus $status ): bool {
		$result = $this->db->update(
			$this->table(),
			array( 'status' => $status->value ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->flush();
		return false !== $result;
	}

	public function setStatusMany( array $ids, RedirectStatus $status ): int {
		$ids = $this->cleanIds( $ids );
		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$result       = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table()} SET status = %s WHERE id IN ({$placeholders})",
				$status->value,
				...$ids
			)
		);

		$this->flush();
		return (int) $result;
	}

	public function retarget( array $oldTargets, string $newTarget, array $excludeSources = array() ): int {
		$oldTargets = array_values( array_unique( array_filter( $oldTargets, static fn( $t ): bool => is_string( $t ) && '' !== $t ) ) );
		if ( array() === $oldTargets ) {
			return 0;
		}

		$excludeSources = array_values( array_unique( array_filter( $excludeSources, 'is_string' ) ) );
		$excludeSources = array() === $excludeSources ? array( '' ) : $excludeSources;

		$targetPlaceholders  = implode( ',', array_fill( 0, count( $oldTargets ), '%s' ) );
		$excludePlaceholders = implode( ',', array_fill( 0, count( $excludeSources ), '%s' ) );

		$result = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table()} SET url_to = %s WHERE match_type = %s AND url_from NOT IN ({$excludePlaceholders}) AND url_to IN ({$targetPlaceholders})",
				$newTarget,
				MatchType::Exact->value,
				...array_merge( $excludeSources, $oldTargets )
			)
		);

		$this->flush();
		return (int) $result;
	}

	public function recordHit( int $id ): void {
		// Hit counters do not affect matching, so the cache is intentionally not flushed.
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table()} SET hits = hits + 1, last_accessed = %s WHERE id = %d",
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	public function paginate(
		int $page,
		int $perPage,
		string $search = '',
		string $orderBy = 'id',
		string $order = 'DESC',
		?RedirectStatus $status = null
	): array {
		$where = array( '1=1' );
		$args  = array();

		if ( null !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status->value;
		}

		if ( '' !== $search ) {
			$like    = '%' . $this->db->esc_like( $search ) . '%';
			$where[] = '(url_from LIKE %s OR url_to LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}

		$whereSql = implode( ' AND ', $where );
		$orderBy  = in_array( $orderBy, self::SORTABLE, true ) ? $orderBy : 'id';
		$order    = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$perPage  = max( 1, $perPage );
		$offset   = max( 0, ( $page - 1 ) * $perPage );

		$countSql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$whereSql}";
		$total    = (int) $this->db->get_var( array() === $args ? $countSql : $this->db->prepare( $countSql, ...$args ) );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table()} WHERE {$whereSql} ORDER BY {$orderBy} {$order}, id DESC LIMIT %d OFFSET %d",
				...array_merge( $args, array( $perPage, $offset ) )
			)
		);

		return array(
			'items' => array_values( array_map( Redirect::fromRow( ... ), is_array( $rows ) ? $rows : array() ) ),
			'total' => $total,
		);
	}

	public function countByStatus(): array {
		$rows   = $this->db->get_results( "SELECT status, COUNT(*) AS total FROM {$this->table()} GROUP BY status" );
		$counts = array( 'all' => 0 );

		foreach ( RedirectStatus::cases() as $case ) {
			$counts[ $case->value ] = 0;
		}

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row->status ] = (int) $row->total;
			$counts['all']                  += (int) $row->total;
		}

		return $counts;
	}

	public function iterateAll( int $batchSize = 500 ): \Generator {
		$lastId    = 0;
		$batchSize = max( 1, $batchSize );

		do {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->table()} WHERE id > %d ORDER BY id ASC LIMIT %d",
					$lastId,
					$batchSize
				)
			);
			$rows = is_array( $rows ) ? $rows : array();

			foreach ( $rows as $row ) {
				$redirect = Redirect::fromRow( $row );
				$lastId   = $redirect->id;
				yield $redirect;
			}
		} while ( count( $rows ) === $batchSize );
	}

	private function table(): string {
		return $this->db->prefix . 'sm_redirects';
	}

	private function salt(): string {
		return (string) wp_cache_get_last_changed( Installer::CACHE_GROUP );
	}

	private function flush(): void {
		wp_cache_delete( 'last_changed', Installer::CACHE_GROUP );
	}

	/**
	 * @param array<mixed> $ids
	 * @return list<int>
	 */
	private function cleanIds( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}
}
