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
		$correlation_id = Observability::correlation_id();
		$form_id = absint( $request->get_param( 'form_id' ) );
		$values  = $request->get_param( 'values' );
		$values  = is_array( $values ) ? $values : [];

		try {
			$schema       = $this->load_form_schema( $form_id );
			$schema_version = $this->resolve_schema_version( $schema, sanitize_text_field( (string) $request->get_param( 'schema_version' ) ) );
			$payment      = $this->calculate_payment_from_schema( $schema, $values );
			$gateway_slug = $this->payment_factory->resolve_gateway_from_schema( $schema );
			$gateway      = $this->payment_factory->make( $gateway_slug );
			$record_id    = wp_generate_uuid4();
			$intent       = $gateway->create_intent(
				(int) $payment['amount'],
				$payment['currency'],
				[
					'form_id'        => (string) $form_id,
					'schema_version' => $schema_version,
					'record_id'      => $record_id,
					'description'    => $payment['description'],
				]
			);
			$intent_id = sanitize_text_field( (string) ( $intent['id'] ?? '' ) );
			$this->store_payment_intent_record( $record_id, $gateway_slug, $intent_id, $form_id, $schema_version, (int) $payment['amount'], $payment['currency'], 'created' );
		} catch ( Exception $exception ) {
			Observability::increment_metric( 'payment_failures' );
			Observability::log( 'error', 'payment_intent_failed', [ 'correlation_id' => $correlation_id, 'form_id' => $form_id, 'message' => $exception->getMessage() ] );
			return new WP_Error(
				'ep_forms_payment_intent_failed',
				__( 'Unable to prepare payment. Please try again.', 'enterprise-forms' ),
				[ 'status' => 400, 'correlation_id' => $correlation_id ]
			);
		}

		return rest_ensure_response(
			[
				'id'              => $intent_id ?: $record_id,
				'payment_record_id' => $record_id,
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
		$correlation_id = Observability::correlation_id();
		try {
			$schema = $this->load_form_schema( $form_id );
			if ( ! $this->schema_requires_payment( $schema ) ) {
				return $sanitized;
			}

			$gateway_slug  = $this->payment_factory->resolve_gateway_from_schema( $schema );
			$payment_id    = sanitize_text_field( (string) ( $payload['payment_intent_id'] ?? $payload['payment_transaction_id'] ?? '' ) );
			$payment_record_id = sanitize_text_field( (string) ( $payload['payment_record_id'] ?? '' ) );
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
			$schema_version = $this->resolve_schema_version( $schema, sanitize_text_field( (string) ( $payload['schema_version'] ?? '' ) ) );
			$record = $this->load_payment_intent_record( $gateway_slug, $payment_id, $payment_record_id );
			if ( is_wp_error( $record ) ) {
				return $record;
			}

			$record_check = $this->validate_payment_intent_record( $record, $form_id, $schema_version, (int) $expected['amount'], $expected['currency'] );
			if ( is_wp_error( $record_check ) ) {
				return $record_check;
			}

			$gateway  = $this->payment_factory->make( $gateway_slug );
			if ( 'braintree' === $gateway_slug && '' !== $payment_token && method_exists( $gateway, 'process_token' ) ) {
				$intent     = $gateway->process_token( $payment_token, (int) $expected['amount'], $expected['currency'], [ 'form_id' => (string) $form_id, 'schema_version' => $schema_version, 'record_id' => (string) $record['record_id'] ] );
				$payment_id = sanitize_text_field( (string) ( $intent['id'] ?? '' ) );
			} else {
				$intent = $gateway->verify_payment( $payment_id );
			}

			$metadata_check = $this->validate_gateway_metadata( $intent, $form_id, $schema_version, (string) $record['record_id'] );
			if ( is_wp_error( $metadata_check ) ) {
				return $metadata_check;
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

			$claim_result = $this->claim_payment_transaction( (int) $record['id'], $gateway_slug, $payment_id );
			if ( is_wp_error( $claim_result ) ) {
				return $claim_result;
			}

			$payment_log = [
				'gateway'        => $gateway_slug,
				'transaction_id' => $payment_id,
				'amount'         => $paid_amount,
				'currency'       => $paid_currency,
				'receipt_url'    => $this->extract_receipt_url( $intent ),
				'status'         => 'paid',
			];

			$sanitized['payment']        = $payment_log;
			$sanitized['transaction_id'] = $payment_log['transaction_id'];
			$sanitized['amount']         = $payment_log['amount'];
			$sanitized['receipt_url']    = $payment_log['receipt_url'];

			return $sanitized;
		} catch ( Exception $exception ) {
			Observability::increment_metric( 'payment_failures' );
			Observability::log( 'error', 'payment_verification_failed', [ 'correlation_id' => $correlation_id, 'form_id' => $form_id, 'message' => $exception->getMessage() ] );
			return new WP_Error(
				'ep_forms_payment_verification_failed',
				__( 'Unable to verify payment. Please try again.', 'enterprise-forms' ),
				[ 'status' => 400, 'correlation_id' => $correlation_id ]
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

	private function resolve_schema_version( array $schema, string $requested_version = '' ): string {
		$schema_version = sanitize_text_field( (string) ( $schema['version'] ?? $schema['schema_version'] ?? '' ) );
		if ( '' !== $requested_version ) {
			return $requested_version;
		}

		return '' !== $schema_version ? $schema_version : '1.0.0';
	}

	private function create_payment_intents_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name = $wpdb->prefix . 'ep_payment_intents';
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			record_id CHAR(36) NOT NULL,
			gateway VARCHAR(50) NOT NULL,
			intent_id VARCHAR(191) DEFAULT '' NOT NULL,
			transaction_id VARCHAR(191) DEFAULT '' NOT NULL,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			schema_version VARCHAR(50) DEFAULT '' NOT NULL,
			amount BIGINT(20) UNSIGNED NOT NULL,
			currency VARCHAR(10) NOT NULL,
			session_hash VARCHAR(64) DEFAULT '' NOT NULL,
			status VARCHAR(30) DEFAULT 'created' NOT NULL,
			entry_uuid CHAR(36) DEFAULT '' NOT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY record_id (record_id),
			UNIQUE KEY gateway_intent (gateway, intent_id),
			UNIQUE KEY gateway_transaction (gateway, transaction_id),
			KEY form_status (form_id, status),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	private function store_payment_intent_record( string $record_id, string $gateway, string $intent_id, int $form_id, string $schema_version, int $amount, string $currency, string $status ): void {
		global $wpdb;

		$this->create_payment_intents_table();
		$stored_intent_id = '' !== $intent_id ? $intent_id : $record_id;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->prefix . 'ep_payment_intents',
			[
				'record_id'      => $record_id,
				'gateway'        => $gateway,
				'intent_id'      => $stored_intent_id,
				'transaction_id' => '',
				'form_id'        => $form_id,
				'schema_version' => $schema_version,
				'amount'         => $amount,
				'currency'       => strtolower( $currency ),
				'session_hash'   => $this->payment_session_hash(),
				'status'         => $status,
				'entry_uuid'     => '',
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'created_at'     => $now,
				'updated_at'     => $now,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private function load_payment_intent_record( string $gateway, string $payment_id, string $record_id ): array|WP_Error {
		global $wpdb;

		$this->create_payment_intents_table();
		$table_name = $wpdb->prefix . 'ep_payment_intents';

		if ( '' !== $record_id ) {
			$record = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table_name} WHERE gateway = %s AND record_id = %s LIMIT 1", $gateway, $record_id ),
				ARRAY_A
			);
		} else {
			$record = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table_name} WHERE gateway = %s AND intent_id = %s LIMIT 1", $gateway, $payment_id ),
				ARRAY_A
			);
		}

		if ( ! is_array( $record ) ) {
			return new WP_Error( 'ep_forms_payment_intent_not_found', __( 'Payment intent could not be matched to this form submission.', 'enterprise-forms' ), [ 'status' => 400 ] );
		}

		return $record;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function validate_payment_intent_record( array $record, int $form_id, string $schema_version, int $amount, string $currency ): bool|WP_Error {
		if ( (int) $record['form_id'] !== $form_id || (string) $record['schema_version'] !== $schema_version ) {
			return new WP_Error( 'ep_forms_payment_form_mismatch', __( 'Payment intent does not belong to this form version.', 'enterprise-forms' ), [ 'status' => 400 ] );
		}

		if ( (int) $record['amount'] !== $amount || strtolower( (string) $record['currency'] ) !== strtolower( $currency ) ) {
			return new WP_Error( 'ep_forms_payment_amount_mismatch', __( 'Payment amount does not match the saved payment intent.', 'enterprise-forms' ), [ 'status' => 400 ] );
		}

		if ( 'paid' === sanitize_key( (string) $record['status'] ) || '' !== (string) $record['entry_uuid'] ) {
			return new WP_Error( 'ep_forms_payment_replay_detected', __( 'This payment has already been used for a submission.', 'enterprise-forms' ), [ 'status' => 409 ] );
		}

		if ( strtotime( (string) $record['expires_at'] ) < time() ) {
			return new WP_Error( 'ep_forms_payment_intent_expired', __( 'Payment intent has expired. Please refresh the form and try again.', 'enterprise-forms' ), [ 'status' => 400 ] );
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $intent
	 */
	private function validate_gateway_metadata( array $intent, int $form_id, string $schema_version, string $record_id ): bool|WP_Error {
		$metadata = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : [];
		if ( empty( $metadata ) ) {
			return true;
		}

		if ( isset( $metadata['form_id'] ) && (string) $metadata['form_id'] !== (string) $form_id ) {
			return new WP_Error( 'ep_forms_payment_form_mismatch', __( 'Payment metadata does not match this form.', 'enterprise-forms' ), [ 'status' => 400 ] );
		}

		if ( isset( $metadata['schema_version'] ) && (string) $metadata['schema_version'] !== $schema_version ) {
			return new WP_Error( 'ep_forms_payment_schema_mismatch', __( 'Payment metadata does not match this form version.', 'enterprise-forms' ), [ 'status' => 400 ] );
		}

		if ( isset( $metadata['record_id'] ) && (string) $metadata['record_id'] !== $record_id ) {
			return new WP_Error( 'ep_forms_payment_record_mismatch', __( 'Payment metadata does not match the local payment record.', 'enterprise-forms' ), [ 'status' => 400 ] );
		}

		return true;
	}

	private function claim_payment_transaction( int $record_id, string $gateway, string $transaction_id ): bool|WP_Error {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ep_payment_intents';
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT record_id FROM {$table_name} WHERE gateway = %s AND transaction_id = %s AND status = 'paid' LIMIT 1",
				$gateway,
				$transaction_id
			)
		);

		if ( $existing ) {
			return new WP_Error( 'ep_forms_payment_replay_detected', __( 'This payment has already been used for a submission.', 'enterprise-forms' ), [ 'status' => 409 ] );
		}

		$updated = $wpdb->update(
			$table_name,
			[
				'transaction_id' => $transaction_id,
				'status'         => 'paid',
				'updated_at'     => current_time( 'mysql', true ),
			],
			[ 'id' => $record_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $updated ? true : new WP_Error( 'ep_forms_payment_claim_failed', __( 'Unable to reserve payment transaction for this submission.', 'enterprise-forms' ), [ 'status' => 500 ] );
	}

	private function payment_session_hash(): string {
		$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return hash_hmac( 'sha256', $ip_address, wp_salt( 'nonce' ) );
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
