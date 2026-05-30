<?php
namespace EnterpriseForms;

use Exception;

class EP_Gateway_Braintree implements EP_Payment_Gateway {
	/** @var array<string, string> */
	private array $credentials;

	/** @var mixed */
	private $gateway = null;

	/**
	 * @param array<string, string> $credentials
	 */
	public function __construct( array $credentials ) {
		$this->credentials = $credentials;
	}

	public function initialize(): void {
		foreach ( [ 'merchant_id', 'public_key', 'private_key' ] as $field ) {
			if ( '' === (string) ( $this->credentials[ $field ] ?? '' ) ) {
				throw new Exception( __( 'Braintree credentials are incomplete.', 'enterprise-forms' ) );
			}
		}

		if ( ! class_exists( '\Braintree\Gateway' ) ) {
			throw new Exception( __( 'Braintree PHP SDK is required before Braintree payments can be processed.', 'enterprise-forms' ) );
		}

		$this->gateway = new \Braintree\Gateway(
			[
				'environment' => in_array( (string) ( $this->credentials['environment'] ?? 'sandbox' ), [ 'production', 'sandbox' ], true ) ? $this->credentials['environment'] : 'sandbox',
				'merchantId'  => $this->credentials['merchant_id'],
				'publicKey'   => $this->credentials['public_key'],
				'privateKey'  => $this->credentials['private_key'],
			]
		);
	}

	public function create_intent( int $amount, string $currency, array $meta ): array {
		if ( ! $this->gateway ) {
			$this->initialize();
		}

		$token_options = [];
		$merchant_account_id = sanitize_text_field( (string) ( $meta['merchant_account_id'] ?? '' ) );
		if ( '' !== $merchant_account_id ) {
			$token_options['merchantAccountId'] = $merchant_account_id;
		}

		$client_token = $this->gateway->clientToken()->generate( $token_options );

		return [
			'id'           => '',
			'client_token' => sanitize_text_field( (string) $client_token ),
			'amount'       => $amount,
			'currency'     => strtolower( sanitize_key( $currency ) ),
			'gateway'      => 'braintree',
		];
	}

	public function verify_payment( string $transaction_id ): array {
		if ( ! $this->gateway ) {
			$this->initialize();
		}

		$transaction = $this->gateway->transaction()->find( sanitize_text_field( $transaction_id ) );
		$status      = sanitize_key( (string) ( $transaction->status ?? '' ) );

		return [
			'id'          => sanitize_text_field( (string) ( $transaction->id ?? '' ) ),
			'status'      => in_array( $status, [ 'settled', 'settling', 'submitted_for_settlement', 'authorized' ], true ) ? 'succeeded' : $status,
			'amount'      => $this->to_minor_units( (float) ( $transaction->amount ?? 0 ), strtolower( sanitize_text_field( (string) ( $transaction->currencyIsoCode ?? '' ) ) ) ),
			'currency'    => strtolower( sanitize_text_field( (string) ( $transaction->currencyIsoCode ?? '' ) ) ),
			'receipt_url' => '',
		];
	}

	/**
	 * Capture a Braintree Drop-in payment method nonce into a real transaction.
	 *
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	public function process_token( string $payment_method_nonce, int $amount, string $currency, array $meta ): array {
		if ( ! $this->gateway ) {
			$this->initialize();
		}

		$result = $this->gateway->transaction()->sale(
			[
				'amount'             => $this->to_major_amount( $amount, $currency ),
				'paymentMethodNonce' => sanitize_text_field( $payment_method_nonce ),
				'options'            => [ 'submitForSettlement' => true ],
			]
		);

		if ( empty( $result->success ) || empty( $result->transaction ) ) {
			$message = ! empty( $result->message ) ? sanitize_text_field( (string) $result->message ) : __( 'Braintree transaction failed.', 'enterprise-forms' );
			throw new Exception( $message );
		}

		return $this->verify_payment( sanitize_text_field( (string) $result->transaction->id ) );
	}

	private function to_major_amount( int $amount_minor, string $currency ): string {
		$zero_decimal = [ 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ];
		$divisor = in_array( strtolower( $currency ), $zero_decimal, true ) ? 1 : 100;

		return number_format( $amount_minor / $divisor, 2, '.', '' );
	}

	private function to_minor_units( float $amount_major, string $currency ): int {
		$zero_decimal = [ 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ];
		$multiplier = in_array( strtolower( $currency ), $zero_decimal, true ) ? 1 : 100;

		return (int) round( $amount_major * $multiplier );
	}
}