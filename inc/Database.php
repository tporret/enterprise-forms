<?php
namespace EnterpriseForms;

/**
 * Manages the custom wp_ep_entries table for high-volume enterprise scalability.
 */
class Database {
	public function init(): void {
		// Activation-time schema management is handled by EP_Installer.
	}

	public function create_tables(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'ep_entries';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT 0,
			entry_data LONGTEXT NOT NULL,
			ip_address VARCHAR(45) DEFAULT '' NOT NULL,
			user_agent VARCHAR(255) DEFAULT '' NOT NULL,
			status VARCHAR(20) DEFAULT 'active' NOT NULL,
			is_encrypted TINYINT(1) DEFAULT 0 NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'ep_forms_db_version', '1.0.0' );
	}

	/**
	 * Retrieve entries for a given form.
	 */
	public static function get_entries( int $form_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ep_entries';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE form_id = %d AND status <> 'spam' ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$form_id,
				$limit,
				$offset
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Count entries globally or for a specific form.
	 */
	public static function count_entries( int $form_id = 0 ): int {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ep_entries';

		if ( $form_id > 0 ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} WHERE form_id = %d AND status <> 'spam'",
					$form_id
				)
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE status <> 'spam'" );
	}

	/**
	 * Count entries for many form IDs in one query.
	 *
	 * @param int[] $form_ids Form IDs to count.
	 * @return array<int, int> Map of form_id => count.
	 */
	public static function count_entries_by_form_ids( array $form_ids ): array {
		global $wpdb;

		$form_ids = array_values( array_filter( array_map( 'absint', $form_ids ) ) );
		if ( empty( $form_ids ) ) {
			return [];
		}

		$table_name   = $wpdb->prefix . 'ep_entries';
		$placeholders = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );
		$raw_results  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_id, COUNT(*) AS entry_count FROM {$table_name} WHERE status <> 'spam' AND form_id IN ({$placeholders}) GROUP BY form_id",
				$form_ids
			),
			ARRAY_A
		);

		$counts = [];
		foreach ( $form_ids as $form_id ) {
			$counts[ $form_id ] = 0;
		}

		foreach ( $raw_results as $row ) {
			$counts[ (int) $row['form_id'] ] = (int) $row['entry_count'];
		}

		return $counts;
	}
}
