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
		add_action( 'admin_menu',           [ $this, 'add_menu' ] );
		add_action( 'admin_init',           [ $this, 'register_settings' ] );
		add_action( 'admin_post_pbcw_save', [ $this, 'save_settings' ] );
		add_action( 'admin_post_pbcw_run',  [ $this, 'manual_run' ] );
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
		// Settings saved via admin-post.php, not the Settings API.
		// Sanitization happens in save_settings().
	}

	public function sanitize_options( array $input ): array {
		$cf_warm = $input['cf_warm'] ?? 'auto';
		if ( ! in_array( $cf_warm, [ 'auto', 'on', 'off' ], true ) ) {
			$cf_warm = 'auto';
		}

		return [
			'auto_warmup'   => ! empty( $input['auto_warmup'] ),
			'health_check'  => ! empty( $input['health_check'] ),
			'cron_schedule' => in_array( $input['cron_schedule'] ?? '', [ 'hourly', 'twicedaily', 'daily', 'weekly' ], true )
			                       ? $input['cron_schedule']
			                       : 'daily',
			'delay_ms'      => max( 0, min( 5000, (int) ( $input['delay_ms'] ?? 300 ) ) ),
			'timeout_s'     => max( 5,  min( 120,  (int) ( $input['timeout_s'] ?? 45 ) ) ),
			'excluded_urls' => sanitize_textarea_field( $input['excluded_urls'] ?? '' ),
			'post_types'    => array_map( 'sanitize_key', (array) ( $input['post_types'] ?? [ 'page', 'post' ] ) ),
			'cf_warm'       => $cf_warm,
		];
	}

	public function save_settings(): void {
		check_admin_referer( 'pbcw_settings_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pb-cache-warmer' ) );
		}

		$data = $this->sanitize_options( $_POST[ self::OPTIONS_KEY ] ?? [] );
		update_option( self::OPTIONS_KEY, $data );

		// Clear CF detection transient so the next page load re-checks.
		delete_transient( 'pbcw_cf_detected' );

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
		$options    = get_option( self::OPTIONS_KEY, [] );
		$log        = PBCW_Warmer::get_log();
		$warmer     = new PBCW_Warmer();
		$url_count  = count( $warmer->get_urls() );
		$next_cron  = wp_next_scheduled( PBCW_Scheduler::HOOK );
		$summary    = get_transient( 'pbcw_last_manual_summary' );
		delete_transient( 'pbcw_last_manual_summary' );

		$cf_detected   = $warmer->detect_cloudflare();
		$post_types    = get_post_types( [ 'public' => true ], 'objects' );
		$saved_types   = $options['post_types'] ?? [ 'page', 'post' ];
		$cf_warm       = $options['cf_warm'] ?? 'auto';
		$origin_base   = apply_filters( 'pbcw_origin_base', null );
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
							/* translators: 1: phase 1 warmed, 2: total, 3: duration */
							esc_html__( 'Phase 1 complete: %1$d / %2$d pages warmed in %3$ss.', 'pb-cache-warmer' ),
							$summary['warmed'], $summary['urls'], $summary['duration']
						);
						if ( $summary['errors'] ) {
							echo ' ' . sprintf(
								esc_html__( '%d errors — see log below.', 'pb-cache-warmer' ),
								count( $summary['errors'] )
							);
						}
						if ( isset( $summary['cf'] ) && $summary['cf'] !== null ) {
							$cf = $summary['cf'];
							echo '<br>';
							printf(
								/* translators: 1: CF pages, 2: CF CSS count */
								esc_html__( 'Phase 2 (CF edge): %1$d pages + %2$d CSS files warmed.', 'pb-cache-warmer' ),
								$cf['pages'], $cf['css']
							);
							if ( $cf['errors'] ) {
								echo ' ' . sprintf(
									esc_html__( '%d CF errors — see log below.', 'pb-cache-warmer' ),
									count( $cf['errors'] )
								);
							}
						} elseif ( isset( $summary['cf_setting'] ) && $summary['cf_setting'] !== 'off' ) {
							echo '<br>' . esc_html__( 'Phase 2 (CF edge): skipped — Cloudflare not detected.', 'pb-cache-warmer' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<div style="display:flex; gap:2rem; align-items:flex-start;">

				<!-- Settings form -->
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
										<?php esc_html_e( 'Trigger warmup automatically when a supported caching plugin purges its cache', 'pb-cache-warmer' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Client-side CSS health check', 'pb-cache-warmer' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="pbcw_settings[health_check]" value="1" <?php checked( ! empty( $options['health_check'] ) ); ?>>
										<?php esc_html_e( 'Auto-reload pages when a stylesheet fails to load', 'pb-cache-warmer' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Injects a small script on every frontend page. If any stylesheet link returns a 404 or fails to load, the script notifies the server (which warms origin + CF edge for that page), then reloads after 3 seconds. A session guard prevents reload loops. Recommended when Cloudflare edge warming is active.', 'pb-cache-warmer' ); ?></p>
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
											printf(
												'<option value="%s"%s>%s</option>',
												esc_attr( $val ),
												selected( $current, $val, false ),
												esc_html( $label )
											);
										}
										?>
									</select>
									<?php if ( $next_cron ) : ?>
										<p class="description">
											<?php
											printf(
												esc_html__( 'Next run: %s', 'pb-cache-warmer' ),
												esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_cron ) )
											);
											?>
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
								<th scope="row"><?php esc_html_e( 'Cloudflare edge warming', 'pb-cache-warmer' ); ?></th>
								<td>
									<fieldset>
										<legend class="screen-reader-text"><?php esc_html_e( 'Cloudflare edge warming', 'pb-cache-warmer' ); ?></legend>
										<label style="display:block; margin-bottom:4px;">
											<input type="radio" name="pbcw_settings[cf_warm]" value="auto" <?php checked( $cf_warm, 'auto' ); ?>>
											<?php esc_html_e( 'Auto-detect (recommended)', 'pb-cache-warmer' ); ?>
										</label>
										<label style="display:block; margin-bottom:4px;">
											<input type="radio" name="pbcw_settings[cf_warm]" value="on" <?php checked( $cf_warm, 'on' ); ?>>
											<?php esc_html_e( 'Always on', 'pb-cache-warmer' ); ?>
										</label>
										<label style="display:block; margin-bottom:4px;">
											<input type="radio" name="pbcw_settings[cf_warm]" value="off" <?php checked( $cf_warm, 'off' ); ?>>
											<?php esc_html_e( 'Disabled', 'pb-cache-warmer' ); ?>
										</label>
									</fieldset>
									<p class="description" style="margin-top:.6rem;">
										<?php if ( $cf_detected ) : ?>
											<span style="color:#0a7a0a;">&#10003; <?php esc_html_e( 'Cloudflare detected', 'pb-cache-warmer' ); ?></span>
										<?php else : ?>
											<span style="color:#666;">&#8212; <?php esc_html_e( 'Cloudflare not detected', 'pb-cache-warmer' ); ?></span>
										<?php endif; ?>
										&nbsp;&middot;&nbsp;<?php esc_html_e( 'Checked via cf-ray response header. Cached for 1 hour — re-checked on settings save.', 'pb-cache-warmer' ); ?>
									</p>
									<p class="description">
										<?php esc_html_e( 'Phase 2 fetches each page via its public URL (no bypass) so Cloudflare caches the HTML at edge, then parses and warms all same-domain CSS files found in the page. Prevents browsers receiving stale or missing CSS from the CF edge cache after a purge.', 'pb-cache-warmer' ); ?>
									</p>
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

					<!-- Developer configuration note -->
					<details style="margin-top:1.5rem; border:1px solid #ddd; border-radius:3px; padding:.8rem 1rem;">
						<summary style="cursor:pointer; font-weight:600; color:#1d2327;">
							<?php esc_html_e( 'Server configuration (developers)', 'pb-cache-warmer' ); ?>
						</summary>
						<div style="margin-top:.8rem;">
							<p>
								<?php esc_html_e( 'By default, phase 1 warmup requests route through your CDN (Cloudflare) the same as real visitors. To bypass Cloudflare entirely and hit your origin server directly, add the following to a must-use plugin or wp-config.php:', 'pb-cache-warmer' ); ?>
							</p>
							<pre style="background:#f6f7f7; padding:.6rem; border-radius:3px; overflow-x:auto; font-size:12px;">add_filter( 'pbcw_origin_base', fn() =&gt; 'http://127.0.0.1' );</pre>
							<p class="description">
								<?php esc_html_e( 'This sends phase 1 requests directly to 127.0.0.1 with a Host: header, avoiding bot detection rules, rate limiting, and unnecessary CDN bandwidth. Recommended for GridPane and other server-managed hosting environments.', 'pb-cache-warmer' ); ?>
							</p>
							<?php if ( $origin_base ) : ?>
								<p style="color:#0a7a0a;">
									&#10003; <?php printf( esc_html__( 'pbcw_origin_base is active: %s', 'pb-cache-warmer' ), '<code>' . esc_html( $origin_base ) . '</code>' ); ?>
								</p>
							<?php endif; ?>
						</div>
					</details>
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
					<p style="font-size:12px; color:#666; margin-top:0;">
						<?php if ( $cf_warm !== 'off' && $cf_detected ) : ?>
							<?php esc_html_e( 'Phase 1 (origin) + Phase 2 (CF edge)', 'pb-cache-warmer' ); ?>
						<?php elseif ( $cf_warm === 'on' ) : ?>
							<?php esc_html_e( 'Phase 1 (origin) + Phase 2 (CF edge, forced on)', 'pb-cache-warmer' ); ?>
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
						<?php esc_html_e( 'Runs synchronously — page will wait until complete. For large sites, use the scheduled cron instead.', 'pb-cache-warmer' ); ?>
					</p>
				</div>

			</div><!-- /flex -->

			<?php if ( $log ) : ?>
				<h2><?php esc_html_e( 'Run History', 'pb-cache-warmer' ); ?></h2>
				<table class="widefat striped" style="max-width:1000px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'pb-cache-warmer' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'pb-cache-warmer' ); ?></th>
							<th><?php esc_html_e( 'Phase 1 (origin)', 'pb-cache-warmer' ); ?></th>
							<th><?php esc_html_e( 'Phase 2 (CF edge)', 'pb-cache-warmer' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'pb-cache-warmer' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'pb-cache-warmer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) :
							$p1_errors = $entry['errors'] ?? [];
							$cf        = $entry['cf'] ?? null;
							$p2_errors = $cf['errors'] ?? [];
							$all_errors = array_merge( $p1_errors, $p2_errors );
							$err_count = count( $all_errors );
						?>
							<tr>
								<td><?php echo esc_html( date_i18n( 'M j Y, H:i', $entry['time'] ) ); ?></td>
								<td><?php echo esc_html( $entry['trigger'] ); ?></td>
								<td><?php echo esc_html( ( $entry['warmed'] ?? 0 ) . ' / ' . ( $entry['urls'] ?? '?' ) ); ?></td>
								<td>
									<?php if ( $cf !== null ) : ?>
										<?php printf(
											/* translators: 1: pages, 2: CSS files */
											esc_html__( '%1$d pages, %2$d CSS', 'pb-cache-warmer' ),
											$cf['pages'], $cf['css']
										); ?>
									<?php elseif ( isset( $entry['cf_setting'] ) && $entry['cf_setting'] === 'off' ) : ?>
										<span style="color:#999;"><?php esc_html_e( 'Disabled', 'pb-cache-warmer' ); ?></span>
									<?php elseif ( isset( $entry['cf_setting'] ) ) : ?>
										<span style="color:#999;"><?php esc_html_e( 'Skipped', 'pb-cache-warmer' ); ?></span>
									<?php else : ?>
										<span style="color:#ccc;">—</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $err_count ) : ?>
										<details>
											<summary style="cursor:pointer; color:#b32d2e;"><?php echo esc_html( $err_count ); ?></summary>
											<ul style="margin:.5rem 0 0 1rem;">
												<?php foreach ( $all_errors as $e ) :
													$phase = isset( $e['phase'] ) ? ' <em>(' . esc_html( $e['phase'] ) . ')</em>' : '';
													echo '<li>' . esc_html( $e['url'] ) . ' <code>' . esc_html( $e['status'] ) . '</code>' . $phase . '</li>';
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
