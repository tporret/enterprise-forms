<?php
namespace EnterpriseForms;

use Exception;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for payment settings, intent creation, and verification.
 */
class EP_REST_Payments extends WP_REST_Controller {
	private EP_Payment_Settings $payment_settings;
	private EP_Payment_Factory $payment_factory;

	public function __construct() {
		$this->namespace        = 'enterprise-forms/v1';
		$this->rest_base        = 'payments';
		$this->payment_settings = new EP_Payment_Settings();
		$this->payment_factory  = new EP_Payment_Factory( $this->payment_settings );
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => [
						'publishable_key' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
						'secret_key'      => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
						'gateways'        => [ 'required' => false ],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/payment-intent',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_payment_intent' ],
				'permission_callback' => [ $this, 'public_payment_permissions_check' ],
				'args'                => [
					'form_id'        => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'schema_version' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
					'ep_forms_nonce' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
					'values'         => [ 'required' => false ],
				],
			]
		);
	}

	public function admin_permissions_check(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'ep_forms_forbidden',
			__( 'You do not have permission to manage payment settings.', 'enterprise-forms' ),
			[ 'status' => 403 ]
		);
	}

	public function public_payment_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		$header_nonce = sanitize_text_field( (string) $request->get_header( 'X-WP-Nonce' ) );
		if ( '' !== $header_nonce && wp_verify_nonce( $header_nonce, 'wp_rest' ) ) {
			return true;
		}

		$public_nonce = sanitize_text_field( (string) $request->get_param( 'ep_forms_nonce' ) );
		if ( '' !== $public_nonce && wp_verify_nonce( $public_nonce, 'ep_forms_public_submit' ) ) {
			return true;
		}

		return new WP_Error(
			'ep_forms_invalid_nonce',
			__( 'Invalid or missing security token.', 'enterprise-forms' ),
			[ 'status' => 403 ]
		);
	}

	public function get_settings(): WP_REST_Response {
		return rest_ensure_response( $this->settings_response() );
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$this->payment_settings->update_from_payload( $request->get_json_params() ?: $request->get_params() );
		} catch ( Exception $exception ) {
			return new WP_Error(
				'ep_forms_payment_settings_save_failed',
				$exception->getMessage(),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( $this->settings_response() );
	}

	public function create_payment_intent( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form_id = absint( $request->get_param( 'form_id' ) );
		$values  = $request->get_param( 'values' );
		$values  = is_array( $values ) ? $values : [];

		try {
			$schema       = $this->load_form_schema( $form_id );
			$payment      = $this->calculate_payment_from_schema( $schema, $values );
			$gateway_slug = $this->payment_factory->resolve_gateway_from_schema( $schema );
			$gateway      = $this->payment_factory->make( $gateway_slug );
			$intent       = $gateway->create_intent(
				(int) $payment['amount'],
				$payment['currency'],
				[
					'form_id'     => (string) $form_id,
					'description' => $payment['description'],
				]
			);
		} catch ( Exception $exception ) {
			return new WP_Error(
				'ep_forms_payment_intent_failed',
				$exception->getMessage(),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response(
			[
				'id'              => sanitize_text_field( (string) ( $intent['id'] ?? '' ) ),
				'client_secret'   => sanitize_text_field( (string) ( $intent['client_secret'] ?? '' ) ),
				'client_token'    => sanitize_text_field( (string) ( $intent['client_token'] ?? '' ) ),
				'amount'          => (int) $payment['amount'],
				'currency'        => $payment['currency'],
				'gateway'         => $gateway_slug,
				'client_config'   => $this->payment_settings->get_client_config( $gateway_slug ),
				'publishable_key' => self::get_publishable_key(),
			]
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>|WP_Error
	 */
	public function verify_payment_for_submission( int $form_id, array $payload, array $sanitized ): array|WP_Error {
		try {
			$schema = $this->load_form_schema( $form_id );
			if ( ! $this->schema_requires_payment( $schema ) ) {
				return $sanitized;
			}

			$gateway_slug  = $this->payment_factory->resolve_gateway_from_schema( $schema );
			$payment_id    = sanitize_text_field( (string) ( $payload['payment_intent_id'] ?? $payload['payment_transaction_id'] ?? '' ) );
			$payment_token = sanitize_text_field( (string) ( $payload['payment_token'] ?? '' ) );
			if ( '' === $payment_id ) {
				$payment_id = $payment_token;
			}

			if ( '' === $payment_id ) {
				return new WP_Error(
					'ep_forms_payment_required',
					__( 'Payment confirmation is required before submission.', 'enterprise-forms' ),
					[ 'status' => 400 ]
				);
			}

			$expected = $this->calculate_payment_from_schema( $schema, $payload );
			$gateway  = $this->payment_factory->make( $gateway_slug );
			if ( 'braintree' === $gateway_slug && '' !== $payment_token && method_exists( $gateway, 'process_token' ) ) {
				$intent     = $gateway->process_token( $payment_token, (int) $expected['amount'], $expected['currency'], [ 'form_id' => (string) $form_id ] );
				$payment_id = sanitize_text_field( (string) ( $intent['id'] ?? '' ) );
			} else {
				$intent = $gateway->verify_payment( $payment_id );
			}

			$status = sanitize_key( (string) ( $intent['status'] ?? '' ) );
			if ( 'succeeded' !== $status ) {
				return new WP_Error(
					'ep_forms_payment_not_succeeded',
					__( 'Payment has not completed successfully.', 'enterprise-forms' ),
					[ 'status' => 400 ]
				);
			}

			$paid_amount   = (int) ( $intent['amount'] ?? 0 );
			$paid_currency = strtolower( sanitize_text_field( (string) ( $intent['currency'] ?? '' ) ) );
			if ( $paid_amount !== (int) $expected['amount'] || $paid_currency !== $expected['currency'] ) {
				return new WP_Error(
					'ep_forms_payment_amount_mismatch',
					__( 'Payment amount does not match the saved form configuration.', 'enterprise-forms' ),
					[ 'status' => 400 ]
				);
			}

			$payment_log = [
				'gateway'        => $gateway_slug,
				'transaction_id' => $payment_id,
				'amount'         => $paid_amount,
				'currency'       => $paid_currency,
				'receipt_url'    => $this->extract_receipt_url( $intent ),
			];

			$sanitized['payment']        = $payment_log;
			$sanitized['transaction_id'] = $payment_log['transaction_id'];
			$sanitized['amount']         = $payment_log['amount'];
			$sanitized['receipt_url']    = $payment_log['receipt_url'];

			return $sanitized;
		} catch ( Exception $exception ) {
			return new WP_Error(
				'ep_forms_payment_verification_failed',
				$exception->getMessage(),
				[ 'status' => 400 ]
			);
		}
	}

	public static function get_publishable_key(): string {
		$settings    = new EP_Payment_Settings();
		$credentials = $settings->get_gateway_credentials( 'stripe' );
		return sanitize_text_field( (string) ( $credentials['publishable_key'] ?? '' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_frontend_gateway_settings(): array {
		$settings = new EP_Payment_Settings();
		return $settings->get_public_settings();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function settings_response(): array {
		$settings = $this->payment_settings->get_public_settings();
		$stripe   = $settings['gateways']['stripe']['fields'] ?? [];

		return array_merge(
			$settings,
			[
				'publishable_key' => sanitize_text_field( (string) ( $stripe['publishable_key'] ?? '' ) ),
				'has_secret_key'  => ! empty( $stripe['has_secret_key'] ),
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	private function load_form_schema( int $form_id ): array {
		if ( $form_id <= 0 ) {
			throw new Exception( __( 'Invalid form ID.', 'enterprise-forms' ) );
		}

		$form = get_post( $form_id );
		if ( ! $form || 'ep_form' !== $form->post_type ) {
			throw new Exception( __( 'Form not found.', 'enterprise-forms' ) );
		}

		$schema_raw = get_post_meta( $form_id, 'ep_form_schema', true );
		if ( ! is_string( $schema_raw ) || '' === trim( $schema_raw ) ) {
			throw new Exception( __( 'Form schema is missing.', 'enterprise-forms' ) );
		}

		$schema = json_decode( $schema_raw, true );
		if ( ! is_array( $schema ) ) {
			throw new Exception( __( 'Stored form schema is invalid JSON.', 'enterprise-forms' ) );
		}

		return $schema;
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private function schema_requires_payment( array $schema ): bool {
		return ! empty( $schema['requires_payment'] ) || null !== $this->find_payment_field( $schema );
	}

	/**
	 * @param array<string, mixed> $schema
	 * @return array<string, mixed>|null
	 */
	private function find_payment_field( array $schema ): ?array {
		foreach ( $this->extract_fields( $schema ) as $field ) {
			if ( 'payment' === sanitize_key( (string) ( $field['type'] ?? '' ) ) ) {
				return $field;
			}
		}

		return null;
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
		$pages  = isset( $schema['pages'] ) && is_array( $schema['pages'] ) ? $schema['pages'] : [];
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

	/**
	 * @param array<string, mixed> $schema
	 * @param array<string, mixed> $values
	 * @return array{amount: int, currency: string, description: string}
	 * @throws Exception
	 */
	private function calculate_payment_from_schema( array $schema, array $values ): array {
		$payment_field = $this->find_payment_field( $schema );
		if ( null === $payment_field ) {
			throw new Exception( __( 'This form is not configured for payment.', 'enterprise-forms' ) );
		}

		$currency      = strtolower( sanitize_key( (string) ( $payment_field['currency'] ?? 'usd' ) ) );
		$currency      = '' !== $currency ? $currency : 'usd';
		$amount_source = sanitize_key( (string) ( $payment_field['amount_source'] ?? 'static' ) );
		$amount_major  = 'field' === $amount_source
			? $this->resolve_mapped_amount( $payment_field, $schema, $values )
			: $this->parse_major_amount( (string) ( $payment_field['amount'] ?? '0' ) );

		$amount_minor = $this->to_minor_units( $amount_major, $currency );
		if ( $amount_minor <= 0 ) {
			throw new Exception( __( 'Payment amount must be greater than zero.', 'enterprise-forms' ) );
		}

		return [
			'amount'      => $amount_minor,
			'currency'    => $currency,
			'description' => sanitize_text_field( (string) ( $payment_field['description'] ?? '' ) ),
		];
	}

	/**
	 * @param array<string, mixed> $payment_field
	 * @param array<string, mixed> $schema
	 * @param array<string, mixed> $values
	 * @throws Exception
	 */
	private function resolve_mapped_amount( array $payment_field, array $schema, array $values ): float {
		$amount_field_key = sanitize_key( (string) ( $payment_field['amount_field'] ?? '' ) );
		if ( '' === $amount_field_key ) {
			throw new Exception( __( 'Payment amount field is not configured.', 'enterprise-forms' ) );
		}

		$source_field = null;
		foreach ( $this->extract_fields( $schema ) as $field ) {
			$name = sanitize_key( (string) ( $field['name'] ?? '' ) );
			$id   = sanitize_key( (string) ( $field['id'] ?? '' ) );
			if ( $amount_field_key === $name || $amount_field_key === $id ) {
				$source_field = $field;
				break;
			}
		}

		if ( ! is_array( $source_field ) ) {
			throw new Exception( __( 'Payment amount field could not be found in the saved schema.', 'enterprise-forms' ) );
		}

		$field_name = sanitize_key( (string) ( $source_field['name'] ?? $source_field['id'] ?? '' ) );
		$raw_value  = $values[ $field_name ] ?? $values[ $amount_field_key ] ?? '';
		$raw_value  = is_scalar( $raw_value ) ? sanitize_text_field( (string) $raw_value ) : '';

		$options = isset( $source_field['options'] ) && is_array( $source_field['options'] ) ? $source_field['options'] : [];
		if ( empty( $options ) ) {
			throw new Exception( __( 'Mapped payment amounts must use a saved choice field.', 'enterprise-forms' ) );
		}

		$allowed = [];
		foreach ( $options as $option ) {
			if ( is_string( $option ) ) {
				$allowed[] = sanitize_text_field( $option );
			} elseif ( is_array( $option ) ) {
				$allowed[] = sanitize_text_field( (string) ( $option['value'] ?? $option['label'] ?? '' ) );
			}
		}

		if ( ! in_array( $raw_value, $allowed, true ) ) {
			throw new Exception( __( 'Payment amount selection is not allowed.', 'enterprise-forms' ) );
		}

		return $this->parse_major_amount( $raw_value );
	}

	/**
	 * @throws Exception
	 */
	private function parse_major_amount( string $value ): float {
		$normalized = preg_replace( '/[^0-9\.\-]/', '', $value );
		$normalized = is_string( $normalized ) ? $normalized : '';
		if ( '' === $normalized || ! is_numeric( $normalized ) ) {
			throw new Exception( __( 'Payment amount is not a valid number.', 'enterprise-forms' ) );
		}

		return (float) $normalized;
	}

	private function to_minor_units( float $amount_major, string $currency ): int {
		$zero_decimal = [ 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ];
		$multiplier   = in_array( strtolower( $currency ), $zero_decimal, true ) ? 1 : 100;

		return (int) round( $amount_major * $multiplier );
	}

	/**
	 * @param array<string, mixed> $intent
	 */
	private function extract_receipt_url( array $intent ): string {
		if ( isset( $intent['receipt_url'] ) ) {
			return esc_url_raw( (string) $intent['receipt_url'] );
		}

		$latest_charge = $intent['latest_charge'] ?? null;
		if ( is_array( $latest_charge ) && isset( $latest_charge['receipt_url'] ) ) {
			return esc_url_raw( (string) $latest_charge['receipt_url'] );
		}

		return '';
	}
}
