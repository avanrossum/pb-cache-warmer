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
		add_action( 'admin_menu',                    [ $this, 'add_menu' ] );
		add_action( 'admin_init',                    [ $this, 'register_settings' ] );
		add_action( 'admin_post_pbcw_save',          [ $this, 'save_settings' ] );
		add_action( 'admin_post_pbcw_run',           [ $this, 'manual_run' ] );
		add_action( 'admin_post_pbcw_detect_zone',   [ $this, 'detect_zone' ] );
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
		// Saved via admin-post.php — sanitization happens in save_settings().
	}

	public function sanitize_options( array $input ): array {
		// Preserve existing CF token if nothing new was submitted.
		$existing  = get_option( self::OPTIONS_KEY, [] );
		$new_token = sanitize_text_field( $input['cf_token'] ?? '' );
		$cf_token  = $new_token !== '' ? $new_token : ( $existing['cf_token'] ?? '' );

		return [
			'auto_warmup'   => ! empty( $input['auto_warmup'] ),
			'health_check'  => ! empty( $input['health_check'] ),
			'cron_schedule' => in_array( $input['cron_schedule'] ?? '', [ 'hourly', 'twicedaily', 'daily', 'weekly' ], true )
			                       ? $input['cron_schedule']
			                       : 'daily',
			'delay_ms'      => max( 0, min( 5000, (int) ( $input['delay_ms'] ?? 300 ) ) ),
			'timeout_s'     => max( 5, min( 120, (int) ( $input['timeout_s'] ?? 45 ) ) ),
			'excluded_urls' => sanitize_textarea_field( $input['excluded_urls'] ?? '' ),
			'post_types'    => array_map( 'sanitize_key', (array) ( $input['post_types'] ?? [ 'page', 'post' ] ) ),
			'cf_token'      => $cf_token,
			'cf_zone_id'    => sanitize_text_field( $input['cf_zone_id'] ?? '' ),
		];
	}

	public function save_settings(): void {
		check_admin_referer( 'pbcw_settings_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pb-cache-warmer' ) );
		}

		$data = $this->sanitize_options( $_POST[ self::OPTIONS_KEY ] ?? [] );
		update_option( self::OPTIONS_KEY, $data );
		PBCW_Scheduler::reschedule( $data['cron_schedule'] );

		wp_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&saved=1' ) );
		exit;
	}

	public function manual_run(): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pb-cache-warmer' ) );
		}

		$summary = ( new PBCW_Warmer() )->run( 'manual' );
		set_transient( 'pbcw_last_manual_summary', $summary, 60 );

		wp_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&ran=1' ) );
		exit;
	}

	/** Auto-detect the CF Zone ID from the current site domain and save it. */
	public function detect_zone(): void {
		check_admin_referer( 'pbcw_detect_zone' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pb-cache-warmer' ) );
		}

		$options = get_option( self::OPTIONS_KEY, [] );
		$cf      = new PBCW_Cloudflare( $options['cf_token'] ?? '', '' );
		$zone_id = $cf->detect_zone_id();

		if ( $zone_id ) {
			$options['cf_zone_id'] = $zone_id;
			update_option( self::OPTIONS_KEY, $options );
			$status = 'zone_detected';
		} else {
			$status = 'zone_not_found';
		}

		wp_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&' . $status . '=1' ) );
		exit;
	}

	public function render_page(): void {
		$options     = get_option( self::OPTIONS_KEY, [] );
		$log         = PBCW_Warmer::get_log();
		$warmer      = new PBCW_Warmer();
		$url_count   = count( $warmer->get_urls() );
		$next_cron   = wp_next_scheduled( PBCW_Scheduler::HOOK );
		$summary     = get_transient( 'pbcw_last_manual_summary' );
		$cf          = new PBCW_Cloudflare( $options['cf_token'] ?? '', $options['cf_zone_id'] ?? '' );
		$origin_base = apply_filters( 'pbcw_origin_base', null );
		$post_types  = get_post_types( [ 'public' => true ], 'objects' );
		$saved_types = $options['post_types'] ?? [ 'page', 'post' ];
		delete_transient( 'pbcw_last_manual_summary' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Page Builder Cache Warmer', 'pb-cache-warmer' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'pb-cache-warmer' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['zone_detected'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Zone ID detected and saved.', 'pb-cache-warmer' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['zone_not_found'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Zone ID not found. Check that your API token has Zone.Read permission and the site domain is in your Cloudflare account.', 'pb-cache-warmer' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['ran'] ) && $summary ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: 1: warmed count, 2: total, 3: seconds */
							esc_html__( 'Phase 1: %1$d / %2$d pages warmed in %3$ss.', 'pb-cache-warmer' ),
							$summary['warmed'], $summary['urls'], $summary['duration']
						);
						if ( ! empty( $summary['errors'] ) ) {
							echo ' ' . sprintf(
								esc_html__( '%d errors — see log below.', 'pb-cache-warmer' ),
								count( $summary['errors'] )
							);
						}
						if ( ! empty( $summary['cf'] ) ) {
							$cf_r = $summary['cf'];
							echo '<br>';
							if ( empty( $cf_r['errors'] ) ) {
								printf(
									/* translators: 1: pages purged, 2: CSS files purged */
									esc_html__( 'CF purge: %1$d pages + %2$d CSS files.', 'pb-cache-warmer' ),
									$cf_r['purged_pages'], $cf_r['purged_css']
								);
							} else {
								esc_html_e( 'CF purge: failed — check token and Zone ID.', 'pb-cache-warmer' );
							}
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<div style="display:flex; gap:2rem; align-items:flex-start;">

				<div style="flex:2; min-width:460px;">
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
										<?php esc_html_e( 'Trigger warmup when a supported caching plugin purges its cache', 'pb-cache-warmer' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'CSS health check', 'pb-cache-warmer' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="pbcw_settings[health_check]" value="1" <?php checked( ! empty( $options['health_check'] ) ); ?>>
										<?php esc_html_e( 'Auto-reload pages when a stylesheet fails to load', 'pb-cache-warmer' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Injects a small script on every frontend page. If a stylesheet 404s, the server warms and CF-purges that page, then the browser reloads. A session guard prevents reload loops.', 'pb-cache-warmer' ); ?></p>
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
											'daily'      => __( 'Daily (recommended)', 'pb-cache-warmer' ),
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
									<p class="description"><?php esc_html_e( '300ms is a safe default. Lower on fast servers, raise if the warmup is slowing the site.', 'pb-cache-warmer' ); ?></p>
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
											<input type="checkbox" name="pbcw_settings[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $saved_types, true ) ); ?>>
											<?php echo esc_html( $pt->labels->singular_name ); ?>
											<code style="color:#888; font-size:11px;"><?php echo esc_html( $pt->name ); ?></code>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>

							<tr>
								<th scope="row"><label for="pbcw_excluded"><?php esc_html_e( 'Excluded URL paths', 'pb-cache-warmer' ); ?></label></th>
								<td>
									<textarea id="pbcw_excluded" name="pbcw_settings[excluded_urls]" rows="4" class="large-text"><?php echo esc_textarea( $options['excluded_urls'] ?? '' ); ?></textarea>
									<p class="description"><?php esc_html_e( 'One path fragment per line. Pages whose URL contains any listed path will be skipped.', 'pb-cache-warmer' ); ?></p>
								</td>
							</tr>

						</table>

						<h2><?php esc_html_e( 'Cloudflare Integration', 'pb-cache-warmer' ); ?></h2>
						<p class="description" style="margin-bottom:1rem;">
							<?php esc_html_e( 'Optional. When configured, the warmer purges CF\'s cached copies of HTML and CSS after each warmup run, so CF re-fetches them from the freshly-warmed origin. Requires an API token with Zone.Cache.Purge permission.', 'pb-cache-warmer' ); ?>
						</p>
						<table class="form-table" role="presentation">

							<tr>
								<th scope="row"><label for="pbcw_cf_token"><?php esc_html_e( 'API Token', 'pb-cache-warmer' ); ?></label></th>
								<td>
									<input id="pbcw_cf_token" type="password" name="pbcw_settings[cf_token]" value="" class="regular-text" autocomplete="off"
										placeholder="<?php echo ! empty( $options['cf_token'] ) ? esc_attr__( '(saved — paste to replace)', 'pb-cache-warmer' ) : esc_attr__( 'Paste API token here', 'pb-cache-warmer' ); ?>">
									<?php if ( ! empty( $options['cf_token'] ) ) : ?>
										<span style="color:#0a7a0a; margin-left:.5rem;">&#10003; <?php esc_html_e( 'Token saved', 'pb-cache-warmer' ); ?></span>
									<?php endif; ?>
									<p class="description"><?php esc_html_e( 'Leave blank to keep the existing token. Clear and save to remove it.', 'pb-cache-warmer' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row"><label for="pbcw_cf_zone_id"><?php esc_html_e( 'Zone ID', 'pb-cache-warmer' ); ?></label></th>
								<td>
									<input id="pbcw_cf_zone_id" type="text" name="pbcw_settings[cf_zone_id]" value="<?php echo esc_attr( $options['cf_zone_id'] ?? '' ); ?>" class="regular-text" placeholder="e.g. 023e105f4ecef8ad9ca31a8372d0c353">
									<?php if ( ! empty( $options['cf_token'] ) ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-left:.5rem;">
											<?php wp_nonce_field( 'pbcw_detect_zone' ); ?>
											<input type="hidden" name="action" value="pbcw_detect_zone">
											<button type="submit" class="button button-secondary"><?php esc_html_e( 'Auto-detect', 'pb-cache-warmer' ); ?></button>
										</form>
									<?php endif; ?>
									<p class="description">
										<?php esc_html_e( 'Found in your Cloudflare dashboard under the domain\'s Overview tab.', 'pb-cache-warmer' ); ?>
										<?php if ( $cf->is_configured() ) : ?>
											<span style="color:#0a7a0a;">&#10003; <?php esc_html_e( 'CF integration active.', 'pb-cache-warmer' ); ?></span>
										<?php else : ?>
											<span style="color:#666;"><?php esc_html_e( 'CF integration inactive — token and Zone ID required.', 'pb-cache-warmer' ); ?></span>
										<?php endif; ?>
									</p>
								</td>
							</tr>

						</table>

						<?php submit_button( __( 'Save Settings', 'pb-cache-warmer' ) ); ?>
					</form>

					<?php if ( $origin_base ) : ?>
						<p style="margin-top:1rem; color:#666; font-size:12px;">
							<?php printf( esc_html__( 'Origin base active: %s', 'pb-cache-warmer' ), '<code>' . esc_html( $origin_base ) . '</code>' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Warm Now panel -->
				<div style="flex:1; min-width:240px; background:#fff; border:1px solid #ddd; border-radius:4px; padding:1.2rem;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Warm Now', 'pb-cache-warmer' ); ?></h2>
					<p><?php printf( esc_html__( '%d URLs queued', 'pb-cache-warmer' ), $url_count ); ?></p>
					<p style="font-size:12px; color:#666; margin-top:0;">
						<?php if ( $cf->is_configured() ) : ?>
							<?php esc_html_e( 'Phase 1 (origin) + Phase 2 (CF purge)', 'pb-cache-warmer' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Phase 1 (origin) only', 'pb-cache-warmer' ); ?>
						<?php endif; ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="action" value="pbcw_run">
						<?php submit_button( __( 'Run Warmup', 'pb-cache-warmer' ), 'primary', 'submit', false ); ?>
					</form>
					<p class="description" style="margin-top:.5rem;">
						<?php esc_html_e( 'Runs synchronously — page waits until complete.', 'pb-cache-warmer' ); ?>
					</p>
				</div>

			</div>

			<?php if ( $log ) : ?>
				<h2><?php esc_html_e( 'Run History', 'pb-cache-warmer' ); ?></h2>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'pb-cache-warmer' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'pb-cache-warmer' ); ?></th>
							<th><?php esc_html_e( 'Phase 1', 'pb-cache-warmer' ); ?></th>
							<th><?php esc_html_e( 'CF Purge', 'pb-cache-warmer' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'pb-cache-warmer' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'pb-cache-warmer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) :
							$p1_errors  = $entry['errors'] ?? [];
							$cf_entry   = $entry['cf'] ?? null;
							$cf_errors  = is_array( $cf_entry ) ? ( $cf_entry['errors'] ?? [] ) : [];
							$all_errors = array_merge( $p1_errors, $cf_errors );
						?>
							<tr>
								<td><?php echo esc_html( date_i18n( 'M j, H:i', $entry['time'] ) ); ?></td>
								<td><?php echo esc_html( $entry['trigger'] ); ?></td>
								<td><?php echo esc_html( ( $entry['warmed'] ?? 0 ) . ' / ' . ( $entry['urls'] ?? '?' ) ); ?></td>
								<td>
									<?php if ( is_array( $cf_entry ) && empty( $cf_entry['errors'] ) ) : ?>
										<?php printf(
											/* translators: 1: pages, 2: CSS */
											esc_html__( '%1$d pg + %2$d css', 'pb-cache-warmer' ),
											$cf_entry['purged_pages'], $cf_entry['purged_css']
										); ?>
									<?php elseif ( is_array( $cf_entry ) ) : ?>
										<span style="color:#b32d2e;"><?php esc_html_e( 'Failed', 'pb-cache-warmer' ); ?></span>
									<?php else : ?>
										<span style="color:#ccc;">—</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $all_errors ) : ?>
										<details>
											<summary style="cursor:pointer; color:#b32d2e;"><?php echo esc_html( count( $all_errors ) ); ?></summary>
											<ul style="margin:.5rem 0 0 1rem;">
												<?php foreach ( $all_errors as $e ) :
													if ( is_array( $e ) ) {
														echo '<li>' . esc_html( $e['url'] ?? '' ) . ' <code>' . esc_html( $e['status'] ?? '' ) . '</code></li>';
													} else {
														echo '<li>' . esc_html( $e ) . '</li>';
													}
												endforeach; ?>
											</ul>
										</details>
									<?php else : ?>
										<span style="color:green;">0</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $entry['duration'] ?? '—' ); ?>s</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

		</div>
		<?php
	}
}
