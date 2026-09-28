<?php
/**
 * Redirects admin table.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Admin;

use SEOmarketeer\RedirectManager\Contracts\RedirectRepositoryInterface;
use SEOmarketeer\RedirectManager\Enum\RedirectStatus;
use SEOmarketeer\RedirectManager\Model\Redirect;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \WP_List_Table::class, false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// Parameter types are intentionally omitted on overridden methods to stay signature-compatible with WP_List_Table.
final class RedirectsListTable extends \WP_List_Table {

	public const SCREEN_ID = 'tools_page_sm-redirect-manager';

	public function __construct( private readonly RedirectRepositoryInterface $repository ) {
		parent::__construct(
			array(
				'singular' => 'redirect',
				'plural'   => 'redirects',
				'ajax'     => false,
				// Explicit screen so the table can also be built inside admin-ajax.php (Quick Add).
				'screen'   => self::SCREEN_ID,
			)
		);
	}

	/**
	 * Render a single <tr> for a redirect (used to inject Quick Add results without a reload).
	 */
	public function renderRow( Redirect $redirect ): string {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'url_from' );

		ob_start();
		$this->single_row( $redirect );
		return (string) ob_get_clean();
	}

	public function get_columns(): array {
		return array(
			'cb'             => '<input type="checkbox" />',
			'url_from'       => __( 'Source', 'sm-redirect-manager' ),
			'url_to'         => __( 'Target', 'sm-redirect-manager' ),
			'action_code'    => __( 'Code', 'sm-redirect-manager' ),
			'match_type'     => __( 'Match', 'sm-redirect-manager' ),
			'query_strategy' => __( 'Query', 'sm-redirect-manager' ),
			'hits'           => __( 'Hits', 'sm-redirect-manager' ),
			'status'         => __( 'Status', 'sm-redirect-manager' ),
			'last_accessed'  => __( 'Last hit', 'sm-redirect-manager' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'url_from'      => array( 'url_from', false ),
			'url_to'        => array( 'url_to', false ),
			'action_code'   => array( 'action_code', false ),
			'hits'          => array( 'hits', true ),
			'status'        => array( 'status', false ),
			'last_accessed' => array( 'last_accessed', true ),
		);
	}

	protected function get_bulk_actions(): array {
		return array(
			'activate'   => __( 'Activate', 'sm-redirect-manager' ),
			'deactivate' => __( 'Deactivate', 'sm-redirect-manager' ),
			'delete'     => __( 'Delete', 'sm-redirect-manager' ),
		);
	}

	protected function get_views(): array {
		$counts  = $this->repository->countByStatus();
		$current = $this->currentStatus();
		$base    = AdminController::pageUrl( 'redirects' );

		$views = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $base ),
				null === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'sm-redirect-manager' ),
				esc_html( number_format_i18n( $counts['all'] ?? 0 ) )
			),
		);

		foreach ( RedirectStatus::cases() as $status ) {
			$views[ $status->value ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'status', $status->value, $base ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $status->label() ),
				esc_html( number_format_i18n( $counts[ $status->value ] ?? 0 ) )
			);
		}

		return $views;
	}

	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list parameters.
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderBy = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'id';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		// phpcs:enable

		$perPage = $this->get_items_per_page( AdminController::PER_PAGE_OPTION, 20 );
		$result  = $this->repository->paginate(
			$this->get_pagenum(),
			$perPage,
			$search,
			$orderBy,
			$order,
			$this->currentStatus()
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'url_from' );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $perPage,
				'total_pages' => (int) ceil( $result['total'] / $perPage ),
			)
		);
	}

	public function no_items(): void {
		esc_html_e( 'No redirects found.', 'sm-redirect-manager' );
	}

	/**
	 * @param Redirect $item
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="sm-rm-cb-%1$d">%2$s</label><input type="checkbox" id="sm-rm-cb-%1$d" name="ids[]" value="%1$d" />',
			$item->id,
			esc_html__( 'Select redirect', 'sm-redirect-manager' )
		);
	}

	/**
	 * @param Redirect $item
	 */
	protected function column_url_from( $item ): string {
		$editUrl = AdminController::pageUrl(
			'redirects',
			array(
				'view' => 'edit',
				'id'   => $item->id,
			)
		);

		$toggleLabel = RedirectStatus::Active === $item->status
			? __( 'Deactivate', 'sm-redirect-manager' )
			: __( 'Activate', 'sm-redirect-manager' );

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $editUrl ), esc_html__( 'Edit', 'sm-redirect-manager' ) ),
			'toggle' => sprintf( '<a href="#" class="sm-rm-toggle" data-id="%d">%s</a>', $item->id, esc_html( $toggleLabel ) ),
			'delete' => sprintf( '<a href="#" class="sm-rm-delete submitdelete" data-id="%d">%s</a>', $item->id, esc_html__( 'Delete', 'sm-redirect-manager' ) ),
		);

		return sprintf(
			'<strong><a class="row-title" href="%s"><code>%s</code></a></strong>%s',
			esc_url( $editUrl ),
			esc_html( $item->urlFrom ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * @param Redirect $item
	 */
	protected function column_url_to( $item ): string {
		if ( ! $item->actionCode->isRedirect() ) {
			return '<span class="description">&mdash;</span>';
		}

		return sprintf( '<code class="sm-rm-target">%s</code>', esc_html( $item->urlTo ) );
	}

	/**
	 * @param Redirect $item
	 */
	protected function column_status( $item ): string {
		return sprintf(
			'<span class="sm-rm-status sm-rm-status--%s">%s</span>',
			esc_attr( $item->status->value ),
			esc_html( $item->status->label() )
		);
	}

	/**
	 * @param Redirect $item
	 * @param string   $column_name
	 */
	protected function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'action_code'    => sprintf( '<span class="sm-rm-code sm-rm-code--%1$d" title="%2$s">%1$d</span>', $item->actionCode->value, esc_attr( $item->actionCode->label() ) ),
			'match_type'     => esc_html( $item->matchType->label() ),
			'query_strategy' => esc_html( $item->queryStrategy->label() ),
			'hits'           => esc_html( number_format_i18n( $item->hits ) ),
			'last_accessed'  => esc_html( AdminController::formatDate( $item->lastAccessed ) ),
			default          => '',
		};
	}

	private function currentStatus(): ?RedirectStatus {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		return RedirectStatus::tryFrom( $value );
	}
}
