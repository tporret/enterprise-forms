<?php
namespace EnterpriseForms;

use Exception;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for form entries.
 */
class EP_REST_Entries extends WP_REST_Controller {
	private EP_Validator $validator;
	private EP_Crypto $crypto;
	private EP_REST_Payments $payments;

	public function __construct() {
		$this->namespace = 'enterprise-forms/v1';
		$this->rest_base = 'entries';
		$this->validator = new EP_Validator();
		$this->crypto    = new EP_Crypto();
		$this->payments  = new EP_REST_Payments();
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<form_id>\\d+)',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'form_id'        => [
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
						'schema_version' => [
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'hp_field'       => [
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'ep_submission_token' => [
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [
						'form_id' => [
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
						'offset'  => [
							'default'           => 0,
							'sanitize_callback' => 'absint',
						],
						'limit'   => [
							'default'           => 20,
							'sanitize_callback' => 'absint',
						],
						'status'  => [
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						],
					],
				],
			]
		);
	}

	public function create_item_permissions_check( $request ): bool|WP_Error {
		$correlation_id = Observability::correlation_id();
		$form_id = absint( $request['form_id'] ?? 0 );
		$honeypot = sanitize_text_field( (string) $request->get_param( 'hp_field' ) );
		if ( '' !== $honeypot ) {
			Observability::increment_metric( 'spam_blocks' );
			return new WP_Error(
				'ep_forms_spam_detected',
				__( 'Spam submission detected.', 'enterprise-forms' ),
				[ 'status' => 403 ]
			);
		}

		$public_nonce = sanitize_text_field( (string) $request->get_param( 'ep_forms_nonce' ) );
		if ( '' === $public_nonce || ! wp_verify_nonce( $public_nonce, 'ep_forms_public_submit' ) ) {
			Observability::increment_metric( 'submission_nonce_failures' );
			return new WP_Error(
				'ep_forms_invalid_nonce',
				__( 'Invalid or missing security token.', 'enterprise-forms' ),
				[ 'status' => 403 ]
			);
		}

		$rate_limited = $this->check_rate_limit( $form_id );
		if ( is_wp_error( $rate_limited ) ) {
			Observability::increment_metric( 'rate_limit_blocks' );
			Observability::log( 'warning', 'submission_rate_limited', [ 'correlation_id' => $correlation_id, 'form_id' => $form_id ] );
			return $rate_limited;
		}

		return $this->consume_submission_token( $request, $form_id );
	}

	public function get_items_permissions_check( $request ): bool|WP_Error {
		if ( current_user_can( 'manage_enterprise_forms' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'ep_forms_forbidden',
			__( 'You do not have permission to view form entries.', 'enterprise-forms' ),
			[ 'status' => 403 ]
		);
	}

	public function create_item( $request ): WP_REST_Response|WP_Error {
		$form_id         = absint( $request['form_id'] );
		$schema_version  = sanitize_text_field( (string) $request->get_param( 'schema_version' ) );
		$payload         = $request->get_params();
		$file_payload    = $request->get_file_params();

		if ( ! EP_Crypto::is_configured() ) {
			Observability::increment_metric( 'submission_encryption_blocks' );
			return new WP_Error(
				'ep_forms_encryption_key_missing',
				__( 'This form is temporarily unavailable because encryption is not configured.', 'enterprise-forms' ),
				[ 'status' => 503 ]
			);
		}

		if ( is_array( $file_payload ) ) {
			foreach ( $file_payload as $file_key => $file_value ) {
				if ( is_string( $file_key ) ) {
					$payload[ $file_key ] = $file_value;
				}
			}
		}

		unset( $payload['form_id'], $payload['schema_version'], $payload['ep_forms_nonce'], $payload['ep_submission_token'], $payload['hp_field'] );

		try {
			$validation = $this->validator->validate_payload( $payload, $form_id, $schema_version );
		} catch ( Exception $exception ) {
			return new WP_Error(
				'ep_forms_invalid_schema',
				$exception->getMessage(),
				[ 'status' => 400 ]
			);
		}

		if ( ! $validation['is_valid'] ) {
			Observability::increment_metric( 'validation_failures' );
			return new WP_Error(
				'ep_forms_validation_failed',
				__( 'Validation failed.', 'enterprise-forms' ),
				[
					'status' => 400,
					'errors' => $validation['errors'],
				]
			);
		}

		$file_reference_check = $this->validate_uploaded_file_references( $form_id, $validation['sanitized'] );
		if ( is_wp_error( $file_reference_check ) ) {
			return $file_reference_check;
		}

		$payment_payload = $payload;
		$payment_payload['schema_version'] = $schema_version;
		$payment_sanitized = $this->payments->verify_payment_for_submission( $form_id, $payment_payload, $validation['sanitized'] );
		if ( is_wp_error( $payment_sanitized ) ) {
			return $payment_sanitized;
		}

		$validation['sanitized'] = $payment_sanitized;

		$duplicate_check = $this->check_duplicate_submission( $form_id, $validation['sanitized'] );
		if ( is_wp_error( $duplicate_check ) ) {
			return $duplicate_check;
		}

		if ( is_array( $file_payload ) && ! empty( $file_payload ) ) {
			try {
				$uploaded_files = $this->persist_uploaded_files( $form_id, $file_payload );
			} catch ( Exception $exception ) {
				return new WP_Error(
					'ep_forms_file_upload_failed',
					$exception->getMessage(),
					[ 'status' => 400 ]
				);
			}

			foreach ( $uploaded_files as $field_name => $media_rows ) {
				$validation['sanitized'][ $field_name ] = $media_rows;
			}
		}

		global $wpdb;

		$table_name = $wpdb->prefix . 'ep_entries';
		$uuid       = wp_generate_uuid4();
		$payload_json = wp_json_encode( $validation['sanitized'] );
		$created_at = current_time( 'mysql', true );

		if ( false === $payload_json ) {
			Observability::log( 'error', 'submission_payload_encode_failed', [ 'form_id' => $form_id ] );
			return new WP_Error(
				'ep_forms_payload_encode_failed',
				__( 'Unable to encode submission payload.', 'enterprise-forms' ),
				[ 'status' => 500 ]
			);
		}

		try {
			$encrypted_payload = $this->crypto->encrypt( $payload_json );
		} catch ( Exception $exception ) {
			Observability::log( 'error', 'submission_encryption_failed', [ 'form_id' => $form_id, 'message' => $exception->getMessage() ] );
			return new WP_Error(
				'ep_forms_encryption_failed',
				__( 'Unable to securely process this submission.', 'enterprise-forms' ),
				[ 'status' => 500 ]
			);
		}

		$stored_payload = wp_json_encode( [ 'ciphertext' => $encrypted_payload ] );
		if ( false === $stored_payload ) {
			return new WP_Error(
				'ep_forms_payload_storage_failed',
				__( 'Unable to prepare encrypted payload for storage.', 'enterprise-forms' ),
				[ 'status' => 500 ]
			);
		}

		$inserted = $wpdb->insert(
			$table_name,
			[
				'uuid'       => $uuid,
				'form_id'    => $form_id,
				'status'     => 'unread',
				'payload'    => $stored_payload,
				'created_at' => $created_at,
			],
			[ '%s', '%d', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			Observability::log( 'error', 'submission_insert_failed', [ 'form_id' => $form_id ] );
			return new WP_Error(
				'ep_forms_insert_failed',
				__( 'Failed to save submission.', 'enterprise-forms' ),
				[ 'status' => 500 ]
			);
		}

		$this->mark_submission_fingerprint( $form_id, $validation['sanitized'] );
		$this->attach_uploaded_file_references( $form_id, $uuid, $validation['sanitized'] );
		$this->attach_payment_record( $uuid, $validation['sanitized'] );

		$notification_sent = ( new NotificationService() )->send_submission_notification( $form_id, $uuid, $created_at, $validation['sanitized'] );
		Observability::increment_metric( 'submissions_accepted' );
		if ( ! $notification_sent ) {
			Observability::increment_metric( 'notification_failures' );
			Observability::log( 'warning', 'notification_failed', [ 'form_id' => $form_id, 'entry_uuid' => $uuid ] );
		}

		return new WP_REST_Response(
			[
				'success'      => true,
				'message'      => __( 'Form submission successful.', 'enterprise-forms' ),
				'entry_uuid'   => $uuid,
				'schemaVersion' => $validation['schema_version'],
				'notification_sent' => (bool) $notification_sent,
			],
			200
		);
	}

	/**
	 * Persist uploaded files as WordPress attachments and return metadata rows by field name.
	 *
	 * @param int $form_id
	 * @param array<string, mixed> $file_payload
	 * @return array<string, array<int, array<string, mixed>>>
	 * @throws Exception
	 */
	private function persist_uploaded_files( int $form_id, array $file_payload ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$schema_raw = get_post_meta( $form_id, 'ep_form_schema', true );
		$schema = is_string( $schema_raw ) ? json_decode( $schema_raw, true ) : null;
		$fields = is_array( $schema ) && isset( $schema['fields'] ) && is_array( $schema['fields'] )
			? $schema['fields']
			: [];

		$persisted = [];

		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_type = sanitize_key( (string) ( $field['type'] ?? '' ) );
			if ( 'file' !== $field_type ) {
				continue;
			}

			$field_id = sanitize_key( (string) ( $field['id'] ?? 'field_' . $index ) );
			$field_name = sanitize_key( (string) ( $field['name'] ?? $field_id ) );

			if ( ! array_key_exists( $field_name, $file_payload ) ) {
				continue;
			}

			$normalized_files = $this->normalize_uploaded_files( $file_payload[ $field_name ] );
			$rows = [];

			foreach ( $normalized_files as $file ) {
				if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) {
					continue;
				}

				$attachment_id = media_handle_sideload( $file, $form_id );
				if ( is_wp_error( $attachment_id ) ) {
					throw new Exception( $attachment_id->get_error_message() );
				}

				$attachment_url = wp_get_attachment_url( $attachment_id );
				$stored_path = get_attached_file( $attachment_id );

				$rows[] = [
					'id'       => (int) $attachment_id,
					'url'      => is_string( $attachment_url ) ? esc_url_raw( $attachment_url ) : '',
					'filename' => sanitize_file_name( (string) ( $file['name'] ?? '' ) ),
					'mime'     => sanitize_text_field( (string) ( $file['type'] ?? '' ) ),
					'size'     => ( is_string( $stored_path ) && file_exists( $stored_path ) ) ? (int) filesize( $stored_path ) : (int) ( $file['size'] ?? 0 ),
				];
			}

			$persisted[ $field_name ] = $rows;
		}

		return $persisted;
	}

	private function consume_submission_token( WP_REST_Request $request, int $form_id ): bool|WP_Error {
		$token = sanitize_text_field( (string) $request->get_param( 'ep_submission_token' ) );
		if ( '' === $token ) {
			return new WP_Error(
				'ep_forms_missing_submission_token',
				__( 'Missing submission token.', 'enterprise-forms' ),
				[ 'status' => 403 ]
			);
		}

		$transient_key = $this->submission_token_key( $token );
		$stored_form_id = absint( get_transient( $transient_key ) );
		if ( $stored_form_id !== $form_id ) {
			return new WP_Error(
				'ep_forms_replay_detected',
				__( 'This form submission token is invalid or has already been used.', 'enterprise-forms' ),
				[ 'status' => 409 ]
			);
		}

		delete_transient( $transient_key );

		return true;
	}

	private function check_rate_limit( int $form_id ): bool|WP_Error {
		$limit = (int) apply_filters( 'ep_forms_submission_rate_limit', 10, $form_id );
		$window = (int) apply_filters( 'ep_forms_submission_rate_window', MINUTE_IN_SECONDS, $form_id );

		if ( $limit < 1 || $window < 1 ) {
			return true;
		}

		$key = 'ep_submit_rate_' . $form_id . '_' . $this->request_fingerprint();
		$count = absint( get_transient( $key ) );
		if ( $count >= $limit ) {
			return new WP_Error(
				'ep_forms_rate_limited',
				__( 'Too many submissions. Please wait a moment and try again.', 'enterprise-forms' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function check_duplicate_submission( int $form_id, array $payload ): bool|WP_Error {
		$key = $this->submission_fingerprint_key( $form_id, $payload );
		if ( get_transient( $key ) ) {
			return new WP_Error(
				'ep_forms_duplicate_submission',
				__( 'This submission looks like a duplicate. Please wait a moment before trying again.', 'enterprise-forms' ),
				[ 'status' => 409 ]
			);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function mark_submission_fingerprint( int $form_id, array $payload ): void {
		set_transient( $this->submission_fingerprint_key( $form_id, $payload ), 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function submission_fingerprint_key( int $form_id, array $payload ): string {
		ksort( $payload );
		$payload_json = wp_json_encode( $payload );
		$hash_source = $form_id . '|' . ( false === $payload_json ? '' : $payload_json ) . '|' . $this->request_fingerprint();

		return 'ep_submit_dup_' . hash_hmac( 'sha256', $hash_source, wp_salt( 'nonce' ) );
	}

	private function request_fingerprint(): string {
		$ip_address = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip_address = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}

		return hash_hmac( 'sha256', $ip_address, wp_salt( 'nonce' ) );
	}

	private function submission_token_key( string $token ): string {
		return 'ep_submit_token_' . hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function validate_uploaded_file_references( int $form_id, array $payload ): bool|WP_Error {
		foreach ( $this->extract_file_reference_rows( $form_id, $payload ) as $field_name => $references ) {
			foreach ( $references as $reference ) {
				if ( ! $this->uploaded_file_reference_exists( $form_id, $field_name, $reference ) ) {
					return new WP_Error(
						'ep_forms_invalid_file_reference',
						__( 'Uploaded file reference is invalid or expired.', 'enterprise-forms' ),
						[ 'status' => 400 ]
					);
				}
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function attach_uploaded_file_references( int $form_id, string $entry_uuid, array $payload ): void {
		global $wpdb;

		foreach ( $this->extract_file_reference_rows( $form_id, $payload ) as $field_name => $references ) {
			foreach ( $references as $reference ) {
				$wpdb->update(
					$wpdb->prefix . 'ep_file_uploads',
					[
						'entry_id' => $entry_uuid,
						'status'   => 'attached_to_entry',
					],
					[
						'form_id'    => (string) $form_id,
						'field_name' => $field_name,
						'file_url'   => $reference,
					],
					[ '%s', '%s' ],
					[ '%s', '%s', '%s' ]
				);
			}
		}
	}

	private function uploaded_file_reference_exists( int $form_id, string $field_name, string $reference ): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ep_file_uploads';

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE form_id = %s AND field_name = %s AND file_url = %s AND status IN ('uploaded', 'created') LIMIT 1",
				(string) $form_id,
				$field_name,
				$reference
			)
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, array<int, string>>
	 */
	private function extract_file_reference_rows( int $form_id, array $payload ): array {
		$schema_raw = get_post_meta( $form_id, 'ep_form_schema', true );
		$schema = is_string( $schema_raw ) ? json_decode( $schema_raw, true ) : null;
		$fields = is_array( $schema ) && isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : [];
		$references = [];

		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) || 'file' !== sanitize_key( (string) ( $field['type'] ?? '' ) ) ) {
				continue;
			}

			$field_id = sanitize_key( (string) ( $field['id'] ?? 'field_' . $index ) );
			$field_name = sanitize_key( (string) ( $field['name'] ?? $field_id ) );
			$value = $payload[ $field_name ] ?? null;

			if ( is_string( $value ) && '' !== $value ) {
				$references[ $field_name ][] = esc_url_raw( $value );
			}
		}

		return $references;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function attach_payment_record( string $entry_uuid, array $payload ): void {
		$payment = isset( $payload['payment'] ) && is_array( $payload['payment'] ) ? $payload['payment'] : [];
		$transaction_id = sanitize_text_field( (string) ( $payment['transaction_id'] ?? '' ) );
		$gateway = sanitize_key( (string) ( $payment['gateway'] ?? '' ) );
		if ( '' === $transaction_id || '' === $gateway ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'ep_payment_intents',
			[
				'entry_uuid' => $entry_uuid,
				'updated_at' => current_time( 'mysql', true ),
			],
			[
				'gateway'        => $gateway,
				'transaction_id' => $transaction_id,
			],
			[ '%s', '%s' ],
			[ '%s', '%s' ]
		);
	}

	/**
	 * Normalize a WP file param into a list of file arrays suitable for media_handle_sideload.
	 *
	 * @param mixed $raw_file
	 * @return array<int, array{name: string, type: string, tmp_name: string, error: int, size: int}>
	 */
	private function normalize_uploaded_files( mixed $raw_file ): array {
		if ( ! is_array( $raw_file ) || ! isset( $raw_file['name'] ) ) {
			return [];
		}

		if ( is_array( $raw_file['name'] ) ) {
			$normalized = [];
			$count = count( $raw_file['name'] );

			for ( $i = 0; $i < $count; $i++ ) {
				$normalized[] = [
					'name'     => (string) ( $raw_file['name'][ $i ] ?? '' ),
					'type'     => (string) ( $raw_file['type'][ $i ] ?? '' ),
					'tmp_name' => (string) ( $raw_file['tmp_name'][ $i ] ?? '' ),
					'error'    => (int) ( $raw_file['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ),
					'size'     => (int) ( $raw_file['size'][ $i ] ?? 0 ),
				];
			}

			return $normalized;
		}

		return [
			[
				'name'     => (string) ( $raw_file['name'] ?? '' ),
				'type'     => (string) ( $raw_file['type'] ?? '' ),
				'tmp_name' => (string) ( $raw_file['tmp_name'] ?? '' ),
				'error'    => (int) ( $raw_file['error'] ?? UPLOAD_ERR_NO_FILE ),
				'size'     => (int) ( $raw_file['size'] ?? 0 ),
			],
		];
	}

	public function get_items( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$form_id = absint( $request['form_id'] );
		$offset  = max( 0, absint( $request->get_param( 'offset' ) ) );
		$limit   = max( 1, min( 100, absint( $request->get_param( 'limit' ) ) ) );
		$status  = sanitize_key( (string) $request->get_param( 'status' ) );

		$table_name = $wpdb->prefix . 'ep_entries';

		$where_sql  = 'WHERE form_id = %d';
		$args       = [ $form_id ];

		if ( '' !== $status ) {
			$where_sql .= ' AND status = %s';
			$args[]     = $status;
		}

		$total_query = "SELECT COUNT(*) FROM {$table_name} {$where_sql}";
		$total       = (int) $wpdb->get_var( $wpdb->prepare( $total_query, $args ) );

		$data_args = array_merge( $args, [ $limit, $offset ] );
		$data_query = "SELECT id, uuid, form_id, status, payload, created_at FROM {$table_name} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$rows       = $wpdb->get_results( $wpdb->prepare( $data_query, $data_args ), ARRAY_A );

		$items = [];
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			$decrypted_payload = [];

			if ( is_array( $payload ) && isset( $payload['ciphertext'] ) && is_string( $payload['ciphertext'] ) ) {
				$plaintext = $this->crypto->decrypt( $payload['ciphertext'] );
				$decoded_plaintext = json_decode( $plaintext, true );
				if ( is_array( $decoded_plaintext ) ) {
					$decrypted_payload = $decoded_plaintext;
				}
			} elseif ( is_array( $payload ) ) {
				$decrypted_payload = $payload;
			}

			$items[] = [
				'id'         => (int) $row['id'],
				'uuid'       => (string) $row['uuid'],
				'form_id'    => (int) $row['form_id'],
				'status'     => (string) $row['status'],
				'payload'    => $decrypted_payload,
				'created_at' => (string) $row['created_at'],
			];
		}

		$total_pages = (int) ceil( $total / $limit );

		$response = new WP_REST_Response(
			[
				'items'  => $items,
				'offset' => $offset,
				'limit'  => $limit,
			],
			200
		);

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, $total_pages ) );

		return $response;
	}
}