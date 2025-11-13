<?php
/**
 * Shared Google Drive service helper.
 *
 * @package WPMUDEV_PluginTest
 */

namespace WPMUDEV\PluginTest;

use Google_Client;
use WP_Error;

defined( 'WPINC' ) || die;

/**
 * Class Drive_Service
 *
 * Provides a single point of truth for storing credentials/tokens and bootstrapping Google Client.
 */
class Drive_Service extends Base {

	const OPTION_CREDENTIALS = 'wpmudev_drive_credentials';
	const OPTION_TOKENS      = 'wpmudev_drive_tokens';
	const CIPHER_METHOD      = 'aes-256-cbc';

	/**
	 * Returns decrypted credential set.
	 *
	 * @return array{client_id:string,client_secret:string}
	 */
	public function get_credentials(): array {
		$stored      = get_option( self::OPTION_CREDENTIALS, array() );
		$client_id   = $this->maybe_decrypt( $stored['client_id'] ?? '' );
		$client_secret = $this->maybe_decrypt( $stored['client_secret'] ?? '' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return array();
		}

		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
	}

	/**
	 * Persist credentials (encrypted when possible).
	 *
	 * @param string $client_id Client ID.
	 * @param string $client_secret Client secret.
	 *
	 * @return bool
	 */
	public function save_credentials( string $client_id, string $client_secret ): bool {
		$payload = array(
			'client_id'     => $this->maybe_encrypt( $client_id ),
			'client_secret' => $this->maybe_encrypt( $client_secret ),
			'updated_at'    => time(),
		);

		$updated = update_option( self::OPTION_CREDENTIALS, $payload, false );

		if ( $updated ) {
			$this->clear_tokens();
		}

		return (bool) $updated;
	}

	/**
	 * Whether we have usable credentials.
	 *
	 * @return bool
	 */
	public function has_credentials(): bool {
		$creds = $this->get_credentials();

		return ! empty( $creds['client_id'] ) && ! empty( $creds['client_secret'] );
	}

	/**
	 * Get stored token payload.
	 *
	 * @return array
	 */
	public function get_tokens(): array {
		$tokens = get_option( self::OPTION_TOKENS, array() );

		if ( empty( $tokens ) || ! is_array( $tokens ) ) {
			return array();
		}

		return $tokens;
	}

	/**
	 * Persist access/refresh tokens.
	 *
	 * @param array $token_payload Token payload from Google Client.
	 *
	 * @return void
	 */
	public function save_tokens( array $token_payload ): void {
		if ( empty( $token_payload['access_token'] ) ) {
			return;
		}

		$stored      = $this->get_tokens();
		$refresh_tok = $token_payload['refresh_token'] ?? ( $stored['refresh_token'] ?? '' );

		$expires_in = ! empty( $token_payload['expires_in'] ) ? absint( $token_payload['expires_in'] ) : 0;
		$expires_at = $expires_in > 0 ? time() + $expires_in : 0;

		$payload = array(
			'access_token'  => $token_payload['access_token'],
			'refresh_token' => $refresh_tok,
			'expires_at'    => $expires_at,
			'scopes'        => $token_payload['scope'] ?? '',
			'fetched_at'    => time(),
		);

		update_option( self::OPTION_TOKENS, $payload, false );
	}

	/**
	 * Forget tokens (called when credentials are updated or user revokes access).
	 *
	 * @return void
	 */
	public function clear_tokens(): void {
		delete_option( self::OPTION_TOKENS );
	}

	/**
	 * Whether the stored token is still valid.
	 *
	 * @return bool
	 */
	public function has_valid_token(): bool {
		$tokens = $this->get_tokens();

		return ! empty( $tokens['access_token'] ) && ! empty( $tokens['expires_at'] ) && time() < absint( $tokens['expires_at'] );
	}

	/**
	 * Builds a configured Google Client instance.
	 *
	 * @return Google_Client|WP_Error
	 */
	public function get_client() {
		if ( ! $this->has_credentials() ) {
			return new WP_Error( 'wpmudev_drive_missing_credentials', __( 'Google Drive credentials are not configured.', 'wpmudev-plugin-test' ) );
		}

		$creds = $this->get_credentials();

		try {
			$client = new Google_Client();
		} catch ( \Exception $e ) {
			return new WP_Error( 'wpmudev_drive_client_error', $e->getMessage() );
		}

		$client->setClientId( $creds['client_id'] );
		$client->setClientSecret( $creds['client_secret'] );
		$client->setAccessType( 'offline' );
		$client->setPrompt( 'consent' );

		$tokens = $this->get_tokens();
		if ( ! empty( $tokens ) && isset( $tokens['access_token'] ) ) {
			$expires_in = max( 0, absint( $tokens['expires_at'] ?? 0 ) - time() );
			$client->setAccessToken( array(
				'access_token'  => $tokens['access_token'],
				'refresh_token' => $tokens['refresh_token'] ?? '',
				'expires_in'    => $expires_in,
				'created'       => time() - $expires_in,
			) );
		}

		return $client;
	}

	/**
	 * Ensures the Google_Client has a valid token, refreshing it if necessary.
	 *
	 * @param Google_Client $client Google client instance.
	 *
	 * @return true|WP_Error
	 */
	public function ensure_valid_token( Google_Client $client ) {
		if ( ! $client->isAccessTokenExpired() ) {
			return true;
		}

		$refresh_token = $client->getRefreshToken();

		if ( empty( $refresh_token ) ) {
			return new WP_Error( 'wpmudev_drive_missing_refresh_token', __( 'Google Drive refresh token is missing. Please authenticate again.', 'wpmudev-plugin-test' ) );
		}

		try {
			$new_token = $client->fetchAccessTokenWithRefreshToken( $refresh_token );
		} catch ( \Exception $e ) {
			return new WP_Error( 'wpmudev_drive_token_refresh_failed', $e->getMessage() );
		}

		if ( isset( $new_token['error'] ) ) {
			return new WP_Error( 'wpmudev_drive_token_refresh_failed', $new_token['error_description'] ?? $new_token['error'] );
		}

		$new_token['refresh_token'] = $refresh_token;

		$client->setAccessToken( $new_token );
		$this->save_tokens( $new_token );

		return true;
	}

	/**
	 * Returns configured redirect URI.
	 *
	 * @return string
	 */
	public function get_redirect_uri(): string {
		return home_url( '/wp-json/wpmudev/v1/drive/callback' );
	}

	/**
	 * Generates a CSRF-safe OAuth state and stores it temporarily.
	 *
	 * @return string
	 */
	public function generate_state(): string {
		$state = wp_generate_uuid4();
		set_transient( 'wpmudev_drive_state_' . $state, time(), MINUTE_IN_SECONDS * 10 );

		return $state;
	}

	/**
	 * Validates previously generated OAuth state.
	 *
	 * @param string $state Provided state.
	 *
	 * @return bool
	 */
	public function validate_state( string $state ): bool {
		$cache_key = 'wpmudev_drive_state_' . $state;
		$data      = get_transient( $cache_key );

		if ( false === $data ) {
			return false;
		}

		delete_transient( $cache_key );

		return true;
	}

	/**
	 * Encrypts value when possible.
	 *
	 * @param string $value Value to protect.
	 *
	 * @return array|string
	 */
	private function maybe_encrypt( string $value ) {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ! $this->can_encrypt() ) {
			return $value;
		}

		$key    = $this->get_encryption_key();
		$iv_len = openssl_cipher_iv_length( self::CIPHER_METHOD );
		$iv     = $this->random_bytes( $iv_len );
		$cipher = openssl_encrypt( $value, self::CIPHER_METHOD, $key, 0, $iv );

		return array(
			'cipher' => base64_encode( $cipher ),
			'iv'     => base64_encode( $iv ),
			'algo'   => self::CIPHER_METHOD,
		);
	}

	/**
	 * Decrypts value when we stored it encrypted.
	 *
	 * @param mixed $value Value from DB.
	 *
	 * @return string
	 */
	private function maybe_decrypt( $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		if ( is_string( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) || empty( $value['cipher'] ) || empty( $value['iv'] ) ) {
			return '';
		}

		if ( ! $this->can_encrypt() ) {
			return '';
		}

		$key      = $this->get_encryption_key();
		$cipher   = base64_decode( $value['cipher'] );
		$iv       = base64_decode( $value['iv'] );
		$method   = ! empty( $value['algo'] ) ? $value['algo'] : self::CIPHER_METHOD;
		$decrypted = openssl_decrypt( $cipher, $method, $key, 0, $iv );

		return is_string( $decrypted ) ? $decrypted : '';
	}

	/**
	 * Returns true if the server can encrypt secrets.
	 *
	 * @return bool
	 */
	private function can_encrypt(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Derives encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private function get_encryption_key(): string {
		$salt = wp_salt();

		return hash( 'sha256', $salt );
	}

	/**
	 * Random bytes helper with fallback.
	 *
	 * @param int $length Length requested.
	 *
	 * @return string
	 */
	private function random_bytes( int $length ): string {
		if ( function_exists( 'random_bytes' ) ) {
			return random_bytes( $length );
		}

		return openssl_random_pseudo_bytes( $length );
	}
}
