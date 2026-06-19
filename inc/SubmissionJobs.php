<?php
namespace EnterpriseForms;

use wpdb;

/**
 * Queued submission side effects such as notifications.
 */
class SubmissionJobs {
	private const SEND_NOTIFICATION_ACTION = 'ep_forms_send_submission_notification';

	public function init(): void {
		add_action( self::SEND_NOTIFICATION_ACTION, [ $this, 'send_submission_notification' ], 10, 3 );
	}

	public static function enqueue_submission_notification( int $form_id, string $entry_uuid, string $created_at_gmt ): bool {
		$scheduled = wp_schedule_single_event(
			time() + 5,
			self::SEND_NOTIFICATION_ACTION,
			[ $form_id, $entry_uuid, $created_at_gmt ],
			true
		);

		if ( is_wp_error( $scheduled ) ) {
			Observability::log( 'error', 'notification_queue_failed', [ 'form_id' => $form_id, 'entry_uuid' => $entry_uuid, 'message' => $scheduled->get_error_message() ] );
			return false;
		}

		return false !== $scheduled;
	}

	public function send_submission_notification( int $form_id, string $entry_uuid, string $created_at_gmt ): void {
		$payload = self::load_entry_payload( $form_id, $entry_uuid );
		if ( null === $payload ) {
			Observability::increment_metric( 'notification_failures' );
			Observability::log( 'warning', 'notification_entry_missing', [ 'form_id' => $form_id, 'entry_uuid' => $entry_uuid ] );
			AuditLog::record( 'notification_failed', 'entry', 0, [ 'form_id' => $form_id, 'entry_uuid' => $entry_uuid, 'reason' => 'entry_missing' ] );
			return;
		}

		$sent = ( new NotificationService() )->send_submission_notification( $form_id, $entry_uuid, $created_at_gmt, $payload );
		if ( $sent ) {
			AuditLog::record( 'notification_sent', 'entry', 0, [ 'form_id' => $form_id, 'entry_uuid' => $entry_uuid ] );
			return;
		}

		Observability::increment_metric( 'notification_failures' );
		Observability::log( 'warning', 'notification_failed', [ 'form_id' => $form_id, 'entry_uuid' => $entry_uuid ] );
		AuditLog::record( 'notification_failed', 'entry', 0, [ 'form_id' => $form_id, 'entry_uuid' => $entry_uuid, 'reason' => 'mail_transport' ] );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function load_entry_payload( int $form_id, string $entry_uuid ): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb || $form_id <= 0 || '' === $entry_uuid ) {
			return null;
		}

		$table_name = $wpdb->prefix . 'ep_entries';
		$raw_payload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT payload FROM {$table_name} WHERE form_id = %d AND uuid = %s LIMIT 1",
				$form_id,
				$entry_uuid
			)
		);

		if ( ! is_string( $raw_payload ) || '' === $raw_payload ) {
			return null;
		}

		$payload = json_decode( $raw_payload, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		if ( isset( $payload['ciphertext'] ) && is_string( $payload['ciphertext'] ) ) {
			$plaintext = ( new EP_Crypto() )->decrypt( $payload['ciphertext'] );
			$decoded = json_decode( $plaintext, true );
			return is_array( $decoded ) ? $decoded : null;
		}

		return $payload;
	}
}