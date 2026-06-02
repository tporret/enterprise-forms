<?php
namespace EnterpriseForms;

/**
 * Creates the Top-Level Menu and Full-Screen React Admin Bridge.
 */
class AdminBridge {
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_menu_page(): void {
		add_menu_page(
			__( 'Enterprise Forms', 'enterprise-forms' ),
			__( 'Enterprise Forms', 'enterprise-forms' ),
			'manage_options',
			'enterprise-forms',
			[ $this, 'render_admin_wrapper' ],
			'dashicons-feedback',
			55
		);
	}

	public function render_admin_wrapper(): void {
		// Output a blank div for React to mount into
		echo '<div id="enterprise-forms-root"></div>';
	}

	public function enqueue_assets( $hook_suffix ): void {
		if ( 'toplevel_page_enterprise-forms' !== $hook_suffix ) {
			return;
		}

		// Hide default WP Admin Sidebars & Menus via inline styles for Full-Screen
		$custom_css = "
			html.wp-toolbar { padding-top: 0 !important; }
			#wpadminbar,
			#adminmenumain,
			#adminmenuback,
			#adminmenuwrap,
			#wpfooter {
				display: none !important;
			}
			#wpcontent,
			#wpbody,
			#wpbody-content,
			#wpwrap {
				margin-left: 0 !important;
				padding-left: 0 !important;
			}
			#wpbody-content {
				padding-bottom: 0 !important;
			}
			#wpwrap {
				min-height: 100vh;
			}
			#enterprise-forms-root {
				width: 100%;
				min-height: 100vh;
				background-color: #fff;
				overflow: auto;
			}
		";
		wp_add_inline_style( 'wp-admin', $custom_css );

		// Assuming build generated from @wordpress/scripts
		$script_path = EP_FORMS_PATH . 'build/admin/index.js';
		$style_path  = EP_FORMS_PATH . 'build/admin/style-index.css';

		if ( file_exists( $script_path ) ) {
			$asset_file = include( EP_FORMS_PATH . 'build/admin/index.asset.php' );
			wp_enqueue_script(
				'enterprise-forms-admin-js',
				EP_FORMS_URL . 'build/admin/index.js',
				$asset_file['dependencies'],
				$asset_file['version'],
				true
			);

			$theme_engine = class_exists( '\\EnterpriseForms\\EP_Theme_Engine' )
				? new EP_Theme_Engine()
				: null;

			wp_localize_script(
				'enterprise-forms-admin-js',
				'enterpriseFormsAdminConfig',
				[
					'themes' => $theme_engine ? $theme_engine->get_registered_themes() : [],
					'encryption' => class_exists( '\\EnterpriseForms\\EP_Crypto' )
						? EP_Crypto::get_admin_config()
						: [
							'isConfigured'    => false,
							'status'          => 'missing',
							'usingFallback'   => false,
							'recheckUrl'      => admin_url(),
							'warningMessage'  => '',
							'wpConfigSnippet' => '',
						],
				]
			);

			// Inject nonce + root URL into wp-api-fetch so authenticated REST requests work.
			wp_add_inline_script(
				'wp-api-fetch',
				sprintf(
					'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( "%s" ) );' .
					'wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware( "%s" ) );',
					wp_create_nonce( 'wp_rest' ),
					esc_url_raw( rest_url() )
				),
				'after'
			);
		}

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'enterprise-forms-admin-css',
				EP_FORMS_URL . 'build/admin/style-index.css',
				[],
				EP_FORMS_VERSION
			);
		}
	}
}
