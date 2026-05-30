<?php
namespace EnterpriseForms;

use wpdb;

/**
 * Handles plugin install and database schema creation.
 */
class EP_Installer {
	/**
	 * Run install routines on plugin activation.
	 */
	public static function activate( bool $network_wide = false ): void {
		EP_Crypto::ensure_encryption_key();

		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
				]
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::create_tables();
				restore_current_blog();
			}

			return;
		}

		self::create_tables();
	}

	/**
	 * Create or update custom entries table.
	 */
	public static function create_tables(): void {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . 'ep_entries';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'unread',
			payload JSON NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY form_id (form_id),
			KEY form_status_created (form_id, status, created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		require_once __DIR__ . '/class-ep-cloud-storage.php';
		\EP_Cloud_Storage::create_uploads_table();

		update_option( 'ep_forms_db_version', '2.1.0', false );
	}
}