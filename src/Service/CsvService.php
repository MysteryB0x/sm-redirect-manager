<?php
/**
 * CSV import and export of redirect rules.
 *
 * Columns: url_from, url_to, match_type, action_code, query_strategy, status.
 *
 * Import features:
 * - Delimiter auto-detection (comma, semicolon, tab) — handles European Excel exports.
 * - UTF-8 BOM stripping; header names are case-insensitive and common aliases
 *   (source, target, type, code, …) are accepted.
 * - Missing optional columns fall back to defaults (exact / 301 / ignore / active).
 * - Each row is validated by the same RedirectValidator used by the admin form.
 * - Existing sources are skipped or updated depending on the chosen mode.
 * - Runs inside a transaction when the storage engine supports it.
 *
 * Export features:
 * - Streams rows in batches (constant memory), UTF-8 BOM for Excel.
 * - Formula-injection protection: cells starting with = + - @ are prefixed
 *   with an apostrophe (and transparently reversed on import).
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Service;

use SEOmarketeer\RedirectManager\Contracts\RedirectRepositoryInterface;
use SEOmarketeer\RedirectManager\Model\ImportReport;
use SEOmarketeer\RedirectManager\Model\Redirect;

defined( 'ABSPATH' ) || exit;

final class CsvService {

	public const COLUMNS = array( 'url_from', 'url_to', 'match_type', 'action_code', 'query_strategy', 'status' );

	public const DEFAULT_MAX_ROWS = 50000;

	private const BOM = "\xEF\xBB\xBF";

	private const HEADER_ALIASES = array(
		'source'        => 'url_from',
		'from'          => 'url_from',
		'old_url'       => 'url_from',
		'url'           => 'url_from',
		'target'        => 'url_to',
		'to'            => 'url_to',
		'destination'   => 'url_to',
		'new_url'       => 'url_to',
		'type'          => 'match_type',
		'match'         => 'match_type',
		'code'          => 'action_code',
		'status_code'   => 'action_code',
		'http_code'     => 'action_code',
		'redirect_type' => 'action_code',
		'query'         => 'query_strategy',
		'query_params'  => 'query_strategy',
		'enabled'       => 'status',
		'active'        => 'status',
	);

	private const VALUE_ALIASES = array(
		'match_type'     => array(
			'plain'  => 'exact',
			'url'    => 'exact',
			'path'   => 'exact',
			'regexp' => 'regex',
			'wild'   => 'wildcard',
		),
		'query_strategy' => array(
			'ignore_all' => 'ignore',
			'none'       => 'ignore',
			'exact_all'  => 'exact',
			'match'      => 'exact',
			'passthru'   => 'pass',
			'pass_all'   => 'pass',
			'pass-through' => 'pass',
			'passthrough'  => 'pass',
		),
		'status'         => array(
			'1'        => 'active',
			'yes'      => 'active',
			'true'     => 'active',
			'enabled'  => 'active',
			'on'       => 'active',
			'0'        => 'inactive',
			'no'       => 'inactive',
			'false'    => 'inactive',
			'disabled' => 'inactive',
			'off'      => 'inactive',
		),
	);

	public function __construct(
		private readonly RedirectRepositoryInterface $repository,
		private readonly RedirectValidator $validator,
	) {}

	/**
	 * Stream all redirects as a CSV download and terminate the request.
	 */
	public function export(): never {
		$filename = sprintf( 'sm-redirects-%s-%s.csv', sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), gmdate( 'Y-m-d-His' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'wb' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Unable to open the output stream.', 'sm-redirect-manager' ) );
		}

		$this->writeCsv( $out, $this->repository->iterateAll() );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Write the header and the given redirects to a stream (separated from export() for testability).
	 *
	 * @param resource           $stream
	 * @param iterable<Redirect> $redirects
	 */
	public function writeCsv( $stream, iterable $redirects ): int {
		fwrite( $stream, self::BOM ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $stream, self::COLUMNS, ',', '"', '' );

		$count = 0;
		foreach ( $redirects as $redirect ) {
			$row = array_map(
				fn( $value ): string => $this->protectCell( (string) $value ),
				array_values( $redirect->toRow() )
			);
			fputcsv( $stream, $row, ',', '"', '' );
			++$count;
		}

		return $count;
	}

	/**
	 * Import redirects from a CSV file on disk.
	 *
	 * @param string $path           Absolute path to the (uploaded) file.
	 * @param bool   $updateExisting Update rules whose source already exists instead of skipping them.
	 */
	public function import( string $path, bool $updateExisting = false ): ImportReport {
		global $wpdb;

		$report = new ImportReport();
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			$report->addError( __( 'The uploaded file could not be read.', 'sm-redirect-manager' ) );
			return $report;
		}

		try {
			$firstLine = fgets( $handle );
			if ( false === $firstLine || '' === trim( $firstLine ) ) {
				$report->addError( __( 'The CSV file is empty.', 'sm-redirect-manager' ) );
				return $report;
			}

			$firstLine = $this->stripBom( $firstLine );
			$delimiter = $this->detectDelimiter( $firstLine );
			$columnMap = $this->mapHeader( str_getcsv( trim( $firstLine ), $delimiter, '"', '' ) );

			if ( ! isset( $columnMap['url_from'] ) ) {
				$report->addError( __( 'Missing required column "url_from" in the header row.', 'sm-redirect-manager' ) );
				return $report;
			}

			/**
			 * Maximum number of data rows processed per import.
			 *
			 * @param int $maxRows
			 */
			$maxRows = (int) apply_filters( 'sm_redirect_manager_csv_max_rows', self::DEFAULT_MAX_ROWS );

			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			$line = 1;
			$rows = 0;

			while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) {
				++$line;

				if ( array( null ) === $cells || array() === array_filter( $cells, static fn( $c ): bool => null !== $c && '' !== trim( (string) $c ) ) ) {
					continue; // Blank line.
				}

				if ( ++$rows > $maxRows ) {
					/* translators: %d: maximum number of rows. */
					$report->addError( sprintf( __( 'Row limit of %d reached; remaining rows were not processed.', 'sm-redirect-manager' ), $maxRows ) );
					break;
				}

				$this->importRow( $this->extractRow( $cells, $columnMap ), $line, $updateExisting, $report );
			}

			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$report->addError( $e->getMessage() );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		/**
		 * Fires after a CSV import completes.
		 *
		 * @param ImportReport $report
		 */
		do_action( 'sm_redirect_manager_csv_imported', $report );

		return $report;
	}

	/**
	 * @param array<string, string> $data
	 */
	private function importRow( array $data, int $line, bool $updateExisting, ImportReport $report ): void {
		$redirect = $this->validator->fromInput( $data );

		if ( $redirect instanceof \WP_Error ) {
			++$report->skipped;
			/* translators: 1: line number, 2: error message. */
			$report->addError( sprintf( __( 'Line %1$d: %2$s', 'sm-redirect-manager' ), $line, $redirect->get_error_message() ) );
			return;
		}

		$existing = $this->repository->findBySource( $redirect->urlFrom, $redirect->matchType );

		if ( null !== $existing ) {
			if ( ! $updateExisting ) {
				++$report->skipped;
				return;
			}

			if ( $this->repository->update( $redirect->withId( $existing->id ) ) ) {
				++$report->updated;
			} else {
				++$report->skipped;
				/* translators: %d: line number. */
				$report->addError( sprintf( __( 'Line %d: database update failed.', 'sm-redirect-manager' ), $line ) );
			}
			return;
		}

		if ( $this->repository->insert( $redirect ) > 0 ) {
			++$report->imported;
		} else {
			++$report->skipped;
			/* translators: %d: line number. */
			$report->addError( sprintf( __( 'Line %d: database insert failed.', 'sm-redirect-manager' ), $line ) );
		}
	}

	/**
	 * @param array<int, string|null> $header
	 * @return array<string, int> canonical column => cell index
	 */
	private function mapHeader( array $header ): array {
		$map = array();

		foreach ( $header as $index => $name ) {
			$key = strtolower( trim( $this->stripBom( (string) $name ), " \t\n\r\0\x0B\"'" ) );
			$key = str_replace( array( ' ', '-' ), '_', $key );
			$key = self::HEADER_ALIASES[ $key ] ?? $key;

			if ( in_array( $key, self::COLUMNS, true ) && ! isset( $map[ $key ] ) ) {
				$map[ $key ] = (int) $index;
			}
		}

		return $map;
	}

	/**
	 * @param array<int, string|null> $cells
	 * @param array<string, int>      $columnMap
	 * @return array<string, string>
	 */
	private function extractRow( array $cells, array $columnMap ): array {
		$defaults = array(
			'url_from'       => '',
			'url_to'         => '',
			'match_type'     => 'exact',
			'action_code'    => '301',
			'query_strategy' => 'ignore',
			'status'         => 'active',
		);

		$row = $defaults;
		foreach ( $columnMap as $column => $index ) {
			$value = trim( $this->unprotectCell( (string) ( $cells[ $index ] ?? '' ) ) );
			if ( '' === $value ) {
				continue;
			}

			$lower = strtolower( $value );
			if ( isset( self::VALUE_ALIASES[ $column ][ $lower ] ) ) {
				$value = self::VALUE_ALIASES[ $column ][ $lower ];
			}

			$row[ $column ] = $value;
		}

		return $row;
	}

	private function detectDelimiter( string $line ): string {
		$counts = array(
			','  => substr_count( $line, ',' ),
			';'  => substr_count( $line, ';' ),
			"\t" => substr_count( $line, "\t" ),
		);
		arsort( $counts );

		$best = (string) array_key_first( $counts );
		return $counts[ $best ] > 0 ? $best : ',';
	}

	private function stripBom( string $value ): string {
		return str_starts_with( $value, self::BOM ) ? substr( $value, 3 ) : $value;
	}

	/**
	 * Neutralise spreadsheet formula injection.
	 */
	private function protectCell( string $value ): string {
		return 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	private function unprotectCell( string $value ): string {
		return 1 === preg_match( '/^\'[=+\-@\t\r]/', $value ) ? substr( $value, 1 ) : $value;
	}
}
