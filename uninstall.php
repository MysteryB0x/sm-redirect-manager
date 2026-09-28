<?php
/**
 * Uninstall routine.
 *
 * Data is only removed when the site owner opted in via
 * Settings → "Delete all redirects, logs and settings when the plugin is deleted".
 * Redirects are SEO-critical, so the safe default is to keep them.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove plugin data for the current site if opted in.
 */
$sm_redirect_manager_uninstall_site = static function (): void {
	global $wpdb;

	$settings = get_option( 'sm_redirect_manager_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['remove_data_on_uninstall'] ) ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sm_redirects" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sm_404_logs" );
	// phpcs:enable

	delete_option( 'sm_redirect_manager_settings' );
	delete_option( 'sm_redirect_manager_db_version' );
	delete_metadata( 'user', 0, 'sm_redirect_manager_per_page', '', true );
	wp_clear_scheduled_hook( 'sm_redirect_manager_purge_404_logs' );
	wp_cache_delete( 'last_changed', 'sm_redirect_manager' );
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $sm_redirect_manager_site_id ) {
		switch_to_blog( (int) $sm_redirect_manager_site_id );
		$sm_redirect_manager_uninstall_site();
		restore_current_blog();
	}
} else {
	$sm_redirect_manager_uninstall_site();
}
