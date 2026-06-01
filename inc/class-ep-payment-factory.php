<?php
namespace EnterpriseForms;

use Exception;

class EP_Payment_Factory {
	private EP_Payment_Settings $settings;

	public function __construct( ?EP_Payment_Settings $settings = null ) {
		$this->settings = $settings ?? new EP_Payment_Settings();
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	public function make_for_schema( array $schema ): EP_Payment_Gateway {
		$gateway = $this->resolve_gateway_from_schema( $schema );
		return $this->make( $gateway );
	}

	public function make( string $gateway ): EP_Payment_Gateway {
		$gateway = EP_Payment_Settings::normalize_gateway( $gateway );
		$credentials = $this->settings->get_gateway_credentials( $gateway );

		switch ( $gateway ) {
			case 'stripe':
				$instance = new EP_Gateway_Stripe( $credentials );
				break;

			case 'braintree':
				$instance = new EP_Gateway_Braintree( $credentials );
				break;

				case 'paypal':
					$instance = new EP_Gateway_PayPal( $credentials );
					break;

				case 'square':
					$instance = new EP_Gateway_Square( $credentials );
					break;

			default:
				$instance = new EP_Gateway_Unsupported( $gateway );
				break;
		}

		$instance->initialize();
		return $instance;
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	public function resolve_gateway_from_schema( array $schema ): string {
		foreach ( $this->extract_fields( $schema ) as $field ) {
			if ( 'payment' !== sanitize_key( (string) ( $field['type'] ?? '' ) ) ) {
				continue;
			}

			return EP_Payment_Settings::normalize_gateway( (string) ( $field['gateway'] ?? EP_Payment_Settings::DEFAULT_GATEWAY ) );
		}

		return EP_Payment_Settings::DEFAULT_GATEWAY;
	}

	/**
	 * @param array<string, mixed> $schema
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_fields( array $schema ): array {
		if ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
			return array_values( array_filter( $schema['fields'], 'is_array' ) );
		}

		$fields = [];
		$pages = isset( $schema['pages'] ) && is_array( $schema['pages'] ) ? $schema['pages'] : [];
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) || ! isset( $page['fields'] ) || ! is_array( $page['fields'] ) ) {
				continue;
			}

			foreach ( $page['fields'] as $field ) {
				if ( is_array( $field ) ) {
					$fields[] = $field;
				}
			}
		}

		return $fields;
	}
}

class EP_Gateway_Unsupported implements EP_Payment_Gateway {
	private string $gateway;

	public function __construct( string $gateway ) {
		$this->gateway = $gateway;
	}

	public function initialize(): void {}

	public function create_intent( int $amount, string $currency, array $meta ): array {
		throw new Exception( sprintf( __( '%s payments are configured but are not implemented by this gateway adapter yet.', 'enterprise-forms' ), $this->gateway ) );
	}

	public function verify_payment( string $transaction_id ): array {
		throw new Exception( sprintf( __( '%s payment verification is not implemented by this gateway adapter yet.', 'enterprise-forms' ), $this->gateway ) );
	}
}