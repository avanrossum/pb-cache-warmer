# Changelog

All notable changes to this project will be documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions correspond to the `Version` header in `pb-cache-warmer.php` and
`Stable tag` in `readme.txt` — keep all three in sync on every release.

---

## [Unreleased]

_Changes staged but not yet versioned._

---
== Changelog ==

= 0.9.5 =
* Restructured changelog, updated stale initial testing information, moved out of readme.md

= 0.9.4 =
* Update Plugin URI to mipyip.com/lab.

= 0.9.3 =
* Fix auto-update breaking site: vendor/composer/ was excluded from git due to an overly broad .gitignore rule ("composer" matched vendor/composer/ at any depth). After each auto-update, WordPress replaced the plugin directory with the release zip (which lacked vendor/composer/), causing a fatal require_once error. Fixed by scoping the ignore to /composer and committing vendor/composer/.

= 0.9.2 =
* Version bump to verify auto-update via Plugin Update Checker.

= 0.9.1 =
* CF purge now targets only page-builder generated CSS (et-cache, Elementor, Beaver Builder, Bricks, Oxygen, Kadence) — WordPress core, plugin, and theme assets with stable ?ver= version strings are no longer purged, since they are covered by the long-TTL Cache Rule and will be naturally missed by Cloudflare when their URL changes. Extensible via pbcw_dynamic_css_paths filter.
* Add Plugin Update Checker (PUC v5.6) for automatic updates via GitHub releases.
* Add Settings link to plugin list entry in WP Admin.
* Rename Settings menu entry to PB Cache Guard.

= 0.9.0 =
* Complete rewrite — Phase 2 now uses Cloudflare Cache Purge API instead of HTTP fetching through Cloudflare (which looped back to origin on most servers and never reached the CF edge)
* Add Cloudflare Cache Rules management (versioned assets, 1-year TTL)
* Add Phase 1b: server page cache purge in the heal path (nginx-helper, WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache)
* Add client-side CSS health check with automatic heal and reload
* Add async "Warm Now" button with live progress display
* Rename plugin to Page Builder Cache Guard
* Version to 0.9.0 (beta) — CF Cache Rules and async run need production time