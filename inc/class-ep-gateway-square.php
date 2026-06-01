<?php
namespace EnterpriseForms;

use Exception;

class EP_Gateway_Square implements EP_Payment_Gateway {
	/** @var array<string, string> */
	private array $credentials;

	/**
	 * @param array<string, string> $credentials
	 */
	public function __construct( array $credentials ) {
		$this->credentials = $credentials;
	}

	public function initialize(): void {
		foreach ( [ 'access_token', 'application_id', 'location_id' ] as $field ) {
			if ( '' === (string) ( $this->credentials[ $field ] ?? '' ) ) {
				throw new Exception( __( 'Square credentials are incomplete.', 'enterprise-forms' ) );
			}
		}
	}

	public function create_intent( int $amount, string $currency, array $meta ): array {
		return [
			'id'      => '',
			'amount'  => $amount,
			'currency'=> strtolower( sanitize_key( $currency ) ),
			'gateway' => 'square',
		];
	}

	public function verify_payment( string $transaction_id ): array {
		$response = wp_remote_get(
			$this->api_base_url() . '/v2/payments/' . rawurlencode( sanitize_text_field( $transaction_id ) ),
			[
				'headers' => [
					'Authorization'  => 'Bearer ' . $this->access_token(),
					'Content-Type'   => 'application/json',
					'Square-Version' => '2026-01-21',
				],
			]
		);

		$data = $this->decode_response( $response, __( 'Square payment lookup failed.', 'enterprise-forms' ) );
		$payment = isset( $data['payment'] ) && is_array( $data['payment'] ) ? $data['payment'] : [];

		return $this->map_payment( $payment, [] );
	}

	/**
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	public function process_token( string $payment_token, int $amount, string $currency, array $meta ): array {
		$response = wp_remote_post(
			$this->api_base_url() . '/v2/payments',
			[
				'headers' => [
					'Authorization'  => 'Bearer ' . $this->access_token(),
					'Content-Type'   => 'application/json',
					'Square-Version' => '2026-01-21',
				],
				'body'    => wp_json_encode(
					[
						'source_id'        => sanitize_text_field( $payment_token ),
						'idempotency_key'  => sanitize_text_field( (string) ( $meta['record_id'] ?? wp_generate_uuid4() ) ),
						'location_id'      => $this->location_id(),
						'autocomplete'     => true,
						'note'             => sanitize_text_field( (string) ( $meta['description'] ?? '' ) ),
						'reference_id'     => sanitize_text_field( (string) ( $meta['record_id'] ?? '' ) ),
						'amount_money'     => [
							'amount'   => $amount,
							'currency' => strtoupper( sanitize_key( $currency ) ),
						],
					],
					JSON_UNESCAPED_SLASHES
				),
			]
		);

		$data = $this->decode_response( $response, __( 'Square payment creation failed.', 'enterprise-forms' ) );
		$payment = isset( $data['payment'] ) && is_array( $data['payment'] ) ? $data['payment'] : [];

		return $this->map_payment( $payment, $meta );
	}

	private function environment(): string {
		$environment = strtolower( sanitize_key( (string) ( $this->credentials['environment'] ?? 'sandbox' ) ) );
		return in_array( $environment, [ 'sandbox', 'production' ], true ) ? $environment : 'sandbox';
	}

	private function api_base_url(): string {
		return 'production' === $this->environment() ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
	}

	private function access_token(): string {
		return sanitize_text_field( (string) ( $this->credentials['access_token'] ?? '' ) );
	}

	private function location_id(): string {
		return sanitize_text_field( (string) ( $this->credentials['location_id'] ?? '' ) );
	}

	/**
	 * @param mixed $response
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	private function decode_response( mixed $response, string $fallback_message ): array {
		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			throw new Exception( __( 'Square returned an invalid response.', 'enterprise-forms' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = $fallback_message;
			if ( isset( $data['errors'] ) && is_array( $data['errors'] ) && isset( $data['errors'][0] ) && is_array( $data['errors'][0] ) ) {
				$message = sanitize_text_field( (string) ( $data['errors'][0]['detail'] ?? $data['errors'][0]['code'] ?? $fallback_message ) );
			}

			throw new Exception( $message );
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $payment
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	private function map_payment( array $payment, array $meta ): array {
		$amount_money = isset( $payment['amount_money'] ) && is_array( $payment['amount_money'] ) ? $payment['amount_money'] : [];
		$status       = strtoupper( sanitize_key( (string) ( $payment['status'] ?? '' ) ) );

		$metadata = [];
		foreach ( [ 'form_id', 'schema_version', 'record_id' ] as $key ) {
			if ( isset( $meta[ $key ] ) ) {
				$metadata[ $key ] = sanitize_text_field( (string) $meta[ $key ] );
			}
		}

		return [
			'id'          => sanitize_text_field( (string) ( $payment['id'] ?? '' ) ),
			'status'      => in_array( $status, [ 'COMPLETED', 'APPROVED' ], true ) ? 'succeeded' : strtolower( $status ),
			'amount'      => (int) ( $amount_money['amount'] ?? 0 ),
			'currency'    => strtolower( sanitize_text_field( (string) ( $amount_money['currency'] ?? '' ) ) ),
			'receipt_url' => esc_url_raw( (string) ( $payment['receipt_url'] ?? '' ) ),
			'metadata'    => $metadata,
		];
	}
}