<?php
namespace EnterpriseForms;

use Exception;

/**
 * Schema-driven payload validation and sanitization for form submissions.
 */
class EP_Validator {
	/**
	 * Validate incoming payload against the form's saved schema.
	 *
	 * @param array<string, mixed> $payload Incoming request payload.
	 * @return array{is_valid: bool, errors: array<string, string>, sanitized: array<string, mixed>, schema_version: string}
	 * @throws Exception When the schema is missing or malformed.
	 */
	public function validate_payload( array &$payload, int $form_id, string $schema_version = '' ): array {
		$schema                   = $this->load_form_schema( $form_id );
		$resolved_schema_version  = (string) ( $schema['schema_version'] ?? $schema['version'] ?? '1.0.0' );
		$requested_schema_version = trim( $schema_version );

		if ( '' !== $requested_schema_version && $requested_schema_version !== $resolved_schema_version ) {
			throw new Exception( __( 'Schema version mismatch. Please refresh and try again.', 'enterprise-forms' ) );
		}

		if ( empty( $schema['fields'] ) || ! is_array( $schema['fields'] ) ) {
			throw new Exception( __( 'Invalid form schema: no fields available for validation.', 'enterprise-forms' ) );
		}

		$errors    = [];
		$sanitized = [];
		$logic     = isset( $schema['logic'] ) && is_array( $schema['logic'] ) ? $schema['logic'] : [];
		$field_lookup = $this->build_field_lookup( $schema['fields'] );

		foreach ( $schema['fields'] as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_id   = sanitize_key( (string) ( $field['id'] ?? 'field_' . $index ) );
			$field_name = sanitize_key( (string) ( $field['name'] ?? $field_id ) );
			$field_type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			$is_active  = $this->is_conditionally_active( $field_id, $field_name, $payload, $logic, $field_lookup );
			$required   = $is_active && $this->is_conditionally_required( $field, $field_id, $field_name, $payload, $logic, $field_lookup );

			if ( 'payment' === $field_type ) {
				continue;
			}

			if ( ! $is_active ) {
				continue;
			}

			$has_field = array_key_exists( $field_name, $payload ) || array_key_exists( $field_id, $payload );
			if ( ! $has_field ) {
				if ( $required ) {
					$errors[ $field_name ] = __( 'This field is required.', 'enterprise-forms' );
				}
				continue;
			}

			$raw_value = array_key_exists( $field_name, $payload ) ? $payload[ $field_name ] : $payload[ $field_id ];
			$result    = $this->validate_field_value( $field_type, $raw_value, $field, $required );

			if ( '' !== $result['error'] ) {
				$errors[ $field_name ] = $result['error'];
				continue;
			}

			$sanitized[ $field_name ] = $result['value'];
		}

		return [
			'is_valid'       => empty( $errors ),
			'errors'         => $errors,
			'sanitized'      => $sanitized,
			'schema_version' => $resolved_schema_version,
		];
	}

	/**
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	private function load_form_schema( int $form_id ): array {
		if ( $form_id <= 0 ) {
			throw new Exception( __( 'Invalid form ID.', 'enterprise-forms' ) );
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
	 * @param array<int, mixed> $fields
	 * @return array<string, string>
	 */
	private function build_field_lookup( array $fields ): array {
		$lookup = [];

		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_id   = sanitize_key( (string) ( $field['id'] ?? 'field_' . $index ) );
			$field_name = sanitize_key( (string) ( $field['name'] ?? $field_id ) );

			if ( '' !== $field_id ) {
				$lookup[ $field_id ] = $field_name;
			}

			if ( '' !== $field_name ) {
				$lookup[ $field_name ] = $field_name;
			}
		}

		return $lookup;
	}

	/**
	 * @param array<string, mixed> $field
	 * @param array<string, mixed> $payload
	 * @param array<int, mixed>    $logic
	 * @param array<string, string> $field_lookup
	 */
	private function is_conditionally_required( array $field, string $field_id, string $field_name, array $payload, array $logic, array $field_lookup ): bool {
		$required = ! empty( $field['required'] );

		foreach ( $logic as $rule ) {
			if ( ! is_array( $rule ) || 'require' !== sanitize_key( (string) ( $rule['action'] ?? '' ) ) ) {
				continue;
			}

			if ( ! $this->rule_targets_field( $rule, $field_id, $field_name ) ) {
				continue;
			}

			if ( $this->evaluate_conditional_rule( $rule, $payload, $field_lookup ) ) {
				$required = true;
			}
		}

		return $required;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<int, mixed>    $logic
	 * @param array<string, string> $field_lookup
	 */
	private function is_conditionally_active( string $field_id, string $field_name, array $payload, array $logic, array $field_lookup ): bool {
		$active = true;

		foreach ( $logic as $rule ) {
			if ( ! is_array( $rule ) || ! $this->rule_targets_field( $rule, $field_id, $field_name ) ) {
				continue;
			}

			$matches = $this->evaluate_conditional_rule( $rule, $payload, $field_lookup );

			switch ( sanitize_key( (string) ( $rule['action'] ?? '' ) ) ) {
				case 'show':
					$active = $matches;
					break;
				case 'hide':
					$active = ! $matches;
					break;
				case 'disable':
					if ( $matches ) {
						$active = false;
					}
					break;
			}
		}

		return $active;
	}

	/**
	 * @param array<string, mixed> $rule
	 */
	private function rule_targets_field( array $rule, string $field_id, string $field_name ): bool {
		$target = sanitize_key( (string) ( $rule['target_field_id'] ?? '' ) );

		return '' !== $target && ( $target === $field_id || $target === $field_name );
	}

	/**
	 * @param array<string, mixed>  $rule
	 * @param array<string, mixed>  $payload
	 * @param array<string, string> $field_lookup
	 */
	private function evaluate_conditional_rule( array $rule, array $payload, array $field_lookup ): bool {
		$field_key = sanitize_key( (string) ( $rule['field_id'] ?? '' ) );
		$field_name = $field_lookup[ $field_key ] ?? $field_key;
		$value = $this->get_payload_value( $payload, $field_name, $field_key );
		$value_text = is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value;
		$expected = (string) ( $rule['value'] ?? '' );

		switch ( sanitize_key( (string) ( $rule['operator'] ?? '' ) ) ) {
			case 'equals':
				return $value_text === $expected;
			case 'not_equals':
				return $value_text !== $expected;
			case 'contains':
				return str_contains( $value_text, $expected );
			case 'is_empty':
				return $this->is_empty_value( $value );
			case 'is_not_empty':
				return ! $this->is_empty_value( $value );
			default:
				return false;
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return mixed
	 */
	private function get_payload_value( array $payload, string $field_name, string $field_id ): mixed {
		if ( '' !== $field_name && array_key_exists( $field_name, $payload ) ) {
			return $payload[ $field_name ];
		}

		if ( '' !== $field_id && array_key_exists( $field_id, $payload ) ) {
			return $payload[ $field_id ];
		}

		return '';
	}

	/**
	 * @param mixed $value
	 * @param array<string, mixed> $field
	 * @return array{value: mixed, error: string}
	 */
	private function validate_field_value( string $type, mixed &$value, array $field, bool $required ): array {
		if ( $this->is_empty_value( $value ) ) {
			if ( $required ) {
				return [
					'value' => null,
					'error' => __( 'This field is required.', 'enterprise-forms' ),
				];
			}

			return [
				'value' => '',
				'error' => '',
			];
		}

		switch ( $type ) {
			case 'email':
				$email = sanitize_email( (string) $value );
				if ( '' === $email || ! is_email( $email ) ) {
					return [ 'value' => '', 'error' => __( 'Invalid email format.', 'enterprise-forms' ) ];
				}
				return [ 'value' => $email, 'error' => '' ];

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return [ 'value' => null, 'error' => __( 'A valid number is required.', 'enterprise-forms' ) ];
				}

				$number = (string) $value;
				$coerced = str_contains( $number, '.' ) ? (float) $number : (int) $number;
				return $this->apply_numeric_rules( $coerced, $field );

			case 'url':
				$url = esc_url_raw( (string) $value );
				if ( '' === $url || ! wp_http_validate_url( $url ) ) {
					return [ 'value' => '', 'error' => __( 'Invalid URL format.', 'enterprise-forms' ) ];
				}
				return [ 'value' => $url, 'error' => '' ];

			case 'phone':
				$phone = preg_replace( '/[^0-9\+\-\(\)\s]/', '', (string) $value );
				$phone = is_string( $phone ) ? trim( $phone ) : '';
				if ( '' === $phone ) {
					return [ 'value' => '', 'error' => __( 'Invalid phone number.', 'enterprise-forms' ) ];
				}
				return [ 'value' => $phone, 'error' => '' ];

			case 'checkbox':
				if ( is_array( $value ) ) {
					$items = [];
					foreach ( $value as $item ) {
						$items[] = sanitize_text_field( (string) $item );
					}
					return [ 'value' => $items, 'error' => '' ];
				}

				return [ 'value' => sanitize_text_field( (string) $value ), 'error' => '' ];

			case 'consent':
				$consent = sanitize_text_field( (string) $value );
				if ( '' === $consent ) {
					return [ 'value' => '', 'error' => __( 'Consent is required.', 'enterprise-forms' ) ];
				}

				return [ 'value' => $consent, 'error' => '' ];

			case 'select':
			case 'radio':
				return $this->validate_option_value( (string) $value, $field );

			case 'checkbox_group':
				return $this->validate_checkbox_group_value( $value, $field, $required );

			case 'rating':
				if ( ! is_numeric( $value ) ) {
					return [ 'value' => null, 'error' => __( 'A valid rating is required.', 'enterprise-forms' ) ];
				}

				$rating = (float) $value;
				return $this->apply_numeric_rules( $rating, $field );

			case 'textarea':
				$text = sanitize_textarea_field( (string) $value );
				return $this->apply_string_rules( $text, $field );

			case 'date':
				$date = sanitize_text_field( (string) $value );
				if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					return [ 'value' => '', 'error' => __( 'Invalid date format.', 'enterprise-forms' ) ];
				}

				$rules = is_array( $field['validation_rules'] ?? null ) ? $field['validation_rules'] : [];
				if ( isset( $rules['min_date'] ) && is_string( $rules['min_date'] ) && '' !== $rules['min_date'] && $date < $rules['min_date'] ) {
					return [ 'value' => '', 'error' => __( 'Date is earlier than allowed.', 'enterprise-forms' ) ];
				}

				if ( isset( $rules['max_date'] ) && is_string( $rules['max_date'] ) && '' !== $rules['max_date'] && $date > $rules['max_date'] ) {
					return [ 'value' => '', 'error' => __( 'Date is later than allowed.', 'enterprise-forms' ) ];
				}

				return [ 'value' => $date, 'error' => '' ];

			case 'time':
				$time = sanitize_text_field( (string) $value );
				if ( 1 !== preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $time ) ) {
					return [ 'value' => '', 'error' => __( 'Invalid time format.', 'enterprise-forms' ) ];
				}
				return [ 'value' => $time, 'error' => '' ];

			case 'file':
				return $this->validate_file_value( $value, $field, $required );

			case 'submit':
			case 'html':
				return [ 'value' => '', 'error' => '' ];

			case 'hidden':
			case 'text':
			default:
				$text = sanitize_text_field( (string) $value );
				return $this->apply_string_rules( $text, $field );
		}
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array{value: string, error: string}
	 */
	private function validate_option_value( string $value, array $field ): array {
		$sanitized = sanitize_text_field( $value );

		if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return [ 'value' => $sanitized, 'error' => '' ];
		}

		$allowed_values = [];
		foreach ( $field['options'] as $option ) {
			if ( is_string( $option ) ) {
				$opt = sanitize_text_field( $option );
				if ( '' !== $opt ) {
					$allowed_values[] = $opt;
				}
				continue;
			}

			if ( is_array( $option ) && isset( $option['value'] ) && is_string( $option['value'] ) ) {
				$opt = sanitize_text_field( $option['value'] );
				if ( '' !== $opt ) {
					$allowed_values[] = $opt;
				}
			}
		}

		if ( ! in_array( $sanitized, $allowed_values, true ) ) {
			return [
				'value' => '',
				'error' => __( 'Selected value is not allowed.', 'enterprise-forms' ),
			];
		}

		return [ 'value' => $sanitized, 'error' => '' ];
	}

	/**
	 * @param mixed $value
	 * @param array<string, mixed> $field
	 * @return array{value: array<int, string>, error: string}
	 */
	private function validate_checkbox_group_value( mixed $value, array $field, bool $required ): array {
		if ( ! is_array( $value ) ) {
			if ( $required ) {
				return [ 'value' => [], 'error' => __( 'Please select at least one option.', 'enterprise-forms' ) ];
			}

			return [ 'value' => [], 'error' => '' ];
		}

		$sanitized_values = [];
		foreach ( $value as $item ) {
			$sanitized = sanitize_text_field( (string) $item );
			if ( '' !== $sanitized ) {
				$sanitized_values[] = $sanitized;
			}
		}

		if ( $required && empty( $sanitized_values ) ) {
			return [ 'value' => [], 'error' => __( 'Please select at least one option.', 'enterprise-forms' ) ];
		}

		if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return [ 'value' => $sanitized_values, 'error' => '' ];
		}

		$allowed_values = [];
		foreach ( $field['options'] as $option ) {
			if ( is_string( $option ) ) {
				$opt = sanitize_text_field( $option );
				if ( '' !== $opt ) {
					$allowed_values[] = $opt;
				}
			}
		}

		foreach ( $sanitized_values as $selected ) {
			if ( ! in_array( $selected, $allowed_values, true ) ) {
				return [ 'value' => [], 'error' => __( 'Selected value is not allowed.', 'enterprise-forms' ) ];
			}
		}

		return [ 'value' => $sanitized_values, 'error' => '' ];
	}

	/**
	 * @param mixed $value
	 * @param array<string, mixed> $field
	 * @return array{value: mixed, error: string}
	 */
	private function validate_file_value( mixed $value, array $field, bool $required ): array {
		if ( is_string( $value ) ) {
			$url = esc_url_raw( $value );

			if ( '' === $url ) {
				if ( $required ) {
					return [ 'value' => null, 'error' => __( 'A file is required.', 'enterprise-forms' ) ];
				}

				return [ 'value' => '', 'error' => '' ];
			}

			if ( ! wp_http_validate_url( $url ) && ! str_starts_with( $url, 'ep-uploads/' ) ) {
				return [ 'value' => '', 'error' => __( 'Invalid file upload reference.', 'enterprise-forms' ) ];
			}

			return [ 'value' => $url, 'error' => '' ];
		}

		if ( ! is_array( $value ) || ! isset( $value['name'] ) ) {
			if ( $required ) {
				return [ 'value' => null, 'error' => __( 'A file is required.', 'enterprise-forms' ) ];
			}

			return [ 'value' => null, 'error' => '' ];
		}

		$max_size_mb = isset( $field['validation_rules']['max_size_mb'] ) ? (float) $field['validation_rules']['max_size_mb'] : 5.0;
		$max_size_bytes = (int) max( 1, $max_size_mb ) * 1024 * 1024;
		$accept_csv = isset( $field['validation_rules']['accept'] ) ? (string) $field['validation_rules']['accept'] : '';
		$accept_tokens = array_values( array_filter( array_map( 'trim', explode( ',', $accept_csv ) ) ) );

		$files = [];
		if ( is_array( $value['name'] ) ) {
			$count = count( $value['name'] );
			for ( $i = 0; $i < $count; $i++ ) {
				$files[] = [
					'name'  => (string) ( $value['name'][ $i ] ?? '' ),
					'type'  => (string) ( $value['type'][ $i ] ?? '' ),
					'size'  => (int) ( $value['size'][ $i ] ?? 0 ),
					'error' => (int) ( $value['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ),
				];
			}
		} else {
			$files[] = [
				'name'  => (string) ( $value['name'] ?? '' ),
				'type'  => (string) ( $value['type'] ?? '' ),
				'size'  => (int) ( $value['size'] ?? 0 ),
				'error' => (int) ( $value['error'] ?? UPLOAD_ERR_NO_FILE ),
			];
		}

		$sanitized_files = [];
		foreach ( $files as $file ) {
			if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
				continue;
			}

			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				return [ 'value' => null, 'error' => __( 'File upload failed.', 'enterprise-forms' ) ];
			}

			if ( $file['size'] > $max_size_bytes ) {
				return [ 'value' => null, 'error' => __( 'Uploaded file is too large.', 'enterprise-forms' ) ];
			}

			if ( ! empty( $accept_tokens ) && ! $this->is_file_type_allowed( $file['name'], $file['type'], $accept_tokens ) ) {
				return [ 'value' => null, 'error' => __( 'Uploaded file type is not allowed.', 'enterprise-forms' ) ];
			}

			$sanitized_files[] = [
				'name' => sanitize_file_name( $file['name'] ),
				'type' => sanitize_text_field( $file['type'] ),
				'size' => $file['size'],
			];
		}

		if ( $required && empty( $sanitized_files ) ) {
			return [ 'value' => null, 'error' => __( 'A file is required.', 'enterprise-forms' ) ];
		}

		return [ 'value' => $sanitized_files, 'error' => '' ];
	}

	/**
	 * @param array<int, string> $accept_tokens
	 */
	private function is_file_type_allowed( string $file_name, string $mime_type, array $accept_tokens ): bool {
		$extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

		foreach ( $accept_tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}

			if ( str_starts_with( $token, '.' ) && '.' . $extension === strtolower( $token ) ) {
				return true;
			}

			if ( str_ends_with( $token, '/*' ) ) {
				$prefix = substr( strtolower( $token ), 0, -1 );
				if ( '' !== $mime_type && str_starts_with( strtolower( $mime_type ), $prefix ) ) {
					return true;
				}
			}

			if ( '' !== $mime_type && strtolower( $mime_type ) === strtolower( $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array{value: string, error: string}
	 */
	private function apply_string_rules( string $value, array $field ): array {
		$rules = is_array( $field['validation_rules'] ?? null ) ? $field['validation_rules'] : [];

		if ( isset( $rules['min_length'] ) && strlen( $value ) < (int) $rules['min_length'] ) {
			return [ 'value' => $value, 'error' => __( 'Value is too short.', 'enterprise-forms' ) ];
		}

		if ( isset( $rules['max_length'] ) && strlen( $value ) > (int) $rules['max_length'] ) {
			return [ 'value' => $value, 'error' => __( 'Value is too long.', 'enterprise-forms' ) ];
		}

		if ( isset( $rules['pattern'] ) && is_string( $rules['pattern'] ) && '' !== $rules['pattern'] ) {
			$pattern = '/' . str_replace( '/', '\\/', $rules['pattern'] ) . '/';
			if ( 1 !== @preg_match( $pattern, $value ) ) {
				return [ 'value' => $value, 'error' => __( 'Value format is invalid.', 'enterprise-forms' ) ];
			}
		}

		return [ 'value' => $value, 'error' => '' ];
	}

	/**
	 * @param int|float $value
	 * @param array<string, mixed> $field
	 * @return array{value: int|float, error: string}
	 */
	private function apply_numeric_rules( int|float $value, array $field ): array {
		$rules = is_array( $field['validation_rules'] ?? null ) ? $field['validation_rules'] : [];

		if ( isset( $rules['min'] ) && $value < (float) $rules['min'] ) {
			return [ 'value' => $value, 'error' => __( 'Value is below the allowed minimum.', 'enterprise-forms' ) ];
		}

		if ( isset( $rules['max'] ) && $value > (float) $rules['max'] ) {
			return [ 'value' => $value, 'error' => __( 'Value exceeds the allowed maximum.', 'enterprise-forms' ) ];
		}

		return [ 'value' => $value, 'error' => '' ];
	}

	/**
	 * @param mixed $value
	 */
	private function is_empty_value( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return empty( $value );
		}

		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}

		return null === $value;
	}
}