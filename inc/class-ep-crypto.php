<?php
namespace EnterpriseForms;

use Exception;

/**
 * Handles data-at-rest encryption for form entry payloads.
 */
class EP_Crypto {
	private const CIPHER = 'aes-256-gcm';
	private const KEY_CONSTANT = 'EP_ENCRYPTION_KEY';
	private const PENDING_KEY_OPTION = 'ep_forms_pending_encryption_key';
	private const KEY_NOTICE_OPTION = 'ep_forms_encryption_key_notice';

	public function init(): void {
		add_action( 'admin_notices', [ $this, 'render_key_notice' ] );
	}

	/**
	 * Ensure encryption key exists, attempting wp-config insertion first.
	 */
	public static function ensure_encryption_key(): void {
		if ( self::has_encryption_key() ) {
			return;
		}

		$key = self::generate_key();
		if ( self::persist_key_to_wp_config( $key ) ) {
			delete_option( self::PENDING_KEY_OPTION );
			delete_option( self::KEY_NOTICE_OPTION );
			return;
		}

		update_option( self::PENDING_KEY_OPTION, $key, false );
		update_option( self::KEY_NOTICE_OPTION, 1, false );
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

		$key = $this->resolve_binary_key();
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv = random_bytes( $iv_length );

		$tag = '';
		$ciphertext = openssl_encrypt( $data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			throw new Exception( __( 'Failed to encrypt payload.', 'enterprise-forms' ) );
		}

		return 'ENCv1:' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt payload string from storage.
	 */
	public function decrypt( string $data ): string {
		if ( '' === $data ) {
			return $data;
		}

		if ( 0 !== strpos( $data, 'ENCv1:' ) ) {
			return $data;
		}

		$encoded = substr( $data, 6 );
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
			$key = $this->resolve_binary_key();
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

		$pending_key = (string) get_option( self::PENDING_KEY_OPTION, '' );
		if ( '' === $pending_key ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: encryption key define */
			__( 'Enterprise Forms encryption key is not yet in wp-config.php. Add %s to wp-config.php to enable payload encryption.', 'enterprise-forms' ),
			'<code>define(\'' . self::KEY_CONSTANT . '\', \'' . esc_html( $pending_key ) . '\');</code>'
		);

		echo '<div class="notice notice-warning"><p>' . wp_kses_post( $message ) . '</p></div>';
	}

	private static function has_encryption_key(): bool {
		if ( defined( self::KEY_CONSTANT ) && is_string( constant( self::KEY_CONSTANT ) ) && '' !== constant( self::KEY_CONSTANT ) ) {
			return true;
		}

		$pending_key = (string) get_option( self::PENDING_KEY_OPTION, '' );

		return '' !== $pending_key;
	}

	private static function generate_key(): string {
		return base64_encode( random_bytes( 32 ) );
	}

	private static function persist_key_to_wp_config( string $key ): bool {
		$config_path = self::detect_wp_config_path();
		if ( '' === $config_path || ! is_writable( $config_path ) ) {
			return false;
		}

		$config_contents = file_get_contents( $config_path );
		if ( ! is_string( $config_contents ) ) {
			return false;
		}

		if ( false !== strpos( $config_contents, self::KEY_CONSTANT ) ) {
			return true;
		}

		$define = "define('" . self::KEY_CONSTANT . "', '" . addslashes( $key ) . "');\n";
		$anchor = "/* That's all, stop editing! Happy publishing. */";

		if ( false !== strpos( $config_contents, $anchor ) ) {
			$updated = str_replace( $anchor, $define . "\n" . $anchor, $config_contents );
		} else {
			$updated = $config_contents . "\n" . $define;
		}

		return false !== file_put_contents( $config_path, $updated );
	}

	private static function detect_wp_config_path(): string {
		if ( defined( 'ABSPATH' ) ) {
			$in_root = ABSPATH . 'wp-config.php';
			if ( file_exists( $in_root ) ) {
				return $in_root;
			}

			$one_level_up = dirname( ABSPATH ) . '/wp-config.php';
			if ( file_exists( $one_level_up ) ) {
				return $one_level_up;
			}
		}

		return '';
	}

	/**
	 * @throws Exception
	 */
	private function resolve_binary_key(): string {
		$raw_key = '';

		if ( defined( self::KEY_CONSTANT ) && is_string( constant( self::KEY_CONSTANT ) ) ) {
			$raw_key = constant( self::KEY_CONSTANT );
		}

		if ( '' === $raw_key ) {
			$raw_key = (string) get_option( self::PENDING_KEY_OPTION, '' );
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
}