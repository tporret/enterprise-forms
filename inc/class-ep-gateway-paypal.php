<?php
namespace EnterpriseForms;

use Exception;

class EP_Gateway_PayPal implements EP_Payment_Gateway {
	/** @var array<string, string> */
	private array $credentials;

	private string $access_token = '';

	/**
	 * @param array<string, string> $credentials
	 */
	public function __construct( array $credentials ) {
		$this->credentials = $credentials;
	}

	public function initialize(): void {
		foreach ( [ 'client_id', 'client_secret' ] as $field ) {
			if ( '' === (string) ( $this->credentials[ $field ] ?? '' ) ) {
				throw new Exception( esc_html__( 'PayPal credentials are incomplete.', 'enterprise-forms' ) );
			}
		}
	}

	public function create_intent( int $amount, string $currency, array $meta ): array {
		$description = sanitize_text_field( (string) ( $meta['description'] ?? '' ) );
		$record_id   = sanitize_text_field( (string) ( $meta['record_id'] ?? '' ) );
		$form_id     = sanitize_text_field( (string) ( $meta['form_id'] ?? '' ) );
		$schema      = sanitize_text_field( (string) ( $meta['schema_version'] ?? '' ) );

		$body = [
			'intent'         => 'CAPTURE',
			'purchase_units' => [
				[
					'invoice_id'   => $record_id,
					'custom_id'    => trim( $form_id . ':' . $schema . ':' . $record_id, ':' ),
					'description'  => $description,
					'amount'       => [
						'currency_code' => strtoupper( sanitize_key( $currency ) ),
						'value'         => $this->to_major_amount( $amount, $currency ),
					],
				],
			],
		];

		$response = wp_remote_post(
			$this->api_base_url() . '/v2/checkout/orders',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->access_token(),
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		$data = $this->decode_response( $response, esc_html__( 'PayPal order creation failed.', 'enterprise-forms' ) );

		return [
			'id'            => sanitize_text_field( (string) ( $data['id'] ?? '' ) ),
			'gateway'       => 'paypal',
			'client_config' => [ 'client_id' => $this->client_id() ],
		];
	}

	public function verify_payment( string $transaction_id ): array {
		$order_id = sanitize_text_field( $transaction_id );

		$response = wp_remote_get(
			$this->api_base_url() . '/v2/checkout/orders/' . rawurlencode( $order_id ),
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->access_token(),
				],
			]
		);

		$data = $this->decode_response( $response, esc_html__( 'PayPal order lookup failed.', 'enterprise-forms' ) );

		$purchase_units = isset( $data['purchase_units'] ) && is_array( $data['purchase_units'] ) ? $data['purchase_units'] : [];
		$primary_unit   = isset( $purchase_units[0] ) && is_array( $purchase_units[0] ) ? $purchase_units[0] : [];
		$amount_data    = isset( $primary_unit['amount'] ) && is_array( $primary_unit['amount'] ) ? $primary_unit['amount'] : [];
		$currency       = strtolower( sanitize_text_field( (string) ( $amount_data['currency_code'] ?? '' ) ) );
		$status         = strtoupper( sanitize_key( (string) ( $data['status'] ?? '' ) ) );

		return [
			'id'          => sanitize_text_field( (string) ( $data['id'] ?? $order_id ) ),
			'status'      => 'COMPLETED' === $status ? 'succeeded' : strtolower( $status ),
			'amount'      => $this->to_minor_units( (float) ( $amount_data['value'] ?? 0 ), $currency ),
			'currency'    => $currency,
			'receipt_url' => $this->extract_receipt_url( $data ),
		];
	}

	private function api_base_url(): string {
		$environment = strtolower( sanitize_key( (string) ( $this->credentials['environment'] ?? 'sandbox' ) ) );
		return 'live' === $environment ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
	}

	private function client_id(): string {
		return sanitize_text_field( (string) ( $this->credentials['client_id'] ?? '' ) );
	}

	private function client_secret(): string {
		return sanitize_text_field( (string) ( $this->credentials['client_secret'] ?? '' ) );
	}

	/**
	 * @throws Exception
	 */
	private function access_token(): string {
		if ( '' !== $this->access_token ) {
			return $this->access_token;
		}

		$cache_key = 'ep_forms_paypal_token_' . md5( $this->api_base_url() . '|' . $this->client_id() );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			$this->access_token = $cached;
			return $this->access_token;
		}

		$response = wp_remote_post(
			$this->api_base_url() . '/v1/oauth2/token',
			[
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $this->client_id() . ':' . $this->client_secret() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'    => [ 'grant_type' => 'client_credentials' ],
			]
		);

		$data = $this->decode_response( $response, esc_html__( 'PayPal authentication failed.', 'enterprise-forms' ) );

		$token = sanitize_text_field( (string) ( $data['access_token'] ?? '' ) );
		if ( '' === $token ) {
			throw new Exception( esc_html__( 'PayPal did not return an access token.', 'enterprise-forms' ) );
		}

		$expires_in = max( 60, (int) ( $data['expires_in'] ?? 300 ) );
		set_transient( $cache_key, $token, max( 60, $expires_in - 60 ) );

		$this->access_token = $token;
		return $this->access_token;
	}

	private function to_major_amount( int $amount_minor, string $currency ): string {
		$zero_decimal = [ 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ];
		$divisor      = in_array( strtolower( $currency ), $zero_decimal, true ) ? 1 : 100;

		return number_format( $amount_minor / $divisor, 2, '.', '' );
	}

	private function to_minor_units( float $amount_major, string $currency ): int {
		$zero_decimal = [ 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ];
		$multiplier   = in_array( strtolower( $currency ), $zero_decimal, true ) ? 1 : 100;

		return (int) round( $amount_major * $multiplier );
	}

	/**
	 * @param mixed $response
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	private function decode_response( mixed $response, string $fallback_message ): array {
		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			throw new Exception( esc_html__( 'PayPal returned an invalid response.', 'enterprise-forms' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = sanitize_text_field( (string) ( $data['message'] ?? $data['error_description'] ?? $fallback_message ) );
			throw new Exception( esc_html( '' !== $message ? $message : $fallback_message ) );
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $order
	 */
	private function extract_receipt_url( array $order ): string {
		$purchase_units = isset( $order['purchase_units'] ) && is_array( $order['purchase_units'] ) ? $order['purchase_units'] : [];
		$primary_unit   = isset( $purchase_units[0] ) && is_array( $purchase_units[0] ) ? $purchase_units[0] : [];
		$payments       = isset( $primary_unit['payments'] ) && is_array( $primary_unit['payments'] ) ? $primary_unit['payments'] : [];
		$captures       = isset( $payments['captures'] ) && is_array( $payments['captures'] ) ? $payments['captures'] : [];

		if ( empty( $captures ) || ! is_array( $captures[0] ) ) {
			return '';
		}

		$links = isset( $captures[0]['links'] ) && is_array( $captures[0]['links'] ) ? $captures[0]['links'] : [];
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}

			if ( 'receipt' === sanitize_key( (string) ( $link['rel'] ?? '' ) ) ) {
				return esc_url_raw( (string) ( $link['href'] ?? '' ) );
			}
		}

		return '';
	}
}