<?php
/**
 * Plugin Name:       Enterprise Forms
 * Plugin URI:        https://enterprise-forms.com
 * Description:       A disruptive, enterprise-grade WordPress form plugin featuring a full-screen React workstation. Zero bloat, API-first approach using WP Interactivity API.
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            tporret
 * License:           GPL-2.0-or-later
 * Text Domain:       enterprise-forms
 * Domain Path:       /languages
 */

namespace EnterpriseForms;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/inc/class-ep-installer.php';
require_once __DIR__ . '/inc/Observability.php';
require_once __DIR__ . '/inc/Permissions.php';
require_once __DIR__ . '/inc/AuditLog.php';
require_once __DIR__ . '/inc/FormVersions.php';
require_once __DIR__ . '/inc/SubmissionJobs.php';
require_once __DIR__ . '/inc/DataGovernance.php';
require_once __DIR__ . '/inc/WebhookIntegrations.php';
require_once __DIR__ . '/inc/class-ep-crypto.php';
require_once __DIR__ . '/inc/class-ep-payment-settings.php';
require_once __DIR__ . '/inc/class-ep-storage-settings.php';
require_once __DIR__ . '/inc/interface-ep-payment-gateway.php';
require_once __DIR__ . '/inc/class-ep-gateway-stripe.php';
require_once __DIR__ . '/inc/class-ep-gateway-braintree.php';
require_once __DIR__ . '/inc/class-ep-gateway-paypal.php';
require_once __DIR__ . '/inc/class-ep-gateway-square.php';
require_once __DIR__ . '/inc/class-ep-payment-factory.php';
require_once __DIR__ . '/inc/class-ep-validator.php';
require_once __DIR__ . '/inc/class-ep-rest-entries.php';
require_once __DIR__ . '/inc/class-ep-rest-payments.php';
require_once __DIR__ . '/inc/class-ep-form-renderer.php';
require_once __DIR__ . '/inc/class-ep-theme-engine.php';

register_activation_hook( __FILE__, [ '\\EnterpriseForms\\EP_Installer', 'activate' ] );

// Security headers.
add_action( 'send_headers', function () {
	if ( ! headers_sent() && is_admin() ) {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-XSS-Protection: 1; mode=block' );
		header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
	}
} );

// Autoloader.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// A simple autoloader fallback for pure dev without running `composer install` yet.
	spl_autoload_register( function ( $class ) {
		$prefix   = 'EnterpriseForms\\';
		$base_dir = __DIR__ . '/inc/';
		$len      = strlen( $prefix );

		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	} );
}

/**
 * Main Singleton Instance.
 */
class Plugin {
	private static ?Plugin $instance = null;

	private function __construct() {
		$this->define_constants();
		$this->init_components();
	}

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function define_constants(): void {
		define( 'EP_FORMS_VERSION', '1.2.0' );
		define( 'EP_FORMS_PATH', plugin_dir_path( __FILE__ ) );
		define( 'EP_FORMS_URL', plugin_dir_url( __FILE__ ) );
	}

	private function init_components(): void {
		if ( class_exists( '\\EnterpriseForms\\EP_Installer' ) ) {
			EP_Installer::init();
		}

		if ( class_exists( '\EnterpriseForms\\Permissions' ) ) {
			( new Permissions() )->init();
		}

		if ( class_exists( '\EnterpriseForms\\SubmissionJobs' ) ) {
			( new SubmissionJobs() )->init();
		}

		if ( class_exists( '\EnterpriseForms\\DataGovernance' ) ) {
			( new DataGovernance() )->init();
		}

		if ( class_exists( '\EnterpriseForms\\WebhookIntegrations' ) ) {
			( new WebhookIntegrations() )->init();
		}

		// Initialize the main core systems.
		if ( class_exists( '\\EnterpriseForms\\PostTypes' ) ) {
			( new PostTypes() )->init();
		}

		if ( class_exists( '\\EnterpriseForms\\AdminBridge' ) ) {
			( new AdminBridge() )->init();
		}

		if ( class_exists( '\\EnterpriseForms\\RestApi' ) ) {
			( new RestApi() )->init();
		}

		if ( class_exists( '\\EnterpriseForms\\Interactivity' ) ) {
			( new Interactivity() )->init();
		}

		if ( class_exists( '\\EnterpriseForms\\EP_Crypto' ) ) {
			( new EP_Crypto() )->init();
		}

		if ( class_exists( '\\EnterpriseForms\\Database' ) ) {
			( new Database() )->init();
		}

		if ( class_exists( '\\EnterpriseForms\\EP_Theme_Engine' ) ) {
			( new EP_Theme_Engine() )->init();
		}
	}
}

// Boot the plugin.
Plugin::get_instance();