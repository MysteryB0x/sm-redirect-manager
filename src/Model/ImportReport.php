<?php
/**
 * Summary of a CSV import run.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Model;

defined( 'ABSPATH' ) || exit;

final class ImportReport {

	public const MAX_REPORTED_ERRORS = 25;

	public int $imported = 0;
	public int $updated  = 0;
	public int $skipped  = 0;

	/** @var list<string> */
	private array $errors = array();

	private int $errorCount = 0;

	public function addError( string $message ): void {
		++$this->errorCount;
		if ( count( $this->errors ) < self::MAX_REPORTED_ERRORS ) {
			$this->errors[] = $message;
		}
	}

	/**
	 * @return list<string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	public function errorCount(): int {
		return $this->errorCount;
	}

	public function hasFatalError(): bool {
		return 0 === $this->imported + $this->updated + $this->skipped && $this->errorCount > 0;
	}
}
