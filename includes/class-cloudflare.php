<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cloudflare Cache API wrapper.
 *
 * Handles cache purge operations for the warmup plugin. Requires an API token
 * with Zone.Cache.Purge permission and the target zone's ID.
 *
 * All methods return false / empty string silently on failure — callers log errors.
 */
class PBCW_Cloudflare {

	const API_BASE = 'https://api.cloudflare.com/client/v4';

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

	/**
	 * Purge specific URLs from Cloudflare's cache.
	 *
	 * Batches into groups of 30 (CF API maximum per request on all plan levels).
	 * Returns true only if every batch succeeded.
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

	/**
	 * Detect the Zone ID for the current site by querying the CF Zones API.
	 * Checks the root domain (strips subdomains — zones are always registered at root).
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
