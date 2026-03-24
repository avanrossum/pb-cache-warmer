== Page Builder Cache Guard ==

Contributors:      avanrossum
Tags:              divi, elementor, beaver builder, cloudflare, cache, performance
Requires at least: 6.0
Tested up to:      6.8
Stable tag:        0.9.0
Requires PHP:      8.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Prevents missing CSS on page builder sites by warming the server cache after
purge events and optionally syncing Cloudflare's edge cache via the API.

Developed by Alex van Rossum at MipYip — https://mipyip.com

== The Problem This Solves ==

If you run a WordPress site with a page builder (Divi, Elementor, Beaver Builder,
Bricks, etc.) behind a server-side page cache and/or Cloudflare, you have probably
seen this: a cache purge happens, and the next visitor gets a white screen or an
unstyled page. Maybe it fixes itself on reload. Maybe it doesn't.

Here is why it happens.

Page builders generate per-post CSS files on first render and store them on disk
(Divi's "et-cache", Elementor's "/elementor/css/", etc.). When your caching plugin
purges its page cache, those CSS files are NOT necessarily deleted — but the HTML
pages that reference them are. The problem has two distinct failure modes, and you
need to address both:

**Failure Mode A — Origin cold start**

The page cache was purged, so the next visitor triggers a fresh PHP render. The
page builder tries to serve the CSS file for that specific page. If the file does
not exist on disk yet (for example, after a full cache wipe that also cleared
et-cache), the page builder writes it during that render — but only for the user
who happened to arrive first. Everyone who arrived in the milliseconds before the
file was written gets a 404 on the CSS. They see an unstyled page.

**Failure Mode B — Cloudflare edge serving stale HTML**

Cloudflare has your HTML cached at its edge. That HTML references CSS files that
no longer exist at origin. Cloudflare serves the HTML from cache (fast!) but the
browser requests the CSS file, Cloudflare tries to fetch it from origin, and gets
a 404 — because the CSS file has not been regenerated yet. This happens even if
your origin server is perfectly healthy.

**Failure Mode C — Page cache and CSS out of sync**

The page cache is serving HTML that was generated before the most recent CSS
regeneration. The HTML references old CSS filenames. The new CSS files exist on
disk, but the HTML is pointing at paths that no longer exist. The page cache
entry needs to be invalidated so the next request gets fresh HTML with the
correct CSS paths.

== How Page Builder Cache Guard Fixes This ==

The plugin uses a layered approach:

**Phase 1 — Force origin warmup**

After any cache purge event (or on a schedule, or manually), the plugin crawls
every published page on your site using a cache-bypassing query string
(?pbcw=<token>). This forces your server to skip its page cache and run a full
PHP render for every page. The page builder writes all of its CSS files to disk.
When real users arrive, the CSS files are already there.

The bypass token is randomised per run so that Cloudflare and other CDN edges
also treat each request as uncached — no stale edge responses slip through.

If you set the "pbcw_origin_base" filter (see Advanced Configuration below),
Phase 1 requests go directly to your server's local IP, bypassing Cloudflare
entirely. This avoids Cloudflare's Bot Fight Mode and rate limiting for warmup
traffic that never needed to go through the CDN in the first place.

**Phase 1b — Server page cache purge (heal path only)**

When the client-side health check detects a broken page and triggers a heal,
the plugin also purges the server-side page cache entry for that specific URL
after regenerating the CSS. This ensures the browser's subsequent reload gets
a fresh PHP-rendered page with correct, up-to-date CSS references — not the
stale cached HTML that caused the problem in the first place.

Supports: nginx-helper (FastCGI / Redis page cache), WP Rocket, LiteSpeed Cache,
W3 Total Cache, WP Super Cache. Extensible via the "pbcw_purge_page_cache" action.

**Phase 2 — Cloudflare cache purge (optional)**

If you configure a Cloudflare API token and Zone ID, the plugin tells Cloudflare
to drop its cached copies of your HTML and CSS after each warmup run. Cloudflare
re-fetches them from your now-warm origin on the next real browser request. This
closes Failure Mode B completely — CF cannot serve stale HTML because we just
invalidated it.

This uses the Cloudflare Cache Purge API (Zone.Cache.Purge permission) and
batches requests to stay within API rate limits.

**Cloudflare Cache Rule (optional)**

The plugin can create a Cache Rule in your Cloudflare zone that sets a one-year
TTL on any CSS or JS file with a "?ver=" query string. WordPress and all page
builders append "?ver=" to every enqueued asset. Because the URL changes when the
file changes, a one-year TTL is completely safe — Cloudflare will always serve the
right version.

This means Cloudflare almost never needs to ask your origin for CSS or JS files
during normal operation. The Phase 2 purge is the safety net for the rare cases
when CF's cache is cold or has been fully purged.

Requires Zone.Cache Settings (write) permission on your API token. The plugin
shows you the exact rule it will create before you confirm. It will not overwrite
any existing Cache Rules in your zone.

**Client-side CSS health check (optional)**

A small script is injected on every frontend page. After the page loads, it checks
whether every stylesheet loaded successfully. If any stylesheet returns a 404, the
script calls the plugin's heal endpoint, which runs a single-page warmup (Phase 1
+ 1b + 2) for that URL, then reloads the page.

A session storage guard prevents reload loops — the heal fires at most once per
page per browser session.

This is the last line of defence. If a real user encounters a broken page before
the scheduled warmup has run, the health check catches it and fixes it while they
wait.

== Features ==

* Scheduled warmup — hourly, twice daily, daily, or weekly via WP-Cron
* Auto-warmup triggered by cache clear events from supported plugins
* Single-post warmup on save (Divi et-cache regeneration)
* Async "Warm Now" button — runs in the background, no browser timeout
* Live progress display while warmup is running
* Cloudflare API integration — cache purge after warmup, Cache Rules management
* Client-side CSS health check with automatic heal and page reload
* Server page cache purge on heal (nginx-helper, WP Rocket, LiteSpeed, W3TC)
* Configurable delay between requests (default 300ms)
* Configurable per-request timeout
* Exclude URL paths from warmup
* Select which post types to warm
* Admin run history log (last 10 runs) with per-phase results

== Supported Page Builders ==

Any page builder that writes CSS files to disk on first render:

* Divi 4 (et-cache)
* Elementor (/elementor/css/)
* Beaver Builder (bb-plugin/cache)
* Bricks
* Oxygen
* Kadence Blocks, GeneratePress, and others

Note: Divi 5 uses a different CSS architecture (no per-post et-cache files).
The warmup and health check still help with general cache priming, but the
specific CSS-missing failure modes above are Divi 4 scenarios.

== Supported Cache Plugins (auto-trigger) ==

The plugin hooks into cache clear events from:

* WP Rocket
* LiteSpeed Cache
* W3 Total Cache
* WP Super Cache
* Autoptimize
* GridPane nginx-helper
* Elementor (CSS regeneration)
* Beaver Builder (cache clear)
* Bricks (post save)

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate the plugin
3. Go to Settings → Cache Warmer
4. Configure your post types, schedule, and delay
5. If you use Cloudflare: paste your API token, detect or paste your Zone ID,
   and optionally apply the versioned assets Cache Rule
6. Enable the CSS health check if you want client-side protection
7. Click "Run Warmup" to warm your site immediately

== Advanced Configuration ==

= Bypass Cloudflare for Phase 1 (recommended on CF-proxied servers) =

On servers where outbound requests route back through Cloudflare (most managed
WordPress hosts), Phase 1 warmup traffic passes through CF unnecessarily and
can be affected by Bot Fight Mode or rate limiting.

Add this to a must-use plugin or wp-config.php to send Phase 1 requests directly
to your server's local IP:

    add_filter( 'pbcw_origin_base', fn() => 'http://127.0.0.1' );

The plugin will add the correct Host: header automatically.

= Available filters =

* pbcw_origin_base — Override the base URL for Phase 1 requests (e.g. http://127.0.0.1)
* pbcw_post_types  — Add or remove post types from the warmup URL list
* pbcw_sslverify   — SSL verification (default: true for public URLs, false for origin-direct)
* pbcw_purge_page_cache — Action fired during heal for custom page cache integrations

== Cloudflare API Token Setup ==

Create a token at https://dash.cloudflare.com/profile/api-tokens with the
following permissions for the relevant zone:

* Zone — Cache Purge — Purge (required for Phase 2 cache purge)
* Zone — Cache Settings — Edit (required for Cache Rules management)
* Zone — Zone — Read (required for Zone ID auto-detection)

All three can be on a single token. Cache Settings and Zone are only needed if
you want to use those features — the Phase 2 purge works with Cache Purge alone.

== Frequently Asked Questions ==

= Will this slow down my server? =

The default 300ms delay between requests means a 300-page site takes about two
minutes to warm. This generates roughly one PHP request every 300ms — comparable
to light organic traffic. Raise the delay in Settings if your server is under load.

Auto-warmup and scheduled warmup run via WP-Cron and do not block admin pages.
The "Warm Now" button uses an async background request, so the admin page returns
immediately while the warmup runs.

= Does the health check affect page load speed? =

The injected script is about 600 bytes of inline JavaScript. It runs a passive
check after the page has fully loaded — it does not block rendering or affect
your Core Web Vitals.

= I don't use Cloudflare. Is this plugin still useful? =

Yes. Phase 1 (origin warmup) and the CSS health check work independently of
Cloudflare. If you have any server-side page cache (FastCGI, WP Rocket, LiteSpeed,
etc.) and a page builder that generates CSS on first render, you can hit Failure
Mode A or C without Cloudflare involved at all.

= What does "Phase 1b" mean in the technical docs? =

When the health check triggers a heal, it's because a real user hit a broken
page. At that point we know the server's page cache is serving stale HTML (or
the CSS file genuinely doesn't exist). After regenerating the CSS (Phase 1),
we also purge the server page cache entry for that URL, so the browser's reload
gets fresh HTML that references the correct CSS paths. This step is called
Phase 1b. It only runs during the heal path, not during full warmup runs.

= The "Warm Now" button used to time out. Is that fixed? =

Yes. The button now fires an asynchronous background request and polls for
status every 2.5 seconds. The admin page never blocks regardless of how many
URLs are queued.

== Changelog ==

= 0.9.0 =
* Complete rewrite — Phase 2 now uses Cloudflare Cache Purge API instead of
  HTTP fetching through Cloudflare (which looped back to origin on most servers
  and never reached the CF edge)
* Add Cloudflare Cache Rules management (versioned assets, 1-year TTL)
* Add Phase 1b: server page cache purge in the heal path (nginx-helper, WP
  Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache)
* Add client-side CSS health check with automatic heal and reload
* Add async "Warm Now" button with live progress display
* Rename plugin to Page Builder Cache Guard
* Version to 0.9.0 (beta) — CF Cache Rules and async run need production time

== About ==

Page Builder Cache Guard is developed and maintained by Alex van Rossum at
MipYip — a WordPress and infrastructure consultancy.

https://mipyip.com
