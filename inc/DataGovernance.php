<?php
namespace EnterpriseForms;

use wpdb;

/**
 * Retention and deletion controls for stored entry data.
 */
class DataGovernance {
	private const SETTINGS_OPTION = 'ep_forms_governance_settings';
	private const RETENTION_ACTION = 'ep_forms_run_retention_policy';

	public function init(): void {
		add_action( self::RETENTION_ACTION, [ $this, 'run_retention_policy' ] );

		if ( ! wp_next_scheduled( self::RETENTION_ACTION ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_ACTION );
		}
	}

	/**
	 * @return array{retention_enabled: bool, retention_days: int, retention_action: string}
	 */
	public function get_settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$action = sanitize_key( (string) ( $stored['retention_action'] ?? 'anonymize' ) );
		if ( ! in_array( $action, [ 'anonymize', 'delete' ], true ) ) {
			$action = 'anonymize';
		}

		return [
			'retention_enabled' => ! empty( $stored['retention_enabled'] ),
			'retention_days'    => max( 1, absint( $stored['retention_days'] ?? 365 ) ),
			'retention_action'  => $action,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{retention_enabled: bool, retention_days: int, retention_action: string}
	 */
	public function update_settings( array $payload ): array {
		$settings = [
			'retention_enabled' => ! empty( $payload['retention_enabled'] ),
			'retention_days'    => max( 1, absint( $payload['retention_days'] ?? 365 ) ),
			'retention_action'  => in_array( sanitize_key( (string) ( $payload['retention_action'] ?? 'anonymize' ) ), [ 'anonymize', 'delete' ], true )
				? sanitize_key( (string) $payload['retention_action'] )
				: 'anonymize',
		];

		update_option( self::SETTINGS_OPTION, $settings, false );
		AuditLog::record( 'governance_settings_updated', 'settings', 0, $settings );

		return $settings;
	}

	public function run_retention_policy(): void {
		$settings = $this->get_settings();
		if ( ! $settings['retention_enabled'] ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $settings['retention_days'] * DAY_IN_SECONDS ) );
		if ( 'delete' === $settings['retention_action'] ) {
			$this->delete_expired_entries( $cutoff );
			return;
		}

		$this->anonymize_expired_entries( $cutoff );
	}

	private function delete_expired_entries( string $cutoff ): void {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$table_name = $wpdb->prefix . 'ep_entries';
		$search_table = $wpdb->prefix . 'ep_entry_search';
		$entry_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE created_at < %s LIMIT 500", $cutoff ) );
		$entry_ids = array_values( array_filter( array_map( 'absint', (array) $entry_ids ) ) );

		if ( empty( $entry_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$search_table} WHERE entry_id IN ({$placeholders})", $entry_ids ) );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE id IN ({$placeholders})", $entry_ids ) );

		AuditLog::record( 'retention_entries_deleted', 'entry', 0, [ 'count' => (int) $deleted, 'cutoff' => $cutoff ] );
	}

	private function anonymize_expired_entries( string $cutoff ): void {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb || ! EP_Crypto::is_configured() ) {
			return;
		}

		$table_name = $wpdb->prefix . 'ep_entries';
		$search_table = $wpdb->prefix . 'ep_entry_search';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, form_id, created_at FROM {$table_name} WHERE created_at < %s AND status <> 'redacted' LIMIT 500", $cutoff ), ARRAY_A );
		if ( empty( $rows ) ) {
			return;
		}

		$crypto = new EP_Crypto();
		$count = 0;

		foreach ( (array) $rows as $row ) {
			$payload_json = wp_json_encode(
				[
					'redacted'    => true,
					'redacted_at' => current_time( 'mysql', true ),
				]
			);

			if ( false === $payload_json ) {
				continue;
			}

			$stored_payload = wp_json_encode( [ 'ciphertext' => $crypto->encrypt( $payload_json ) ] );
			if ( false === $stored_payload ) {
				continue;
			}

			$updated = $wpdb->update(
				$table_name,
				[ 'status' => 'redacted', 'payload' => $stored_payload ],
				[ 'id' => (int) $row['id'] ],
				[ '%s', '%s' ],
				[ '%d' ]
			);

			if ( false === $updated ) {
				continue;
			}

			$wpdb->replace(
				$search_table,
				[
					'entry_id'    => (int) $row['id'],
					'form_id'     => (int) $row['form_id'],
					'status'      => 'redacted',
					'created_at'  => (string) $row['created_at'],
					'search_text' => 'redacted ' . (string) $row['created_at'],
				],
				[ '%d', '%d', '%s', '%s', '%s' ]
			);

			$count++;
		}

		if ( $count > 0 ) {
			AuditLog::record( 'retention_entries_anonymized', 'entry', 0, [ 'count' => $count, 'cutoff' => $cutoff ] );
		}
	}
}