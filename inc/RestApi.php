<?php
namespace EnterpriseForms;

/**
 * Handles REST API endpoints for form submissions.
 */
class RestApi {
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_form_endpoints' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'localize_frontend_data' ] );
	}

	public function register_form_endpoints(): void {
		$entries_controller = new EP_REST_Entries();
		$entries_controller->register_routes();

		$payments_controller = new EP_REST_Payments();
		$payments_controller->register_routes();

		// Upload intent endpoint for direct-to-cloud file uploads
		register_rest_route( 'enterprise-forms/v1', '/upload-intent', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_upload_intent' ],
			'permission_callback' => [ $this, 'check_upload_permissions' ],
			'args'                => [
				'file_name' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_file_name',
				],
				'mime_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'file_size' => [
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
				'form_id' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'field_name' => [
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				],
				'checksum' => [
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		// Receive uploaded file via pre-signed URL
		register_rest_route( 'enterprise-forms/v1', '/upload', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'handle_direct_upload' ],
			'permission_callback' => [ $this, 'check_upload_token_permissions' ],
		] );

		register_rest_route( 'enterprise-forms/v1', '/stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_admin_stats' ],
			'permission_callback' => [ $this, 'check_admin_permissions' ],
		] );

		register_rest_route( 'enterprise-forms/v1', '/forms/entry-counts', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_form_entry_counts' ],
			'permission_callback' => [ $this, 'check_admin_permissions' ],
			'args'                => [
				'form_ids' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( 'enterprise-forms/v1', '/notifications/statuses', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_notification_statuses' ],
			'permission_callback' => [ $this, 'check_admin_permissions' ],
			'args'                => [
				'form_ids' => [
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( 'enterprise-forms/v1', '/storage/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_storage_settings' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_storage_settings' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
				'args'                => [
					'active_provider' => [ 'required' => false, 'sanitize_callback' => 'sanitize_key' ],
					'providers'       => [ 'required' => false ],
				],
			],
		] );

		register_rest_route( 'enterprise-forms/v1', '/health', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_health' ],
			'permission_callback' => [ $this, 'check_admin_permissions' ],
		] );
	}

	public function check_admin_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Check if user can upload files (requires nonce or authentication).
	 */
	public function check_upload_permissions(): bool {
		return wp_verify_nonce( $_SERVER['HTTP_X_WP_NONCE'] ?? '', 'wp_rest' ) !== false || is_user_logged_in();
	}

	/**
	 * Check if upload token is valid.
	 */
	public function check_upload_token_permissions(): bool {
		// Token validation is handled per-request via query parameters
		return true;
	}

	/**
	 * Handle upload intent request - generate pre-signed URL.
	 */
	public function handle_upload_intent( \WP_REST_Request $request ): \WP_REST_Response {
		$correlation_id = Observability::correlation_id();
		$file_name = $request->get_param( 'file_name' );
		$mime_type = $request->get_param( 'mime_type' );
		$file_size = $request->get_param( 'file_size' );
		$form_id   = $request->get_param( 'form_id' );
		$field_name = sanitize_key( (string) $request->get_param( 'field_name' ) );
		$checksum = sanitize_text_field( (string) $request->get_param( 'checksum' ) );

		// Validate all required parameters
		if ( ! $file_name || ! $mime_type || ! $file_size || ! $form_id ) {
			return rest_ensure_response(
				new \WP_Error(
					'missing_parameters',
					__( 'Missing required upload parameters.', 'enterprise-forms' ),
					[ 'status' => 400 ]
				)
			);
		}

		// Get form to extract upload settings
		$form_post = get_post( $form_id );
		if ( ! $form_post || $form_post->post_type !== 'ep_form' ) {
			return rest_ensure_response(
				new \WP_Error(
					'invalid_form',
					__( 'Form not found.', 'enterprise-forms' ),
					[ 'status' => 404 ]
				)
			);
		}

		$schema_raw = get_post_meta( $form_id, 'ep_form_schema', true );
		$schema = is_string( $schema_raw ) ? json_decode( $schema_raw, true ) : null;
		$upload_constraints = $this->extract_upload_constraints( is_array( $schema ) ? $schema : [], $field_name );

		if ( is_wp_error( $upload_constraints ) ) {
			Observability::increment_metric( 'upload_rejects' );
			return rest_ensure_response(
				new \WP_Error(
					$upload_constraints->get_error_code(),
					$upload_constraints->get_error_message(),
					[ 'status' => 400 ]
				)
			);
		}

		$max_size = $upload_constraints['max_size'];
		$allowed_types = $upload_constraints['allowed_types'];

		// Validate upload intent
		require_once plugin_dir_path( __FILE__ ) . 'class-ep-cloud-storage.php';
		$validation = \EP_Cloud_Storage::validate_upload_intent( $file_name, $mime_type, $file_size, $form_id, $allowed_types, $max_size );

		if ( is_wp_error( $validation ) ) {
			Observability::increment_metric( 'upload_rejects' );
			return rest_ensure_response(
				new \WP_Error(
					$validation->get_error_code(),
					$validation->get_error_message(),
					[ 'status' => 400 ]
				)
			);
		}

		// Generate pre-signed URL
		$upload_intent = \EP_Cloud_Storage::generate_upload_intent( $file_name, $mime_type, $file_size, $form_id, $field_name, $allowed_types, $max_size, $checksum );

		if ( is_wp_error( $upload_intent ) ) {
			Observability::increment_metric( 'upload_rejects' );
			Observability::log( 'error', 'upload_intent_failed', [ 'correlation_id' => $correlation_id, 'form_id' => $form_id, 'code' => $upload_intent->get_error_code() ] );
			return rest_ensure_response(
				new \WP_Error(
					$upload_intent->get_error_code(),
					__( 'Unable to prepare file upload.', 'enterprise-forms' ),
					[ 'status' => 400 ]
				)
			);
		}

		Observability::increment_metric( 'upload_intents_created' );

		return rest_ensure_response( $upload_intent );
	}

	/**
	 * @param array<string, mixed> $schema
	 * @return array{allowed_types: array<int, string>, max_size: int}|\WP_Error
	 */
	private function extract_upload_constraints( array $schema, string $requested_field_name = '' ): array|\WP_Error {
		$fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : [];
		$upload_fields = [];

		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) || 'file' !== sanitize_key( (string) ( $field['type'] ?? '' ) ) ) {
				continue;
			}

			$field_id = sanitize_key( (string) ( $field['id'] ?? 'field_' . $index ) );
			$field_name = sanitize_key( (string) ( $field['name'] ?? $field_id ) );

			if ( '' !== $requested_field_name && $requested_field_name !== $field_name && $requested_field_name !== $field_id ) {
				continue;
			}

			$upload_fields[] = $field;
		}

		if ( '' !== $requested_field_name && empty( $upload_fields ) ) {
			return new \WP_Error( 'invalid_upload_field', __( 'Upload field is not part of this form.', 'enterprise-forms' ) );
		}

		if ( empty( $upload_fields ) ) {
			return [
				'allowed_types' => [ 'pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png' ],
				'max_size'      => 10 * 1024 * 1024,
			];
		}

		$allowed_types = [];
		$max_size = 0;

		foreach ( $upload_fields as $field ) {
			$rules = isset( $field['validation_rules'] ) && is_array( $field['validation_rules'] ) ? $field['validation_rules'] : [];
			$max_size_mb = isset( $rules['max_size_mb'] ) ? max( 1, (int) $rules['max_size_mb'] ) : 10;
			$max_size = max( $max_size, $max_size_mb * 1024 * 1024 );

			$accept = isset( $rules['accept'] ) ? (string) $rules['accept'] : '';
			$allowed_types = array_merge( $allowed_types, $this->accept_to_extensions( $accept ) );
		}

		return [
			'allowed_types' => array_values( array_unique( array_filter( $allowed_types ) ) ),
			'max_size'      => $max_size > 0 ? $max_size : 10 * 1024 * 1024,
		];
	}

	/**
	 * @return array<int, string>
	 */
	private function accept_to_extensions( string $accept ): array {
		$tokens = array_values( array_filter( array_map( 'trim', explode( ',', $accept ) ) ) );
		$extensions = [];

		foreach ( $tokens as $token ) {
			$token = strtolower( $token );

			if ( str_starts_with( $token, '.' ) ) {
				$extensions[] = sanitize_key( ltrim( $token, '.' ) );
				continue;
			}

			if ( ! str_contains( $token, '/' ) ) {
				$extensions[] = sanitize_key( $token );
				continue;
			}

			if ( 'image/*' === $token ) {
				$extensions = array_merge( $extensions, [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ] );
				continue;
			}

			foreach ( wp_get_mime_types() as $extension_pattern => $mime_type ) {
				if ( strtolower( $mime_type ) !== $token ) {
					continue;
				}

				foreach ( explode( '|', $extension_pattern ) as $extension ) {
					$extensions[] = sanitize_key( $extension );
				}
			}
		}

		return $extensions;
	}

	/**
	 * Handle direct file upload via pre-signed URL.
	 */
	public function handle_direct_upload( \WP_REST_Request $request ): \WP_REST_Response {
		$correlation_id = Observability::correlation_id();
		$token = $request->get_param( 'token' );

		if ( ! $token ) {
			return rest_ensure_response(
				new \WP_Error(
					'missing_token',
					__( 'Upload token missing.', 'enterprise-forms' ),
					[ 'status' => 401 ]
				)
			);
		}

		$upload_data = json_decode( get_transient( "ep_upload_token_$token" ), true );
		if ( ! $upload_data ) {
			Observability::increment_metric( 'upload_rejects' );
			return rest_ensure_response(
				new \WP_Error(
					'invalid_token',
					__( 'Upload token is invalid or expired.', 'enterprise-forms' ),
					[ 'status' => 401 ]
				)
			);
		}
		delete_transient( "ep_upload_token_$token" );

		// Get request body as file content
		$file_content = $request->get_body();

		if ( empty( $file_content ) ) {
			Observability::increment_metric( 'upload_rejects' );
			return rest_ensure_response(
				new \WP_Error(
					'empty_file',
					__( 'File content is empty.', 'enterprise-forms' ),
					[ 'status' => 400 ]
				)
			);
		}

		if ( strlen( $file_content ) !== (int) ( $upload_data['file_size'] ?? 0 ) ) {
			Observability::increment_metric( 'upload_rejects' );
			return rest_ensure_response(
				new \WP_Error(
					'file_size_mismatch',
					__( 'Uploaded file size does not match the validated upload intent.', 'enterprise-forms' ),
					[ 'status' => 400 ]
				)
			);
		}

		$file_name = sanitize_file_name( (string) ( $upload_data['file_name'] ?? '' ) );
		$allowed_types = isset( $upload_data['allowed_types'] ) && is_array( $upload_data['allowed_types'] )
			? array_map( 'sanitize_key', $upload_data['allowed_types'] )
			: [];
		$detected_type = $this->validate_local_upload_content( $file_content, $file_name, $allowed_types );

		if ( is_wp_error( $detected_type ) ) {
			Observability::increment_metric( 'upload_rejects' );
			Observability::log( 'warning', 'upload_content_rejected', [ 'correlation_id' => $correlation_id, 'form_id' => (string) ( $upload_data['form_id'] ?? '' ), 'code' => $detected_type->get_error_code() ] );
			return rest_ensure_response(
				new \WP_Error(
					$detected_type->get_error_code(),
					$detected_type->get_error_message(),
					[ 'status' => 400 ]
				)
			);
		}

		$upload_dir = wp_upload_dir();
		$ep_upload_dir = $upload_dir['basedir'] . '/ep-uploads/' . $upload_data['form_id'] . '/';

		// Create directory if it doesn't exist
		if ( ! wp_mkdir_p( $ep_upload_dir ) ) {
			Observability::log( 'error', 'upload_directory_failed', [ 'correlation_id' => $correlation_id, 'form_id' => (string) ( $upload_data['form_id'] ?? '' ) ] );
			return rest_ensure_response(
				new \WP_Error(
					'upload_dir_error',
					__( 'Unable to create upload directory.', 'enterprise-forms' ),
					[ 'status' => 500 ]
				)
			);
		}

		// Save the file
		$stored_file_name = wp_unique_filename( $ep_upload_dir, $file_name );
		$file_path = $ep_upload_dir . $stored_file_name;
		$bytes_written = file_put_contents( $file_path, $file_content );

		if ( $bytes_written === false ) {
			Observability::log( 'error', 'upload_write_failed', [ 'correlation_id' => $correlation_id, 'form_id' => (string) ( $upload_data['form_id'] ?? '' ) ] );
			return rest_ensure_response(
				new \WP_Error(
					'file_write_error',
					__( 'Unable to save uploaded file.', 'enterprise-forms' ),
					[ 'status' => 500 ]
				)
			);
		}

		// Store metadata
		require_once plugin_dir_path( __FILE__ ) . 'class-ep-cloud-storage.php';
		$file_url = $upload_dir['baseurl'] . '/ep-uploads/' . $upload_data['form_id'] . '/' . $stored_file_name;

		$metadata = \EP_Cloud_Storage::store_upload_metadata(
			$file_url,
			$stored_file_name,
			$upload_data['file_size'],
			$upload_data['form_id'],
			'',
			sanitize_key( (string) ( $upload_data['field_name'] ?? '' ) ),
			'uploaded',
			hash_hmac( 'sha256', (string) $token, wp_salt( 'nonce' ) ),
			gmdate( 'Y-m-d H:i:s', (int) ( $upload_data['expires_at'] ?? time() ) )
		);
		Observability::increment_metric( 'uploads_accepted' );

		return rest_ensure_response( [
			'success' => true,
			'file'    => $metadata,
		] );
	}

	public function get_health(): \WP_REST_Response {
		return rest_ensure_response( Observability::health() );
	}

	/**
	 * @param string[] $allowed_types
	 */
	private function validate_local_upload_content( string $file_content, string $file_name, array $allowed_types ): bool|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$temp_file = wp_tempnam( $file_name );
		if ( ! is_string( $temp_file ) || '' === $temp_file ) {
			return new \WP_Error( 'upload_temp_file_failed', __( 'Unable to inspect uploaded file.', 'enterprise-forms' ) );
		}

		file_put_contents( $temp_file, $file_content );
		$detected = wp_check_filetype_and_ext( $temp_file, $file_name );
		@unlink( $temp_file );

		$extension = strtolower( (string) ( $detected['ext'] ?? pathinfo( $file_name, PATHINFO_EXTENSION ) ) );
		$mime_type = strtolower( (string) ( $detected['type'] ?? '' ) );

		if ( '' === $extension || '' === $mime_type ) {
			return new \WP_Error( 'file_type_not_detected', __( 'Uploaded file type could not be verified.', 'enterprise-forms' ) );
		}

		if ( ! empty( $allowed_types ) && ! in_array( $extension, array_map( 'strtolower', $allowed_types ), true ) ) {
			return new \WP_Error( 'file_type_not_allowed', __( 'Uploaded file type is not allowed for this field.', 'enterprise-forms' ) );
		}

		return true;
	}

	public function get_admin_stats(): \WP_REST_Response {
		$form_counts = wp_count_posts( 'ep_form' );
		$recent_ids  = get_posts( [
			'post_type'      => 'ep_form',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		] );

		return rest_ensure_response( [
			'total_forms'      => absint( $form_counts->publish ?? 0 ),
			'recently_updated' => count( $recent_ids ),
			'total_entries'    => Database::count_entries(),
		] );
	}

	public function get_form_entry_counts( \WP_REST_Request $request ): \WP_REST_Response {
		$form_ids_raw = (string) $request->get_param( 'form_ids' );
		$form_ids     = array_values( array_filter( array_map( 'absint', explode( ',', $form_ids_raw ) ) ) );

		if ( empty( $form_ids ) ) {
			return rest_ensure_response( [
				'counts' => [],
			] );
		}

		return rest_ensure_response( [
			'counts' => Database::count_entries_by_form_ids( $form_ids ),
		] );
	}

	public function get_notification_statuses( \WP_REST_Request $request ): \WP_REST_Response {
		$form_ids_raw = (string) $request->get_param( 'form_ids' );
		$form_ids     = array_values( array_filter( array_map( 'absint', explode( ',', $form_ids_raw ) ) ) );

		$notification_service = new NotificationService();

		return rest_ensure_response( [
			'transport' => $notification_service->get_mail_transport_status(),
			'forms'     => $notification_service->get_forms_notification_statuses( $form_ids ),
		] );
	}

	public function get_storage_settings(): \WP_REST_Response {
		$settings = new EP_Storage_Settings();
		return rest_ensure_response( $settings->get_public_settings() );
	}

	public function update_storage_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$settings = new EP_Storage_Settings();

		try {
			$settings->update_from_payload( $request->get_json_params() ?: $request->get_params() );
		} catch ( \Exception $exception ) {
			return new \WP_Error(
				'ep_forms_storage_settings_save_failed',
				$exception->getMessage(),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( $settings->get_public_settings() );
	}

	/**
	 * Localizes the nonce and REST URL on the frontend so the Interactivity API view script can use them.
	 */
	public function localize_frontend_data(): void {
		if ( ! has_block( 'enterprise-forms/renderer' ) ) {
			return;
		}

		wp_enqueue_script( 'enterprise-forms-renderer-view' );
		wp_localize_script( 'enterprise-forms-renderer-view', 'epFormsData', [
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'restBaseUrl' => esc_url_raw( rest_url( 'enterprise-forms/v1/entries/' ) ),
		] );
	}
}
