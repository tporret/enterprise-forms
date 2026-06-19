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
		$tables = self::table_statuses();

		return [
			'encryption' => [
				'configured' => EP_Crypto::is_configured(),
			],
			'database' => [
				'tables' => $tables,
				'ready'  => ! in_array( false, $tables, true ),
				'db_version' => sanitize_text_field( (string) get_option( 'ep_forms_db_version', '' ) ),
			],
			'storage' => [
				'provider' => class_exists( '\\EP_Cloud_Storage' ) ? \EP_Cloud_Storage::get_provider() : 'unknown',
			],
			'mail' => ( new NotificationService() )->get_mail_transport_status(),
			'cron' => [
				'configured' => $cron_ready,
				'next_retention_run' => self::next_scheduled_gmt( 'ep_forms_run_retention_policy' ),
			],
			'governance' => class_exists( '\EnterpriseForms\\DataGovernance' ) ? ( new DataGovernance() )->get_settings() : [],
			'audit' => [
				'total_events' => self::count_table_rows( 'ep_audit_log' ),
				'recent_events_24h' => self::count_recent_audit_events(),
			],
			'form_versions' => [
				'total_snapshots' => self::count_table_rows( 'ep_form_versions' ),
			],
			'metrics' => get_option( self::METRICS_OPTION, [] ),
		];
	}

	/**
	 * @return array<string, bool>
	 */
	private static function table_statuses(): array {
		$tables = [ 'ep_entries', 'ep_entry_search', 'ep_payment_intents', 'ep_file_uploads', 'ep_audit_log', 'ep_form_versions' ];
		$statuses = [];

		foreach ( $tables as $table ) {
			$statuses[ $table ] = self::table_exists( $table );
		}

		return $statuses;
	}

	private static function table_exists( string $suffix ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$table_name = $wpdb->prefix . sanitize_key( $suffix );
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	private static function count_table_rows( string $suffix ): int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb || ! self::table_exists( $suffix ) ) {
			return 0;
		}

		$table_name = $wpdb->prefix . sanitize_key( $suffix );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
	}

	private static function count_recent_audit_events(): int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb || ! self::table_exists( 'ep_audit_log' ) ) {
			return 0;
		}

		$table_name = $wpdb->prefix . 'ep_audit_log';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
	}

	private static function next_scheduled_gmt( string $hook ): string {
		$timestamp = wp_next_scheduled( $hook );
		return $timestamp ? gmdate( 'c', (int) $timestamp ) : '';
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