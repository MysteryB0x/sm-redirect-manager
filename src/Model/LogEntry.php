<?php
/**
 * Immutable 404 log entry.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Model;

defined( 'ABSPATH' ) || exit;

final class LogEntry {

	public function __construct(
		public readonly int $id,
		public readonly string $url,
		public readonly string $referrer,
		public readonly string $userAgent,
		public readonly string $ipAddress,
		public readonly int $hitCount,
		public readonly string $createdAt,
		public readonly string $lastSeen,
	) {}

	public static function fromRow( object $row ): self {
		return new self(
			id: (int) ( $row->id ?? 0 ),
			url: (string) ( $row->url ?? '' ),
			referrer: (string) ( $row->referrer ?? '' ),
			userAgent: (string) ( $row->user_agent ?? '' ),
			ipAddress: (string) ( $row->ip_address ?? '' ),
			hitCount: (int) ( $row->hit_count ?? 0 ),
			createdAt: (string) ( $row->created_at ?? '' ),
			lastSeen: (string) ( $row->last_seen ?? '' ),
		);
	}

	/**
	 * The path portion of the logged URL (without query string).
	 */
	public function path(): string {
		$path = strtok( $this->url, '?' );
		return false === $path || '' === $path ? '/' : $path;
	}
}
