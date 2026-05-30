<?php
namespace EnterpriseForms;

/**
 * Registers frontend form themes and injects CSS variable maps per rendered theme.
 */
class EP_Theme_Engine {
	private const STYLE_HANDLE = 'enterprise-forms-form-base';

	/**
	 * @var array<string, bool>
	 */
	private static array $injected_themes = [];

	public function init(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
	}

	/**
	 * @return array<string, string>
	 */
	public function get_registered_themes(): array {
		return [
			'chameleon' => __( 'FSE Default', 'enterprise-forms' ),
			'itsm'      => __( 'ITSM Standard', 'enterprise-forms' ),
		];
	}

	public function register_assets(): void {
		wp_register_style(
			self::STYLE_HANDLE,
			EP_FORMS_URL . 'src/styles/form-base.css',
			[],
			EP_FORMS_VERSION
		);
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	public function resolve_form_theme( array $schema ): string {
		$settings = isset( $schema['settings'] ) && is_array( $schema['settings'] ) ? $schema['settings'] : [];
		$theme    = sanitize_key( (string) ( $settings['theme'] ?? 'chameleon' ) );

		return array_key_exists( $theme, $this->get_registered_themes() ) ? $theme : 'chameleon';
	}

	public function enqueue_form_theme( string $theme ): void {
		$theme = sanitize_key( $theme );

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( self::STYLE_HANDLE );

		if ( isset( self::$injected_themes[ $theme ] ) ) {
			return;
		}

		$inline_css = $this->build_theme_css( $theme );
		if ( '' === $inline_css ) {
			return;
		}

		wp_add_inline_style( self::STYLE_HANDLE, $inline_css );
		self::$injected_themes[ $theme ] = true;
	}

	private function build_theme_css( string $theme ): string {
		$map = match ( $theme ) {
			'chameleon' => $this->get_chameleon_variable_map(),
			'itsm'      => $this->get_itsm_variable_map(),
			default => [],
		};

		if ( [] === $map ) {
			return '';
		}

		$declarations = [];
		foreach ( $map as $property => $value ) {
			$declarations[] = sprintf( '%s: %s;', $property, $value );
		}

		return sprintf(
			'.ep-form-container[data-theme="%1$s"] {%2$s}',
			esc_attr( $theme ),
			implode( '', $declarations )
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_chameleon_variable_map(): array {
		return [
			'--ep-bg' => 'var(--wp--preset--color--base, #ffffff)',
			'--ep-text' => 'var(--wp--preset--color--contrast, #1f2937)',
			'--ep-muted-text' => 'color-mix(in srgb, var(--wp--preset--color--contrast, #1f2937) 64%, var(--wp--preset--color--base, #ffffff))',
			'--ep-border-color' => 'color-mix(in srgb, var(--wp--preset--color--contrast, #1f2937) 16%, var(--wp--preset--color--base, #ffffff))',
			'--ep-border-width' => '1px',
			'--ep-border-radius' => 'var(--wp--custom--border--radius--medium, 0.75rem)',
			'--ep-primary-color' => 'var(--wp--preset--color--primary, #2563eb)',
			'--ep-focus-ring' => 'none',
			'--ep-focus-border-color' => 'var(--wp--preset--color--primary, #2563eb)',
			'--ep-focus-shadow' => 'color-mix(in srgb, var(--wp--preset--color--primary, #2563eb) 18%, transparent)',
			'--ep-spacing' => '0.75rem 0.875rem',
			'--ep-container-padding' => 'var(--wp--preset--spacing--40, 1rem)',
			'--ep-field-gap' => 'var(--wp--preset--spacing--30, 0.875rem)',
			'--ep-font-family' => 'var(--wp--preset--font-family--base, var(--wp--preset--font-family--system-font, inherit))',
			'--ep-font-size' => 'var(--wp--preset--font-size--medium, 1rem)',
			'--ep-label-size' => '0.9375rem',
			'--ep-label-weight' => '600',
			'--ep-input-font-weight' => '400',
			'--ep-shadow' => '0 10px 28px color-mix(in srgb, var(--wp--preset--color--contrast, #1f2937) 8%, transparent)',
			'--ep-input-bg' => 'var(--wp--preset--color--base, #ffffff)',
			'--ep-input-text' => 'var(--wp--preset--color--contrast, #1f2937)',
			'--ep-input-border' => 'color-mix(in srgb, var(--wp--preset--color--contrast, #1f2937) 18%, var(--wp--preset--color--base, #ffffff))',
			'--ep-control-padding' => '0.75rem 0.875rem',
			'--ep-button-bg' => 'var(--wp--preset--color--primary, #2563eb)',
			'--ep-button-hover-bg' => 'color-mix(in srgb, var(--wp--preset--color--primary, #2563eb) 86%, black)',
			'--ep-button-text' => 'var(--wp--preset--color--base, #ffffff)',
			'--ep-submit-padding-y' => '0.875rem',
			'--ep-submit-padding-x' => '1.25rem',
			'--ep-error-color' => 'var(--wp--preset--color--vivid-red, #b91c1c)',
			'--ep-success-color' => 'var(--wp--preset--color--primary, #2563eb)',
			'--ep-success-bg' => 'color-mix(in srgb, var(--wp--preset--color--primary, #2563eb) 10%, var(--wp--preset--color--base, #ffffff))',
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function get_itsm_variable_map(): array {
		return [
			'--ep-bg' => '#ffffff',
			'--ep-text' => '#181a1f',
			'--ep-muted-text' => '#5c6773',
			'--ep-border-color' => '#879596',
			'--ep-border-width' => '1px',
			'--ep-border-radius' => '2px',
			'--ep-primary-color' => '#1f84d6',
			'--ep-focus-ring' => '1px solid #1f84d6',
			'--ep-focus-border-color' => '#1f84d6',
			'--ep-focus-shadow' => 'none',
			'--ep-spacing' => '0.4rem 0.6rem',
			'--ep-container-padding' => '0.75rem',
			'--ep-field-gap' => '0.5rem',
			'--ep-font-family' => 'Arial, "Helvetica Neue", Helvetica, sans-serif',
			'--ep-font-size' => '0.95rem',
			'--ep-label-size' => '0.85rem',
			'--ep-label-weight' => '600',
			'--ep-input-font-weight' => '400',
			'--ep-shadow' => 'none',
			'--ep-input-bg' => '#ffffff',
			'--ep-input-text' => '#181a1f',
			'--ep-input-border' => '#879596',
			'--ep-control-padding' => '0.4rem 0.6rem',
			'--ep-button-bg' => '#1f84d6',
			'--ep-button-hover-bg' => '#176fb7',
			'--ep-button-text' => '#ffffff',
			'--ep-submit-padding-y' => '0.5rem',
			'--ep-submit-padding-x' => '0.85rem',
			'--ep-error-color' => '#c62828',
			'--ep-success-color' => '#181a1f',
			'--ep-success-bg' => '#eef4f8',
		];
	}
}