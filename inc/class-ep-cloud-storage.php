<?php
/**
 * Cloud Storage Service for Direct File Uploads
 *
 * Handles pre-signed URLs for direct-to-cloud uploads, bypassing PHP execution limits.
 *
 * @package enterprise-forms
 */

class EP_Cloud_Storage {

	/**
	 * Supported cloud providers.
	 *
	 * @var array
	 */
	const PROVIDERS = [ 'local', 's3', 'r2', 'gcs' ];

	/**
	 * Get the configured cloud storage provider.
	 *
	 * @return string
	 */
	public static function get_provider(): string {
		return self::settings()->get_active_provider();
	}

	/**
	 * Generate a pre-signed URL for direct file upload.
	 *
	 * @param string $file_name Original file name.
	 * @param string $mime_type MIME type of the file.
	 * @param int    $file_size File size in bytes.
	 * @param string $form_id   Form ID for organization.
	 *
	 * @return array|WP_Error {
	 *     'url'          => string,  // URL to PUT the file to
	 *     'headers'      => array,   // Headers to send with PUT request
	 *     'expires_in'   => int,     // Seconds until URL expires
	 *     'storage_path' => string,  // Final storage path after upload
	 * }
	 */
	public static function generate_upload_intent( $file_name, $mime_type, $file_size, $form_id, string $field_name = '', array $allowed_types = [], int $max_size = 0, string $checksum = '' ): array|WP_Error {
		$provider = self::get_provider();

		switch ( $provider ) {
			case 's3':
				return self::generate_s3_presigned_url( $file_name, $mime_type, $file_size, $form_id, $field_name, $allowed_types, $max_size, $checksum );
			case 'r2':
				return self::generate_r2_presigned_url( $file_name, $mime_type, $file_size, $form_id, $field_name, $allowed_types, $max_size, $checksum );
			case 'gcs':
				return self::generate_gcs_presigned_url( $file_name, $mime_type, $file_size, $form_id, $field_name, $allowed_types, $max_size, $checksum );
			case 'local':
			default:
				return self::generate_local_presigned_url( $file_name, $mime_type, $file_size, $form_id, $field_name, $allowed_types, $max_size, $checksum );
		}
	}

	/**
	 * Generate a local "pre-signed" URL (using nonce-protected endpoint).
	 *
	 * For V1, we use a secure local upload endpoint that mimics pre-signed URL behavior.
	 *
	 * @param string $file_name Original file name.
	 * @param string $mime_type MIME type.
	 * @param int    $file_size File size in bytes.
	 * @param string $form_id   Form ID.
	 *
	 * @return array
	 */
	protected static function generate_local_presigned_url( $file_name, $mime_type, $file_size, $form_id, string $field_name = '', array $allowed_types = [], int $max_size = 0, string $checksum = '' ): array {
		// Create a secure upload token valid for 1 hour
		$token = wp_generate_password( 32, true, true );
		$expires = time() + 3600;

		// Store token in transient
		$upload_data = [
			'file_name' => $file_name,
			'mime_type' => $mime_type,
			'file_size' => $file_size,
			'form_id'   => $form_id,
			'field_name' => $field_name,
			'allowed_types' => array_values( array_filter( array_map( 'sanitize_key', $allowed_types ) ) ),
			'max_size' => $max_size,
			'checksum' => $checksum,
			'status' => 'created',
			'expires_at' => $expires,
			'uploaded_at' => current_time( 'mysql' ),
		];

		set_transient( "ep_upload_token_$token", wp_json_encode( $upload_data ), 3600 );

		$upload_url = get_rest_url( null, 'enterprise-forms/v1/upload' );

		return [
			'url'           => add_query_arg( 'token', $token, $upload_url ),
			'headers'       => [
				'Content-Type' => $mime_type,
			],
			'expires_in'    => 3600,
			'storage_path'  => "ep-uploads/$form_id/" . sanitize_file_name( $file_name ),
			'method'        => 'PUT',
		];
	}

	/**
	 * Generate AWS S3 pre-signed URL.
	 *
	 * @param string $file_name Original file name.
	 * @param string $mime_type MIME type.
	 * @param int    $file_size File size in bytes.
	 * @param string $form_id   Form ID.
	 *
	 * @return array
	 */
	protected static function generate_s3_presigned_url( $file_name, $mime_type, $file_size, $form_id, string $field_name = '', array $allowed_types = [], int $max_size = 0, string $checksum = '' ): array|WP_Error {
		return self::generate_s3_compatible_presigned_url( 's3', $file_name, $mime_type, $file_size, $form_id, $field_name, $allowed_types, $max_size, $checksum );
	}

	/**
	 * Generate Cloudflare R2 pre-signed URL.
	 *
	 * @param string $file_name Original file name.
	 * @param string $mime_type MIME type.
	 * @param int    $file_size File size in bytes.
	 * @param string $form_id   Form ID.
	 *
	 * @return array
	 */
	protected static function generate_r2_presigned_url( $file_name, $mime_type, $file_size, $form_id, string $field_name = '', array $allowed_types = [], int $max_size = 0, string $checksum = '' ): array|WP_Error {
		return self::generate_s3_compatible_presigned_url( 'r2', $file_name, $mime_type, $file_size, $form_id, $field_name, $allowed_types, $max_size, $checksum );
	}

	/**
	 * Generate Google Cloud Storage pre-signed URL.
	 *
	 * @param string $file_name Original file name.
	 * @param string $mime_type MIME type.
	 * @param int    $file_size File size in bytes.
	 * @param string $form_id   Form ID.
	 *
	 * @return array
	 */
	protected static function generate_gcs_presigned_url( $file_name, $mime_type, $file_size, $form_id, string $field_name = '', array $allowed_types = [], int $max_size = 0, string $checksum = '' ): array|WP_Error {
		return self::generate_s3_compatible_presigned_url( 'gcs', $file_name, $mime_type, $file_size, $form_id, $field_name, $allowed_types, $max_size, $checksum );
	}

	protected static function generate_s3_compatible_presigned_url( string $provider, string $file_name, string $mime_type, int $file_size, string $form_id, string $field_name = '', array $allowed_types = [], int $max_size = 0, string $checksum = '' ): array|WP_Error {
		$settings = self::settings();

		if ( ! $settings->is_provider_configured( $provider ) ) {
			return new WP_Error( 'storage_provider_not_configured', __( 'The selected storage provider is not configured.', 'enterprise-forms' ) );
		}

		$credentials = $settings->get_provider_credentials( $provider );
		$bucket = sanitize_text_field( (string) ( $credentials['bucket'] ?? '' ) );
		$region = sanitize_text_field( (string) ( $credentials['region'] ?? '' ) );
		$access_key = sanitize_text_field( (string) ( $credentials['access_key_id'] ?? '' ) );
		$secret_key = sanitize_text_field( (string) ( $credentials['secret_access_key'] ?? '' ) );
		$path_style = '1' === (string) ( $credentials['path_style'] ?? '' );
		$key = self::build_object_key( $file_name, $form_id, (string) ( $credentials['key_prefix'] ?? '' ) );
		$expires = 3600;
		$endpoint = self::resolve_endpoint( $provider, $credentials );
		$endpoint_parts = wp_parse_url( $endpoint );

		if ( ! is_array( $endpoint_parts ) || empty( $endpoint_parts['scheme'] ) || empty( $endpoint_parts['host'] ) ) {
			return new WP_Error( 'invalid_storage_endpoint', __( 'Storage endpoint must be a valid URL.', 'enterprise-forms' ) );
		}

		$scheme = (string) $endpoint_parts['scheme'];
		$base_host = (string) $endpoint_parts['host'];
		$port = isset( $endpoint_parts['port'] ) ? (int) $endpoint_parts['port'] : 0;
		$host = $path_style ? $base_host : $bucket . '.' . $base_host;
		if ( $port > 0 && ! in_array( $port, [ 80, 443 ], true ) ) {
			$host .= ':' . $port;
		}

		$canonical_uri = $path_style
			? '/' . self::encode_path( $bucket . '/' . $key )
			: '/' . self::encode_path( $key );

		$amz_date = gmdate( 'Ymd\THis\Z' );
		$short_date = gmdate( 'Ymd' );
		$scope = $short_date . '/' . $region . '/s3/aws4_request';
		$query = [
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $access_key . '/' . $scope,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string) $expires,
			'X-Amz-SignedHeaders' => 'host',
		];

		$canonical_query = self::canonical_query_string( $query );
		$canonical_request = implode( "\n", [
			'PUT',
			$canonical_uri,
			$canonical_query,
			'host:' . $host . "\n",
			'host',
			'UNSIGNED-PAYLOAD',
		] );

		$string_to_sign = implode( "\n", [
			'AWS4-HMAC-SHA256',
			$amz_date,
			$scope,
			hash( 'sha256', $canonical_request ),
		] );

		$signature = hash_hmac( 'sha256', $string_to_sign, self::signing_key( $secret_key, $short_date, $region ) );
		$url = $scheme . '://' . $host . $canonical_uri . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;

		$storage_path = self::public_storage_url( $provider, $credentials, $scheme, $host, $canonical_uri, $bucket, $key, $path_style );
		self::store_upload_metadata( $storage_path, sanitize_file_name( $file_name ), $file_size, $form_id, '', $field_name, 'created', '', gmdate( 'Y-m-d H:i:s', time() + $expires ) );

		return [
			'url'          => $url,
			'headers'      => [],
			'expires_in'   => $expires,
			'storage_path' => $storage_path,
			'method'       => 'PUT',
			'provider'     => $provider,
		];
	}

	private static function settings(): \EnterpriseForms\EP_Storage_Settings {
		if ( ! class_exists( '\EnterpriseForms\EP_Storage_Settings' ) ) {
			require_once __DIR__ . '/class-ep-storage-settings.php';
		}

		return new \EnterpriseForms\EP_Storage_Settings();
	}

	/**
	 * @param array<string, string> $credentials
	 */
	private static function resolve_endpoint( string $provider, array $credentials ): string {
		$endpoint = esc_url_raw( (string) ( $credentials['endpoint'] ?? '' ) );

		if ( '' !== $endpoint ) {
			return untrailingslashit( $endpoint );
		}

		$region = sanitize_text_field( (string) ( $credentials['region'] ?? '' ) );

		return match ( $provider ) {
			'gcs' => 'https://storage.googleapis.com',
			default => 'https://s3.' . $region . '.amazonaws.com',
		};
	}

	private static function build_object_key( string $file_name, string $form_id, string $prefix = '' ): string {
		$prefix = trim( sanitize_text_field( $prefix ), "/ \t\n\r\0\x0B" );
		$parts = array_filter( [
			$prefix,
			'forms',
			sanitize_key( $form_id ),
			wp_generate_uuid4() . '-' . sanitize_file_name( $file_name ),
		] );

		return implode( '/', $parts );
	}

	private static function encode_path( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/**
	 * @param array<string, string> $query
	 */
	private static function canonical_query_string( array $query ): string {
		ksort( $query );
		$pairs = [];

		foreach ( $query as $key => $value ) {
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		return implode( '&', $pairs );
	}

	private static function signing_key( string $secret_key, string $short_date, string $region ): string {
		$date_key = hash_hmac( 'sha256', $short_date, 'AWS4' . $secret_key, true );
		$region_key = hash_hmac( 'sha256', $region, $date_key, true );
		$service_key = hash_hmac( 'sha256', 's3', $region_key, true );

		return hash_hmac( 'sha256', 'aws4_request', $service_key, true );
	}

	/**
	 * @param array<string, string> $credentials
	 */
	private static function public_storage_url( string $provider, array $credentials, string $scheme, string $host, string $canonical_uri, string $bucket, string $key, bool $path_style ): string {
		$public_base_url = esc_url_raw( (string) ( $credentials['public_base_url'] ?? '' ) );

		if ( '' !== $public_base_url ) {
			return trailingslashit( $public_base_url ) . self::encode_path( $key );
		}

		if ( $path_style || 'gcs' === $provider ) {
			return $scheme . '://' . $host . '/' . self::encode_path( $bucket . '/' . $key );
		}

		return $scheme . '://' . $host . $canonical_uri;
	}

	/**
	 * Validate file upload intent parameters.
	 *
	 * @param string $file_name File name.
	 * @param string $mime_type MIME type.
	 * @param int    $file_size File size in bytes.
	 * @param string $form_id   Form ID.
	 * @param array  $allowed_types Allowed file extensions.
	 * @param int    $max_size   Maximum file size in bytes.
	 *
	 * @return WP_Error|true
	 */
	public static function validate_upload_intent( $file_name, $mime_type, $file_size, $form_id, $allowed_types = [], $max_size = 0 ): object|bool {
		// Validate file name
		if ( empty( $file_name ) ) {
			return new WP_Error( 'invalid_file_name', __( 'File name is required.', 'enterprise-forms' ) );
		}

		// Validate file size
		if ( $file_size <= 0 ) {
			return new WP_Error( 'invalid_file_size', __( 'Invalid file size.', 'enterprise-forms' ) );
		}

		if ( $max_size > 0 && $file_size > $max_size ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: maximum allowed file size, for example 10 MB. */
					__( 'File size exceeds maximum of %s.', 'enterprise-forms' ),
					wp_convert_bytes_to_hr( $max_size )
				)
			);
		}

		// Validate MIME type
		if ( empty( $mime_type ) ) {
			return new WP_Error( 'invalid_mime_type', __( 'MIME type is required.', 'enterprise-forms' ) );
		}

		// Validate file extension against allowed types
		if ( ! empty( $allowed_types ) ) {
			$file_extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $file_extension, array_map( 'strtolower', $allowed_types ), true ) ) {
				return new WP_Error(
					'file_type_not_allowed',
					sprintf(
						/* translators: %s: uploaded file extension. */
						__( 'File type .%s is not allowed.', 'enterprise-forms' ),
						esc_html( $file_extension )
					)
				);
			}
		}

		// Validate form ID
		if ( empty( $form_id ) ) {
			return new WP_Error( 'invalid_form_id', __( 'Form ID is required.', 'enterprise-forms' ) );
		}

		return true;
	}

	/**
	 * Store uploaded file metadata in database.
	 *
	 * @param string $file_url     Final file URL.
	 * @param string $file_name    Original file name.
	 * @param int    $file_size    File size in bytes.
	 * @param string $form_id      Form ID.
	 * @param string $entry_id     Entry ID (optional).
	 * @param string $field_name   Field name.
	 *
	 * @return array {
	 *     'id'          => string,
	 *     'url'         => string,
	 *     'name'        => string,
	 *     'size'        => int,
	 * }
	 */
	public static function store_upload_metadata( $file_url, $file_name, $file_size, $form_id, $entry_id = '', $field_name = '', $status = 'uploaded', $upload_token_hash = '', $expires_at = '' ): array {
		global $wpdb;

		// Create uploads metadata table if it doesn't exist
		self::create_uploads_table();

		$upload_id = wp_generate_uuid4();

		$wpdb->insert(
			$wpdb->prefix . 'ep_file_uploads',
			[
				'id'          => $upload_id,
				'form_id'     => $form_id,
				'entry_id'    => $entry_id,
				'field_name'  => $field_name,
				'file_name'   => $file_name,
				'file_url'    => $file_url,
				'file_size'   => $file_size,
				'provider'    => self::get_provider(),
				'status'      => sanitize_key( (string) $status ),
				'upload_token_hash' => sanitize_text_field( (string) $upload_token_hash ),
				'expires_at'  => sanitize_text_field( (string) $expires_at ),
				'uploaded_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		return [
			'id'   => $upload_id,
			'url'  => $file_url,
			'name' => $file_name,
			'size' => $file_size,
		];
	}

	/**
	 * Create uploads metadata table.
	 */
	public static function create_uploads_table(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ep_file_uploads';
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			// Continue through dbDelta so new metadata columns are added on upgrade.
		}

		$sql = "CREATE TABLE $table_name (
			id VARCHAR(36) PRIMARY KEY,
			form_id VARCHAR(255) NOT NULL,
			entry_id VARCHAR(255),
			field_name VARCHAR(255),
			file_name VARCHAR(255) NOT NULL,
			file_url LONGTEXT NOT NULL,
			file_size BIGINT NOT NULL,
			provider VARCHAR(50) NOT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'uploaded',
			upload_token_hash VARCHAR(64) DEFAULT '',
			expires_at DATETIME DEFAULT NULL,
			uploaded_at DATETIME NOT NULL,
			KEY form_id (form_id),
			KEY entry_id (entry_id),
			KEY field_status (form_id, field_name, status),
			KEY expires_at (expires_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
