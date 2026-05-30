<?php
namespace EnterpriseForms;

use Exception;

/**
 * Centralized storage and sanitization for file storage provider credentials.
 */
class EP_Storage_Settings {
	public const DEFAULT_PROVIDER = 'local';

	private const PROVIDERS = [
		'local' => [
			'label'  => 'Local WordPress uploads',
			'fields' => [],
		],
		's3'    => [
			'label'  => 'Amazon S3',
			'fields' => [
				'bucket'            => [ 'secret' => false ],
				'region'            => [ 'secret' => false ],
				'endpoint'          => [ 'secret' => false ],
				'access_key_id'     => [ 'secret' => false ],
				'secret_access_key' => [ 'secret' => true ],
				'path_style'        => [ 'secret' => false ],
				'public_base_url'   => [ 'secret' => false ],
				'key_prefix'        => [ 'secret' => false ],
			],
		],
		'r2'    => [
			'label'  => 'Cloudflare R2',
			'fields' => [
				'bucket'            => [ 'secret' => false ],
				'region'            => [ 'secret' => false ],
				'endpoint'          => [ 'secret' => false ],
				'access_key_id'     => [ 'secret' => false ],
				'secret_access_key' => [ 'secret' => true ],
				'path_style'        => [ 'secret' => false ],
				'public_base_url'   => [ 'secret' => false ],
				'key_prefix'        => [ 'secret' => false ],
			],
		],
		'gcs'   => [
			'label'  => 'Google Cloud Storage S3 interoperability',
			'fields' => [
				'bucket'            => [ 'secret' => false ],
				'region'            => [ 'secret' => false ],
				'endpoint'          => [ 'secret' => false ],
				'access_key_id'     => [ 'secret' => false ],
				'secret_access_key' => [ 'secret' => true ],
				'path_style'        => [ 'secret' => false ],
				'public_base_url'   => [ 'secret' => false ],
				'key_prefix'        => [ 'secret' => false ],
			],
		],
	];

	private EP_Crypto $crypto;

	public function __construct( ?EP_Crypto $crypto = null ) {
		$this->crypto = $crypto ?? new EP_Crypto();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function provider_definitions(): array {
		return self::PROVIDERS;
	}

	public static function normalize_provider( string $provider ): string {
		$provider = sanitize_key( $provider );
		return isset( self::PROVIDERS[ $provider ] ) ? $provider : self::DEFAULT_PROVIDER;
	}

	public static function option_name( string $provider, string $field ): string {
		return 'ep_forms_storage_' . self::normalize_provider( $provider ) . '_' . sanitize_key( $field );
	}

	public static function active_provider_option_name(): string {
		return 'ep_forms_storage_active_provider';
	}

	public function get_active_provider(): string {
		$provider = (string) get_option( self::active_provider_option_name(), '' );

		if ( '' === $provider ) {
			$provider = (string) get_option( 'ep_cloud_storage_provider', self::DEFAULT_PROVIDER );
		}

		return self::normalize_provider( $provider );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_public_settings(): array {
		$providers = [];

		foreach ( self::PROVIDERS as $provider => $definition ) {
			$fields = [];

			foreach ( $definition['fields'] as $field => $meta ) {
				$is_secret = ! empty( $meta['secret'] );
				$value = $this->get_field( $provider, $field );
				$fields[ $field ] = $is_secret ? '' : $value;
				$fields[ 'has_' . $field ] = '' !== $value;
			}

			$providers[ $provider ] = [
				'label'       => (string) $definition['label'],
				'configured'  => $this->is_provider_configured( $provider ),
				'implemented' => true,
				'fields'      => $fields,
			];
		}

		return [
			'active_provider' => $this->get_active_provider(),
			'default_provider' => self::DEFAULT_PROVIDER,
			'providers'       => $providers,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @throws Exception
	 */
	public function update_from_payload( array $payload ): void {
		if ( isset( $payload['active_provider'] ) ) {
			update_option( self::active_provider_option_name(), self::normalize_provider( (string) $payload['active_provider'] ), false );
		}

		$providers = isset( $payload['providers'] ) && is_array( $payload['providers'] ) ? $payload['providers'] : [];

		foreach ( self::PROVIDERS as $provider => $definition ) {
			$provider_payload = isset( $providers[ $provider ] ) && is_array( $providers[ $provider ] ) ? $providers[ $provider ] : [];

			foreach ( $definition['fields'] as $field => $meta ) {
				if ( ! array_key_exists( $field, $provider_payload ) ) {
					continue;
				}

				$value = $this->sanitize_field_value( $field, $provider_payload[ $field ] );
				if ( ! empty( $meta['secret'] ) && '' === $value ) {
					continue;
				}

				$this->update_field( $provider, $field, $value, ! empty( $meta['secret'] ) );
			}
		}
	}

	/**
	 * @return array<string, string>
	 */
	public function get_provider_credentials( string $provider ): array {
		$provider = self::normalize_provider( $provider );
		$definition = self::PROVIDERS[ $provider ];
		$credentials = [];

		foreach ( $definition['fields'] as $field => $_meta ) {
			$credentials[ $field ] = $this->get_field( $provider, $field );
		}

		return $credentials;
	}

	public function is_provider_configured( string $provider ): bool {
		$provider = self::normalize_provider( $provider );

		if ( 'local' === $provider ) {
			return true;
		}

		$credentials = $this->get_provider_credentials( $provider );
		return '' !== ( $credentials['bucket'] ?? '' )
			&& '' !== ( $credentials['region'] ?? '' )
			&& '' !== ( $credentials['access_key_id'] ?? '' )
			&& '' !== ( $credentials['secret_access_key'] ?? '' );
	}

	private function get_field( string $provider, string $field ): string {
		$provider = self::normalize_provider( $provider );
		$option_name = self::option_name( $provider, $field );
		$value = (string) get_option( $option_name, '' );

		if ( '' === $value ) {
			return '';
		}

		$definition = self::PROVIDERS[ $provider ];
		$is_secret = ! empty( $definition['fields'][ $field ]['secret'] );

		if ( ! $is_secret ) {
			return $this->sanitize_field_value( $field, $value );
		}

		$decrypted = $this->crypto->decrypt( $value );
		return 0 === strpos( $decrypted, 'ENCv1:' ) ? '' : sanitize_text_field( $decrypted );
	}

	/**
	 * @throws Exception
	 */
	private function update_field( string $provider, string $field, string $value, bool $secret ): void {
		$option_name = self::option_name( $provider, $field );
		update_option( $option_name, $secret ? $this->crypto->encrypt( $value ) : $value, false );
	}

	private function sanitize_field_value( string $field, mixed $value ): string {
		$value = (string) $value;

		return match ( $field ) {
			'endpoint', 'public_base_url' => esc_url_raw( $value ),
			'path_style' => in_array( sanitize_key( $value ), [ '1', 'true', 'yes', 'on' ], true ) ? '1' : '',
			'key_prefix' => trim( sanitize_text_field( $value ), "/ \t\n\r\0\x0B" ),
			default => sanitize_text_field( $value ),
		};
	}
}