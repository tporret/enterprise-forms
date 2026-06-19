<?php
namespace EnterpriseForms;

/**
 * Custom capability management for Enterprise Forms.
 * Encryption is handled by EP_Crypto (AES-256-GCM).
 */
class Permissions {
	public const MANAGE = 'manage_enterprise_forms';
	public const EDIT_FORMS = 'edit_enterprise_forms';
	public const VIEW_ENTRIES = 'view_enterprise_form_entries';
	public const EXPORT_ENTRIES = 'export_enterprise_form_entries';
	public const MANAGE_SETTINGS = 'manage_enterprise_form_settings';
	public const MANAGE_PAYMENTS = 'manage_enterprise_form_payments';
	public const MANAGE_STORAGE = 'manage_enterprise_form_storage';

	private const ADMIN_CAPABILITIES = [
		self::MANAGE,
		self::EDIT_FORMS,
		self::VIEW_ENTRIES,
		self::EXPORT_ENTRIES,
		self::MANAGE_SETTINGS,
		self::MANAGE_PAYMENTS,
		self::MANAGE_STORAGE,
	];

	public function init(): void {
		add_filter( 'user_has_cap', [ $this, 'grant_super_admin_capabilities' ], 10, 4 );
	}

	/**
	 * Grant Enterprise Forms capabilities to administrators on activation.
	 */
	public static function add_caps(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}

		foreach ( self::ADMIN_CAPABILITIES as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/**
	 * Keep network super admins authorized even when site roles drift.
	 *
	 * @param array<string, bool> $allcaps
	 * @param string[]            $caps
	 * @param mixed[]             $args
	 * @param \WP_User            $user
	 * @return array<string, bool>
	 */
	public function grant_super_admin_capabilities( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( ! is_multisite() || ! is_super_admin( $user->ID ) ) {
			return $allcaps;
		}

		foreach ( self::ADMIN_CAPABILITIES as $capability ) {
			if ( in_array( $capability, $caps, true ) ) {
				$allcaps[ $capability ] = true;
			}
		}

		return $allcaps;
	}
}
