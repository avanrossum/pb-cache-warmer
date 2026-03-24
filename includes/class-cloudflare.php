<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cloudflare API wrapper.
 *
 * Handles cache purge and Cache Rules management. Requires an API token with:
 *   - Zone.Cache.Purge    — for purge_urls()
 *   - Zone.Cache Settings — for apply_cache_rule() / remove_cache_rule()
 *   - Zone.Zone (read)    — for detect_zone_id()
 *
 * All methods return false / empty string on failure — callers handle errors.
 */
class PBCW_Cloudflare {

	const API_BASE = 'https://api.cloudflare.com/client/v4';

	/**
	 * Description tag embedded in the rule so we can find and manage it later.
	 * Changing this will cause the plugin to lose track of previously-created rules.
	 */
	const RULE_TAG = 'pbcw:versioned-assets';

	private string $token;
	private string $zone_id;

	public function __construct( string $token, string $zone_id ) {
		$this->token   = $token;
		$this->zone_id = $zone_id;
	}

	/** True when both token and zone ID are present. */
	public function is_configured(): bool {
		return $this->token !== '' && $this->zone_id !== '';
	}

	// ── Cache Purge ──────────────────────────────────────────────────────────

	/**
	 * Purge specific URLs from Cloudflare's cache.
	 * Batched in groups of 30 (CF API maximum per request on all plan levels).
	 *
	 * @param  string[] $urls  Absolute URLs to purge.
	 */
	public function purge_urls( array $urls ): bool {
		if ( ! $this->is_configured() || empty( $urls ) ) {
			return false;
		}

		$success = true;
		foreach ( array_chunk( array_values( array_unique( $urls ) ), 30 ) as $batch ) {
			if ( ! $this->api_post( '/purge_cache', [ 'files' => $batch ] ) ) {
				$success = false;
			}
		}
		return $success;
	}

	// ── Cache Rules ──────────────────────────────────────────────────────────

	/**
	 * Return the current state of the plugin's Cache Rule.
	 *
	 * @return array{
	 *   active:      bool,
	 *   rule:        array|null,
	 *   total_rules: int,
	 *   error:       string,
	 * }
	 */
	public function get_cache_rule_status(): array {
		$blank = [ 'active' => false, 'rule' => null, 'total_rules' => 0, 'error' => '' ];

		if ( ! $this->is_configured() ) {
			return array_merge( $blank, [ 'error' => 'not_configured' ] );
		}

		$response = wp_remote_get(
			self::API_BASE . '/zones/' . $this->zone_id . '/rulesets/phases/http_request_cache_settings/entrypoint',
			[ 'headers' => $this->auth_headers(), 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			return array_merge( $blank, [ 'error' => $response->get_error_message() ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// 404 = no cache rules ruleset exists yet — that's fine, rule is just not active.
		if ( $code === 404 ) {
			return $blank;
		}

		if ( empty( $data['success'] ) ) {
			$msg = $data['errors'][0]['message'] ?? 'API error';
			return array_merge( $blank, [ 'error' => $msg ] );
		}

		$rules = $data['result']['rules'] ?? [];
		$found = null;
		foreach ( $rules as $rule ) {
			if ( str_contains( $rule['description'] ?? '', self::RULE_TAG ) ) {
				$found = $rule;
				break;
			}
		}

		return [
			'active'      => $found !== null && ( $rule['enabled'] ?? true ),
			'rule'        => $found,
			'total_rules' => count( $rules ),
			'error'       => '',
		];
	}

	/**
	 * Add the plugin's Cache Rule to the zone's cache settings ruleset.
	 * Retrieves the existing ruleset first and appends — does not overwrite other rules.
	 * Idempotent: no-ops if the rule is already present.
	 *
	 * @return array{ success: bool, error: string }
	 */
	public function apply_cache_rule(): array {
		if ( ! $this->is_configured() ) {
			return [ 'success' => false, 'error' => 'not_configured' ];
		}

		// Fetch existing rules so we can append without overwriting.
		$existing = $this->get_existing_rules();
		if ( isset( $existing['error'] ) ) {
			return [ 'success' => false, 'error' => $existing['error'] ];
		}

		// Idempotency: remove any previous version of our rule before re-adding.
		$rules = array_values( array_filter( $existing['rules'], function ( $r ) {
			return ! str_contains( $r['description'] ?? '', self::RULE_TAG );
		} ) );

		$rules[] = $this->build_rule();

		return $this->put_ruleset( $existing['ruleset_id'], $rules );
	}

	/**
	 * Remove the plugin's Cache Rule from the zone's cache settings ruleset.
	 *
	 * @return array{ success: bool, error: string }
	 */
	public function remove_cache_rule(): array {
		if ( ! $this->is_configured() ) {
			return [ 'success' => false, 'error' => 'not_configured' ];
		}

		$existing = $this->get_existing_rules();
		if ( isset( $existing['error'] ) ) {
			return [ 'success' => false, 'error' => $existing['error'] ];
		}

		$rules = array_values( array_filter( $existing['rules'], function ( $r ) {
			return ! str_contains( $r['description'] ?? '', self::RULE_TAG );
		} ) );

		return $this->put_ruleset( $existing['ruleset_id'], $rules );
	}

	// ── Zone Detection ───────────────────────────────────────────────────────

	/**
	 * Detect the Zone ID for the current site by querying the CF Zones API.
	 * Strips subdomains — zones are always registered at the root domain.
	 *
	 * @return string Zone ID, or empty string on failure.
	 */
	public function detect_zone_id(): string {
		if ( $this->token === '' ) {
			return '';
		}

		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$parts  = explode( '.', $host );
		$domain = count( $parts ) >= 2 ? implode( '.', array_slice( $parts, -2 ) ) : $host;

		$response = wp_remote_get( self::API_BASE . '/zones?name=' . rawurlencode( $domain ), [
			'headers' => $this->auth_headers(),
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return $data['result'][0]['id'] ?? '';
	}

	// ── Private ───────────────────────────────────────────────────────────────

	/**
	 * Fetch the current cache settings ruleset and its rules.
	 * Returns ['rules' => [...], 'ruleset_id' => string] or ['error' => string].
	 */
	private function get_existing_rules(): array {
		$response = wp_remote_get(
			self::API_BASE . '/zones/' . $this->zone_id . '/rulesets/phases/http_request_cache_settings/entrypoint',
			[ 'headers' => $this->auth_headers(), 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'error' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 404 ) {
			// No ruleset exists yet — start with an empty one.
			return [ 'rules' => [], 'ruleset_id' => null ];
		}

		if ( empty( $data['success'] ) ) {
			return [ 'error' => $data['errors'][0]['message'] ?? 'API error fetching ruleset' ];
		}

		return [
			'rules'      => $data['result']['rules'] ?? [],
			'ruleset_id' => $data['result']['id'] ?? null,
		];
	}

	/** PUT the full ruleset back. Creates it if $ruleset_id is null. */
	private function put_ruleset( ?string $ruleset_id, array $rules ): array {
		// Strip CF-managed fields that cannot be round-tripped in a PUT.
		$clean = array_map( function ( $r ) {
			return array_intersect_key( $r, array_flip( [
				'expression', 'action', 'action_parameters', 'description', 'enabled',
			] ) );
		}, $rules );

		$endpoint = '/zones/' . $this->zone_id . '/rulesets/phases/http_request_cache_settings/entrypoint';
		$body     = wp_json_encode( [ 'rules' => $clean ] );

		$response = wp_remote_request( self::API_BASE . $endpoint, [
			'method'  => 'PUT',
			'headers' => array_merge( $this->auth_headers(), [ 'Content-Type' => 'application/json' ] ),
			'body'    => $body,
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['success'] ) ) {
			return [ 'success' => false, 'error' => $data['errors'][0]['message'] ?? 'API error saving ruleset' ];
		}

		return [ 'success' => true, 'error' => '' ];
	}

	/** Build the plugin's Cache Rule definition. */
	private function build_rule(): array {
		return [
			'description'      => 'Page Builder Cache Guard — ' . self::RULE_TAG,
			'expression'       => '(ends_with(http.request.uri.path, ".css") or ends_with(http.request.uri.path, ".js")) and http.request.uri.query contains "ver="',
			'action'           => 'set_cache_settings',
			'action_parameters' => [
				'cache'      => true,
				'edge_ttl'   => [ 'mode' => 'override_origin', 'default' => 31536000 ],
				'browser_ttl' => [ 'mode' => 'override_origin', 'default' => 31536000 ],
			],
		];
	}

	private function api_post( string $endpoint, array $body ): bool {
		$response = wp_remote_post(
			self::API_BASE . '/zones/' . $this->zone_id . $endpoint,
			[
				'headers' => array_merge( $this->auth_headers(), [ 'Content-Type' => 'application/json' ] ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $data['success'] );
	}

	private function auth_headers(): array {
		return [ 'Authorization' => 'Bearer ' . $this->token ];
	}
}
