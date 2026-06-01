<?php
namespace EnterpriseForms;

/**
 * Lightweight observability primitives for submission, upload, and payment flows.
 */
class Observability {
	private const METRICS_OPTION = 'ep_forms_metrics';

	public static function correlation_id(): string {
		$existing = isset( $_SERVER['HTTP_X_EP_CORRELATION_ID'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_EP_CORRELATION_ID'] ) ) : '';
		if ( '' !== $existing && preg_match( '/^[a-zA-Z0-9_-]{8,80}$/', $existing ) ) {
			return $existing;
		}

		return wp_generate_uuid4();
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function log( string $level, string $event, array $context = [] ): void {
		$payload = [
			'level'          => sanitize_key( $level ),
			'event'          => sanitize_key( $event ),
			'correlation_id' => sanitize_text_field( (string) ( $context['correlation_id'] ?? self::correlation_id() ) ),
			'timestamp'      => gmdate( 'c' ),
			'context'        => self::sanitize_context( $context ),
		];

		$message = wp_json_encode( $payload );
		if ( false !== $message ) {
			error_log( '[enterprise-forms] ' . $message );
		}
	}

	public static function increment_metric( string $metric ): void {
		$metric = sanitize_key( $metric );
		$metrics = get_option( self::METRICS_OPTION, [] );
		if ( ! is_array( $metrics ) ) {
			$metrics = [];
		}

		$metrics[ $metric ] = absint( $metrics[ $metric ] ?? 0 ) + 1;
		update_option( self::METRICS_OPTION, $metrics, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function health(): array {
		$cron_ready = ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON;

		return [
			'encryption' => [
				'configured' => EP_Crypto::is_configured(),
			],
			'storage' => [
				'provider' => class_exists( '\\EP_Cloud_Storage' ) ? \EP_Cloud_Storage::get_provider() : 'unknown',
			],
			'mail' => ( new NotificationService() )->get_mail_transport_status(),
			'cron' => [
				'configured' => $cron_ready,
			],
			'metrics' => get_option( self::METRICS_OPTION, [] ),
		];
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private static function sanitize_context( array $context ): array {
		$sanitized = [];
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( in_array( $key, [ 'secret', 'secret_key', 'private_key', 'token', 'access_token', 'payment_token', 'client_secret' ], true ) ) {
				$sanitized[ $key ] = '[redacted]';
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}
}