<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client-side CSS health check + server-side heal.
 *
 * Injects a small inline JS snippet on every frontend page. On load, the JS
 * inspects each stylesheet link — if any sheet failed to load (link.sheet === null),
 * it POSTs the current URL to the /heal REST endpoint, waits 3 seconds for the
 * server to warm origin and CF edge, then reloads the page.
 *
 * Server side: the heal endpoint runs PBCW_Warmer::run_single() synchronously
 * for the requested URL (phase 1 origin bypass + phase 2 CF edge warm), then
 * returns. A 60-second transient prevents concurrent heals for the same URL.
 *
 * The JS guard (sessionStorage) ensures a browser retries at most once per
 * page path per session, preventing reload loops if the heal fails to resolve
 * the underlying issue.
 *
 * Enable via Settings → Cache Warmer → "Client-side CSS health check".
 */
class PBCW_Health_Check {

	const REST_NAMESPACE = 'pb-cache-warmer/v1';
	const REST_ROUTE     = '/heal';
	const NONCE_ACTION   = 'pbcw_heal';
	const RATE_LIMIT_TTL = 60; // seconds between heals for the same URL

	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'rest_api_init',      [ $this, 'register_routes' ] );
		add_action( 'wp_footer',          [ $this, 'fix_divi_late_css_href' ], 999 );
	}

	/**
	 * Inject the health-check script on frontend pages when the feature is enabled.
	 */
	public function enqueue(): void {
		$options = get_option( 'pbcw_settings', [] );
		if ( empty( $options['health_check'] ) ) {
			return;
		}

		// Don't run in admin, customizer, or REST context.
		if ( is_customize_preview() ) {
			return;
		}

		$data = wp_json_encode( [
			'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
			'endpoint' => rest_url( self::REST_NAMESPACE . self::REST_ROUTE ),
		] );

		// Inline script: set global config then load the health-check logic.
		wp_register_script( 'pbcw-health-check', false, [], PBCW_VERSION, [ 'strategy' => 'defer' ] );
		wp_add_inline_script(
			'pbcw-health-check',
			'window.pbcw_heal=' . $data . ';',
			'before'
		);
		wp_add_inline_script(
			'pbcw-health-check',
			file_get_contents( PBCW_PLUGIN_DIR . 'assets/health-check.js' ) // phpcs:ignore
		);
		wp_enqueue_script( 'pbcw-health-check' );
	}

	/**
	 * Register the REST heal endpoint.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_heal' ],
				'permission_callback' => [ $this, 'verify_nonce' ],
				'args'                => [
					'url' => [
						'required'          => true,
						'type'              => 'string',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
					],
				],
			]
		);
	}

	/**
	 * Verify the nonce from the X-WP-Nonce request header.
	 * Works for both logged-in and anonymous users (nonce tied to user_id,
	 * which is 0 for guests — consistent between JS generation and REST verify).
	 */
	public function verify_nonce( WP_REST_Request $request ): bool {
		return (bool) wp_verify_nonce(
			$request->get_header( 'X-WP-Nonce' ),
			self::NONCE_ACTION
		);
	}

	/**
	 * Heal handler: warm origin + CF edge for the requested URL, then return.
	 * Rate-limited to one active heal per URL per 60 seconds.
	 */
	public function handle_heal( WP_REST_Request $request ): WP_REST_Response {
		$url = $request->get_param( 'url' );

		// Only warm URLs that belong to this site.
		if ( ! str_starts_with( $url, home_url() ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'url_mismatch' ], 400 );
		}

		// Rate limit: one heal per URL per 60 seconds across all visitors.
		$lock = 'pbcw_heal_' . md5( $url );
		if ( get_transient( $lock ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'status' => 'in_progress' ], 200 );
		}
		set_transient( $lock, 1, self::RATE_LIMIT_TTL );

		$warmer = new PBCW_Warmer();
		$warmer->run_single( $url, 'health-check heal' );

		return new WP_REST_Response( [ 'ok' => true, 'status' => 'healed' ], 200 );
	}

	/**
	 * Fix Divi 4's late CSS href bug on every frontend page.
	 *
	 * Divi generates an inline script that loads late CSS files like this:
	 *   var file = ["url1", "url2"];
	 *   link.href = file;
	 *
	 * JavaScript coerces the array to a string via Array.toString(), producing
	 * "url1,url2". The browser resolves this as a single malformed URL and
	 * requests it — nginx returns 403 and both CSS files are never loaded.
	 *
	 * This footer script (priority 999, after Divi's inline script) finds the
	 * element Divi created, splits its href on the comma boundary, and inserts
	 * a separate <link> for each URL. Only runs when Divi 4 is active.
	 */
	public function fix_divi_late_css_href(): void {
		if ( is_admin() || ! defined( 'ET_BUILDER_VERSION' ) ) {
			return;
		}
		?>
		<script>
		(function() {
			var el = document.getElementById('et-dynamic-late-css');
			if (!el || !el.href || el.href.indexOf(',http') === -1) return;
			var urls = el.href.split(/,(?=https?:\/\/)/);
			el.href = urls[0];
			for (var i = 1; i < urls.length; i++) {
				var link  = document.createElement('link');
				link.rel  = 'stylesheet';
				link.href = urls[i];
				el.parentNode.insertBefore(link, el.nextSibling);
			}
		}());
		</script>
		<?php
	}
}
