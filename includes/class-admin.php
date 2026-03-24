<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page + async manual warmup trigger.
 *
 * The Warm Now button fires an AJAX request that validates the nonce, stores a
 * one-time token, then POSTs a non-blocking loopback request to admin-ajax.php
 * (?action=pbcw_do_run) using that token as auth. The loopback runs the warmup
 * in the background while the JS polls a status endpoint every 2 seconds.
 */
class PBCW_Admin {

	const MENU_SLUG    = 'pb-cache-warmer';
	const OPTIONS_KEY  = 'pbcw_settings';
	const NONCE_ACTION = 'pbcw_warmup_now';

	public function __construct() {
		add_filter( 'plugin_action_links_pb-cache-warmer/pb-cache-warmer.php', [ $this, 'action_links' ] );
		add_action( 'admin_menu',                    [ $this, 'add_menu' ] );
		add_action( 'admin_init',                    [ $this, 'register_settings' ] );
		add_action( 'admin_post_pbcw_save',          [ $this, 'save_settings' ] );
		add_action( 'admin_post_pbcw_detect_zone',   [ $this, 'detect_zone' ] );
		add_action( 'admin_post_pbcw_apply_rule',    [ $this, 'apply_rule' ] );
		add_action( 'admin_post_pbcw_remove_rule',   [ $this, 'remove_rule' ] );
		add_action( 'wp_ajax_pbcw_start_run',        [ $this, 'start_run' ] );
		add_action( 'wp_ajax_pbcw_do_run',           [ $this, 'do_run' ] );
		add_action( 'wp_ajax_nopriv_pbcw_do_run',    [ $this, 'do_run' ] );
		add_action( 'wp_ajax_pbcw_poll_status',      [ $this, 'poll_status' ] );
	}

	public function action_links( array $links ): array {
		array_unshift( $links, sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Settings', 'pb-cache-warmer' )
		) );
		return $links;
	}

	public function add_menu(): void {
		add_options_page(
			__( 'Page Builder Cache Guard', 'pb-cache-warmer' ),
			__( 'PB Cache Guard', 'pb-cache-warmer' ),
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

	// ── Cache Rules ─────────────────────────────────────────────────────────────

	/** Apply the plugin's Cache Rule to the CF zone. */
	public function apply_rule(): void {
		check_admin_referer( 'pbcw_apply_rule' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pb-cache-warmer' ) );
		}

		$options = get_option( self::OPTIONS_KEY, [] );
		$cf      = new PBCW_Cloudflare( $options['cf_token'] ?? '', $options['cf_zone_id'] ?? '' );
		$result  = $cf->apply_cache_rule();

		$param = $result['success'] ? 'rule_applied=1' : ( 'rule_error=' . rawurlencode( $result['error'] ) );
		wp_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&' . $param ) );
		exit;
	}

	/** Remove the plugin's Cache Rule from the CF zone. */
	public function remove_rule(): void {
		check_admin_referer( 'pbcw_remove_rule' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'pb-cache-warmer' ) );
		}

		$options = get_option( self::OPTIONS_KEY, [] );
		$cf      = new PBCW_Cloudflare( $options['cf_token'] ?? '', $options['cf_zone_id'] ?? '' );
		$result  = $cf->remove_cache_rule();

		$param = $result['success'] ? 'rule_removed=1' : ( 'rule_error=' . rawurlencode( $result['error'] ) );
		wp_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&' . $param ) );
		exit;
	}

	// ── Async run ───────────────────────────────────────────────────────────────

	/**
	 * AJAX: validate nonce + cap, issue a one-time token, fire background worker.
	 * Returns immediately with { success: true }.
	 */
	public function start_run(): void {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		// Clear any stale status from a previous run.
		delete_transient( 'pbcw_run_token' );
		delete_transient( 'pbcw_run_status' );

		$token = wp_generate_password( 32, false );
		set_transient( 'pbcw_run_token',  $token,                             120 );
		set_transient( 'pbcw_run_status', [ 'state' => 'running', 'started' => time() ], 300 );

		$this->fire_background_run( $token );

		wp_send_json_success( [ 'started' => true ] );
	}

	/**
	 * AJAX (no-priv): background worker. Authenticated by one-time token only.
	 * PHP continues after the HTTP client closes the socket.
	 */
	public function do_run(): void {
		$token = sanitize_text_field( $_POST['token'] ?? '' );
		$saved = get_transient( 'pbcw_run_token' );

		if ( ! $token || ! $saved || ! hash_equals( (string) $saved, $token ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}
		delete_transient( 'pbcw_run_token' );

		// Let PHP keep running even if the HTTP client disconnects.
		ignore_user_abort( true );
		set_time_limit( 600 );

		$summary = ( new PBCW_Warmer() )->run( 'manual' );

		set_transient( 'pbcw_run_status', [
			'state'   => 'done',
			'summary' => $summary,
		], 120 );

		wp_die();
	}

	/** AJAX: return current run status as JSON. */
	public function poll_status(): void {
		check_ajax_referer( 'pbcw_poll' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$status = get_transient( 'pbcw_run_status' ) ?: [ 'state' => 'idle' ];

		// If it's been running for more than 10 minutes, consider it stale.
		if ( $status['state'] === 'running' && ( time() - ( $status['started'] ?? 0 ) ) > 600 ) {
			$status['state'] = 'stale';
		}

		wp_send_json_success( $status );
	}

	/**
	 * POST a non-blocking loopback request to admin-ajax.php to run the warmup.
	 * Uses pbcw_origin_base when set so the request bypasses Cloudflare.
	 */
	private function fire_background_run( string $token ): void {
		$origin_base = apply_filters( 'pbcw_origin_base', null );

		if ( $origin_base ) {
			$url     = rtrim( $origin_base, '/' ) . '/wp-admin/admin-ajax.php';
			$headers = [ 'Host' => wp_parse_url( admin_url(), PHP_URL_HOST ) ];
		} else {
			$url     = admin_url( 'admin-ajax.php' );
			$headers = [];
		}

		wp_remote_post( $url, [
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
			'headers'   => $headers,
			'body'      => [
				'action' => 'pbcw_do_run',
				'token'  => $token,
			],
		] );
	}

	// ── Render ──────────────────────────────────────────────────────────────────

	public function render_page(): void {
		$options     = get_option( self::OPTIONS_KEY, [] );
		$log         = PBCW_Warmer::get_log();
		$warmer      = new PBCW_Warmer();
		$url_count   = count( $warmer->get_urls() );
		$next_cron   = wp_next_scheduled( PBCW_Scheduler::HOOK );
		$cf          = new PBCW_Cloudflare( $options['cf_token'] ?? '', $options['cf_zone_id'] ?? '' );
		$origin_base = apply_filters( 'pbcw_origin_base', null );
		$post_types  = get_post_types( [ 'public' => true ], 'objects' );
		$saved_types = $options['post_types'] ?? [ 'page', 'post' ];
		$run_status  = get_transient( 'pbcw_run_status' ) ?: [ 'state' => 'idle' ];

		$start_nonce  = wp_create_nonce( self::NONCE_ACTION );
		$poll_nonce   = wp_create_nonce( 'pbcw_poll' );
		$rule_status  = $cf->is_configured() ? $cf->get_cache_rule_status() : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Page Builder Cache Guard', 'pb-cache-warmer' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'pb-cache-warmer' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['zone_detected'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Zone ID detected and saved.', 'pb-cache-warmer' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['zone_not_found'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Zone ID not found. Check that your API token has Zone.Zone (read) permission and the site domain is in your Cloudflare account.', 'pb-cache-warmer' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rule_applied'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cache Rule applied. Versioned assets (CSS/JS with ?ver=) will now be cached at the CF edge for 1 year.', 'pb-cache-warmer' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rule_removed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cache Rule removed.', 'pb-cache-warmer' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rule_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php printf( esc_html__( 'Cache Rule error: %s', 'pb-cache-warmer' ), esc_html( urldecode( $_GET['rule_error'] ) ) ); ?></p></div>
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
									<p class="description"><?php esc_html_e( 'Leave blank to keep the existing token.', 'pb-cache-warmer' ); ?></p>
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

					<?php if ( $cf->is_configured() ) : ?>
					<h2><?php esc_html_e( 'CF Cache Rule — Versioned Assets', 'pb-cache-warmer' ); ?></h2>
					<p class="description" style="margin-bottom:1rem;">
						<?php esc_html_e( 'Sets a 1-year edge + browser TTL in Cloudflare for any CSS or JS file with a ?ver= query string. WordPress and page builders append ?ver= to all enqueued assets — the URL changes when the file changes, so a long TTL is safe. This prevents CF from ever needing to fetch these files from origin during normal operation.', 'pb-cache-warmer' ); ?>
					</p>

					<?php if ( ! empty( $rule_status['error'] ) && $rule_status['error'] !== 'not_configured' ) : ?>
						<div class="notice notice-warning inline"><p>
							<?php printf( esc_html__( 'Could not read CF ruleset: %s — check that your token has Zone.Cache Settings permission.', 'pb-cache-warmer' ), '<code>' . esc_html( $rule_status['error'] ) . '</code>' ); ?>
						</p></div>
					<?php elseif ( $rule_status['active'] ) : ?>
						<p><span style="color:#0a7a0a; font-weight:600;">&#10003; <?php esc_html_e( 'Rule is active', 'pb-cache-warmer' ); ?></span>
						<?php if ( $rule_status['total_rules'] > 1 ) : ?>
							&mdash; <?php printf( esc_html__( '%d other rule(s) in this zone will be preserved.', 'pb-cache-warmer' ), $rule_status['total_rules'] - 1 ); ?>
						<?php endif; ?>
						</p>
						<p style="background:#f9f9f9; border:1px solid #ddd; padding:.6rem .8rem; font-family:monospace; font-size:12px; max-width:700px;">
							(ends_with(.css) or ends_with(.js)) and uri.query contains "ver=" &rarr; edge TTL 1 year, browser TTL 1 year
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'pbcw_remove_rule' ); ?>
							<input type="hidden" name="action" value="pbcw_remove_rule">
							<?php submit_button( __( 'Remove Rule', 'pb-cache-warmer' ), 'delete', 'submit', false ); ?>
						</form>
					<?php else : ?>
						<p style="color:#666;"><?php esc_html_e( 'No rule active. The rule below will be added to your CF zone\'s Cache Rules:', 'pb-cache-warmer' ); ?></p>
						<p style="background:#f9f9f9; border:1px solid #ddd; padding:.6rem .8rem; font-family:monospace; font-size:12px; max-width:700px;">
							(ends_with(.css) or ends_with(.js)) and uri.query contains "ver=" &rarr; edge TTL 1 year, browser TTL 1 year
						</p>
						<?php if ( $rule_status['total_rules'] > 0 ) : ?>
							<p class="description" style="color:#666;">
								<?php printf( esc_html__( 'Your zone already has %d Cache Rule(s). This will be appended — existing rules will not be changed.', 'pb-cache-warmer' ), $rule_status['total_rules'] ); ?>
							</p>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'pbcw_apply_rule' ); ?>
							<input type="hidden" name="action" value="pbcw_apply_rule">
							<?php submit_button( __( 'Apply Rule', 'pb-cache-warmer' ), 'primary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
					<?php endif; // cf->is_configured() ?>


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
					<button id="pbcw-warm-btn" class="button button-primary"
						<?php echo ( $run_status['state'] === 'running' ) ? 'disabled' : ''; ?>>
						<?php esc_html_e( 'Run Warmup', 'pb-cache-warmer' ); ?>
					</button>
					<div id="pbcw-warm-status" style="margin-top:.75rem; font-size:13px;"></div>
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

		<script>
		(function () {
			var btn        = document.getElementById('pbcw-warm-btn');
			var statusDiv  = document.getElementById('pbcw-warm-status');
			var pollTimer  = null;
			var startNonce = <?php echo wp_json_encode( $start_nonce ); ?>;
			var pollNonce  = <?php echo wp_json_encode( $poll_nonce ); ?>;
			var ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			<?php if ( $run_status['state'] === 'running' ) : ?>
			// Run was already in progress when the page loaded.
			startPolling();
			<?php endif; ?>

			btn.addEventListener('click', function () {
				btn.disabled = true;
				showSpinner('Starting\u2026');

				post(ajaxUrl, { action: 'pbcw_start_run', _ajax_nonce: startNonce })
					.then(function (r) {
						if (r && r.success) {
							startPolling();
						} else {
							showError('Could not start warmup.');
							btn.disabled = false;
						}
					})
					.catch(function () {
						showError('Request failed.');
						btn.disabled = false;
					});
			});

			function startPolling() {
				btn.disabled = true;
				showSpinner('Running\u2026');
				pollTimer = setInterval(poll, 2500);
			}

			function poll() {
				post(ajaxUrl, { action: 'pbcw_poll_status', _ajax_nonce: pollNonce })
					.then(function (r) {
						if (!r || !r.success) return;
						var s = r.data;

						if (s.state === 'done') {
							clearInterval(pollTimer);
							btn.disabled = false;
							showResult(s.summary);
							// Reload the history table without a full page refresh.
							reloadLog();
						} else if (s.state === 'stale') {
							clearInterval(pollTimer);
							btn.disabled = false;
							showError('Warmup timed out or the background request was blocked. Try again.');
						}
					})
					.catch(function () { /* keep polling */ });
			}

			function showSpinner(msg) {
				statusDiv.innerHTML =
					'<span class="spinner is-active" style="float:none;vertical-align:middle;margin:-3px 4px 0 0;"></span>' +
					escHtml(msg);
			}

			function showResult(sum) {
				var msg = 'Phase 1: ' + sum.warmed + '\u202f/\u202f' + sum.urls + ' pages (' + sum.duration + 's).';
				if (sum.cf) {
					if (!sum.cf.errors.length) {
						msg += ' CF purge: ' + sum.cf.purged_pages + '\u202fpg\u202f+\u202f' + sum.cf.purged_css + '\u202fcss.';
					} else {
						msg += ' CF purge failed \u2014 check token and Zone ID.';
					}
				}
				statusDiv.innerHTML = '<span style="color:#0a7a0a;">\u2713</span> ' + escHtml(msg);
			}

			function showError(msg) {
				statusDiv.innerHTML = '<span style="color:#b32d2e;">' + escHtml(msg) + '</span>';
			}

			function reloadLog() {
				// Soft-reload just the run history by refreshing the page after a
				// short delay so the user can read the inline result first.
				setTimeout(function () { window.location.reload(); }, 3000);
			}

			function post(url, data) {
				var fd = new FormData();
				Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
				return fetch(url, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
			}

			function escHtml(s) {
				return String(s)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;');
			}
		}());
		</script>
		<?php
	}
}
