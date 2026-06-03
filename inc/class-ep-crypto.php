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
	private const FALLBACK_KEY_OPTION = 'ep_forms_encryption_key_fallback';
	private const FALLBACK_FLAG = 'EP_ALLOW_DB_ENCRYPTION_KEY_FALLBACK';
	private const KEY_NOTICE_OPTION = 'ep_forms_encryption_key_notice';
	private const ACTIVATION_NOTICE_OPTION = 'ep_forms_show_activation_fallback_key_notice';
	private const ACTIVATION_NOTICE_DISMISSED_META = 'ep_forms_activation_fallback_key_notice_dismissed';
	private const DISMISS_ACTIVATION_NOTICE_ACTION = 'ep_forms_dismiss_activation_fallback_key_notice';
	private const RECHECK_ACTION = 'ep_forms_recheck_encryption_key';
	private const FALLBACK_OPTION_STATUS = 'fallback';
	private const PRIMARY_OPTION_STATUS = 'primary';
	private const MISSING_OPTION_STATUS = 'missing';

	public function init(): void {
		add_action( 'admin_notices', [ $this, 'render_key_notice' ] );
		add_action( 'admin_post_' . self::RECHECK_ACTION, [ $this, 'handle_recheck_key_action' ] );
		add_action( 'admin_post_' . self::DISMISS_ACTIVATION_NOTICE_ACTION, [ $this, 'handle_dismiss_activation_notice' ] );
	}

	public static function mark_activation_notice_pending(): void {
		update_option( self::ACTIVATION_NOTICE_OPTION, 1, false );
	}

	/**
	 * Ensure an encryption key exists from constant, environment, or optional fallback.
	 */
	public static function ensure_encryption_key(): void {
		if ( self::has_encryption_key() ) {
			delete_option( self::KEY_NOTICE_OPTION );
			return;
		}

		self::ensure_fallback_key();

		if ( self::has_encryption_key() ) {
			delete_option( self::KEY_NOTICE_OPTION );
			return;
		}

		update_option( self::KEY_NOTICE_OPTION, 1, false );
	}

	public static function is_configured(): bool {
		return self::has_encryption_key();
	}

	public static function get_recheck_action_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::RECHECK_ACTION ),
			self::RECHECK_ACTION
		);
	}

	/**
	 * Build encryption configuration details for admin surfaces.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_admin_config(): array {
		$status = self::current_key_status();

		$config = [
			'isConfigured'    => self::has_encryption_key(),
			'status'          => $status,
			'usingFallback'   => self::FALLBACK_OPTION_STATUS === $status,
			'recheckUrl'      => self::get_recheck_action_url(),
			'warningMessage'  => '',
			'wpConfigSnippet' => '',
		];

		if ( self::FALLBACK_OPTION_STATUS === $status ) {
			$config['warningMessage'] = __( 'Enterprise Forms generated and stored a unique encryption key in the database so submissions stay available. For stronger isolation, move this key into wp-config.php and then re-check the configuration.', 'enterprise-forms' );
			$config['wpConfigSnippet'] = self::build_wp_config_snippet();
		} elseif ( self::PRIMARY_OPTION_STATUS === $status ) {
			$config['warningMessage'] = __( 'Enterprise Forms is already using a wp-config.php or environment key. Keep that key outside the database and back it up securely.', 'enterprise-forms' );
		}

		return $config;
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

		$show_activation_notice = (int) get_option( self::ACTIVATION_NOTICE_OPTION, 0 );
		if ( 1 !== $show_activation_notice ) {
			return;
		}

		if ( ! self::is_using_fallback_key() ) {
			delete_option( self::ACTIVATION_NOTICE_OPTION );
			return;
		}

		$current_user_id = get_current_user_id();
		if ( $current_user_id > 0 ) {
			$dismissed = (int) get_user_meta( $current_user_id, self::ACTIVATION_NOTICE_DISMISSED_META, true );
			if ( 1 === $dismissed ) {
				return;
			}
		}

		$fallback_message = sprintf(
			/* translators: 1: encryption key define */
			__( 'Enterprise Forms is using a database-stored fallback encryption key. For stronger security, move the generated key into %1$s or your environment and then re-check the configuration.', 'enterprise-forms' ),
			'<code>define(\'' . self::KEY_CONSTANT . '\', \'base64-encoded-32-byte-key\');</code>',
		);

		echo '<div class="notice notice-info is-dismissible"><p>' . wp_kses_post( $fallback_message ) . '</p>' . $this->get_recheck_button_markup() . $this->get_dismiss_activation_notice_button_markup() . '</div>';
	}

	public function handle_dismiss_activation_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Enterprise Forms encryption settings.', 'enterprise-forms' ) );
		}

		check_admin_referer( self::DISMISS_ACTIVATION_NOTICE_ACTION );

		$current_user_id = get_current_user_id();
		if ( $current_user_id > 0 ) {
			update_user_meta( $current_user_id, self::ACTIVATION_NOTICE_DISMISSED_META, 1 );
		}

		$redirect_url = wp_get_referer();
		if ( ! is_string( $redirect_url ) || '' === $redirect_url ) {
			$redirect_url = admin_url();
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	private function get_dismiss_activation_notice_button_markup(): string {
		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DISMISS_ACTIVATION_NOTICE_ACTION ),
			self::DISMISS_ACTIVATION_NOTICE_ACTION
		);

		return '<p><a class="button button-link" href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'enterprise-forms' ) . '</a></p>';
	}

	private function get_recheck_button_markup(): string {
		$recheck_url = self::get_recheck_action_url();

		return '<p><a class="button button-secondary" href="' . esc_url( $recheck_url ) . '">' . esc_html__( 'Re-check encryption key configuration', 'enterprise-forms' ) . '</a></p>';
	}

	public function handle_recheck_key_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Enterprise Forms encryption settings.', 'enterprise-forms' ) );
		}

		check_admin_referer( self::RECHECK_ACTION );

		self::ensure_encryption_key();

		$status = self::MISSING_OPTION_STATUS;
		if ( self::has_primary_encryption_key() ) {
			$status = self::PRIMARY_OPTION_STATUS;
		} elseif ( self::has_fallback_encryption_key() ) {
			$status = self::FALLBACK_OPTION_STATUS;
		}

		$redirect_url = wp_get_referer();
		if ( ! is_string( $redirect_url ) || '' === $redirect_url ) {
			$redirect_url = admin_url();
		}

		$redirect_url = remove_query_arg( [ 'ep_forms_key_check', 'ep_forms_key_status' ], $redirect_url );
		$redirect_url = add_query_arg(
			[
				'ep_forms_key_check'  => 'done',
				'ep_forms_key_status' => $status,
			],
			$redirect_url
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	private static function has_encryption_key(): bool {
		if ( self::has_primary_encryption_key() ) {
			return true;
		}

		return self::has_fallback_encryption_key();
	}

	private static function has_primary_encryption_key(): bool {
		if ( defined( self::KEY_CONSTANT ) && is_string( constant( self::KEY_CONSTANT ) ) && '' !== constant( self::KEY_CONSTANT ) ) {
			return true;
		}

		$env_key = getenv( self::KEY_CONSTANT );

		return is_string( $env_key ) && '' !== $env_key;
	}

	private static function has_fallback_encryption_key(): bool {
		$fallback_key = get_option( self::FALLBACK_KEY_OPTION, '' );

		return is_string( $fallback_key ) && '' !== $fallback_key;
	}

	private static function is_using_fallback_key(): bool {
		return ! self::has_primary_encryption_key() && self::has_fallback_encryption_key();
	}

	private static function current_key_status(): string {
		if ( self::has_primary_encryption_key() ) {
			return self::PRIMARY_OPTION_STATUS;
		}

		if ( self::has_fallback_encryption_key() ) {
			return self::FALLBACK_OPTION_STATUS;
		}

		return self::MISSING_OPTION_STATUS;
	}

	private static function ensure_fallback_key(): void {
		if ( self::has_fallback_encryption_key() ) {
			return;
		}

		try {
			$generated_key = base64_encode( random_bytes( 32 ) );
		} catch ( Exception ) {
			return;
		}

		add_option( self::FALLBACK_KEY_OPTION, $generated_key, '', false );
	}

	private static function is_fallback_generation_enabled(): bool {
		if ( defined( self::FALLBACK_FLAG ) && true === constant( self::FALLBACK_FLAG ) ) {
			return true;
		}

		$env_flag = getenv( self::FALLBACK_FLAG );
		if ( ! is_string( $env_flag ) ) {
			return false;
		}

		$normalized = strtolower( trim( $env_flag ) );

		return in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true );
	}

	private static function build_wp_config_snippet(): string {
		$fallback_key = get_option( self::FALLBACK_KEY_OPTION, '' );
		if ( ! is_string( $fallback_key ) || '' === $fallback_key ) {
			return '';
		}

		return "define( '" . self::KEY_CONSTANT . "', '" . esc_js( $fallback_key ) . "' );\ndefine( '" . self::KEY_ID_CONSTANT . "', 'current' );";
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
			$fallback_key = get_option( self::FALLBACK_KEY_OPTION, '' );
			$raw_key = is_string( $fallback_key ) ? $fallback_key : '';
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