<?php
namespace EnterpriseForms;

use Exception;

/**
 * Handles data-at-rest encryption for form entry payloads.
 */
class EP_Crypto {
	private const CIPHER = 'aes-256-gcm';
	private const KEY_CONSTANT = 'EP_ENCRYPTION_KEY';
	private const KEY_ID_CONSTANT = 'EP_ENCRYPTION_KEY_ID';
	private const KEY_NOTICE_OPTION = 'ep_forms_encryption_key_notice';

	public function init(): void {
		add_action( 'admin_notices', [ $this, 'render_key_notice' ] );
	}

	/**
	 * Ensure encryption key exists, attempting wp-config insertion first.
	 */
	public static function ensure_encryption_key(): void {
		if ( self::has_encryption_key() ) {
			delete_option( self::KEY_NOTICE_OPTION );
			return;
		}

		update_option( self::KEY_NOTICE_OPTION, 1, false );
	}

	public static function is_configured(): bool {
		return self::has_encryption_key();
	}

	/**
	 * Encrypt payload string for storage.
	 *
	 * @throws Exception
	 */
	public function encrypt( string $data ): string {
		if ( '' === $data ) {
			return $data;
		}

		$key_id = $this->current_key_id();
		$key = $this->resolve_binary_key( $key_id );
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv = random_bytes( $iv_length );

		$tag = '';
		$ciphertext = openssl_encrypt( $data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			throw new Exception( __( 'Failed to encrypt payload.', 'enterprise-forms' ) );
		}

		return 'ENCv2:' . $key_id . ':' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt payload string from storage.
	 */
	public function decrypt( string $data ): string {
		if ( '' === $data ) {
			return $data;
		}

		if ( 0 !== strpos( $data, 'ENCv1:' ) && 0 !== strpos( $data, 'ENCv2:' ) ) {
			return $data;
		}

		$key_id = 'legacy';
		$encoded = substr( $data, 6 );
		if ( str_starts_with( $data, 'ENCv2:' ) ) {
			$parts = explode( ':', $data, 3 );
			if ( 3 !== count( $parts ) ) {
				return $data;
			}

			$key_id = sanitize_key( $parts[1] );
			$encoded = $parts[2];
		}

		$raw = base64_decode( $encoded, true );
		if ( false === $raw ) {
			return $data;
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$tag_length = 16;
		if ( strlen( $raw ) <= ( $iv_length + $tag_length ) ) {
			return $data;
		}

		$iv = substr( $raw, 0, $iv_length );
		$tag = substr( $raw, $iv_length, $tag_length );
		$ciphertext = substr( $raw, $iv_length + $tag_length );

		try {
			$key = $this->resolve_binary_key( $key_id );
		} catch ( Exception ) {
			return $data;
		}

		$decrypted = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		return false === $decrypted ? $data : $decrypted;
	}

	public function render_key_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$show_notice = (int) get_option( self::KEY_NOTICE_OPTION, 0 );
		if ( 1 !== $show_notice ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: encryption key define */
			__( 'Enterprise Forms requires an encryption key before accepting submissions. Add %s to wp-config.php or provide it through the environment.', 'enterprise-forms' ),
			'<code>define(\'' . self::KEY_CONSTANT . '\', \'base64-encoded-32-byte-key\');</code>'
		);

		echo '<div class="notice notice-warning"><p>' . wp_kses_post( $message ) . '</p></div>';
	}

	private static function has_encryption_key(): bool {
		if ( defined( self::KEY_CONSTANT ) && is_string( constant( self::KEY_CONSTANT ) ) && '' !== constant( self::KEY_CONSTANT ) ) {
			return true;
		}

		$env_key = getenv( self::KEY_CONSTANT );

		return is_string( $env_key ) && '' !== $env_key;
	}

	/**
	 * @throws Exception
	 */
	private function resolve_binary_key( string $key_id = '' ): string {
		$raw_key = '';
		$keyring = apply_filters( 'ep_forms_encryption_keyring', [] );
		if ( '' !== $key_id && is_array( $keyring ) && isset( $keyring[ $key_id ] ) && is_string( $keyring[ $key_id ] ) ) {
			$raw_key = $keyring[ $key_id ];
		}

		if ( '' === $raw_key && defined( self::KEY_CONSTANT ) && is_string( constant( self::KEY_CONSTANT ) ) ) {
			$raw_key = constant( self::KEY_CONSTANT );
		}

		if ( '' === $raw_key ) {
			$env_key = getenv( self::KEY_CONSTANT );
			$raw_key = is_string( $env_key ) ? $env_key : '';
		}

		if ( '' === $raw_key ) {
			throw new Exception( __( 'Encryption key is not configured.', 'enterprise-forms' ) );
		}

		$decoded = base64_decode( $raw_key, true );
		if ( false !== $decoded && strlen( $decoded ) === 32 ) {
			return $decoded;
		}

		return hash( 'sha256', $raw_key, true );
	}

	private function current_key_id(): string {
		if ( defined( self::KEY_ID_CONSTANT ) && is_string( constant( self::KEY_ID_CONSTANT ) ) && '' !== constant( self::KEY_ID_CONSTANT ) ) {
			return sanitize_key( (string) constant( self::KEY_ID_CONSTANT ) );
		}

		return 'current';
	}
}