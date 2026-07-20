<?php
namespace EnterpriseForms;

/**
 * Handles registration of Custom Post Types.
 */
class PostTypes {
	public function init(): void {
		add_action( 'init', [ $this, 'register_form_post_type' ] );
		add_action( 'init', [ $this, 'register_form_post_status' ] );
		add_action( 'init', [ $this, 'register_form_meta' ] );
		add_action( 'added_post_meta', [ $this, 'audit_schema_meta_change' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'audit_schema_meta_change' ], 10, 4 );
		add_action( 'transition_post_status', [ $this, 'audit_lifecycle_transition' ], 10, 3 );
	}

	public function register_form_post_type(): void {
		$args = [
			'labels'                => [
				'name'          => __( 'Enterprise Forms', 'enterprise-forms' ),
				'singular_name' => __( 'Enterprise Form', 'enterprise-forms' ),
			],
			'public'                => false,
			'show_ui'               => true,
			'show_in_menu'          => false, // Handled by AdminBridge top level menu.
			'capability_type'       => 'post',
			'hierarchical'          => false,
			'supports'              => [ 'title', 'editor', 'custom-fields' ],
			'show_in_rest'          => true, // Exposes REST API automatic support
			'show_in_graphql'       => true, // Adds support for WPGraphQL if active
			'graphql_single_name'   => 'enterpriseForm',
			'graphql_plural_name'   => 'enterpriseForms',
			'rest_base'             => 'ep-forms',
			'rest_controller_class' => 'WP_REST_Posts_Controller',
		];

		register_post_type( 'ep_form', $args );
	}

	public function register_form_post_status(): void {
		register_post_status( 'inactive', [
			'label'                     => _x( 'Inactive', 'post status', 'enterprise-forms' ),
			/* translators: %s: number of inactive forms. */
			'label_count'               => _n_noop(
				'Inactive <span class="count">(%s)</span>',
				'Inactive <span class="count">(%s)</span>',
				'enterprise-forms'
			),
			'public'                    => false,
			'internal'                  => false,
			'protected'                 => false,
			'private'                   => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'date_floating'             => false,
		] );

		register_post_status( 'archived', [
			'label'                     => _x( 'Archived', 'post status', 'enterprise-forms' ),
			/* translators: %s: number of archived forms. */
			'label_count'               => _n_noop(
				'Archived <span class="count">(%s)</span>',
				'Archived <span class="count">(%s)</span>',
				'enterprise-forms'
			),
			'public'                    => false,
			'internal'                  => false,
			'protected'                 => false,
			'private'                   => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'date_floating'             => false,
		] );
	}

	public function register_form_meta(): void {
		register_post_meta( 'ep_form', 'ep_form_schema', [
			'single'            => true,
			'type'              => 'string',
			'show_in_rest'      => true,
			'sanitize_callback' => 'wp_kses_post',
			'auth_callback'     => function (): bool {
				return current_user_can( Permissions::EDIT_FORMS );
			},
		] );
	}

	public function audit_schema_meta_change( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
		if ( 'ep_form_schema' !== $meta_key || 'ep_form' !== get_post_type( $object_id ) ) {
			return;
		}

		$schema = is_string( $meta_value ) ? json_decode( $meta_value, true ) : null;
		AuditLog::record(
			'form_schema_saved',
			'form',
			$object_id,
			[
				'schema_version' => is_array( $schema ) ? (string) ( $schema['schema_version'] ?? $schema['version'] ?? '' ) : '',
				'field_count'    => is_array( $schema ) && isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? count( $schema['fields'] ) : 0,
			]
		);

		FormVersions::record_schema_snapshot( $object_id, (string) $meta_value, (string) get_post_status( $object_id ) );
	}

	public function audit_lifecycle_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'ep_form' !== $post->post_type || $new_status === $old_status ) {
			return;
		}

		AuditLog::record(
			'form_lifecycle_changed',
			'form',
			(int) $post->ID,
			[
				'old_status' => sanitize_key( $old_status ),
				'new_status' => sanitize_key( $new_status ),
			]
		);

		$schema_raw = get_post_meta( (int) $post->ID, 'ep_form_schema', true );
		if ( is_string( $schema_raw ) && '' !== trim( $schema_raw ) ) {
			FormVersions::record_schema_snapshot( (int) $post->ID, $schema_raw, $new_status );
		}
	}
}
