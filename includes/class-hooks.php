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
		add_action( 'rt_nginx_helper_purge_all',      [ $this, 'schedule_warmup' ] );

		// ── Elementor ──────────────────────────────────────────────────────
		add_action( 'elementor/core/files/clear_cache', [ $this, 'schedule_warmup' ] );

		// ── Divi ───────────────────────────────────────────────────────────
		// ET_BUILDER_VERSION is defined by the Divi theme during after_setup_theme,
		// which runs AFTER plugins load — check inside the callback, not here.
		add_action( 'save_post', [ $this, 'schedule_single_warmup' ] );

		// ── Beaver Builder ─────────────────────────────────────────────────
		add_action( 'fl_builder_cache_cleared',       [ $this, 'schedule_warmup' ] );
		add_action( 'fl_builder_after_save_layout',   [ $this, 'schedule_warmup' ] );

		// ── Bricks ─────────────────────────────────────────────────────────
		add_action( 'bricks/builder/save_post',       [ $this, 'schedule_warmup' ] );

		// ── Oxygen ─────────────────────────────────────────────────────────
		add_action( 'oxy_render_shortcodes',          [ $this, 'schedule_warmup' ] );

		// ── Generic WordPress ──────────────────────────────────────────────
		add_action( 'switch_theme',                   [ $this, 'schedule_warmup' ] );
		add_action( 'upgrader_process_complete',      [ $this, 'schedule_warmup' ] );

		// ── Async runners ──────────────────────────────────────────────────
		add_action( 'pbcw_async_warmup',        [ $this, 'run_warmup' ] );
		add_action( 'pbcw_async_warmup_single', [ $this, 'run_single_warmup' ] );
	}

	/**
	 * Schedule a full-site warmup ~30 seconds from now (async, non-blocking).
	 * De-duplicates: if an event is already scheduled in the next 5 minutes,
	 * skip to prevent a cascade of purge events from stacking up.
	 */
	public function schedule_warmup(): void {
		$options = get_option( 'pbcw_settings', [] );
		if ( empty( $options['auto_warmup'] ) ) {
			return;
		}

		$next = wp_next_scheduled( 'pbcw_async_warmup' );
		if ( $next && $next < time() + 300 ) {
			return;
		}

		wp_schedule_single_event( time() + 30, 'pbcw_async_warmup' );
	}

	/**
	 * Schedule a single-post warmup 15 seconds after a Divi page save.
	 *
	 * Only fires when Divi is active. Check happens here (at call time)
	 * because ET_BUILDER_VERSION is defined by the theme after plugins load.
	 */
	public function schedule_single_warmup( int $post_id ): void {
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
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

	/** Async: run full warmup (both phases). */
	public function run_warmup(): void {
		// Bail if another run is already in progress (admin-triggered or a prior
		// auto-warmup that hasn't finished yet). This prevents a cache-purge event
		// from stacking a warmup on top of one that's still crawling — which can
		// saturate the FPM pool when et-cache is cold and CSS generation is slow.
		$status = get_transient( 'pbcw_run_status' );
		if ( $status && ( $status['state'] ?? '' ) === 'running' ) {
			return;
		}

		set_transient( 'pbcw_run_status', [ 'state' => 'running', 'started' => time() ], 300 );
		$warmer = new PBCW_Warmer();
		$warmer->run( 'auto (cache clear)' );
		delete_transient( 'pbcw_run_status' );
	}

	/** Async: warm a single post URL through both phases. */
	public function run_single_warmup( int $post_id ): void {
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return;
		}
		$warmer = new PBCW_Warmer();
		$warmer->run_single( $permalink, 'divi save (post ' . $post_id . ')' );
	}
}
