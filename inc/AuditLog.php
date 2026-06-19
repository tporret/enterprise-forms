<?php
namespace EnterpriseForms;

use wpdb;

/**
 * Append-only audit log for enterprise governance events.
 */
class AuditLog {
	/**
	 * @param array<string, mixed> $context
	 */
	public static function record( string $event, string $object_type = '', int $object_id = 0, array $context = [] ): void {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$encoded_context = wp_json_encode( self::sanitize_context( $context ) );
		if ( false === $encoded_context ) {
			$encoded_context = '{}';
		}

		$wpdb->insert(
			$wpdb->prefix . 'ep_audit_log',
			[
				'event'       => sanitize_key( $event ),
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => max( 0, $object_id ),
				'user_id'     => get_current_user_id(),
				'ip_address'  => self::request_ip_address(),
				'user_agent'  => self::request_user_agent(),
				'context'     => $encoded_context,
				'created_at'  => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private static function sanitize_context( array $context ): array {
		$sanitized = [];
		$redacted_keys = [ 'secret', 'secret_key', 'private_key', 'access_token', 'client_secret', 'token', 'payment_token', 'client_secret' ];

		foreach ( $context as $key => $value ) {
			$normalized_key = sanitize_key( (string) $key );
			if ( '' === $normalized_key ) {
				continue;
			}

			if ( in_array( $normalized_key, $redacted_keys, true ) ) {
				$sanitized[ $normalized_key ] = '[redacted]';
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$sanitized[ $normalized_key ] = $value;
				continue;
			}

			if ( is_scalar( $value ) ) {
				$sanitized[ $normalized_key ] = sanitize_text_field( (string) $value );
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $normalized_key ] = self::sanitize_context( $value );
			}
		}

		return $sanitized;
	}

	private static function request_ip_address(): string {
		$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		return sanitize_text_field( $ip_address );
	}

	private static function request_user_agent(): string {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		return substr( sanitize_text_field( $user_agent ), 0, 255 );
	}
}