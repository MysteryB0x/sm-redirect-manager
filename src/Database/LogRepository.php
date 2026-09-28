<?php
/**
 * $wpdb-backed 404 log storage with atomic hit aggregation.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Database;

use SEOmarketeer\RedirectManager\Contracts\LogRepositoryInterface;
use SEOmarketeer\RedirectManager\Model\LogEntry;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; write-heavy log, no caching.

defined( 'ABSPATH' ) || exit;

final class LogRepository implements LogRepositoryInterface {

	private const SORTABLE = array( 'id', 'url', 'hit_count', 'created_at', 'last_seen' );

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Single-statement upsert keyed on sha1(lowercased URL): race-free under
	 * concurrent 404 floods and never produces duplicate rows.
	 */
	public function record( string $url, string $referrer, string $userAgent, string $ipAddress ): void {
		$now = current_time( 'mysql', true );

		$this->db->query(
			$this->db->prepare(
				"INSERT INTO {$this->table()}
					(url, url_hash, referrer, user_agent, ip_address, hit_count, created_at, last_seen)
				VALUES (%s, %s, %s, %s, %s, 1, %s, %s)
				ON DUPLICATE KEY UPDATE
					hit_count  = hit_count + 1,
					last_seen  = VALUES(last_seen),
					referrer   = IF(VALUES(referrer) = '', referrer, VALUES(referrer)),
					user_agent = VALUES(user_agent),
					ip_address = VALUES(ip_address)",
				$url,
				sha1( strtolower( $url ) ),
				$referrer,
				$userAgent,
				$ipAddress,
				$now,
				$now
			)
		);
	}

	public function findById( int $id ): ?LogEntry {
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id )
		);

		return is_object( $row ) ? LogEntry::fromRow( $row ) : null;
	}

	public function delete( int $id ): bool {
		return (bool) $this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	public function deleteMany( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $this->db->query(
			$this->db->prepare( "DELETE FROM {$this->table()} WHERE id IN ({$placeholders})", ...$ids )
		);
	}

	public function deleteByPath( string $path ): int {
		return (int) $this->db->query(
			$this->db->prepare(
				"DELETE FROM {$this->table()} WHERE url = %s OR url LIKE %s",
				$path,
				$this->db->esc_like( $path . '?' ) . '%'
			)
		);
	}

	public function truncate(): void {
		$this->db->query( "TRUNCATE TABLE {$this->table()}" );
	}

	public function purgeOlderThan( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return (int) $this->db->query(
			$this->db->prepare( "DELETE FROM {$this->table()} WHERE last_seen < %s", $threshold )
		);
	}

	public function paginate(
		int $page,
		int $perPage,
		string $search = '',
		string $orderBy = 'last_seen',
		string $order = 'DESC'
	): array {
		$whereSql = '1=1';
		$args     = array();

		if ( '' !== $search ) {
			$whereSql = '(url LIKE %s OR referrer LIKE %s)';
			$like     = '%' . $this->db->esc_like( $search ) . '%';
			$args     = array( $like, $like );
		}

		$orderBy = in_array( $orderBy, self::SORTABLE, true ) ? $orderBy : 'last_seen';
		$order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$perPage = max( 1, $perPage );
		$offset  = max( 0, ( $page - 1 ) * $perPage );

		$countSql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$whereSql}";
		$total    = (int) $this->db->get_var( array() === $args ? $countSql : $this->db->prepare( $countSql, ...$args ) );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table()} WHERE {$whereSql} ORDER BY {$orderBy} {$order}, id DESC LIMIT %d OFFSET %d",
				...array_merge( $args, array( $perPage, $offset ) )
			)
		);

		return array(
			'items' => array_values( array_map( LogEntry::fromRow( ... ), is_array( $rows ) ? $rows : array() ) ),
			'total' => $total,
		);
	}

	private function table(): string {
		return $this->db->prefix . 'sm_404_logs';
	}
}
