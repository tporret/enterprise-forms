<?php
namespace EnterpriseForms;

/**
 * Handles form notification settings, mail transport discovery, and submission emails.
 */
class NotificationService {
	private const SCHEMA_META_KEY = 'ep_form_schema';

	/**
	 * @return array{enabled: bool, recipients: string, included_field_ids: string[]|null, include_plaintext_fields: bool, include_sensitive_fields: bool, attach_files: bool}
	 */
	public function get_form_notification_settings( int $form_id ): array {
		$defaults = [
			'enabled'                  => true,
			'recipients'               => '',
			'included_field_ids'       => null,
			'include_plaintext_fields' => false,
			'include_sensitive_fields' => false,
			'attach_files'             => false,
		];

		$schema_raw = get_post_meta( $form_id, self::SCHEMA_META_KEY, true );
		if ( ! is_string( $schema_raw ) || '' === $schema_raw ) {
			return $defaults;
		}

		$schema = json_decode( $schema_raw, true );
		if ( ! is_array( $schema ) ) {
			return $defaults;
		}

		$settings = $schema['settings'] ?? [];
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$notification = $settings['notification'] ?? [];
		if ( ! is_array( $notification ) ) {
			$notification = [];
		}

		$legacy_notification_email = $settings['notificationEmail'] ?? '';
		$legacy_recipients = is_string( $legacy_notification_email ) ? sanitize_text_field( $legacy_notification_email ) : '';

		$enabled = array_key_exists( 'enabled', $notification ) ? (bool) $notification['enabled'] : true;
		$recipients = '';
		if ( isset( $notification['recipients'] ) && is_string( $notification['recipients'] ) ) {
			$recipients = sanitize_text_field( $notification['recipients'] );
		} elseif ( '' !== $legacy_recipients ) {
			$recipients = $legacy_recipients;
		}

		$included_field_ids = null;
		if ( isset( $notification['included_field_ids'] ) && is_array( $notification['included_field_ids'] ) ) {
			$included_field_ids = array_values( array_filter( array_map( 'sanitize_key', $notification['included_field_ids'] ) ) );
		}

		return [
			'enabled'                  => $enabled,
			'recipients'               => $recipients,
			'included_field_ids'       => $included_field_ids,
			'include_plaintext_fields' => ! empty( $notification['include_plaintext_fields'] ),
			'include_sensitive_fields' => ! empty( $notification['include_sensitive_fields'] ),
			'attach_files'             => ! empty( $notification['attach_files'] ),
		];
	}

	/**
	 * @param int[] $form_ids
	 * @return array<string, array{enabled: bool, has_custom_recipients: bool, using_admin_fallback: bool, resolved_recipients: string[]}>
	 */
	public function get_forms_notification_statuses( array $form_ids ): array {
		$statuses = [];
		foreach ( $form_ids as $form_id ) {
			$normalized_form_id = absint( $form_id );
			if ( $normalized_form_id <= 0 ) {
				continue;
			}

			$statuses[ (string) $normalized_form_id ] = $this->get_form_notification_status( $normalized_form_id );
		}

		return $statuses;
	}

	/**
	 * @return array{enabled: bool, has_custom_recipients: bool, using_admin_fallback: bool, resolved_recipients: string[]}
	 */
	public function get_form_notification_status( int $form_id ): array {
		$settings = $this->get_form_notification_settings( $form_id );
		$custom_recipients = $this->resolve_valid_user_emails_from_csv( $settings['recipients'] );
		$fallback_recipients = $this->get_admin_fallback_recipients();

		$has_custom_recipients = ! empty( $custom_recipients );
		$using_admin_fallback = false;
		$resolved_recipients = [];

		if ( $settings['enabled'] ) {
			if ( $has_custom_recipients ) {
				$resolved_recipients = $custom_recipients;
			} else {
				$resolved_recipients = $fallback_recipients;
				$using_admin_fallback = ! empty( $fallback_recipients );
			}
		}

		return [
			'enabled'               => (bool) $settings['enabled'],
			'has_custom_recipients' => $has_custom_recipients,
			'using_admin_fallback'  => $using_admin_fallback,
			'resolved_recipients'   => $resolved_recipients,
		];
	}

	/**
	 * @return array{mode: string, provider: string, configured: bool, from_email: string, from_name: string, host: string}
	 */
	public function get_mail_transport_status(): array {
		$admin_email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		$from_email = sanitize_email( (string) apply_filters( 'wp_mail_from', $admin_email ) );
		$from_name = sanitize_text_field( (string) apply_filters( 'wp_mail_from_name', (string) get_bloginfo( 'name' ) ) );

		$status = [
			'mode'       => 'wp_mail',
			'provider'   => 'WordPress default (wp_mail)',
			'configured' => true,
			'from_email' => $from_email,
			'from_name'  => $from_name,
			'host'       => '',
		];

		$smtp_host_constant = defined( 'SMTP_HOST' ) ? (string) SMTP_HOST : '';
		if ( '' !== trim( $smtp_host_constant ) ) {
			$status['mode'] = 'smtp';
			$status['provider'] = 'wp-config SMTP constants';
			$status['configured'] = true;
			$status['host'] = sanitize_text_field( $smtp_host_constant );
			return $status;
		}

		$wp_mail_smtp = get_option( 'wp_mail_smtp', [] );
		if ( is_array( $wp_mail_smtp ) && ! empty( $wp_mail_smtp ) ) {
			$mailer = isset( $wp_mail_smtp['mailer'] ) ? sanitize_text_field( (string) $wp_mail_smtp['mailer'] ) : 'smtp';
			$host = isset( $wp_mail_smtp['mail']['host'] ) ? sanitize_text_field( (string) $wp_mail_smtp['mail']['host'] ) : '';
			$status['mode'] = 'smtp';
			$status['provider'] = 'WP Mail SMTP (' . $mailer . ')';
			$status['configured'] = '' !== $mailer;
			$status['host'] = $host;
			return $status;
		}

		$easy_wp_smtp = get_option( 'swpsmtp_options', [] );
		if ( is_array( $easy_wp_smtp ) && ! empty( $easy_wp_smtp ) ) {
			$host = isset( $easy_wp_smtp['smtp_settings']['host'] ) ? sanitize_text_field( (string) $easy_wp_smtp['smtp_settings']['host'] ) : '';
			$status['mode'] = 'smtp';
			$status['provider'] = 'Easy WP SMTP';
			$status['configured'] = '' !== $host;
			$status['host'] = $host;
			return $status;
		}

		$fluent_mail = get_option( 'fluentmail-settings', [] );
		if ( is_array( $fluent_mail ) && ! empty( $fluent_mail ) ) {
			$connections = isset( $fluent_mail['connections'] ) && is_array( $fluent_mail['connections'] ) ? $fluent_mail['connections'] : [];
			$connection = ! empty( $connections ) ? reset( $connections ) : [];
			$host = is_array( $connection ) && isset( $connection['host'] ) ? sanitize_text_field( (string) $connection['host'] ) : '';
			$status['mode'] = 'smtp';
			$status['provider'] = 'FluentSMTP';
			$status['configured'] = ! empty( $connections );
			$status['host'] = $host;
			return $status;
		}

		$postman = get_option( 'postman_options', [] );
		if ( is_array( $postman ) && ! empty( $postman ) ) {
			$transport = isset( $postman['transport_type'] ) ? sanitize_text_field( (string) $postman['transport_type'] ) : 'smtp';
			$host = isset( $postman['hostname'] ) ? sanitize_text_field( (string) $postman['hostname'] ) : '';
			$status['mode'] = 'smtp';
			$status['provider'] = 'Post SMTP (' . $transport . ')';
			$status['configured'] = '' !== $transport;
			$status['host'] = $host;
			return $status;
		}

		return $status;
	}

	/**
	 * @param array<string, mixed> $payload Sanitized submission payload.
	 */
	public function send_submission_notification( int $form_id, string $entry_uuid, string $created_at_gmt, array $payload = [] ): bool {
		$notification_status = $this->get_form_notification_status( $form_id );
		if ( ! $notification_status['enabled'] ) {
			return true;
		}

		$recipients = $notification_status['resolved_recipients'];
		if ( empty( $recipients ) ) {
			return false;
		}

		$form_title = get_the_title( $form_id );
		if ( ! is_string( $form_title ) || '' === trim( $form_title ) ) {
			$form_title = 'Form #' . $form_id;
		}

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] New form submission: %s', $site_name, $form_title );

		$local_timestamp = get_date_from_gmt( $created_at_gmt, 'Y-m-d H:i:s T' );
		if ( '' === $local_timestamp ) {
			$local_timestamp = gmdate( 'Y-m-d H:i:s T' );
		}

		$settings           = $this->get_form_notification_settings( $form_id );
		$included_field_ids = $settings['included_field_ids'];

		$message_lines = [
			sprintf( 'Form: %s', $form_title ),
			sprintf( 'Submitted At: %s', $local_timestamp ),
			sprintf( 'Entry UUID: %s', sanitize_text_field( $entry_uuid ) ),
			sprintf( 'Entry Link: %s', esc_url_raw( admin_url( 'admin.php?page=enterprise-forms#/entries/' . $form_id ) ) ),
		];

		$field_lines = ( ! empty( $payload ) && $settings['include_plaintext_fields'] )
			? $this->build_field_data_lines( $form_id, $payload, $included_field_ids, $settings['include_sensitive_fields'] )
			: [];

		if ( ! empty( $field_lines ) ) {
			$message_lines[] = '';
			$message_lines[] = '--- Submitted Data ---';
			foreach ( $field_lines as $line ) {
				$message_lines[] = $line;
			}
		}

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
		$attachments = $settings['attach_files'] ? $this->get_payload_attachment_paths( $payload ) : [];

		return wp_mail( $recipients, $subject, implode( "\n", $message_lines ), $headers, $attachments );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return string[]
	 */
	private function get_payload_attachment_paths( array $payload ): array {
		$attachments = [];

		foreach ( $payload as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$path = '';
				$attachment_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;

				if ( $attachment_id > 0 ) {
					$attached_file = get_attached_file( $attachment_id );
					$path = is_string( $attached_file ) ? $attached_file : '';
				} elseif ( isset( $item['url'] ) && is_string( $item['url'] ) ) {
					$path = $this->local_upload_url_to_path( $item['url'] );
				}

				if ( '' !== $path && file_exists( $path ) && is_readable( $path ) ) {
					$attachments[] = $path;
				}
			}
		}

		return array_values( array_unique( $attachments ) );
	}

	private function local_upload_url_to_path( string $url ): string {
		$upload_dir = wp_upload_dir();
		$base_url = isset( $upload_dir['baseurl'] ) ? (string) $upload_dir['baseurl'] : '';
		$base_dir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';

		if ( '' === $base_url || '' === $base_dir || ! str_starts_with( $url, $base_url ) ) {
			return '';
		}

		$relative_path = ltrim( substr( $url, strlen( $base_url ) ), '/' );
		$path = wp_normalize_path( trailingslashit( $base_dir ) . $relative_path );
		$base = wp_normalize_path( trailingslashit( $base_dir ) );

		if ( ! str_starts_with( $path, $base ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param string[]|null        $included_field_ids null = all eligible fields
	 * @return string[]
	 */
	private function build_field_data_lines( int $form_id, array $payload, ?array $included_field_ids, bool $include_sensitive_fields ): array {
		$schema_raw = get_post_meta( $form_id, self::SCHEMA_META_KEY, true );
		$schema     = is_string( $schema_raw ) ? json_decode( $schema_raw, true ) : null;
		$fields     = is_array( $schema ) && isset( $schema['fields'] ) && is_array( $schema['fields'] )
			? $schema['fields']
			: [];

		if ( empty( $fields ) ) {
			return [];
		}

		$lines = [];

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_id    = sanitize_key( (string) ( $field['id'] ?? '' ) );
			$field_type  = sanitize_key( (string) ( $field['type'] ?? '' ) );
			$field_label = sanitize_text_field( (string) ( $field['label'] ?? $field_id ) );
			$field_name  = sanitize_key( (string) ( $field['name'] ?? $field_id ) );

			if ( 'submit' === $field_type || 'hidden' === $field_type ) {
				continue;
			}

			if ( $included_field_ids !== null && ! in_array( $field_id, $included_field_ids, true ) ) {
				continue;
			}

			if ( ! array_key_exists( $field_name, $payload ) ) {
				continue;
			}

			$value = $this->is_sensitive_field( $field ) && ! $include_sensitive_fields
				? '[redacted]'
				: $this->format_field_value( $payload[ $field_name ] );

			$lines[] = sprintf( '%s: %s', $field_label, $value );
		}

		return $lines;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function is_sensitive_field( array $field ): bool {
		$field_type = sanitize_key( (string) ( $field['type'] ?? '' ) );
		$rules = isset( $field['validation_rules'] ) && is_array( $field['validation_rules'] ) ? $field['validation_rules'] : [];

		return ! empty( $field['sensitive'] )
			|| ! empty( $rules['sensitive'] )
			|| in_array( $field_type, [ 'file', 'password', 'payment', 'hidden' ], true );
	}

	/**
	 * @param mixed $value
	 */
	private function format_field_value( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'Yes' : 'No';
		}

		if ( ! is_array( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		// Flat string array (checkbox_group, multi-select).
		if ( isset( $value[0] ) && is_string( $value[0] ) ) {
			return implode( ', ', array_map( 'sanitize_text_field', $value ) );
		}

		// File upload rows: [{id, url, filename, mime, size}, ...].
		$parts = [];
		foreach ( $value as $item ) {
			if ( is_array( $item ) && isset( $item['filename'] ) ) {
				$parts[] = sanitize_text_field( (string) $item['filename'] );
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * @return string[]
	 */
	private function get_admin_fallback_recipients(): array {
		$admin_email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		if ( '' === $admin_email || ! is_email( $admin_email ) ) {
			return [];
		}

		return [ $admin_email ];
	}

	/**
	 * @return string[]
	 */
	private function resolve_valid_user_emails_from_csv( string $recipients_csv ): array {
		if ( '' === trim( $recipients_csv ) ) {
			return [];
		}

		$emails = array_filter( array_map( 'trim', explode( ',', $recipients_csv ) ) );
		$valid = [];

		foreach ( $emails as $candidate ) {
			$email = sanitize_email( $candidate );
			if ( '' === $email || ! is_email( $email ) ) {
				continue;
			}

			$user = get_user_by( 'email', $email );
			if ( false === $user ) {
				continue;
			}

			$valid[] = $email;
		}

		$unique = array_values( array_unique( $valid ) );
		return array_map( 'strval', $unique );
	}
}
