<?php
namespace EnterpriseForms;

/**
 * Handles registration of Custom Post Types.
 */
class PostTypes {
	public function init(): void {
		add_action( 'init', [ $this, 'register_form_post_type' ] );
		add_action( 'init', [ $this, 'register_form_meta' ] );
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

	public function register_form_meta(): void {
		register_post_meta( 'ep_form', 'ep_form_schema', [
			'single'            => true,
			'type'              => 'string',
			'show_in_rest'      => true,
			'sanitize_callback' => 'wp_kses_post',
			'auth_callback'     => function (): bool {
				return current_user_can( 'manage_options' );
			},
		] );
	}
}
