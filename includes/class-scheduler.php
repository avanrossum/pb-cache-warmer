<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron integration.
 *
 * Registers a daily scheduled event and exposes a custom interval so site
 * admins can choose a different frequency from the settings page.
 */
class PBCW_Scheduler {

    const HOOK = 'pbcw_scheduled_warmup';

    public function __construct() {
        add_action( self::HOOK, [ $this, 'run' ] );
        add_filter( 'cron_schedules', [ $this, 'add_intervals' ] );
    }

    /** Run called by WP-Cron. */
    public function run(): void {
        // Bail if another run is already in progress (auto-warmup or admin-triggered).
        $status = get_transient( 'pbcw_run_status' );
        if ( $status && ( $status['state'] ?? '' ) === 'running' ) {
            return;
        }

        set_transient( 'pbcw_run_status', [ 'state' => 'running', 'started' => time() ], 3600 );
        $warmer = new PBCW_Warmer();
        $warmer->run( 'scheduled' );
        delete_transient( 'pbcw_run_status' );
    }

    /** Register the cron event on plugin activation. */
    public static function activate(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            $options  = get_option( 'pbcw_settings', [] );
            $schedule = $options['cron_schedule'] ?? 'daily';
            wp_schedule_event( strtotime( 'tomorrow 03:00:00' ), $schedule, self::HOOK );
        }
    }

    /** Remove the cron event on deactivation. */
    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Re-schedule the cron when the user changes the interval in settings.
     * Called from PBCW_Admin after saving options.
     */
    public static function reschedule( string $new_schedule ): void {
        wp_clear_scheduled_hook( self::HOOK );
        wp_schedule_event( time() + 60, $new_schedule, self::HOOK );
    }

    /** Add custom intervals WP doesn't ship with. */
    public function add_intervals( array $schedules ): array {
        $schedules['twicedaily'] ??= [
            'interval' => 43200,
            'display'  => __( 'Twice Daily', 'pb-cache-warmer' ),
        ];
        $schedules['weekly'] ??= [
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Weekly', 'pb-cache-warmer' ),
        ];
        return $schedules;
    }
}
