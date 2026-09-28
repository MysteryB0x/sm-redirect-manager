<?php
/**
 * Schema creation, upgrades, default options, cron scheduling and multisite lifecycle.
 *
 * @package SEOmarketeer\RedirectManager
 */

declare(strict_types=1);

namespace SEOmarketeer\RedirectManager\Database;

use SEOmarketeer\RedirectManager\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public const DB_VERSION_OPTION = 'sm_redirect_manager_db_version';
	public const CRON_HOOK         = 'sm_redirect_manager_purge_404_logs';
	public const CACHE_GROUP       = 'sm_redirect_manager';

	/**
	 * Activation callback. Handles network-wide activation on multisite.
	 */
	public static function activate( bool $networkWide = false ): void {
		if ( is_multisite() && $networkWide ) {
			foreach ( self::siteIds() as $siteId ) {
				switch_to_blog( $siteId );
				self::install();
				restore_current_blog();
			}
			return;
		}

		self::install();
	}

	public static function deactivate( bool $networkWide = false ): void {
		if ( is_multisite() && $networkWide ) {
			foreach ( self::siteIds() as $siteId ) {
				switch_to_blog( $siteId );
				self::unschedule();
				restore_current_blog();
			}
			return;
		}

		self::unschedule();
	}

	/**
	 * Create/upgrade tables, seed default options and schedule maintenance.
	 */
	public static function install(): void {
		self::createTables();

		add_option( Settings::OPTION, Settings::DEFAULTS );
		update_option( self::DB_VERSION_OPTION, SM_REDIRECT_MANAGER_DB_VERSION, true );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		wp_cache_delete( 'last_changed', self::CACHE_GROUP );
	}

	/**
	 * Run the installer when the stored schema version is behind the code (e.g. after a file-only update).
	 */
	public static function maybeUpgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== SM_REDIRECT_MANAGER_DB_VERSION ) {
			self::install();
		}
	}

	public static function createTables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charsetCollate = $wpdb->get_charset_collate();
		$redirects      = self::redirectsTable();
		$logs           = self::logsTable();

		/*
		 * dbDelta formatting rules: one column per line, two spaces after
		 * PRIMARY KEY, named KEYs. VARCHAR(2048) columns use 191-char prefix
		 * indexes to stay within the utf8mb4 InnoDB key length limit.
		 */
		$sql = array(
			"CREATE TABLE {$redirects} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_from varchar(2048) NOT NULL DEFAULT '',
  url_to varchar(2048) NOT NULL DEFAULT '',
  match_type varchar(20) NOT NULL DEFAULT 'exact',
  action_code smallint(3) unsigned NOT NULL DEFAULT 301,
  query_strategy varchar(20) NOT NULL DEFAULT 'ignore',
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  last_accessed datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY url_from (url_from(191)),
  KEY status_match (status,match_type)
) {$charsetCollate};",
			"CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url varchar(2048) NOT NULL DEFAULT '',
  url_hash char(40) NOT NULL DEFAULT '',
  referrer varchar(2048) NOT NULL DEFAULT '',
  user_agent varchar(512) NOT NULL DEFAULT '',
  ip_address varchar(45) NOT NULL DEFAULT '',
  hit_count bigint(20) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY url (url(191)),
  KEY last_seen (last_seen)
) {$charsetCollate};",
		);

		dbDelta( $sql );
	}

	public static function redirectsTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'sm_redirects';
	}

	public static function logsTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'sm_404_logs';
	}

	/**
	 * Provision tables for a newly created site when network-activated.
	 */
	public static function onNewSite( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( SM_REDIRECT_MANAGER_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Drop our tables together with a deleted multisite site.
	 *
	 * @param array<int|string, string> $tables
	 * @return array<int|string, string>
	 */
	public static function filterDropTables( array $tables, int $siteId ): array {
		global $wpdb;
		$prefix   = $wpdb->get_blog_prefix( $siteId );
		$tables[] = $prefix . 'sm_redirects';
		$tables[] = $prefix . 'sm_404_logs';
		return $tables;
	}

	private static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_cache_delete( 'last_changed', self::CACHE_GROUP );
	}

	/**
	 * @return list<int>
	 */
	private static function siteIds(): array {
		return array_map(
			'intval',
			get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);
	}
}
