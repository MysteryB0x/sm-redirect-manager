# SM Redirect Manager

Advanced redirect management, auto-slug tracking, and 404 monitoring for WordPress — by [SEOmarketeer](https://seomarketeer.eu).

![CI](../../actions/workflows/ci.yml/badge.svg)

## Features

- 301, 302, 307, 308, 410 and 451 responses
- Exact, wildcard (`/blog/*` → `/news/$1`) and regex (`^/p/(\d+)$` → `/post-$1`) matching
- Query strategies: ignore, exact match, pass-through
- Quick Add bar with automatic match-type detection
- Automatic 301s when a published URL changes (incl. child pages), with chain flattening
- 404 log with hit aggregation, GDPR IP anonymisation and one-click "convert to redirect"
- CSV import/export (comma, semicolon or tab; Excel-safe)
- Loop protection, object-cache aware, runs before the main query

## Requirements

WordPress 6.0+ · PHP 8.1+

## Development

```
sm-redirect-manager.php   bootstrap
src/                      PSR-4 (SEOmarketeer\RedirectManager\)
assets/                   admin JS/CSS
```

CI runs PHP 8.1–8.4 syntax checks and the official WordPress Plugin Check on every push.
Publishing a GitHub Release deploys to WordPress.org (see `.github/workflows/deploy.yml`).

## License

GPL-2.0-or-later
