<?php
namespace EnterpriseForms;

use Exception;

class EP_Gateway_Stripe implements EP_Payment_Gateway {
	/** @var array<string, string> */
	private array $credentials;

	/**
	 * @param array<string, string> $credentials
	 */
	public function __construct( array $credentials ) {
		$this->credentials = $credentials;
	}

	public function initialize(): void {
		if ( '' === $this->secret_key() ) {
			throw new Exception( esc_html__( 'Stripe secret key is not configured.', 'enterprise-forms' ) );
		}
	}

	public function create_intent( int $amount, string $currency, array $meta ): array {
		$description = sanitize_text_field( (string) ( $meta['description'] ?? '' ) );
		$metadata = [];
		foreach ( $meta as $key => $value ) {
			if ( 'description' === $key ) {
				continue;
			}

			$metadata[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
		}

		if ( class_exists( '\Stripe\StripeClient' ) ) {
			$stripe = new \Stripe\StripeClient( $this->secret_key() );
			$intent = $stripe->paymentIntents->create(
				[
					'amount'                    => $amount,
					'currency'                  => $currency,
					'description'               => $description,
					'automatic_payment_methods' => [ 'enabled' => true ],
					'metadata'                  => $metadata,
				]
			);

			return $intent->toArray();
		}

		$body = [
			'amount'                            => (string) $amount,
			'currency'                          => $currency,
			'description'                       => $description,
			'automatic_payment_methods[enabled]' => 'true',
		];

		foreach ( $metadata as $key => $value ) {
			$body[ 'metadata[' . $key . ']' ] = $value;
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/payment_intents',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $this->secret_key() ],
				'body'    => $body,
			]
		);

		return $this->decode_response( $response );
	}

	public function verify_payment( string $transaction_id ): array {
		$transaction_id = sanitize_text_field( $transaction_id );

		if ( class_exists( '\Stripe\StripeClient' ) ) {
			$stripe = new \Stripe\StripeClient( $this->secret_key() );
			$intent = $stripe->paymentIntents->retrieve( $transaction_id, [ 'expand' => [ 'latest_charge' ] ] );
			return $intent->toArray();
		}

		$response = wp_remote_get(
			add_query_arg( [ 'expand[]' => 'latest_charge' ], 'https://api.stripe.com/v1/payment_intents/' . rawurlencode( $transaction_id ) ),
			[ 'headers' => [ 'Authorization' => 'Bearer ' . $this->secret_key() ] ]
		);

		return $this->decode_response( $response );
	}

	private function secret_key(): string {
		return sanitize_text_field( (string) ( $this->credentials['secret_key'] ?? '' ) );
	}

	/**
	 * @param mixed $response
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	private function decode_response( mixed $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			throw new Exception( esc_html__( 'Stripe returned an invalid response.', 'enterprise-forms' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? sanitize_text_field( (string) $data['error']['message'] ) : esc_html__( 'Stripe request failed.', 'enterprise-forms' );
			throw new Exception( esc_html( $message ) );
		}

		return $data;
	}
}