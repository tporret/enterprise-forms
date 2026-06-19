<?php
namespace EnterpriseForms;

use wpdb;

/**
 * Immutable schema version snapshots for form lifecycle governance.
 */
class FormVersions {
	public const TABLE_SUFFIX = 'ep_form_versions';

	public static function record_schema_snapshot( int $form_id, string $schema_raw, string $lifecycle_status = '' ): void {
		global $wpdb;

		if ( $form_id <= 0 || '' === trim( $schema_raw ) || ! $wpdb instanceof wpdb ) {
			return;
		}

		$schema = json_decode( $schema_raw, true );
		if ( ! is_array( $schema ) ) {
			return;
		}

		$canonical_schema = wp_json_encode( $schema );
		if ( false === $canonical_schema ) {
			return;
		}

		$schema_hash = hash( 'sha256', $canonical_schema );
		$table_name = $wpdb->prefix . self::TABLE_SUFFIX;
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE form_id = %d AND schema_hash = %s LIMIT 1",
				$form_id,
				$schema_hash
			)
		);

		if ( $exists ) {
			return;
		}

		$wpdb->insert(
			$table_name,
			[
				'form_id'          => $form_id,
				'schema_version'   => self::resolve_schema_version( $schema ),
				'schema_hash'      => $schema_hash,
				'lifecycle_status' => sanitize_key( $lifecycle_status ?: (string) get_post_status( $form_id ) ),
				'schema_json'      => $canonical_schema,
				'created_by'       => get_current_user_id(),
				'created_at'       => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private static function resolve_schema_version( array $schema ): string {
		$version = sanitize_text_field( (string) ( $schema['schema_version'] ?? $schema['version'] ?? '' ) );
		return '' !== $version ? $version : '1.0.0';
	}
}