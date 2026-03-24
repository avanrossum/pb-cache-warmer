== Page Builder Cache Warmer ==

Contributors:      avanrossum
Tags:              divi, elementor, beaver builder, cache, performance
Requires at least: 6.0
Tested up to:      6.8
Stable tag:        1.0.0
Requires PHP:      8.0
License:           GPL-3.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-3.0.html

Proactively warms page builder CSS caches after purge events so users never land on an unstyled page.

== Description ==

Page builders like Divi, Elementor, and Beaver Builder generate per-post CSS
files on first render and store them on disk. When a caching plugin purges its
cache (or when a server migration forces a cold start), those files are
regenerated on demand — meaning the first user to visit each page after the
purge triggers a slow PHP render.

This plugin solves that by crawling all published pages after cache clear
events, forcing PHP to execute and the page builder to write its CSS files to
disk before any real user arrives.

= How it works =

The warmer makes an HTTP request to each published page with a randomised query
string. The query string bypasses server-side caching layers (GP FastCGI,
WP Rocket file cache, LiteSpeed Cache, W3TC, etc.) so PHP actually runs,
WordPress loads, and the page builder generates its CSS. Subsequent real-user
requests find the CSS already on disk and load fast.

= Supported page builders =

Any page builder that generates CSS on first render benefits automatically:
Divi (et-cache), Elementor (/elementor/css/), Beaver Builder (bb-plugin/cache),
Bricks, Oxygen, Kadence Blocks, GeneratePress, and others.

= Supported caching plugins (auto-trigger) =

- WP Rocket
- LiteSpeed Cache
- W3 Total Cache
- WP Super Cache
- Autoptimize
- GridPane nginx-helper
- Elementor (CSS regeneration)
- Beaver Builder (cache clear)
- Bricks (post save)

= Features =

* Scheduled warmup (hourly / twice daily / daily / weekly)
* Auto-warmup triggered by cache clear events
* Single-post warmup on save (for Divi et-cache regeneration)
* Configurable delay between requests
* Exclude URL paths
* Select which post types to warm
* Admin run history log (last 10 runs)
* Manual "Warm Now" button in WP Admin

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate the plugin
3. Go to Settings → Cache Warmer to configure

== Frequently Asked Questions ==

= Will this slow down my admin? =

Auto-warmup and scheduled warmup run asynchronously via WP-Cron — they don't
block admin requests. The "Warm Now" button runs synchronously (the page waits
for completion), so it may take a minute or two for large sites.

= What is the cache bypass query string? =

?pbcw=<random>. The value is randomised per run so CDN edge caches (Cloudflare,
Varnish) also treat each warmup request as uncached. The parameter is ignored
by WordPress — it has no effect on page content.

= Does this work with Divi 5? =

Divi 5 uses a different CSS generation architecture (server-rendered, no
per-post et-cache files). This plugin is still useful for warming the FastCGI
or CDN cache after a purge, but the "missing CSS" problem that motivated this
plugin is specific to Divi 4's et-cache. Divi 5 sites benefit from the
scheduled warmup for general cache warming.

= Will this hammer my server? =

The default 300ms delay between requests means a 300-page site takes ~2 minutes
to warm. This generates ~1 PHP request every 300ms — comparable to light organic
traffic. Raise the delay if you're on a resource-constrained server.

== Changelog ==

= 1.0.0 =
* Initial release.
