<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cache-clear hook integrations.
 *
 * Listens for cache purge events fired by popular caching plugins and page
 * builders, then schedules an async warmup so CSS regenerates before the
 * next real user visit.
 *
 * We use wp_schedule_single_event( time() + 30 ) rather than running inline
 * so we don't block the admin request that triggered the purge.
 */
class PBCW_Hooks {

    public function __construct() {
        $this->register();
    }

    private function register(): void {
        // ── WP Rocket ──────────────────────────────────────────────────────
        add_action( 'after_rocket_clean_domain',      [ $this, 'schedule_warmup' ] );
        add_action( 'after_rocket_clean_post',        [ $this, 'schedule_warmup' ] );

        // ── LiteSpeed Cache ────────────────────────────────────────────────
        add_action( 'litespeed_purge_all',            [ $this, 'schedule_warmup' ] );
        add_action( 'litespeed_purge_post',           [ $this, 'schedule_warmup' ] );

        // ── W3 Total Cache ─────────────────────────────────────────────────
        add_action( 'w3tc_flush_all',                 [ $this, 'schedule_warmup' ] );

        // ── WP Super Cache ─────────────────────────────────────────────────
        add_action( 'wp_cache_cleared',               [ $this, 'schedule_warmup' ] );

        // ── Autoptimize ────────────────────────────────────────────────────
        add_action( 'autoptimize_action_cachepurged',  [ $this, 'schedule_warmup' ] );

        // ── GridPane nginx-helper ──────────────────────────────────────────
        // nginx-helper fires purge_url per-URL and a custom action on full flush.
        add_action( 'rt_nginx_helper_purge_all',      [ $this, 'schedule_warmup' ] );

        // ── Elementor ──────────────────────────────────────────────────────
        // Elementor regenerates its own CSS; we re-warm so the new files are
        // in nginx/CDN cache before users arrive.
        add_action( 'elementor/core/files/clear_cache', [ $this, 'schedule_warmup' ] );

        // ── Divi ───────────────────────────────────────────────────────────
        // Divi doesn't expose a reliable "cache cleared" action, but it does
        // bust et-cache files on save. We hook the post save cycle so pages
        // get re-warmed after content changes regenerate their CSS.
        //
        // The Divi theme defines ET_BUILDER_VERSION during after_setup_theme,
        // which runs AFTER plugins load — so we can't check at register() time.
        // Instead we always register save_post and gate inside the callback,
        // where the theme is guaranteed to be bootstrapped.
        add_action( 'save_post', [ $this, 'schedule_single_warmup' ] );

        // ── Beaver Builder ─────────────────────────────────────────────────
        add_action( 'fl_builder_cache_cleared',       [ $this, 'schedule_warmup' ] );
        add_action( 'fl_builder_after_save_layout',   [ $this, 'schedule_warmup' ] );

        // ── Bricks ─────────────────────────────────────────────────────────
        add_action( 'bricks/builder/save_post',       [ $this, 'schedule_warmup' ] );

        // ── Oxygen ─────────────────────────────────────────────────────────
        add_action( 'oxy_render_shortcodes',          [ $this, 'schedule_warmup' ] );

        // ── Generic WordPress ──────────────────────────────────────────────
        // Theme switch and core/plugin upgrades often trigger CSS regeneration.
        add_action( 'switch_theme',                   [ $this, 'schedule_warmup' ] );
        add_action( 'upgrader_process_complete',      [ $this, 'schedule_warmup' ] );

        // ── Async runner ───────────────────────────────────────────────────
        add_action( 'pbcw_async_warmup', [ $this, 'run_warmup' ] );
        add_action( 'pbcw_async_warmup_single', [ $this, 'run_single_warmup' ] );
    }

    /**
     * Schedule a full-site warmup ~30 seconds from now (async, non-blocking).
     * De-duplicates: if an event is already scheduled in the next 5 minutes,
     * skip — prevents a cascade of purge events from stacking up.
     */
    public function schedule_warmup(): void {
        $options = get_option( 'pbcw_settings', [] );

        if ( empty( $options['auto_warmup'] ) ) {
            return;
        }

        $next = wp_next_scheduled( 'pbcw_async_warmup' );
        if ( $next && $next < time() + 300 ) {
            return; // already queued within the next 5 minutes
        }

        wp_schedule_single_event( time() + 30, 'pbcw_async_warmup' );
    }

    /**
     * Schedule a warmup for a single post after save.
     *
     * Only fires when Divi is active. The check happens here (at call time)
     * rather than at hook registration because ET_BUILDER_VERSION is defined
     * by the Divi theme during after_setup_theme — after plugins have loaded.
     */
    public function schedule_single_warmup( int $post_id ): void {
        if ( ! $this->is_divi_active() ) {
            return;
        }
        $options = get_option( 'pbcw_settings', [] );
        if ( empty( $options['auto_warmup'] ) ) {
            return;
        }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        wp_schedule_single_event( time() + 15, 'pbcw_async_warmup_single', [ $post_id ] );
    }

    /** Async: run full warmup. */
    public function run_warmup(): void {
        $warmer = new PBCW_Warmer();
        $warmer->run( 'auto (cache clear)' );
    }

    /**
     * Returns true if the Divi theme or Divi Builder plugin is active.
     *
     * ET_BUILDER_VERSION is defined by both the theme (functions.php) and the
     * standalone Divi Builder plugin, making it the most reliable single check.
     * We test at call time (not in __construct) because themes load after plugins.
     */
    private function is_divi_active(): bool {
        return defined( 'ET_BUILDER_VERSION' );
    }

    /** Async: warm a single post URL. */
    public function run_single_warmup( int $post_id ): void {
        $permalink = get_permalink( $post_id );
        if ( ! $permalink ) {
            return;
        }
        $warmer  = new PBCW_Warmer();
        $urls    = [ $permalink ];
        $bypass  = 'pbcw_' . substr( md5( uniqid( '', true ) ), 0, 6 );
        // Use wp_remote_get directly for single-URL case.
        wp_remote_get( add_query_arg( 'pbcw', $bypass, $permalink ), [
            'timeout'    => 45,
            'user-agent' => 'pb-cache-warmer/' . PBCW_VERSION,
            'sslverify'  => apply_filters( 'pbcw_sslverify', true ),
            'redirection' => 5,
            'blocking'   => false, // fire-and-forget
        ] );
    }
}
