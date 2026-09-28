<?php
/**
 * 404 log admin table.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Admin;

use SEOmarketeer\RedirectManager\Contracts\LogRepositoryInterface;
use SEOmarketeer\RedirectManager\Model\LogEntry;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \WP_List_Table::class, false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class LogsListTable extends \WP_List_Table {

	public function __construct( private readonly LogRepositoryInterface $repository ) {
		parent::__construct(
			array(
				'singular' => 'log',
				'plural'   => 'logs',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'url'        => __( 'URL', 'sm-redirect-manager' ),
			'hit_count'  => __( 'Hits', 'sm-redirect-manager' ),
			'referrer'   => __( 'Last referrer', 'sm-redirect-manager' ),
			'user_agent' => __( 'Last user agent', 'sm-redirect-manager' ),
			'ip_address' => __( 'Last IP', 'sm-redirect-manager' ),
			'created_at' => __( 'First seen', 'sm-redirect-manager' ),
			'last_seen'  => __( 'Last seen', 'sm-redirect-manager' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'url'        => array( 'url', false ),
			'hit_count'  => array( 'hit_count', true ),
			'created_at' => array( 'created_at', true ),
			'last_seen'  => array( 'last_seen', true ),
		);
	}

	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'sm-redirect-manager' ),
		);
	}

	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list parameters.
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderBy = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'last_seen';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		// phpcs:enable

		$perPage = $this->get_items_per_page( AdminController::PER_PAGE_OPTION, 20 );
		$result  = $this->repository->paginate( $this->get_pagenum(), $perPage, $search, $orderBy, $order );

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'url' );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $perPage,
				'total_pages' => (int) ceil( $result['total'] / $perPage ),
			)
		);
	}

	public function no_items(): void {
		esc_html_e( 'No 404 errors logged. Nice!', 'sm-redirect-manager' );
	}

	/**
	 * @param LogEntry $item
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="sm-rm-log-%1$d">%2$s</label><input type="checkbox" id="sm-rm-log-%1$d" name="ids[]" value="%1$d" />',
			$item->id,
			esc_html__( 'Select entry', 'sm-redirect-manager' )
		);
	}

	/**
	 * @param LogEntry $item
	 */
	protected function column_url( $item ): string {
		// Non-JS fallback: open the full redirect form prefilled with this URL.
		$convertUrl = AdminController::pageUrl(
			'redirects',
			array(
				'view'   => 'new',
				'from'   => rawurlencode( $item->path() ),
				'log_id' => $item->id,
			)
		);

		$actions = array(
			'convert' => sprintf(
				'<a href="%s" class="sm-rm-convert" data-id="%d" data-url="%s">%s</a>',
				esc_url( $convertUrl ),
				$item->id,
				esc_attr( $item->path() ),
				esc_html__( 'Convert to redirect', 'sm-redirect-manager' )
			),
			'view'    => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( home_url( $item->url ) ),
				esc_html__( 'Open', 'sm-redirect-manager' )
			),
			'delete'  => sprintf(
				'<a href="#" class="sm-rm-delete-log submitdelete" data-id="%d">%s</a>',
				$item->id,
				esc_html__( 'Delete', 'sm-redirect-manager' )
			),
		);

		return sprintf( '<strong><code>%s</code></strong>%s', esc_html( $item->url ), $this->row_actions( $actions ) );
	}

	/**
	 * @param LogEntry $item
	 * @param string   $column_name
	 */
	protected function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'hit_count'  => sprintf( '<strong>%s</strong>', esc_html( number_format_i18n( $item->hitCount ) ) ),
			'referrer'   => '' !== $item->referrer
				? sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer nofollow" class="sm-rm-truncate">%2$s</a>', esc_url( $item->referrer ), esc_html( $item->referrer ) )
				: '<span class="description">&mdash;</span>',
			'user_agent' => sprintf( '<span class="sm-rm-truncate" title="%1$s">%2$s</span>', esc_attr( $item->userAgent ), esc_html( $item->userAgent ) ),
			'ip_address' => esc_html( $item->ipAddress ),
			'created_at' => esc_html( AdminController::formatDate( $item->createdAt ) ),
			'last_seen'  => esc_html( AdminController::formatDate( $item->lastSeen ) ),
			default      => '',
		};
	}
}
