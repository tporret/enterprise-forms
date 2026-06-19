<?php
namespace EnterpriseForms;

use wpdb;

/**
 * Handles plugin install and database schema creation.
 */
class EP_Installer {
	public static function init(): void {
		add_action( 'wp_initialize_site', [ __CLASS__, 'initialize_new_site' ], 10, 1 );
	}

	/**
	 * Run install routines on plugin activation.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
				]
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::setup_current_site();
				restore_current_blog();
			}

			return;
		}

		self::setup_current_site();
	}

	/**
	 * Provision plugin storage and encryption state for a newly created multisite blog.
	 */
	public static function initialize_new_site( \WP_Site $new_site ): void {
		if ( ! is_multisite() || 0 >= (int) $new_site->blog_id ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		self::setup_current_site();
		restore_current_blog();
	}

	private static function setup_current_site(): void {
		Permissions::add_caps();
		EP_Crypto::ensure_encryption_key();
		EP_Crypto::mark_activation_notice_pending();
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

		$search_table = $wpdb->prefix . 'ep_entry_search';
		$search_sql = "CREATE TABLE {$search_table} (
			entry_id BIGINT(20) UNSIGNED NOT NULL,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'unread',
			created_at DATETIME NOT NULL,
			search_text LONGTEXT NOT NULL,
			PRIMARY KEY  (entry_id),
			KEY form_created (form_id, created_at),
			KEY form_status_created (form_id, status, created_at),
			FULLTEXT KEY search_text (search_text)
		) {$charset_collate};";

		dbDelta( $search_sql );

		$payments_table = $wpdb->prefix . 'ep_payment_intents';
		$payments_sql = "CREATE TABLE {$payments_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			record_id CHAR(36) NOT NULL,
			gateway VARCHAR(50) NOT NULL,
			intent_id VARCHAR(191) DEFAULT '' NOT NULL,
			transaction_id VARCHAR(191) DEFAULT NULL,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			schema_version VARCHAR(50) DEFAULT '' NOT NULL,
			amount BIGINT(20) UNSIGNED NOT NULL,
			currency VARCHAR(10) NOT NULL,
			session_hash VARCHAR(64) DEFAULT '' NOT NULL,
			status VARCHAR(30) DEFAULT 'created' NOT NULL,
			entry_uuid CHAR(36) DEFAULT '' NOT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY record_id (record_id),
			UNIQUE KEY gateway_intent (gateway, intent_id),
			UNIQUE KEY gateway_transaction (gateway, transaction_id),
			KEY form_status (form_id, status),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $payments_sql );
		self::normalize_payment_intents_table();

		$audit_table = $wpdb->prefix . 'ep_audit_log';
		$audit_sql = "CREATE TABLE {$audit_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event VARCHAR(80) NOT NULL,
			object_type VARCHAR(50) DEFAULT '' NOT NULL,
			object_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			ip_address VARCHAR(45) DEFAULT '' NOT NULL,
			user_agent VARCHAR(255) DEFAULT '' NOT NULL,
			context JSON NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_created (event, created_at),
			KEY object_created (object_type, object_id, created_at),
			KEY user_created (user_id, created_at)
		) {$charset_collate};";

		dbDelta( $audit_sql );

		$form_versions_table = $wpdb->prefix . 'ep_form_versions';
		$form_versions_sql = "CREATE TABLE {$form_versions_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			schema_version VARCHAR(50) DEFAULT '' NOT NULL,
			schema_hash CHAR(64) NOT NULL,
			lifecycle_status VARCHAR(20) DEFAULT '' NOT NULL,
			schema_json LONGTEXT NOT NULL,
			created_by BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY form_schema_hash (form_id, schema_hash),
			KEY form_created (form_id, created_at),
			KEY form_status_created (form_id, lifecycle_status, created_at)
		) {$charset_collate};";

		dbDelta( $form_versions_sql );

		require_once __DIR__ . '/class-ep-cloud-storage.php';
		\EP_Cloud_Storage::create_uploads_table();

		update_option( 'ep_forms_db_version', '2.2.0', false );
	}

	public static function normalize_payment_intents_table(): void {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$table_name = $wpdb->prefix . 'ep_payment_intents';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $table_exists !== $table_name ) {
			return;
		}

		$wpdb->query( "UPDATE {$table_name} SET transaction_id = NULL WHERE transaction_id = '' AND status <> 'paid'" );
		$wpdb->query( "ALTER TABLE {$table_name} MODIFY transaction_id VARCHAR(191) NULL DEFAULT NULL" );
	}
}