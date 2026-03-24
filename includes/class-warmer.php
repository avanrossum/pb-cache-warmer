<?php
defined( 'ABSPATH' ) || exit;

/**
 * Core warmup logic.
 *
 * Fetches all published URLs with a cache-bypassing query string, forcing the
 * page builder to execute a full PHP render and regenerate its per-post CSS.
 *
 * Works with any page builder that generates CSS on first render:
 *   Divi (et-cache), Elementor (elementor/css), Beaver Builder (bb-plugin/cache),
 *   Bricks, Oxygen, Kadence, GeneratePress, etc.
 *
 * Cache bypass mechanism:
 *   Appending any query string causes GP FastCGI / LiteSpeed / W3TC / WP Rocket
 *   (and most other server-side caches) to skip their cached version and invoke
 *   PHP directly. The query parameter value is randomised per-run so CDN edge
 *   caches (Cloudflare, Varnish) also treat it as a new, uncached URL.
 */
class PBCW_Warmer {

    /** @var array Plugin options. */
    private $options;

    public function __construct() {
        $this->options = get_option( 'pbcw_settings', [] );
    }

    /**
     * Run a full warmup pass. Stores a log entry when complete.
     *
     * @param  string $trigger  Human-readable label for what triggered this run.
     * @return array            Summary: { urls, warmed, errors, duration }
     */
    public function run( string $trigger = 'manual' ): array {
        $start   = microtime( true );
        $urls    = $this->get_urls();
        $bypass  = $this->bypass_param();
        $delay   = (int) ( $this->options['delay_ms'] ?? 300 );
        $timeout = (int) ( $this->options['timeout_s'] ?? 45 );

        $warmed = 0;
        $errors = [];

        foreach ( $urls as $url ) {
            $result = $this->fetch( $url, $bypass, $timeout );

            if ( $result['ok'] ) {
                $warmed++;
            } else {
                $errors[] = [ 'url' => $url, 'status' => $result['status'] ];
            }

            if ( $delay > 0 ) {
                usleep( $delay * 1000 );
            }
        }

        $summary = [
            'trigger'  => $trigger,
            'time'     => time(),
            'urls'     => count( $urls ),
            'warmed'   => $warmed,
            'errors'   => $errors,
            'duration' => round( microtime( true ) - $start, 1 ),
        ];

        $this->save_log( $summary );

        return $summary;
    }

    /**
     * Fetch a single URL with the cache-bypass query string.
     */
    private function fetch( string $url, string $bypass, int $timeout ): array {
        $fetch_url = add_query_arg( 'pbcw', $bypass, $url );

        $response = wp_remote_get( $fetch_url, [
            'timeout'    => $timeout,
            'user-agent' => 'pb-cache-warmer/' . PBCW_VERSION,
            'sslverify'  => apply_filters( 'pbcw_sslverify', true ),
            'redirection' => 5,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'status' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );

        return [ 'ok' => ( $code >= 200 && $code < 400 ), 'status' => $code ];
    }

    /**
     * Get all published page/post URLs, optionally filtered by user config.
     *
     * @return string[]
     */
    public function get_urls(): array {
        $post_types = apply_filters( 'pbcw_post_types', [ 'page', 'post' ] );
        $excluded   = array_filter( array_map( 'trim', explode( "\n", $this->options['excluded_urls'] ?? '' ) ) );

        $ids = get_posts( [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ] );

        $urls = [];
        foreach ( $ids as $id ) {
            $permalink = get_permalink( $id );
            if ( ! $permalink ) {
                continue;
            }
            // Strip trailing slash for comparison, then restore.
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
     * Returns a unique bypass token for this run.
     * Using a run-scoped random value means CDN caches also miss.
     */
    private function bypass_param(): string {
        static $token = null;
        if ( $token === null ) {
            $token = substr( md5( uniqid( '', true ) ), 0, 8 );
        }
        return $token;
    }

    /**
     * Persist the last N run summaries to wp_options.
     */
    private function save_log( array $summary ): void {
        $log   = get_option( 'pbcw_log', [] );
        array_unshift( $log, $summary );
        $log   = array_slice( $log, 0, 10 ); // keep last 10 runs
        update_option( 'pbcw_log', $log, false );
    }

    /** Return stored run history. */
    public static function get_log(): array {
        return get_option( 'pbcw_log', [] );
    }
}
