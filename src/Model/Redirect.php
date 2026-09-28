<?php
/**
 * Immutable redirect rule value object.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Model;

use SEOmarketeer\RedirectManager\Enum\MatchType;
use SEOmarketeer\RedirectManager\Enum\QueryStrategy;
use SEOmarketeer\RedirectManager\Enum\RedirectStatus;
use SEOmarketeer\RedirectManager\Enum\StatusCode;

defined( 'ABSPATH' ) || exit;

final class Redirect {

	public function __construct(
		public readonly string $urlFrom,
		public readonly string $urlTo,
		public readonly MatchType $matchType = MatchType::Exact,
		public readonly StatusCode $actionCode = StatusCode::MovedPermanently,
		public readonly QueryStrategy $queryStrategy = QueryStrategy::Ignore,
		public readonly RedirectStatus $status = RedirectStatus::Active,
		public readonly int $id = 0,
		public readonly int $hits = 0,
		public readonly ?string $createdAt = null,
		public readonly ?string $lastAccessed = null,
	) {}

	/**
	 * Hydrate from a database row. Unknown enum values fall back to safe defaults.
	 */
	public static function fromRow( object $row ): self {
		return new self(
			urlFrom: (string) ( $row->url_from ?? '' ),
			urlTo: (string) ( $row->url_to ?? '' ),
			matchType: MatchType::tryFrom( (string) ( $row->match_type ?? '' ) ) ?? MatchType::Exact,
			actionCode: StatusCode::tryFrom( (int) ( $row->action_code ?? 301 ) ) ?? StatusCode::MovedPermanently,
			queryStrategy: QueryStrategy::tryFrom( (string) ( $row->query_strategy ?? '' ) ) ?? QueryStrategy::Ignore,
			status: RedirectStatus::tryFrom( (string) ( $row->status ?? '' ) ) ?? RedirectStatus::Inactive,
			id: (int) ( $row->id ?? 0 ),
			hits: (int) ( $row->hits ?? 0 ),
			createdAt: isset( $row->created_at ) ? (string) $row->created_at : null,
			lastAccessed: isset( $row->last_accessed ) ? (string) $row->last_accessed : null,
		);
	}

	public function withId( int $id ): self {
		return new self(
			$this->urlFrom,
			$this->urlTo,
			$this->matchType,
			$this->actionCode,
			$this->queryStrategy,
			$this->status,
			$id,
			$this->hits,
			$this->createdAt,
			$this->lastAccessed
		);
	}

	/**
	 * Column => value map of the user-editable fields.
	 *
	 * @return array{url_from: string, url_to: string, match_type: string, action_code: int, query_strategy: string, status: string}
	 */
	public function toRow(): array {
		return array(
			'url_from'       => $this->urlFrom,
			'url_to'         => $this->urlTo,
			'match_type'     => $this->matchType->value,
			'action_code'    => $this->actionCode->value,
			'query_strategy' => $this->queryStrategy->value,
			'status'         => $this->status->value,
		);
	}
}
