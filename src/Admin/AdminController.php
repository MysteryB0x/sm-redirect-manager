<?php
/**
 * Admin UI: Tools → Redirect Manager.
 *
 * Tabs: Redirects (list + add/edit), 404 Log, Import / Export, Settings.
 *
 * Request handling map (all require manage_options + nonce):
 *   admin-post.php?action=sm_redirect_manager_save_redirect  POST  Create / update rule.
 *   admin-post.php?action=sm_redirect_manager_export_csv     POST  Stream CSV download.
 *   admin-post.php?action=sm_redirect_manager_import_csv     POST  Multipart CSV upload.
 *   admin-post.php?action=sm_redirect_manager_clear_logs     POST  Truncate 404 log.
 *   admin-ajax.php?action=sm_redirect_manager_toggle_status  POST  Activate / deactivate rule.
 *   admin-ajax.php?action=sm_redirect_manager_delete_redirect POST Delete rule.
 *   admin-ajax.php?action=sm_redirect_manager_delete_log     POST  Delete 404 entry.
 *   admin-ajax.php?action=sm_redirect_manager_convert_404    POST  404 entry → redirect.
 *   List-table bulk actions                    GET   Nonce "bulk-{plural}".
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Admin;

use SEOmarketeer\RedirectManager\Contracts\HookableInterface;
use SEOmarketeer\RedirectManager\Contracts\LogRepositoryInterface;
use SEOmarketeer\RedirectManager\Contracts\RedirectRepositoryInterface;
use SEOmarketeer\RedirectManager\Enum\MatchType;
use SEOmarketeer\RedirectManager\Enum\QueryStrategy;
use SEOmarketeer\RedirectManager\Enum\RedirectStatus;
use SEOmarketeer\RedirectManager\Enum\StatusCode;
use SEOmarketeer\RedirectManager\Hooks\Tracker404;
use SEOmarketeer\RedirectManager\Model\ImportReport;
use SEOmarketeer\RedirectManager\Model\Redirect;
use SEOmarketeer\RedirectManager\Service\CsvService;
use SEOmarketeer\RedirectManager\Service\RedirectValidator;
use SEOmarketeer\RedirectManager\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class AdminController implements HookableInterface {

	public const PAGE_SLUG       = 'sm-redirect-manager';
	public const CAPABILITY      = 'manage_options';
	public const AJAX_NONCE      = 'sm_redirect_manager_ajax';
	public const PER_PAGE_OPTION = 'sm_redirect_manager_per_page';

	private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

	private const FORM_FIELDS = array( 'url_from', 'url_to', 'match_type', 'action_code', 'query_strategy', 'status' );

	private string $hookSuffix = '';

	public function __construct(
		private readonly RedirectRepositoryInterface $redirects,
		private readonly LogRepositoryInterface $logs,
		private readonly RedirectValidator $validator,
		private readonly CsvService $csv,
		private readonly Tracker404 $tracker,
		private readonly Settings $settings,
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );

		add_action( 'admin_post_sm_redirect_manager_save_redirect', array( $this, 'handleSaveRedirect' ) );
		add_action( 'admin_post_sm_redirect_manager_export_csv', array( $this, 'handleExport' ) );
		add_action( 'admin_post_sm_redirect_manager_import_csv', array( $this, 'handleImport' ) );
		add_action( 'admin_post_sm_redirect_manager_clear_logs', array( $this, 'handleClearLogs' ) );

		add_action( 'wp_ajax_sm_redirect_manager_toggle_status', array( $this, 'ajaxToggleStatus' ) );
		add_action( 'wp_ajax_sm_redirect_manager_delete_redirect', array( $this, 'ajaxDeleteRedirect' ) );
		add_action( 'wp_ajax_sm_redirect_manager_delete_log', array( $this, 'ajaxDeleteLog' ) );
		add_action( 'wp_ajax_sm_redirect_manager_convert_404', array( $this, 'ajaxConvert404' ) );
		add_action( 'wp_ajax_sm_redirect_manager_quick_add', array( $this, 'ajaxQuickAdd' ) );

		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, array( $this, 'saveScreenOption' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( SM_REDIRECT_MANAGER_FILE ), array( $this, 'actionLinks' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menu, assets, settings
	 * ------------------------------------------------------------------- */

	public function registerMenu(): void {
		$hook = add_submenu_page(
			'tools.php',
			__( 'SM Redirect Manager', 'sm-redirect-manager' ),
			__( 'Redirect Manager', 'sm-redirect-manager' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'renderPage' )
		);

		if ( is_string( $hook ) ) {
			$this->hookSuffix = $hook;
			add_action( "load-{$hook}", array( $this, 'onLoadPage' ) );
		}
	}

	public function registerSettings(): void {
		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::DEFAULTS,
				'show_in_rest'      => false,
			)
		);
	}

	public function enqueueAssets( string $hookSuffix ): void {
		if ( $hookSuffix !== $this->hookSuffix ) {
			return;
		}

		wp_enqueue_style( 'sm-rm-admin', SM_REDIRECT_MANAGER_URL . 'assets/css/admin.css', array(), SM_REDIRECT_MANAGER_VERSION );
		wp_enqueue_script( 'sm-rm-admin', SM_REDIRECT_MANAGER_URL . 'assets/js/admin.js', array(), SM_REDIRECT_MANAGER_VERSION, true );

		wp_localize_script(
			'sm-rm-admin',
			'smRedirectManager',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::AJAX_NONCE ),
				'matchTypes' => MatchType::options(),
				'i18n'       => array(
					/* translators: %s: match type label, e.g. "Wildcard (*)". */
					'detected'      => __( 'Detected: %s', 'sm-redirect-manager' ),
					'saving'        => __( 'Adding…', 'sm-redirect-manager' ),
					'activate'      => __( 'Activate', 'sm-redirect-manager' ),
					'deactivate'    => __( 'Deactivate', 'sm-redirect-manager' ),
					'confirmDelete' => __( 'Delete this item permanently?', 'sm-redirect-manager' ),
					'requestFailed' => __( 'The request failed. Please reload the page and try again.', 'sm-redirect-manager' ),
				),
			)
		);
	}

	public function onLoadPage(): void {
		$this->assertCapability();

		$tab = $this->currentTab();

		if ( in_array( $tab, array( 'redirects', 'logs' ), true ) ) {
			add_screen_option(
				'per_page',
				array(
					'label'   => __( 'Items per page', 'sm-redirect-manager' ),
					'default' => 20,
					'option'  => self::PER_PAGE_OPTION,
				)
			);
			$this->processBulkActions( $tab );
		}
	}

	/**
	 * @param mixed $screenOption
	 * @param mixed $value
	 * @return mixed
	 */
	public function saveScreenOption( mixed $screenOption, string $option, mixed $value ): mixed {
		return self::PER_PAGE_OPTION === $option ? max( 1, min( 500, (int) $value ) ) : $screenOption;
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public function actionLinks( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::pageUrl( 'redirects' ) ),
			esc_html__( 'Manage redirects', 'sm-redirect-manager' )
		);

		return array( 'sm-rm' => $settings ) + $links;
	}

	/* ---------------------------------------------------------------------
	 * Page rendering
	 * ------------------------------------------------------------------- */

	public function renderPage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sm-redirect-manager' ), 403 );
		}

		$tab  = $this->currentTab();
		$view = $this->currentView();

		echo '<div class="wrap sm-rm-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'SM Redirect Manager', 'sm-redirect-manager' ) . '</h1>';

		if ( 'redirects' === $tab && '' === $view ) {
			printf(
				' <a href="%s" class="page-title-action">%s</a>',
				esc_url( self::pageUrl( 'redirects', array( 'view' => 'new' ) ) ),
				esc_html__( 'Add New Redirect', 'sm-redirect-manager' )
			);
		}

		echo '<hr class="wp-header-end">';

		$this->renderFlashNotice();
		$this->renderTabs( $tab );

		match ( $tab ) {
			'logs'     => $this->renderLogsTab(),
			'tools'    => $this->renderToolsTab(),
			'settings' => $this->renderSettingsTab(),
			default    => '' !== $view ? $this->renderRedirectForm() : $this->renderRedirectsTab(),
		};

		echo '</div>';
	}

	private function renderTabs( string $current ): void {
		$tabs = array(
			'redirects' => __( 'Redirects', 'sm-redirect-manager' ),
			'logs'      => __( '404 Log', 'sm-redirect-manager' ),
			'tools'     => __( 'Import / Export', 'sm-redirect-manager' ),
			'settings'  => __( 'Settings', 'sm-redirect-manager' ),
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Redirect Manager sections', 'sm-redirect-manager' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( self::pageUrl( $slug ) ),
				$slug === $current ? ' nav-tab-active' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	private function renderRedirectsTab(): void {
		$table = new RedirectsListTable( $this->redirects );
		$table->prepare_items();

		$this->renderQuickAdd();

		echo '<form method="get" class="sm-rm-list-form">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::PAGE_SLUG ) );
		echo '<input type="hidden" name="tab" value="redirects" />';
		$table->views();
		$table->search_box( __( 'Search redirects', 'sm-redirect-manager' ), 'sm-rm-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Quick Add bar shown above the redirects list.
	 *
	 * Posts to the regular save handler (works without JS); admin.js upgrades it
	 * to AJAX so the new row is inserted instantly and the cursor returns to the
	 * source field for rapid entry. Match type is auto-detected server-side.
	 */
	private function renderQuickAdd(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$isFiltered = ! empty( $_GET['s'] ) || ! empty( $_GET['paged'] ) && 1 < absint( $_GET['paged'] );
		?>
		<div class="sm-rm-quick-add">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-rm-quick-form" autocomplete="off" novalidate>
				<input type="hidden" name="action" value="sm_redirect_manager_save_redirect" />
				<input type="hidden" name="match_type" value="auto" />
				<input type="hidden" name="query_strategy" value="<?php echo esc_attr( QueryStrategy::Ignore->value ); ?>" />
				<input type="hidden" name="status" value="<?php echo esc_attr( RedirectStatus::Active->value ); ?>" />
				<?php wp_nonce_field( 'sm_redirect_manager_save_redirect' ); ?>

				<h2 class="sm-rm-quick-title"><?php esc_html_e( 'Quick add redirect', 'sm-redirect-manager' ); ?></h2>

				<div class="sm-rm-quick-fields">
					<div class="sm-rm-field sm-rm-field--source">
						<label for="sm-rm-quick-from"><?php esc_html_e( 'Source', 'sm-redirect-manager' ); ?></label>
						<input type="text" id="sm-rm-quick-from" name="url_from" class="code" required maxlength="2048"
							placeholder="/old-page" <?php echo $isFiltered ? '' : 'autofocus'; ?> />
						<span class="sm-rm-detected" aria-live="polite"></span>
					</div>

					<span class="sm-rm-quick-arrow" aria-hidden="true">&rarr;</span>

					<div class="sm-rm-field sm-rm-field--target">
						<label for="sm-rm-quick-to"><?php esc_html_e( 'Target', 'sm-redirect-manager' ); ?></label>
						<input type="text" id="sm-rm-quick-to" name="url_to" class="code" maxlength="2048"
							placeholder="/new-page or https://…" />
					</div>

					<div class="sm-rm-field sm-rm-field--code">
						<label for="sm-rm-quick-code"><?php esc_html_e( 'Type', 'sm-redirect-manager' ); ?></label>
						<?php $this->renderSelect( 'action_code', 'sm-rm-quick-code', StatusCode::options(), (string) StatusCode::MovedPermanently->value ); ?>
					</div>

					<button type="submit" class="button button-primary sm-rm-quick-submit"><?php esc_html_e( 'Add redirect', 'sm-redirect-manager' ); ?></button>
				</div>

				<p class="sm-rm-quick-help">
					<?php esc_html_e( 'Use * for wildcards (/blog/* → /news/$1) or start with ^ for a regex. Query parameters are ignored.', 'sm-redirect-manager' ); ?>
					<a href="<?php echo esc_url( self::pageUrl( 'redirects', array( 'view' => 'new' ) ) ); ?>"><?php esc_html_e( 'More options', 'sm-redirect-manager' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	private function renderRedirectForm(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$logId = isset( $_GET['log_id'] ) ? absint( $_GET['log_id'] ) : 0;
		$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		// phpcs:enable

		$redirect = $id > 0 ? $this->redirects->findById( $id ) : null;

		if ( $id > 0 && null === $redirect ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Redirect not found.', 'sm-redirect-manager' ) );
			return;
		}

		$values = null !== $redirect
			? array_map( 'strval', $redirect->toRow() )
			: array(
				'url_from'       => $from,
				'url_to'         => '',
				'match_type'     => MatchType::Exact->value,
				'action_code'    => (string) StatusCode::MovedPermanently->value,
				'query_strategy' => QueryStrategy::Ignore->value,
				'status'         => RedirectStatus::Active->value,
			);

		// Re-populate after a validation error.
		$old = get_transient( $this->formTransientKey() );
		if ( is_array( $old ) ) {
			delete_transient( $this->formTransientKey() );
			$values = array_merge( $values, array_map( 'strval', array_intersect_key( $old, $values ) ) );
		}

		$title = null !== $redirect ? __( 'Edit Redirect', 'sm-redirect-manager' ) : __( 'Add New Redirect', 'sm-redirect-manager' );
		?>
		<h2><?php echo esc_html( $title ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-rm-form">
			<input type="hidden" name="action" value="sm_redirect_manager_save_redirect" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input type="hidden" name="log_id" value="<?php echo esc_attr( (string) $logId ); ?>" />
			<?php wp_nonce_field( 'sm_redirect_manager_save_redirect' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sm-rm-url-from"><?php esc_html_e( 'Source URL', 'sm-redirect-manager' ); ?></label></th>
					<td>
						<input type="text" id="sm-rm-url-from" name="url_from" class="large-text code" required maxlength="2048"
							value="<?php echo esc_attr( $values['url_from'] ); ?>" placeholder="/old-page" />
						<p class="description">
							<?php esc_html_e( 'Exact: /old-page — Wildcard: /blog/* — Regex (no delimiters): ^/news/(\d+)/(.*)$', 'sm-redirect-manager' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-rm-match-type"><?php esc_html_e( 'Match type', 'sm-redirect-manager' ); ?></label></th>
					<td><?php $this->renderSelect( 'match_type', 'sm-rm-match-type', MatchType::options(), $values['match_type'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-rm-action-code"><?php esc_html_e( 'HTTP status', 'sm-redirect-manager' ); ?></label></th>
					<td><?php $this->renderSelect( 'action_code', 'sm-rm-action-code', StatusCode::options(), $values['action_code'] ); ?></td>
				</tr>
				<tr id="sm-rm-target-row">
					<th scope="row"><label for="sm-rm-url-to"><?php esc_html_e( 'Target URL', 'sm-redirect-manager' ); ?></label></th>
					<td>
						<input type="text" id="sm-rm-url-to" name="url_to" class="large-text code" maxlength="2048"
							value="<?php echo esc_attr( $values['url_to'] ); ?>" placeholder="/new-page or https://example.com/page" />
						<p class="description">
							<?php esc_html_e( 'Use $1, $2 … to insert captured groups from regex or wildcard sources.', 'sm-redirect-manager' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-rm-query-strategy"><?php esc_html_e( 'Query parameters', 'sm-redirect-manager' ); ?></label></th>
					<td><?php $this->renderSelect( 'query_strategy', 'sm-rm-query-strategy', QueryStrategy::options(), $values['query_strategy'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-rm-status"><?php esc_html_e( 'Status', 'sm-redirect-manager' ); ?></label></th>
					<td><?php $this->renderSelect( 'status', 'sm-rm-status', RedirectStatus::options(), $values['status'] ); ?></td>
				</tr>
			</table>

			<?php submit_button( null !== $redirect ? __( 'Update Redirect', 'sm-redirect-manager' ) : __( 'Add Redirect', 'sm-redirect-manager' ) ); ?>
			<a href="<?php echo esc_url( self::pageUrl( 'redirects' ) ); ?>"><?php esc_html_e( 'Cancel', 'sm-redirect-manager' ); ?></a>
		</form>
		<?php
	}

	private function renderLogsTab(): void {
		$table = new LogsListTable( $this->logs );
		$table->prepare_items();
		?>
		<div class="sm-rm-toolbar">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-rm-confirm"
				data-confirm="<?php esc_attr_e( 'Delete ALL 404 log entries?', 'sm-redirect-manager' ); ?>">
				<input type="hidden" name="action" value="sm_redirect_manager_clear_logs" />
				<?php wp_nonce_field( 'sm_redirect_manager_clear_logs' ); ?>
				<?php submit_button( __( 'Clear 404 log', 'sm-redirect-manager' ), 'secondary delete', 'submit', false ); ?>
			</form>
		</div>

		<form method="get" class="sm-rm-list-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="logs" />
			<?php
			$table->search_box( __( 'Search 404s', 'sm-redirect-manager' ), 'sm-rm-log-search' );
			$table->display();
			?>
		</form>

		<template id="sm-rm-convert-template">
			<tr class="sm-rm-convert-row">
				<td colspan="8">
					<form class="sm-rm-convert-form">
						<span class="sm-rm-convert-label">
							<?php esc_html_e( 'Redirect', 'sm-redirect-manager' ); ?>
							<code class="sm-rm-convert-source"></code>
							<?php esc_html_e( 'to', 'sm-redirect-manager' ); ?>
						</span>
						<label class="screen-reader-text" for="sm-rm-convert-target"><?php esc_html_e( 'Target URL', 'sm-redirect-manager' ); ?></label>
						<input type="text" id="sm-rm-convert-target" name="url_to" class="regular-text code" placeholder="/new-page" />
						<label class="screen-reader-text" for="sm-rm-convert-code"><?php esc_html_e( 'HTTP status', 'sm-redirect-manager' ); ?></label>
						<?php $this->renderSelect( 'action_code', 'sm-rm-convert-code', StatusCode::options(), '301' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create redirect', 'sm-redirect-manager' ); ?></button>
						<button type="button" class="button-link sm-rm-convert-cancel"><?php esc_html_e( 'Cancel', 'sm-redirect-manager' ); ?></button>
					</form>
				</td>
			</tr>
		</template>
		<?php
	}

	private function renderToolsTab(): void {
		$counts = $this->redirects->countByStatus();
		?>
		<div class="sm-rm-cards">
			<div class="card sm-rm-card">
				<h2><?php esc_html_e( 'Export to CSV', 'sm-redirect-manager' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: number of redirects. */
						esc_html__( 'Download all %s redirects as a UTF-8 CSV file.', 'sm-redirect-manager' ),
						esc_html( number_format_i18n( $counts['all'] ?? 0 ) )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sm_redirect_manager_export_csv" />
					<?php wp_nonce_field( 'sm_redirect_manager_export_csv' ); ?>
					<?php submit_button( __( 'Download CSV', 'sm-redirect-manager' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="card sm-rm-card">
				<h2><?php esc_html_e( 'Import from CSV', 'sm-redirect-manager' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="sm_redirect_manager_import_csv" />
					<?php wp_nonce_field( 'sm_redirect_manager_import_csv' ); ?>
					<p>
						<label for="sm-rm-csv-file" class="screen-reader-text"><?php esc_html_e( 'CSV file', 'sm-redirect-manager' ); ?></label>
						<input type="file" id="sm-rm-csv-file" name="sm_redirect_manager_csv" accept=".csv,text/csv,text/plain" required />
					</p>
					<p>
						<label>
							<input type="checkbox" name="update_existing" value="1" />
							<?php esc_html_e( 'Update redirects whose source already exists (otherwise they are skipped)', 'sm-redirect-manager' ); ?>
						</label>
					</p>
					<?php submit_button( __( 'Import CSV', 'sm-redirect-manager' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		</div>

		<h2><?php esc_html_e( 'CSV format', 'sm-redirect-manager' ); ?></h2>
		<p><?php esc_html_e( 'First row must be a header. Comma, semicolon and tab delimiters are detected automatically. Only url_from is required; missing columns use the defaults below.', 'sm-redirect-manager' ); ?></p>
		<table class="widefat striped sm-rm-format">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Column', 'sm-redirect-manager' ); ?></th>
					<th><?php esc_html_e( 'Allowed values', 'sm-redirect-manager' ); ?></th>
					<th><?php esc_html_e( 'Default', 'sm-redirect-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>url_from</code></td><td><?php esc_html_e( 'Path, full URL on this site, wildcard or regex', 'sm-redirect-manager' ); ?></td><td>&mdash;</td></tr>
				<tr><td><code>url_to</code></td><td><?php esc_html_e( 'Path or http(s) URL; may contain $1 … (empty for 410/451)', 'sm-redirect-manager' ); ?></td><td>&mdash;</td></tr>
				<tr><td><code>match_type</code></td><td><code><?php echo esc_html( implode( ' | ', array_keys( MatchType::options() ) ) ); ?></code></td><td><code>exact</code></td></tr>
				<tr><td><code>action_code</code></td><td><code><?php echo esc_html( implode( ' | ', array_keys( StatusCode::options() ) ) ); ?></code></td><td><code>301</code></td></tr>
				<tr><td><code>query_strategy</code></td><td><code><?php echo esc_html( implode( ' | ', array_keys( QueryStrategy::options() ) ) ); ?></code></td><td><code>ignore</code></td></tr>
				<tr><td><code>status</code></td><td><code><?php echo esc_html( implode( ' | ', array_keys( RedirectStatus::options() ) ) ); ?></code></td><td><code>active</code></td></tr>
			</tbody>
		</table>
		<p><strong><?php esc_html_e( 'Example', 'sm-redirect-manager' ); ?></strong></p>
		<pre class="sm-rm-example">url_from,url_to,match_type,action_code,query_strategy,status
/old-page,/new-page,exact,301,ignore,active
/blog/*,/news/$1,wildcard,301,pass,active
^/product/(\d+)$,/shop/item-$1,regex,308,ignore,active
/discontinued,,exact,410,ignore,active</pre>
		<?php
	}

	private function renderSettingsTab(): void {
		$values = $this->settings->all();
		$name   = static fn( string $key ): string => Settings::OPTION . '[' . $key . ']';

		settings_errors();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( Settings::GROUP ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Slug tracking', 'sm-redirect-manager' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'auto_slug_redirects' ) ); ?>" value="1" <?php checked( (bool) $values['auto_slug_redirects'] ); ?> />
						<?php esc_html_e( 'Automatically create a 301 redirect when a published URL changes', 'sm-redirect-manager' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '404 logging', 'sm-redirect-manager' ); ?></th>
					<td>
						<fieldset>
							<label><input type="checkbox" name="<?php echo esc_attr( $name( 'enable_404_log' ) ); ?>" value="1" <?php checked( (bool) $values['enable_404_log'] ); ?> />
							<?php esc_html_e( 'Log 404 errors', 'sm-redirect-manager' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $name( 'ignore_bots' ) ); ?>" value="1" <?php checked( (bool) $values['ignore_bots'] ); ?> />
							<?php esc_html_e( 'Ignore known bots and crawlers', 'sm-redirect-manager' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $name( 'anonymize_ip' ) ); ?>" value="1" <?php checked( (bool) $values['anonymize_ip'] ); ?> />
							<?php esc_html_e( 'Anonymise IP addresses (recommended for GDPR)', 'sm-redirect-manager' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-rm-retention"><?php esc_html_e( 'Log retention', 'sm-redirect-manager' ); ?></label></th>
					<td>
						<input type="number" id="sm-rm-retention" name="<?php echo esc_attr( $name( 'log_retention_days' ) ); ?>" min="0" max="3650" step="1" class="small-text"
							value="<?php echo esc_attr( (string) $values['log_retention_days'] ); ?>" />
						<?php esc_html_e( 'days (0 = keep forever)', 'sm-redirect-manager' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'sm-redirect-manager' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'remove_data_on_uninstall' ) ); ?>" value="1" <?php checked( (bool) $values['remove_data_on_uninstall'] ); ?> />
						<?php esc_html_e( 'Delete all redirects, logs and settings when the plugin is deleted', 'sm-redirect-manager' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * @param array<int|string, string> $options
	 */
	private function renderSelect( string $name, string $id, array $options, string $selected ): void {
		printf( '<select name="%s" id="%s">', esc_attr( $name ), esc_attr( $id ) );
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( (string) $value, $selected, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/* ---------------------------------------------------------------------
	 * admin-post handlers
	 * ------------------------------------------------------------------- */

	public function handleSaveRedirect(): void {
		$this->assertCapability();
		check_admin_referer( 'sm_redirect_manager_save_redirect' );

		$id    = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$logId = isset( $_POST['log_id'] ) ? absint( wp_unslash( $_POST['log_id'] ) ) : 0;

		$input = array();
		foreach ( self::FORM_FIELDS as $field ) {
			// Field-specific sanitisation happens in RedirectValidator.
			$input[ $field ] = isset( $_POST[ $field ] ) ? (string) wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$formUrl = self::pageUrl(
			'redirects',
			array_filter(
				array(
					'view'   => $id > 0 ? 'edit' : 'new',
					'id'     => $id,
					'log_id' => $logId,
				)
			)
		);

		$redirect = $this->validator->fromInput( $input, $id );

		if ( ! $redirect instanceof \WP_Error ) {
			$duplicate = $this->redirects->findBySource( $redirect->urlFrom, $redirect->matchType );
			if ( null !== $duplicate && $duplicate->id !== $id ) {
				$redirect = new \WP_Error(
					'sm_redirect_manager_duplicate',
					/* translators: %s: source URL. */
					sprintf( __( 'A redirect for %s already exists.', 'sm-redirect-manager' ), $redirect->urlFrom )
				);
			}
		}

		if ( $redirect instanceof \WP_Error ) {
			// Kept verbatim (regex may contain "<"); only ever re-output through esc_attr().
			set_transient(
				$this->formTransientKey(),
				array_map( static fn( string $v ): string => mb_substr( wp_check_invalid_utf8( $v, true ), 0, 2048 ), $input ),
				5 * MINUTE_IN_SECONDS
			);
			$this->flash( 'error', $redirect->get_error_message() );
			$this->redirectTo( $formUrl );
		}

		$saved = $id > 0 ? $this->redirects->update( $redirect ) : $this->redirects->insert( $redirect ) > 0;

		if ( ! $saved ) {
			$this->flash( 'error', __( 'The redirect could not be saved.', 'sm-redirect-manager' ) );
			$this->redirectTo( $formUrl );
		}

		if ( $logId > 0 ) {
			$this->logs->deleteByPath( $redirect->urlFrom );
		}

		$this->flash( 'success', $id > 0 ? __( 'Redirect updated.', 'sm-redirect-manager' ) : __( 'Redirect created.', 'sm-redirect-manager' ) );
		$this->redirectTo( self::pageUrl( 'redirects' ) );
	}

	public function handleExport(): void {
		$this->assertCapability();
		check_admin_referer( 'sm_redirect_manager_export_csv' );

		$this->csv->export();
	}

	public function handleImport(): void {
		$this->assertCapability();
		check_admin_referer( 'sm_redirect_manager_import_csv' );

		$back = self::pageUrl( 'tools' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Validated field by field below.
		$file = isset( $_FILES['sm_redirect_manager_csv'] ) && is_array( $_FILES['sm_redirect_manager_csv'] ) ? $_FILES['sm_redirect_manager_csv'] : null;

		if ( null === $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$this->flash( 'error', __( 'No file was uploaded, or the upload failed.', 'sm-redirect-manager' ) );
			$this->redirectTo( $back );
		}

		$tmpName = (string) ( $file['tmp_name'] ?? '' );
		$name    = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$type    = wp_check_filetype( $name, array( 'csv' => 'text/csv', 'txt' => 'text/plain' ) );

		if ( ! is_uploaded_file( $tmpName ) || false === $type['ext'] ) {
			$this->flash( 'error', __( 'Please upload a .csv file.', 'sm-redirect-manager' ) );
			$this->redirectTo( $back );
		}

		/**
		 * Maximum accepted CSV size in bytes.
		 *
		 * @param int $bytes Default 5 MB.
		 */
		$maxBytes = (int) apply_filters( 'sm_redirect_manager_csv_max_bytes', self::MAX_UPLOAD_BYTES );
		if ( (int) ( $file['size'] ?? 0 ) > $maxBytes ) {
			/* translators: %s: human readable size. */
			$this->flash( 'error', sprintf( __( 'The file exceeds the maximum size of %s.', 'sm-redirect-manager' ), size_format( $maxBytes ) ) );
			$this->redirectTo( $back );
		}

		$report = $this->csv->import( $tmpName, ! empty( $_POST['update_existing'] ) );

		$this->flash(
			$report->hasFatalError() ? 'error' : ( $report->errorCount() > 0 ? 'warning' : 'success' ),
			$this->importSummary( $report ),
			$report->errors()
		);
		$this->redirectTo( $back );
	}

	public function handleClearLogs(): void {
		$this->assertCapability();
		check_admin_referer( 'sm_redirect_manager_clear_logs' );

		$this->logs->truncate();

		$this->flash( 'success', __( '404 log cleared.', 'sm-redirect-manager' ) );
		$this->redirectTo( self::pageUrl( 'logs' ) );
	}

	private function processBulkActions( string $tab ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below via check_admin_referer.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '-1';
		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '-1';
		}
		if ( '-1' === $action || '' === $action ) {
			return;
		}

		$ids = isset( $_REQUEST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) : array();
		// phpcs:enable

		check_admin_referer( 'redirects' === $tab ? 'bulk-redirects' : 'bulk-logs' );

		if ( array() === $ids ) {
			$this->redirectTo( self::pageUrl( $tab ) );
		}

		$affected = 0;

		if ( 'redirects' === $tab ) {
			$affected = match ( $action ) {
				'activate'   => $this->redirects->setStatusMany( $ids, RedirectStatus::Active ),
				'deactivate' => $this->redirects->setStatusMany( $ids, RedirectStatus::Inactive ),
				'delete'     => $this->redirects->deleteMany( $ids ),
				default      => 0,
			};
		} elseif ( 'delete' === $action ) {
			$affected = $this->logs->deleteMany( $ids );
		}

		/* translators: %d: number of items. */
		$this->flash( 'success', sprintf( _n( '%d item updated.', '%d items updated.', $affected, 'sm-redirect-manager' ), $affected ) );
		$this->redirectTo( self::pageUrl( $tab ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX handlers
	 * ------------------------------------------------------------------- */

	public function ajaxToggleStatus(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		$this->assertAjaxCapability();

		$redirect = $this->redirects->findById( ( isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0 ) );
		if ( null === $redirect ) {
			wp_send_json_error( array( 'message' => __( 'Redirect not found.', 'sm-redirect-manager' ) ), 404 );
		}

		$status = $redirect->status->toggled();
		if ( ! $this->redirects->setStatus( $redirect->id, $status ) ) {
			wp_send_json_error( array( 'message' => __( 'The status could not be changed.', 'sm-redirect-manager' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'status' => $status->value,
				'label'  => $status->label(),
			)
		);
	}

	public function ajaxDeleteRedirect(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		$this->assertAjaxCapability();

		if ( ! $this->redirects->delete( ( isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0 ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Redirect not found.', 'sm-redirect-manager' ) ), 404 );
		}

		wp_send_json_success();
	}

	public function ajaxDeleteLog(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		$this->assertAjaxCapability();

		if ( ! $this->logs->delete( ( isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0 ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Log entry not found.', 'sm-redirect-manager' ) ), 404 );
		}

		wp_send_json_success();
	}

	/**
	 * Quick Add: validate, insert, and return the rendered list-table row.
	 */
	public function ajaxQuickAdd(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		$this->assertAjaxCapability();

		$input = array(
			'match_type'     => 'auto',
			'query_strategy' => QueryStrategy::Ignore->value,
			'status'         => RedirectStatus::Active->value,
		);
		// url_from may be a regex, which sanitize_text_field()/esc_url_raw() would corrupt; RedirectValidator
		// validates UTF-8, strips control characters, bounds the length and compiles regexes.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$input['url_from']    = isset( $_POST['url_from'] ) ? (string) wp_unslash( $_POST['url_from'] ) : '';
		$input['url_to']      = isset( $_POST['url_to'] ) ? esc_url_raw( wp_unslash( $_POST['url_to'] ) ) : '';
		$input['action_code'] = isset( $_POST['action_code'] ) ? absint( wp_unslash( $_POST['action_code'] ) ) : StatusCode::MovedPermanently->value;

		$redirect = $this->validator->fromInput( $input );
		if ( $redirect instanceof \WP_Error ) {
			wp_send_json_error( array( 'message' => $redirect->get_error_message(), 'field' => $this->errorField( $redirect ) ), 400 );
		}

		if ( null !== $this->redirects->findBySource( $redirect->urlFrom, $redirect->matchType ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: source URL. */
					'message' => sprintf( __( 'A redirect for %s already exists.', 'sm-redirect-manager' ), $redirect->urlFrom ),
					'field'   => 'url_from',
				),
				409
			);
		}

		$id = $this->redirects->insert( $redirect );
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'The redirect could not be saved.', 'sm-redirect-manager' ) ), 500 );
		}

		$saved = $redirect->withId( $id );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: source, 2: status code, 3: match type label. */
					__( 'Added %1$s (%2$d, %3$s).', 'sm-redirect-manager' ),
					$saved->urlFrom,
					$saved->actionCode->value,
					$saved->matchType->label()
				),
				'rowHtml' => ( new RedirectsListTable( $this->redirects ) )->renderRow( $saved ),
			)
		);
	}

	private function errorField( \WP_Error $error ): string {
		return match ( $error->get_error_code() ) {
			'sm_redirect_manager_url_to', 'sm_redirect_manager_loop' => 'url_to',
			'sm_redirect_manager_action_code'          => 'action_code',
			default                      => 'url_from',
		};
	}

	public function ajaxConvert404(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		$this->assertAjaxCapability();

		$logId  = isset( $_POST['log_id'] ) ? absint( wp_unslash( $_POST['log_id'] ) ) : 0;
		$target = isset( $_POST['url_to'] ) ? esc_url_raw( wp_unslash( $_POST['url_to'] ) ) : '';
		$code   = isset( $_POST['action_code'] ) ? absint( wp_unslash( $_POST['action_code'] ) ) : 0;

		$result = $this->tracker->convertToRedirect(
			$logId,
			$target,
			$code > 0 ? $code : StatusCode::MovedPermanently->value
		);

		if ( $result instanceof \WP_Error ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: source path, 2: status code. */
					__( 'Redirect created for %1$s (%2$d).', 'sm-redirect-manager' ),
					$result->urlFrom,
					$result->actionCode->value
				),
				'editUrl' => self::pageUrl(
					'redirects',
					array(
						'view' => 'edit',
						'id'   => $result->id,
					)
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * @param array<string, int|string> $args
	 */
	public static function pageUrl( string $tab = 'redirects', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Format a stored UTC MySQL datetime in the site's timezone and format.
	 */
	public static function formatDate( ?string $gmtDate ): string {
		if ( null === $gmtDate || '' === $gmtDate || str_starts_with( $gmtDate, '0000' ) ) {
			return '—';
		}

		$timestamp = strtotime( $gmtDate . ' UTC' );
		if ( false === $timestamp ) {
			return '—';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Capability check for AJAX handlers. Each handler verifies its nonce with
	 * check_ajax_referer() itself, before any request data is read.
	 */
	private function assertAjaxCapability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'sm-redirect-manager' ) ), 403 );
		}
	}

	private function assertCapability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'sm-redirect-manager' ), 403 );
		}
	}

	private function currentTab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'redirects';
		return in_array( $tab, array( 'redirects', 'logs', 'tools', 'settings' ), true ) ? $tab : 'redirects';
	}

	private function currentView(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		return in_array( $view, array( 'new', 'edit' ), true ) ? $view : '';
	}

	private function importSummary( ImportReport $report ): string {
		return sprintf(
			/* translators: 1: imported, 2: updated, 3: skipped, 4: errors. */
			__( 'CSV import finished: %1$d imported, %2$d updated, %3$d skipped, %4$d errors.', 'sm-redirect-manager' ),
			$report->imported,
			$report->updated,
			$report->skipped,
			$report->errorCount()
		);
	}

	/**
	 * Store a one-time notice for the current user (shown after the PRG redirect).
	 *
	 * @param list<string> $details
	 */
	private function flash( string $type, string $message, array $details = array() ): void {
		set_transient(
			'sm_redirect_manager_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
				'details' => $details,
			),
			MINUTE_IN_SECONDS
		);
	}

	private function renderFlashNotice(): void {
		$key    = 'sm_redirect_manager_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		$type = in_array( $notice['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';

		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p>', esc_attr( $type ), esc_html( (string) $notice['message'] ) );

		if ( ! empty( $notice['details'] ) && is_array( $notice['details'] ) ) {
			echo '<ul class="sm-rm-notice-details">';
			foreach ( $notice['details'] as $detail ) {
				echo '<li>' . esc_html( (string) $detail ) . '</li>';
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	private function formTransientKey(): string {
		return 'sm_redirect_manager_form_' . get_current_user_id();
	}

	private function redirectTo( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}
}
