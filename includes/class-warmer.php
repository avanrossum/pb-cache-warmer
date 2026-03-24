<?php
defined( 'ABSPATH' ) || exit;

/**
 * Core warmup engine — two phases.
 *
 * Phase 1 — Origin-direct bypass:
 *   Fetches each URL with ?pbcw=<token>, bypassing all server-side caches and
 *   forcing the page builder to run a full PHP render. Per-post CSS files are
 *   written to disk. When pbcw_origin_base is set, requests go directly to
 *   origin (e.g. http://127.0.0.1) with a Host: header, skipping Cloudflare.
 *
 * Phase 2 — Cloudflare cache purge:
 *   Fetches each page HTML from origin (without bypass token, so we get what
 *   the cache actually serves), parses same-domain stylesheet hrefs, then calls
 *   the CF Zones API to purge both the page URL and all CSS URLs. CF re-fetches
 *   them fresh on the next real browser request — where they now exist at origin.
 *   Only runs when a CF API token and Zone ID are configured in settings.
 */
class PBCW_Warmer {

	/** @var array Plugin options. */
	private array $options;

	public function __construct() {
		$this->options = get_option( 'pbcw_settings', [] );
	}

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Run a full warmup pass over all configured URLs.
	 *
	 * @param  string $trigger  Label for what triggered this run.
	 * @return array            Run summary — see save_log() for schema.
	 */
	public function run( string $trigger = 'manual' ): array {
		$start   = microtime( true );
		$urls    = $this->get_urls();
		$bypass  = $this->bypass_param();
		$delay   = (int) ( $this->options['delay_ms'] ?? 300 );
		$timeout = (int) ( $this->options['timeout_s'] ?? 45 );

		// Phase 1: force PHP render on every URL, regenerate page-builder CSS.
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

		// Phase 2: purge CF cache so it re-fetches the freshly-generated assets.
		$cf         = null;
		$cloudflare = $this->make_cloudflare();

		if ( $cloudflare->is_configured() ) {
			$cf = $this->run_cf_purge( $urls, $timeout, $cloudflare );
		}

		$summary = [
			'trigger'  => $trigger,
			'time'     => time(),
			'urls'     => count( $urls ),
			'warmed'   => $warmed,
			'errors'   => $errors,
			'duration' => round( microtime( true ) - $start, 1 ),
			'cf'       => $cf,
		];

		$this->save_log( $summary );
		return $summary;
	}

	/**
	 * Warm a single URL through both phases.
	 * Used for post-save single-page warmup. Does not write to the run log.
	 */
	public function run_single( string $url, string $trigger = 'single' ): void {
		$bypass  = $this->bypass_param();
		$timeout = (int) ( $this->options['timeout_s'] ?? 45 );

		$this->fetch_phase1( $url, $bypass, $timeout );

		$cloudflare = $this->make_cloudflare();
		if ( $cloudflare->is_configured() ) {
			$this->run_cf_purge( [ $url ], $timeout, $cloudflare );
		}
	}

	/**
	 * Collect all published page/post URLs, applying post-type and exclusion config.
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

	/** Return stored run history. */
	public static function get_log(): array {
		return get_option( 'pbcw_log', [] );
	}

	// ── Phase 1 ────────────────────────────────────────────────────────────────

	/**
	 * Fetch a single URL with the cache-bypass query string.
	 *
	 * When pbcw_origin_base is set (e.g. http://127.0.0.1), the request goes
	 * directly to origin with a Host: header, bypassing Cloudflare and any
	 * upstream proxy. Set in a must-use plugin or wp-config.php:
	 *
	 *   add_filter( 'pbcw_origin_base', fn() => 'http://127.0.0.1' );
	 */
	private function fetch_phase1( string $public_url, string $bypass, int $timeout ): array {
		$origin_base = apply_filters( 'pbcw_origin_base', null );

		if ( $origin_base ) {
			$path      = wp_parse_url( $public_url, PHP_URL_PATH ) ?? '/';
			$fetch_url = add_query_arg( 'pbcw', $bypass, rtrim( $origin_base, '/' ) . $path );
			$args      = [
				'timeout'    => $timeout,
				'user-agent' => 'pb-cache-warmer/' . PBCW_VERSION,
				'sslverify'  => apply_filters( 'pbcw_sslverify', false ),
				'redirection' => 0,
				'headers'    => [ 'Host' => wp_parse_url( $public_url, PHP_URL_HOST ) ],
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

	// ── Phase 2 ────────────────────────────────────────────────────────────────

	/**
	 * Purge CF's cached copies of each page and its CSS dependencies.
	 *
	 * Fetches each page from origin (no bypass token — we want the real cached
	 * response) to discover stylesheet hrefs, then purges all discovered URLs
	 * via the CF API. CF will re-fetch them from origin on the next browser request,
	 * where phase 1 has already ensured the files exist.
	 *
	 * @return array{ purged_pages: int, purged_css: int, errors: string[] }
	 */
	private function run_cf_purge( array $urls, int $timeout, PBCW_Cloudflare $cf ): array {
		$parsed    = wp_parse_url( home_url() );
		$base_host = $parsed['scheme'] . '://' . $parsed['host'];

		$page_urls = array_unique( $urls );
		$css_urls  = [];

		foreach ( $urls as $url ) {
			$html = $this->fetch_html_origin( $url, $timeout );
			if ( $html === '' ) {
				continue;
			}
			foreach ( $this->extract_css_urls( $html, $base_host ) as $css_url ) {
				$css_urls[ $css_url ] = true;
			}
		}

		$all_urls = array_merge( $page_urls, array_keys( $css_urls ) );
		$success  = $cf->purge_urls( $all_urls );

		return [
			'purged_pages' => count( $page_urls ),
			'purged_css'   => count( $css_urls ),
			'errors'       => $success ? [] : [ 'CF API purge failed — check token and Zone ID in settings.' ],
		];
	}

	/**
	 * Fetch HTML from origin without a bypass token.
	 * Used to discover CSS dependencies for the CF purge list.
	 * Uses pbcw_origin_base when set (faster, avoids CF loopback issue).
	 *
	 * @return string HTML body, or empty string on failure.
	 */
	private function fetch_html_origin( string $public_url, int $timeout ): string {
		$origin_base = apply_filters( 'pbcw_origin_base', null );

		if ( $origin_base ) {
			$path      = wp_parse_url( $public_url, PHP_URL_PATH ) ?? '/';
			$fetch_url = rtrim( $origin_base, '/' ) . $path;
			$args      = [
				'timeout'    => $timeout,
				'user-agent' => 'pb-cache-warmer/' . PBCW_VERSION,
				'sslverify'  => apply_filters( 'pbcw_sslverify', false ),
				'redirection' => 0,
				'headers'    => [ 'Host' => wp_parse_url( $public_url, PHP_URL_HOST ) ],
			];
		} else {
			$fetch_url = $public_url;
			$args      = [
				'timeout'    => $timeout,
				'user-agent' => 'pb-cache-warmer/' . PBCW_VERSION,
				'sslverify'  => apply_filters( 'pbcw_sslverify', true ),
				'redirection' => 5,
			];
		}

		$response = wp_remote_get( $fetch_url, $args );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		return ( $code >= 200 && $code < 400 ) ? wp_remote_retrieve_body( $response ) : '';
	}

	/**
	 * Extract same-domain stylesheet hrefs from an HTML document.
	 * Preserves query strings (?ver=...) — CF caches by full URL.
	 *
	 * @return string[]
	 */
	private function extract_css_urls( string $html, string $base_host ): array {
		preg_match_all( '/<link[^>]+>/i', $html, $tags );

		$urls = [];
		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '/rel=["\']stylesheet["\']/i', $tag ) ) {
				continue;
			}
			if ( ! preg_match( '/href=["\']([^"\']+)["\']/i', $tag, $m ) ) {
				continue;
			}

			$url = $m[1];

			if ( str_starts_with( $url, '//' ) ) {
				$url = wp_parse_url( $base_host, PHP_URL_SCHEME ) . ':' . $url;
			} elseif ( str_starts_with( $url, '/' ) ) {
				$url = rtrim( $base_host, '/' ) . $url;
			}

			if ( str_starts_with( $url, $base_host ) ) {
				$urls[] = $url;
			}
		}

		return array_unique( $urls );
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/** Instantiate PBCW_Cloudflare from current settings. */
	private function make_cloudflare(): PBCW_Cloudflare {
		return new PBCW_Cloudflare(
			$this->options['cf_token']   ?? '',
			$this->options['cf_zone_id'] ?? ''
		);
	}

	/**
	 * Per-run bypass token. Randomised so CDN edge caches also miss.
	 * Static so all phase 1 requests within a run share the same token.
	 */
	private function bypass_param(): string {
		static $token = null;
		if ( $token === null ) {
			$token = substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		return $token;
	}

	/**
	 * Prepend a run summary to the log, keeping the last 10 entries.
	 *
	 * Summary schema:
	 *   trigger        string   What triggered the run.
	 *   time           int      Unix timestamp.
	 *   urls           int      Total URLs collected.
	 *   warmed         int      Phase 1 success count.
	 *   errors         array    Phase 1 errors: [{ url, status }].
	 *   duration       float    Wall-clock seconds.
	 *   cf             null | { Phase 2 results; null = CF not configured.
	 *     purged_pages   int
	 *     purged_css     int
	 *     errors         string[]
	 *   }
	 */
	private function save_log( array $summary ): void {
		$log = get_option( 'pbcw_log', [] );
		array_unshift( $log, $summary );
		update_option( 'pbcw_log', array_slice( $log, 0, 10 ), false );
	}
}
