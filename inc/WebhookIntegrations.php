<?php
namespace EnterpriseForms;

use Exception;

/**
 * Disabled-by-default outbound webhooks for enterprise integrations.
 */
class WebhookIntegrations {
	private const SETTINGS_OPTION = 'ep_forms_webhook_settings';
	private const DELIVERY_ACTION = 'ep_forms_deliver_submission_webhook';

	public function init(): void {
		add_action( self::DELIVERY_ACTION, [ $this, 'deliver_submission_webhook' ], 10, 3 );
	}

	/**
	 * @return array{enabled: bool, endpoints: string[], has_signing_secret: bool}
	 */
	public function get_settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'enabled'            => ! empty( $stored['enabled'] ),
			'endpoints'          => $this->sanitize_endpoints( $stored['endpoints'] ?? [] ),
			'has_signing_secret' => '' !== $this->get_signing_secret(),
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{enabled: bool, endpoints: string[], has_signing_secret: bool}
	 * @throws Exception
	 */
	public function update_settings( array $payload ): array {
		$current = get_option( self::SETTINGS_OPTION, [] );
		$current = is_array( $current ) ? $current : [];
		$secret = isset( $current['signing_secret'] ) && is_string( $current['signing_secret'] ) ? $current['signing_secret'] : '';

		if ( isset( $payload['signing_secret'] ) && '' !== trim( (string) $payload['signing_secret'] ) ) {
			if ( ! EP_Crypto::is_configured() ) {
				throw new Exception( esc_html__( 'Encryption must be configured before saving webhook secrets.', 'enterprise-forms' ) );
			}

			$secret = ( new EP_Crypto() )->encrypt( sanitize_text_field( (string) $payload['signing_secret'] ) );
		}

		$settings = [
			'enabled'        => ! empty( $payload['enabled'] ),
			'endpoints'      => $this->sanitize_endpoints( $payload['endpoints'] ?? [] ),
			'signing_secret' => $secret,
		];

		update_option( self::SETTINGS_OPTION, $settings, false );
		AuditLog::record( 'webhook_settings_updated', 'settings', 0, [ 'enabled' => $settings['enabled'], 'endpoint_count' => count( $settings['endpoints'] ) ] );

		return $this->get_settings();
	}

	public static function enqueue_submission_event( int $form_id, string $entry_uuid, string $created_at_gmt ): bool {
		$settings = ( new self() )->get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['endpoints'] ) ) {
			return true;
		}

		$scheduled = wp_schedule_single_event(
			time() + 5,
			self::DELIVERY_ACTION,
			[ $form_id, $entry_uuid, $created_at_gmt ],
			true
		);

		if ( is_wp_error( $scheduled ) ) {
			Observability::log( 'error', 'webhook_queue_failed', [ 'form_id' => $form_id, 'entry_uuid' => $entry_uuid, 'message' => $scheduled->get_error_message() ] );
			return false;
		}

		return false !== $scheduled;
	}

	public function deliver_submission_webhook( int $form_id, string $entry_uuid, string $created_at_gmt ): void {
		$settings = $this->get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['endpoints'] ) ) {
			return;
		}

		$payload = SubmissionJobs::load_entry_payload( $form_id, $entry_uuid );
		if ( null === $payload ) {
			AuditLog::record( 'webhook_delivery_failed', 'entry', 0, [ 'form_id' => $form_id, 'entry_uuid' => $entry_uuid, 'reason' => 'entry_missing' ] );
			return;
		}

		$event = [
			'event'      => 'submission.accepted',
			'form_id'    => $form_id,
			'entry_uuid' => $entry_uuid,
			'created_at' => $created_at_gmt,
			'payload'    => $payload,
		];
		$body = wp_json_encode( $event );
		if ( false === $body ) {
			return;
		}

		$secret = $this->get_signing_secret();
		$headers = [
			'Content-Type' => 'application/json',
			'X-Enterprise-Forms-Event' => 'submission.accepted',
		];

		if ( '' !== $secret ) {
			$headers['X-Enterprise-Forms-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		foreach ( $settings['endpoints'] as $endpoint ) {
			$response = wp_remote_post(
				$endpoint,
				[
					'timeout' => 10,
					'headers' => $headers,
					'body'    => $body,
				]
			);

			if ( is_wp_error( $response ) ) {
				AuditLog::record( 'webhook_delivery_failed', 'entry', 0, [ 'form_id' => $form_id, 'entry_uuid' => $entry_uuid, 'endpoint' => $endpoint, 'reason' => $response->get_error_message() ] );
				continue;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			AuditLog::record(
				$status_code >= 200 && $status_code < 300 ? 'webhook_delivered' : 'webhook_delivery_failed',
				'entry',
				0,
				[ 'form_id' => $form_id, 'entry_uuid' => $entry_uuid, 'endpoint' => $endpoint, 'status_code' => $status_code ]
			);
		}
	}

	/**
	 * @param mixed $raw_endpoints
	 * @return string[]
	 */
	private function sanitize_endpoints( mixed $raw_endpoints ): array {
		if ( is_string( $raw_endpoints ) ) {
			$raw_endpoints = preg_split( '/[\r\n,]+/', $raw_endpoints );
		}

		if ( ! is_array( $raw_endpoints ) ) {
			return [];
		}

		$endpoints = [];
		foreach ( $raw_endpoints as $endpoint ) {
			$url = esc_url_raw( (string) $endpoint );
			if ( '' !== $url && wp_http_validate_url( $url ) ) {
				$endpoints[] = $url;
			}
		}

		return array_values( array_unique( $endpoints ) );
	}

	private function get_signing_secret(): string {
		$stored = get_option( self::SETTINGS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$secret = isset( $stored['signing_secret'] ) && is_string( $stored['signing_secret'] ) ? $stored['signing_secret'] : '';
		if ( '' === $secret ) {
			return '';
		}

		$decrypted = ( new EP_Crypto() )->decrypt( $secret );
		return str_starts_with( $decrypted, 'ENCv' ) ? '' : sanitize_text_field( $decrypted );
	}
}