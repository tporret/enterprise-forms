<?php
namespace EnterpriseForms;

use Exception;

/**
 * Centralized storage and sanitization for payment gateway credentials.
 */
class EP_Payment_Settings {
	public const DEFAULT_GATEWAY = 'stripe';

	private const GATEWAYS = [
		'stripe' => [
			'label'  => 'Stripe',
			'fields' => [
				'publishable_key' => [ 'secret' => false ],
				'secret_key'      => [ 'secret' => true ],
			],
		],
		'braintree' => [
			'label'  => 'Braintree',
			'fields' => [
				'environment' => [ 'secret' => false ],
				'merchant_id' => [ 'secret' => false ],
				'public_key'  => [ 'secret' => false ],
				'private_key' => [ 'secret' => true ],
			],
		],
		'paypal' => [
			'label'  => 'PayPal',
			'fields' => [
				'environment'   => [ 'secret' => false ],
				'client_id'     => [ 'secret' => false ],
				'client_secret' => [ 'secret' => true ],
			],
		],
		'square' => [
			'label'  => 'Square',
			'fields' => [
				'environment'    => [ 'secret' => false ],
				'application_id' => [ 'secret' => false ],
				'location_id'    => [ 'secret' => false ],
				'access_token'   => [ 'secret' => true ],
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
	public static function gateway_definitions(): array {
		return self::GATEWAYS;
	}

	public static function normalize_gateway( string $gateway ): string {
		$gateway = sanitize_key( $gateway );
		return isset( self::GATEWAYS[ $gateway ] ) ? $gateway : self::DEFAULT_GATEWAY;
	}

	public static function option_name( string $gateway, string $field ): string {
		return 'ep_forms_payment_' . self::normalize_gateway( $gateway ) . '_' . sanitize_key( $field );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_public_settings(): array {
		$gateways = [];

		foreach ( self::GATEWAYS as $gateway => $definition ) {
			$fields = [];
			foreach ( $definition['fields'] as $field => $meta ) {
				$is_secret = ! empty( $meta['secret'] );
				$value     = $this->get_field( $gateway, $field );
				$fields[ $field ] = $is_secret ? '' : $value;
				$fields[ 'has_' . $field ] = '' !== $value;
			}

			$gateways[ $gateway ] = [
				'label'        => (string) $definition['label'],
				'configured'   => $this->is_gateway_configured( $gateway ),
				'implemented'  => true,
				'fields'       => $fields,
				'client_config' => $this->get_client_config( $gateway ),
			];
		}

		return [
			'default_gateway' => self::DEFAULT_GATEWAY,
			'gateways'        => $gateways,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @throws Exception
	 */
	public function update_from_payload( array $payload ): void {
		$gateways = isset( $payload['gateways'] ) && is_array( $payload['gateways'] ) ? $payload['gateways'] : [];

		foreach ( self::GATEWAYS as $gateway => $definition ) {
			$gateway_payload = isset( $gateways[ $gateway ] ) && is_array( $gateways[ $gateway ] ) ? $gateways[ $gateway ] : [];

			foreach ( $definition['fields'] as $field => $meta ) {
				if ( ! array_key_exists( $field, $gateway_payload ) ) {
					continue;
				}

				$value = sanitize_text_field( (string) $gateway_payload[ $field ] );
				if ( 'braintree' === $gateway && 'environment' === $field ) {
					$value = in_array( sanitize_key( $value ), [ 'sandbox', 'production' ], true ) ? sanitize_key( $value ) : 'sandbox';
				}

				if ( 'paypal' === $gateway && 'environment' === $field ) {
					$value = in_array( sanitize_key( $value ), [ 'sandbox', 'live' ], true ) ? sanitize_key( $value ) : 'sandbox';
				}

				if ( 'square' === $gateway && 'environment' === $field ) {
					$value = in_array( sanitize_key( $value ), [ 'sandbox', 'production' ], true ) ? sanitize_key( $value ) : 'sandbox';
				}

				if ( ! empty( $meta['secret'] ) && '' === $value ) {
					continue;
				}

				$this->update_field( $gateway, $field, $value, ! empty( $meta['secret'] ) );
			}
		}

		if ( isset( $payload['publishable_key'] ) ) {
			$this->update_field( 'stripe', 'publishable_key', sanitize_text_field( (string) $payload['publishable_key'] ), false );
		}

		if ( isset( $payload['secret_key'] ) && '' !== (string) $payload['secret_key'] ) {
			$this->update_field( 'stripe', 'secret_key', sanitize_text_field( (string) $payload['secret_key'] ), true );
		}
	}

	/**
	 * @return array<string, string>
	 */
	public function get_gateway_credentials( string $gateway ): array {
		$gateway = self::normalize_gateway( $gateway );
		$definition = self::GATEWAYS[ $gateway ];
		$credentials = [];

		foreach ( $definition['fields'] as $field => $_meta ) {
			$credentials[ $field ] = $this->get_field( $gateway, $field );
		}

		return $credentials;
	}

	/**
	 * @return array<string, string>
	 */
	public function get_client_config( string $gateway ): array {
		$gateway = self::normalize_gateway( $gateway );
		$credentials = $this->get_gateway_credentials( $gateway );

		switch ( $gateway ) {
			case 'stripe':
				return [ 'publishable_key' => $credentials['publishable_key'] ?? '' ];

			case 'paypal':
				return [ 'client_id' => $credentials['client_id'] ?? '' ];

			case 'square':
				return [
					'application_id' => $credentials['application_id'] ?? '',
					'location_id'    => $credentials['location_id'] ?? '',
					'environment'    => $credentials['environment'] ?? 'sandbox',
				];
		}

		return [];
	}

	public function is_gateway_configured( string $gateway ): bool {
		$gateway = self::normalize_gateway( $gateway );
		$credentials = $this->get_gateway_credentials( $gateway );

		switch ( $gateway ) {
			case 'stripe':
				return '' !== ( $credentials['publishable_key'] ?? '' ) && '' !== ( $credentials['secret_key'] ?? '' );

			case 'braintree':
				return '' !== ( $credentials['merchant_id'] ?? '' ) && '' !== ( $credentials['public_key'] ?? '' ) && '' !== ( $credentials['private_key'] ?? '' );

			case 'paypal':
				return '' !== ( $credentials['client_id'] ?? '' ) && '' !== ( $credentials['client_secret'] ?? '' );

			case 'square':
				return '' !== ( $credentials['application_id'] ?? '' ) && '' !== ( $credentials['location_id'] ?? '' ) && '' !== ( $credentials['access_token'] ?? '' );
		}

		return false;
	}

	private function get_field( string $gateway, string $field ): string {
		$option_name = self::option_name( $gateway, $field );
		$value       = (string) get_option( $option_name, '' );
		$definition = self::GATEWAYS[ self::normalize_gateway( $gateway ) ];
		$is_secret  = ! empty( $definition['fields'][ $field ]['secret'] );

		if ( '' === $value && 'braintree' === $gateway && 'environment' === $field ) {
			return 'sandbox';
		}

		if ( '' === $value && 'paypal' === $gateway && 'environment' === $field ) {
			return 'sandbox';
		}

		if ( '' === $value && 'square' === $gateway && 'environment' === $field ) {
			return 'sandbox';
		}

		if ( '' === $value && 'stripe' === $gateway ) {
			$legacy_option = 'publishable_key' === $field ? 'ep_forms_stripe_publishable_key' : ( 'secret_key' === $field ? 'ep_forms_stripe_secret_key' : '' );
			$value = '' !== $legacy_option ? (string) get_option( $legacy_option, '' ) : '';

			if ( '' !== $value && $this->migrate_legacy_stripe_field( $field, $value, $is_secret ) ) {
				delete_option( $legacy_option );
			}
		}

		if ( '' === $value ) {
			return '';
		}

		if ( ! $is_secret ) {
			return sanitize_text_field( $value );
		}

		$decrypted = $this->crypto->decrypt( $value );
		return 0 === strpos( $decrypted, 'ENCv1:' ) ? '' : sanitize_text_field( $decrypted );
	}

	/**
	 * @throws Exception
	 */
	private function update_field( string $gateway, string $field, string $value, bool $secret ): void {
		$option_name = self::option_name( $gateway, $field );
		update_option( $option_name, $secret ? $this->crypto->encrypt( $value ) : $value, false );
	}

	private function migrate_legacy_stripe_field( string $field, string $value, bool $secret ): bool {
		if ( $secret && ! EP_Crypto::is_configured() ) {
			return false;
		}

		try {
			$this->update_field( 'stripe', $field, sanitize_text_field( $value ), $secret );
		} catch ( Exception ) {
			return false;
		}

		return true;
	}
}