<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page + manual warmup trigger.
 */
class PBCW_Admin {

    const MENU_SLUG    = 'pb-cache-warmer';
    const OPTIONS_KEY  = 'pbcw_settings';
    const NONCE_ACTION = 'pbcw_warmup_now';

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_post_pbcw_save',  [ $this, 'save_settings' ] );
        add_action( 'admin_post_pbcw_run',   [ $this, 'manual_run' ] );
    }

    public function add_menu(): void {
        add_options_page(
            __( 'Page Builder Cache Warmer', 'pb-cache-warmer' ),
            __( 'Cache Warmer', 'pb-cache-warmer' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        // Settings are saved via admin-post.php (not the Settings API),
        // so register_setting is not used. Sanitization happens in save_settings().
    }

    public function sanitize_options( $input ): array {
        return [
            'auto_warmup'    => ! empty( $input['auto_warmup'] ),
            'cron_schedule'  => in_array( $input['cron_schedule'] ?? '', [ 'hourly', 'twicedaily', 'daily', 'weekly' ], true )
                                    ? $input['cron_schedule']
                                    : 'daily',
            'delay_ms'       => max( 0, min( 5000, (int) ( $input['delay_ms'] ?? 300 ) ) ),
            'timeout_s'      => max( 5,  min( 120,  (int) ( $input['timeout_s'] ?? 45 ) ) ),
            'excluded_urls'  => sanitize_textarea_field( $input['excluded_urls'] ?? '' ),
            'post_types'     => array_map( 'sanitize_key', (array) ( $input['post_types'] ?? [ 'page', 'post' ] ) ),
        ];
    }

    public function save_settings(): void {
        check_admin_referer( 'pbcw_settings_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'pb-cache-warmer' ) );
        }

        $data = $this->sanitize_options( $_POST[ self::OPTIONS_KEY ] ?? [] );
        update_option( self::OPTIONS_KEY, $data );

        // Re-schedule cron with potentially new interval.
        PBCW_Scheduler::reschedule( $data['cron_schedule'] );

        wp_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&saved=1' ) );
        exit;
    }

    public function manual_run(): void {
        check_admin_referer( self::NONCE_ACTION );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'pb-cache-warmer' ) );
        }

        $warmer  = new PBCW_Warmer();
        $summary = $warmer->run( 'manual' );

        set_transient( 'pbcw_last_manual_summary', $summary, 60 );

        wp_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&ran=1' ) );
        exit;
    }

    public function render_page(): void {
        $options = get_option( self::OPTIONS_KEY, [] );
        $log     = PBCW_Warmer::get_log();
        $warmer  = new PBCW_Warmer();
        $url_count = count( $warmer->get_urls() );
        $next_cron = wp_next_scheduled( PBCW_Scheduler::HOOK );
        $summary = get_transient( 'pbcw_last_manual_summary' );
        delete_transient( 'pbcw_last_manual_summary' );

        $post_types   = get_post_types( [ 'public' => true ], 'objects' );
        $saved_types  = $options['post_types'] ?? [ 'page', 'post' ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Page Builder Cache Warmer', 'pb-cache-warmer' ); ?></h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'pb-cache-warmer' ); ?></p></div>
            <?php endif; ?>

            <?php if ( isset( $_GET['ran'] ) && $summary ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: warmed count, 2: total count, 3: duration */
                            esc_html__( 'Warmup complete: %1$d / %2$d pages warmed in %3$ss.', 'pb-cache-warmer' ),
                            $summary['warmed'], $summary['urls'], $summary['duration']
                        );
                        if ( $summary['errors'] ) {
                            echo ' ' . sprintf( esc_html__( '%d errors — see log below.', 'pb-cache-warmer' ), count( $summary['errors'] ) );
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div style="display:flex; gap:2rem; align-items:flex-start;">

                <!-- Settings form -->
                <div style="flex:2; min-width:420px;">
                    <h2><?php esc_html_e( 'Settings', 'pb-cache-warmer' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'pbcw_settings_nonce' ); ?>
                        <input type="hidden" name="action" value="pbcw_save">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Auto-warm on cache clear', 'pb-cache-warmer' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="pbcw_settings[auto_warmup]" value="1" <?php checked( ! empty( $options['auto_warmup'] ) ); ?>>
                                        <?php esc_html_e( 'Trigger warmup automatically when a supported caching plugin purges its cache', 'pb-cache-warmer' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pbcw_schedule"><?php esc_html_e( 'Scheduled warmup', 'pb-cache-warmer' ); ?></label></th>
                                <td>
                                    <select id="pbcw_schedule" name="pbcw_settings[cron_schedule]">
                                        <?php
                                        $schedules = [
                                            'hourly'     => __( 'Hourly', 'pb-cache-warmer' ),
                                            'twicedaily' => __( 'Twice Daily', 'pb-cache-warmer' ),
                                            'daily'      => __( 'Daily', 'pb-cache-warmer' ),
                                            'weekly'     => __( 'Weekly', 'pb-cache-warmer' ),
                                        ];
                                        $current = $options['cron_schedule'] ?? 'daily';
                                        foreach ( $schedules as $val => $label ) {
                                            printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $current, $val, false ), esc_html( $label ) );
                                        }
                                        ?>
                                    </select>
                                    <?php if ( $next_cron ) : ?>
                                        <p class="description">
                                            <?php printf( esc_html__( 'Next run: %s', 'pb-cache-warmer' ), esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_cron ) ) ); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pbcw_delay"><?php esc_html_e( 'Delay between requests (ms)', 'pb-cache-warmer' ); ?></label></th>
                                <td>
                                    <input id="pbcw_delay" type="number" min="0" max="5000" name="pbcw_settings[delay_ms]" value="<?php echo esc_attr( $options['delay_ms'] ?? 300 ); ?>" class="small-text">
                                    <p class="description"><?php esc_html_e( 'Throttle to avoid hammering the server. 300ms is a safe default for most setups.', 'pb-cache-warmer' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pbcw_timeout"><?php esc_html_e( 'Request timeout (s)', 'pb-cache-warmer' ); ?></label></th>
                                <td>
                                    <input id="pbcw_timeout" type="number" min="5" max="120" name="pbcw_settings[timeout_s]" value="<?php echo esc_attr( $options['timeout_s'] ?? 45 ); ?>" class="small-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Post types', 'pb-cache-warmer' ); ?></th>
                                <td>
                                    <?php foreach ( $post_types as $pt ) : ?>
                                        <label style="display:block; margin-bottom:4px;">
                                            <input type="checkbox" name="pbcw_settings[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>"
                                                <?php checked( in_array( $pt->name, $saved_types, true ) ); ?>>
                                            <?php echo esc_html( $pt->labels->singular_name ); ?>
                                            <code style="color:#888; font-size:11px;"><?php echo esc_html( $pt->name ); ?></code>
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pbcw_excluded"><?php esc_html_e( 'Excluded URL paths', 'pb-cache-warmer' ); ?></label></th>
                                <td>
                                    <textarea id="pbcw_excluded" name="pbcw_settings[excluded_urls]" rows="5" class="large-text"><?php echo esc_textarea( $options['excluded_urls'] ?? '' ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'One path per line. Pages whose URL contains any listed path will be skipped. E.g. /wp-json/ or /private/', 'pb-cache-warmer' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button( __( 'Save Settings', 'pb-cache-warmer' ) ); ?>
                    </form>
                </div>

                <!-- Manual run + status -->
                <div style="flex:1; min-width:260px; background:#fff; border:1px solid #ddd; border-radius:4px; padding:1.2rem;">
                    <h2 style="margin-top:0;"><?php esc_html_e( 'Warm Now', 'pb-cache-warmer' ); ?></h2>
                    <p>
                        <?php printf(
                            /* translators: %d: number of URLs */
                            esc_html__( '%d URLs queued', 'pb-cache-warmer' ),
                            $url_count
                        ); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        <input type="hidden" name="action" value="pbcw_run">
                        <?php submit_button( __( 'Run Warmup', 'pb-cache-warmer' ), 'primary', 'submit', false ); ?>
                    </form>
                    <p class="description" style="margin-top:.5rem;">
                        <?php esc_html_e( 'Runs synchronously — page will wait until complete. For large sites, use the scheduled cron instead.', 'pb-cache-warmer' ); ?>
                    </p>
                </div>

            </div><!-- /flex -->

            <?php if ( $log ) : ?>
                <h2><?php esc_html_e( 'Run History', 'pb-cache-warmer' ); ?></h2>
                <table class="widefat striped" style="max-width:900px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'pb-cache-warmer' ); ?></th>
                            <th><?php esc_html_e( 'Trigger', 'pb-cache-warmer' ); ?></th>
                            <th><?php esc_html_e( 'Warmed', 'pb-cache-warmer' ); ?></th>
                            <th><?php esc_html_e( 'Errors', 'pb-cache-warmer' ); ?></th>
                            <th><?php esc_html_e( 'Duration', 'pb-cache-warmer' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log as $entry ) :
                            $err_count = count( $entry['errors'] ?? [] );
                        ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( 'M j Y, H:i', $entry['time'] ) ); ?></td>
                                <td><?php echo esc_html( $entry['trigger'] ); ?></td>
                                <td><?php echo esc_html( $entry['warmed'] ) . ' / ' . esc_html( $entry['urls'] ); ?></td>
                                <td>
                                    <?php if ( $err_count ) : ?>
                                        <details>
                                            <summary style="cursor:pointer; color:#b32d2e;"><?php echo esc_html( $err_count ); ?></summary>
                                            <ul style="margin:.5rem 0 0 1rem;">
                                                <?php foreach ( $entry['errors'] as $e ) :
                                                    echo '<li>' . esc_html( $e['url'] ) . ' <code>' . esc_html( $e['status'] ) . '</code></li>';
                                                endforeach; ?>
                                            </ul>
                                        </details>
                                    <?php else : ?>
                                        <span style="color:green;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $entry['duration'] ); ?>s</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div><!-- /wrap -->
        <?php
    }
}
