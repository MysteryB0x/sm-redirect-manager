=== SEOmarketeer Redirect Manager ===
Contributors: seomarketeer
Tags: redirect, 301, 404, seo, csv
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced redirect management, auto-slug tracking, and 404 monitoring.

== Description ==

* 301, 302, 307, 308, 410 and 451 responses.
* Exact, wildcard (`/blog/*` → `/news/$1`) and regex (`^/p/(\d+)$` → `/post-$1`) matching.
* Query strategies: ignore, exact match (order-insensitive) or pass-through.
* Runs on `init` before the main query; object-cache aware (zero SQL on hot paths with Redis/Memcached).
* Loop protection: self-references, A→B→A chains and chains longer than 10 hops are never executed.
* Automatic 301s when a published post/page URL changes (including wildcard rules for child pages).
* 404 log with hit aggregation, GDPR-friendly IP anonymisation, bot filter and retention purge.
* One-click "Convert to redirect" from the 404 log.
* CSV import/export (comma, semicolon or tab; Excel-safe).

Admin: Tools → Redirect Manager.

== Installation ==

1. Install the plugin from Plugins → Add New (search for "SEOmarketeer Redirect Manager"), or upload the zip.
2. Activate it. Database tables are created automatically.
3. Go to Tools → Redirect Manager and add your first redirect with the Quick Add bar.

== Screenshots ==

1. Quick Add: type a source and target and press Enter. Wildcards and regexes are detected automatically.
2. 404 log with hit counts, referrers and one-click "Convert to redirect".
3. Full redirect editor: match type, HTTP status, query-parameter strategy and status.
4. CSV import and export (comma, semicolon or tab; Excel-safe).
5. Settings: slug tracking, 404 logging, GDPR IP anonymisation and log retention.

== Theme templates ==

Place `410.php` or `451.php` in your theme to render a custom page for those status codes.

== Developer hooks ==

Filters: `sm_redirect_manager_engine_priority`, `sm_redirect_manager_allowed_methods`, `sm_redirect_manager_should_redirect`,
`sm_redirect_manager_match_result`, `sm_redirect_manager_redirect_location`, `sm_redirect_manager_track_slug_change`, `sm_redirect_manager_log_404`,
`sm_redirect_manager_client_ip`, `sm_redirect_manager_csv_max_rows`, `sm_redirect_manager_csv_max_bytes`.

Actions: `sm_redirect_manager_booted`, `sm_redirect_manager_loop_detected`, `sm_redirect_manager_deliver_status`, `sm_redirect_manager_slug_redirect_saved`,
`sm_redirect_manager_404_converted`, `sm_redirect_manager_csv_imported`.

== Changelog ==

= 1.0.2 =
* Packaging: removed stray build files that were accidentally included in the 1.0.0 and 1.0.1 packages. No functional changes.

= 1.0.1 =
* Fixed: long referrers and user agents in the 404 log no longer overlap neighbouring columns.
* Improved: column widths in the redirects and 404 tables.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.2 =
Smaller, clean package. No functional changes.

= 1.0.1 =
Layout fixes for the 404 log and redirects tables.
