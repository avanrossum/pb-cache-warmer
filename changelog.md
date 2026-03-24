# Changelog

All notable changes to this project will be documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions correspond to the `Version` header in `pb-cache-warmer.php` and
`Stable tag` in `readme.txt` — keep all three in sync on every release.

---

## [Unreleased]

_Changes staged but not yet versioned._

---

## [1.0.0] — 2026-03-23

### Added
- Core warmup engine (`PBCW_Warmer`): collects published page/post URLs,
  fetches each with a randomised cache-bypass query string (`?pbcw=<token>`),
  stores last 10 run summaries in `wp_options`.
- WP-Cron scheduler (`PBCW_Scheduler`): daily scheduled warmup at 03:00,
  configurable to hourly / twice daily / weekly. Activates and deactivates
  cleanly.
- Cache-clear hook integrations (`PBCW_Hooks`): auto-warmup triggered by
  WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Autoptimize,
  GridPane nginx-helper, Elementor CSS clear, Beaver Builder cache clear,
  Bricks builder save, Oxygen, `switch_theme`, and `upgrader_process_complete`.
- Divi `save_post` integration: single-post warmup 15s after Divi page save.
  Gated to Divi-active sites by checking `defined('ET_BUILDER_VERSION')` at
  call time (not at hook registration, to respect theme load order).
- Admin settings page (Settings → Cache Warmer): auto-warmup toggle, cron
  schedule selector, delay between requests, request timeout, post type
  selection, excluded URL path list.
- Manual "Warm Now" button with synchronous run and inline result summary.
- Run history table showing last 10 runs (time, trigger, warmed count, errors,
  duration). Errors expandable via `<details>`.
- `pbcw_post_types` filter for programmatic post type extension.
- `pbcw_sslverify` filter for self-signed origin cert environments.
- Auto-warmup de-duplication: skips scheduling if an event is already queued
  within the next 5 minutes.
- GPL-2.0-or-later licence.
- `readme.txt` in WordPress.org format.
- `CLAUDE.md`, `architecture.md`, `roadmap.md`, `changelog.md` for agent
  continuity and project documentation.

### Notes
- Initial build. Not yet committed or released.
- Known limitation: `*-late-ds.css` (Divi deferred-split CSS) is not generated
  by the warmer's bypass-query-string requests. Mitigate at server level with
  an nginx `try_files` fast-fail rule for `/wp-content/et-cache/`. See
  `architecture.md` → Known Limitations.
