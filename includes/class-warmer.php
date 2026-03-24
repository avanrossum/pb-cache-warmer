<?php
defined( 'ABSPATH' ) || exit;

/**
 * Core warmup logic — two-phase.
 *
 * Phase 1 — Origin-direct bypass warm:
 *   Appends a unique cache-bypass query string (?pbcw=<token>) to each URL,
 *   forcing every server-side cache layer (FastCGI, WP Rocket, LiteSpeed, W3TC)
 *   to skip its stored copy and invoke PHP directly. The page builder then
 *   executes a full render and writes its per-post CSS files to disk.
 *
 *   When the pbcw_origin_base filter is set (e.g. http://127.0.0.1), requests
 *   go directly to the origin server with a Host: header, bypassing Cloudflare
 *   entirely. This avoids CF bot detection, rate-limit rules, and unnecessary
 *   bandwidth on traffic that never needed to leave the server.
 *
 * Phase 2 — Cloudflare edge warm:
 *   After phase 1 the origin is warm and CSS files exist. Phase 2 fetches each
 *   page via its public URL (no bypass query string) using a real browser
 *   User-Agent so Cloudflare caches the HTML response at edge. It then parses
 *   each HTML response for same-domain <link rel="stylesheet"> hrefs and
 *   requests each CSS asset, causing CF to cache those files at edge too.
 *
 *   Phase 2 is controlled by the cf_warm setting: auto (detect CF via cf-ray
 *   header), on (always run), or off (skip). CF detection result is cached
 *   in a 1-hour transient so the network check runs at most once per hour.
 */
class PBCW_Warmer {

	/** @var array Plugin options. */
	private array $options;

	public function __construct() {
		$this->options = get_option( 'pbcw_settings', [] );
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Run a full warmup pass (phase 1 + optional phase 2) over all URLs.
	 *
	 * @param  string $trigger  Human-readable label for what triggered this run.
	 * @return array            Summary array; see save_log() for schema.
	 */
	public function run( string $trigger = 'manual' ): array {
		$start   = microtime( true );
		$urls    = $this->get_urls();
		$bypass  = $this->bypass_param();
		$delay   = (int) ( $this->options['delay_ms'] ?? 300 );
		$timeout = (int) ( $this->options['timeout_s'] ?? 45 );

		// ── Phase 1: origin-direct bypass ────────────────────────────────────
		$warmed = 0;
		$errors = [];

		foreach ( $urls as $url ) {
			$result = $this->fetch_phase1( $url, $bypass, $timeout );

			if ( $result['ok'] ) {
				$warmed++;
			} else {
				$errors[] = [ 'url' => $url, 'status' => $result['status'] ];
			}

			if ( $delay > 0 ) {
				usleep( $delay * 1000 );
			}
		}

		// ── Phase 2: CF edge warm ─────────────────────────────────────────────
		$cf_detected = $this->detect_cloudflare();
		$cf_setting  = $this->options['cf_warm'] ?? 'auto';
		$run_p2      = ( $cf_setting === 'on' ) || ( $cf_setting === 'auto' && $cf_detected );
		$cf          = null;

		if ( $run_p2 ) {
			$cf = $this->run_phase2( $urls, $timeout, $delay );
		}

		$summary = [
			'trigger'     => $trigger,
			'time'        => time(),
			'urls'        => count( $urls ),
			'warmed'      => $warmed,
			'errors'      => $errors,
			'duration'    => round( microtime( true ) - $start, 1 ),
			'cf_detected' => $cf_detected,
			'cf_setting'  => $cf_setting,
			'cf'          => $cf, // null = skipped; array = ran
		];

		$this->save_log( $summary );

		return $summary;
	}

	/**
	 * Warm a single URL through both phases. Used for post-save single-page
	 * warmup (e.g. after a Divi page save). Does not write to the run log.
	 *
	 * @param string $url     Permalink of the post to warm.
	 * @param string $trigger Label for debugging/logging purposes.
	 */
	public function run_single( string $url, string $trigger = 'single' ): void {
		$bypass  = $this->bypass_param();
		$timeout = (int) ( $this->options['timeout_s'] ?? 45 );

		$this->fetch_phase1( $url, $bypass, $timeout );

		$cf_setting = $this->options['cf_warm'] ?? 'auto';
		if ( $cf_setting === 'on' || ( $cf_setting === 'auto' && $this->detect_cloudflare() ) ) {
			$this->run_phase2( [ $url ], $timeout, 0 );
		}
	}

	/**
	 * Get all published page/post URLs, applying post type and exclusion config.
	 *
	 * @return string[]
	 */
	public function get_urls(): array {
		$post_types = apply_filters( 'pbcw_post_types', [ 'page', 'post' ] );
		$excluded   = array_filter( array_map( 'trim', explode( "\n", $this->options['excluded_urls'] ?? '' ) ) );

		$ids = get_posts( [
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'numberposts'            => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		$urls = [];
		foreach ( $ids as $id ) {
			$permalink = get_permalink( $id );
			if ( ! $permalink ) {
				continue;
			}
			$path = rtrim( wp_parse_url( $permalink, PHP_URL_PATH ), '/' );
			foreach ( $excluded as $excl ) {
				if ( $excl && str_contains( $path, rtrim( $excl, '/' ) ) ) {
					continue 2;
				}
			}
			$urls[] = $permalink;
		}

		return $urls;
	}

	/**
	 * Detect whether the site is fronted by Cloudflare.
	 *
	 * Two-signal approach:
	 *
	 * 1. Inbound request headers — if the current PHP request arrived through
	 *    Cloudflare (e.g. a browser or admin page load), CF injects
	 *    HTTP_CF_RAY and HTTP_CF_CONNECTING_IP into $_SERVER. This is the
	 *    most reliable signal and works even when the server routes outbound
	 *    requests to itself (bypassing CF internally).
	 *
	 * 2. Outbound HEAD request — fallback for CLI/cron contexts where there is
	 *    no incoming request. Makes a HEAD request to home_url() and checks for
	 *    the cf-ray response header. Fails silently if the server routes back
	 *    to itself without going through CF (returns false in that case).
	 *
	 * Result cached in a transient for 1 hour. Cleared on settings save.
	 * Once detected via signal 1 (admin page load), the cached true result
	 * covers subsequent cron/background runs.
	 */
	public function detect_cloudflare(): bool {
		$cached = get_transient( 'pbcw_cf_detected' );
		if ( $cached !== false ) {
			return (bool) $cached;
		}

		// Signal 1: CF forwarding headers on the current inbound request.
		if ( ! empty( $_SERVER['HTTP_CF_RAY'] ) || ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			set_transient( 'pbcw_cf_detected', 1, HOUR_IN_SECONDS );
			return true;
		}

		// Signal 2: outbound HEAD request (may not work if server loops back).
		$response = wp_remote_head( home_url( '/' ), [
			'timeout'    => 10,
			'user-agent' => $this->phase2_ua(),
			'sslverify'  => apply_filters( 'pbcw_sslverify', true ),
			'redirection' => 5,
		] );

		$detected = ! is_wp_error( $response ) &&
		            wp_remote_retrieve_header( $response, 'cf-ray' ) !== '';

		// Store 1/0 — never store raw false (WP uses false as "not found" sentinel).
		set_transient( 'pbcw_cf_detected', $detected ? 1 : 0, HOUR_IN_SECONDS );
		return $detected;
	}

	/** Return stored run history. */
	public static function get_log(): array {
		return get_option( 'pbcw_log', [] );
	}

	// ── Phase 1 ───────────────────────────────────────────────────────────────

	/**
	 * Fetch a single URL with the cache-bypass query string.
	 *
	 * When pbcw_origin_base is set, routes the request directly to origin
	 * using that base URL (e.g. http://127.0.0.1) with a Host: header,
	 * bypassing Cloudflare and any upstream proxy entirely.
	 *
	 * Example mu-plugin usage:
	 *   add_filter( 'pbcw_origin_base', fn() => 'http://127.0.0.1' );
	 */
	private function fetch_phase1( string $public_url, string $bypass, int $timeout ): array {
		$origin_base = apply_filters( 'pbcw_origin_base', null );

		if ( $origin_base ) {
			$parsed    = wp_parse_url( $public_url );
			$path      = $parsed['path'] ?? '/';
			$fetch_url = add_query_arg( 'pbcw', $bypass, rtrim( $origin_base, '/' ) . $path );
			$args      = [
				'timeout'    => $timeout,
				'user-agent' => 'pb-cache-warmer/' . PBCW_VERSION,
				// Origin-direct typically means HTTP or self-signed HTTPS — default off.
				'sslverify'  => apply_filters( 'pbcw_sslverify', false ),
				'redirection' => 0, // origin serves directly; don't chase redirects
				'headers'    => [
					'Host' => wp_parse_url( $public_url, PHP_URL_HOST ),
				],
			];
		} else {
			$fetch_url = add_query_arg( 'pbcw', $bypass, $public_url );
			$args      = [
				'timeout'    => $timeout,
				'user-agent' => 'pb-cache-warmer/' . PBCW_VERSION,
				'sslverify'  => apply_filters( 'pbcw_sslverify', true ),
				'redirection' => 5,
			];
		}

		$response = wp_remote_get( $fetch_url, $args );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'status' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		return [ 'ok' => ( $code >= 200 && $code < 400 ), 'status' => $code ];
	}

	// ── Phase 2 ───────────────────────────────────────────────────────────────

	/**
	 * Warm Cloudflare's edge cache for a set of URLs.
	 *
	 * Step A: Fetch each page URL cleanly (no bypass query string) with a real
	 *         browser User-Agent. CF caches the HTML response at edge.
	 * Step B: Parse each HTML response for same-domain stylesheet link hrefs.
	 *         Collect all unique CSS URLs across all pages.
	 * Step C: Request each CSS URL. CF fetches from origin (files now exist
	 *         from phase 1) and caches them at edge.
	 *
	 * @return array { pages: int, css: int, errors: array[] }
	 */
	private function run_phase2( array $urls, int $timeout, int $delay ): array {
		$ua        = $this->phase2_ua();
		$parsed    = wp_parse_url( home_url() );
		$base_host = $parsed['scheme'] . '://' . $parsed['host'];

		$pages_warmed = 0;
		$css_warmed   = 0;
		$errors       = [];
		$all_css_urls = [];

		// Step A + B: fetch pages and discover CSS URLs.
		foreach ( $urls as $url ) {
			$response = wp_remote_get( $url, [
				'timeout'    => $timeout,
				'user-agent' => $ua,
				'sslverify'  => apply_filters( 'pbcw_sslverify', true ),
				'redirection' => 5,
			] );

			if ( is_wp_error( $response ) ) {
				$errors[] = [ 'url' => $url, 'status' => $response->get_error_message(), 'phase' => 'p2-page' ];
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 400 ) {
				$errors[] = [ 'url' => $url, 'status' => $code, 'phase' => 'p2-page' ];
				continue;
			}

			$pages_warmed++;
			$css_urls = $this->extract_css_urls( wp_remote_retrieve_body( $response ), $base_host );
			foreach ( $css_urls as $css_url ) {
				$all_css_urls[ $css_url ] = true;
			}

			if ( $delay > 0 ) {
				usleep( $delay * 1000 );
			}
		}

		// Step C: request all unique CSS URLs.
		foreach ( array_keys( $all_css_urls ) as $css_url ) {
			$response = wp_remote_get( $css_url, [
				'timeout'    => 15,
				'user-agent' => $ua,
				'sslverify'  => apply_filters( 'pbcw_sslverify', true ),
				'redirection' => 3,
			] );

			if ( is_wp_error( $response ) ) {
				$errors[] = [ 'url' => $css_url, 'status' => $response->get_error_message(), 'phase' => 'p2-css' ];
			} else {
				$code = wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 400 ) {
					$css_warmed++;
				} else {
					$errors[] = [ 'url' => $css_url, 'status' => $code, 'phase' => 'p2-css' ];
				}
			}

			if ( $delay > 0 ) {
				usleep( (int) ( $delay / 2 * 1000 ) ); // lighter throttle for static assets
			}
		}

		return [
			'pages'  => $pages_warmed,
			'css'    => $css_warmed,
			'errors' => $errors,
		];
	}

	/**
	 * Extract same-domain stylesheet hrefs from an HTML document.
	 * Returns absolute URLs (including query strings) for each unique asset.
	 *
	 * @param  string $html       Full HTML body.
	 * @param  string $base_host  scheme://host with no trailing slash.
	 * @return string[]
	 */
	private function extract_css_urls( string $html, string $base_host ): array {
		preg_match_all( '/<link[^>]+>/i', $html, $tag_matches );

		$urls = [];
		foreach ( $tag_matches[0] as $tag ) {
			// Must have rel="stylesheet".
			if ( ! preg_match( '/rel=["\']stylesheet["\']/i', $tag ) ) {
				continue;
			}
			// Must have an href.
			if ( ! preg_match( '/href=["\']([^"\']+)["\']/i', $tag, $href ) ) {
				continue;
			}

			$url = $href[1];

			// Resolve protocol-relative URLs.
			if ( str_starts_with( $url, '//' ) ) {
				$url = wp_parse_url( $base_host, PHP_URL_SCHEME ) . ':' . $url;
			}

			// Resolve site-root-relative URLs.
			if ( str_starts_with( $url, '/' ) ) {
				$url = rtrim( $base_host, '/' ) . $url;
			}

			// Keep same-domain only — do not warm external CDNs.
			if ( str_starts_with( $url, $base_host ) ) {
				$urls[] = $url; // preserve query string (?ver=...) — CF caches by full URL
			}
		}

		return array_unique( $urls );
	}

	// ── Shared helpers ────────────────────────────────────────────────────────

	/**
	 * User-Agent used for phase 2 (public-URL) requests.
	 * A real browser UA avoids CF Bot Fight Mode triggering on warmup requests.
	 * Override via the pbcw_user_agent filter.
	 */
	private function phase2_ua(): string {
		return apply_filters(
			'pbcw_user_agent',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
		);
	}

	/**
	 * Returns a unique bypass token for this run.
	 * Randomised per-run so CDN edge caches also miss. Stable within a run
	 * (static var) so all phase 1 requests share the same token.
	 */
	private function bypass_param(): string {
		static $token = null;
		if ( $token === null ) {
			$token = substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		return $token;
	}

	/**
	 * Persist the last 10 run summaries to wp_options (autoload: no).
	 *
	 * Summary schema:
	 *   trigger     string   What triggered the run.
	 *   time        int      Unix timestamp.
	 *   urls        int      Total URLs collected.
	 *   warmed      int      Phase 1 success count.
	 *   errors      array    Phase 1 errors: [ { url, status } ].
	 *   duration    float    Total wall-clock seconds.
	 *   cf_detected bool     Whether CF was detected via cf-ray header.
	 *   cf_setting  string   'auto'|'on'|'off' at time of run.
	 *   cf          null|{   Phase 2 results; null if phase 2 was skipped.
	 *     pages  int           Pages warmed at CF edge.
	 *     css    int           CSS files warmed at CF edge.
	 *     errors array         Phase 2 errors: [ { url, status, phase } ].
	 *   }
	 */
	private function save_log( array $summary ): void {
		$log = get_option( 'pbcw_log', [] );
		array_unshift( $log, $summary );
		update_option( 'pbcw_log', array_slice( $log, 0, 10 ), false );
	}
}
