<?php
/**
 * Dynamic frontend view for the renderer block.
 */

use EnterpriseForms\EP_Form_Renderer;
use EnterpriseForms\EP_REST_Payments;
use EnterpriseForms\EP_Theme_Engine;

$attributes = is_array( $attributes ?? null ) ? $attributes : [];
$form_id    = absint( $attributes['formId'] ?? 0 );

$schema_raw = $form_id > 0 ? get_post_meta( $form_id, 'ep_form_schema', true ) : '';
$schema     = is_string( $schema_raw ) && '' !== trim( $schema_raw ) ? json_decode( $schema_raw, true ) : [];
$schema     = is_array( $schema ) ? $schema : [];

$renderer = new EP_Form_Renderer();


$fields = [];
$pages  = [];
if ( isset( $schema['pages'] ) && is_array( $schema['pages'] ) ) {
	$pages = array_values( array_filter( $schema['pages'], 'is_array' ) );
	foreach ( $pages as $page ) {
		if ( isset( $page['fields'] ) && is_array( $page['fields'] ) ) {
			$fields = array_merge( $fields, $page['fields'] );
		}
	}
} elseif ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
	$fields = $schema['fields'];
} elseif ( isset( $schema['steps'] ) && is_array( $schema['steps'] ) ) {
	foreach ( $schema['steps'] as $step ) {
		if ( is_array( $step ) && isset( $step['fields'] ) && is_array( $step['fields'] ) ) {
			$fields = array_merge( $fields, $step['fields'] );
		}
	}
}

$page_count = max( 1, count( $pages ) );

$initial_values     = [ 'hp_field' => '' ];
$initial_errors     = [ 'hp_field' => '' ];
$initial_visibility = [];
$logic_rules        = isset( $schema['logic'] ) && is_array( $schema['logic'] ) ? $schema['logic'] : [];
$requires_payment   = ! empty( $schema['requires_payment'] );
$payment_gateway    = 'stripe';
$payment_client_config = [];

foreach ( $fields as $index => $field ) {
	if ( ! is_array( $field ) ) {
		continue;
	}

	$field_name = sanitize_key( (string) ( $field['name'] ?? $field['id'] ?? 'field_' . $index ) );
	if ( '' === $field_name ) {
		$field_name = 'field_' . $index;
	}

	$field_type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
	if ( 'payment' === $field_type ) {
		$requires_payment = true;
		$payment_gateway = sanitize_key( (string) ( $field['gateway'] ?? 'stripe' ) );
		continue;
	}

	if ( in_array( $field_type, [ 'checkbox', 'consent' ], true ) ) {
		$initial_values[ $field_name ] = '';
	} else {
		$initial_values[ $field_name ] = sanitize_text_field( (string) ( $field['value'] ?? '' ) );
	}

	$initial_errors[ $field_name ] = '';

	$field_id       = sanitize_key( (string) ( $field['id'] ?? '' ) );
	$has_field_logic = ! empty( $field['conditionalLogic'] ) || ! empty( $field['conditional_logic'] );

	if ( ! $has_field_logic && '' !== $field_id ) {
		foreach ( $logic_rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$target_field = sanitize_key( (string) ( $rule['target_field_id'] ?? '' ) );
			if ( $target_field === $field_id ) {
				$has_field_logic = true;
				break;
			}
		}
	}

	$initial_visibility[ $field_name ] = $has_field_logic ? false : true;
}

if ( $requires_payment ) {
	$initial_values['payment_intent_id'] = '';
	$initial_values['payment_token'] = '';
	$initial_errors['payment_intent_id'] = '';
}

$settings = isset( $schema['settings'] ) && is_array( $schema['settings'] ) ? $schema['settings'] : [];

$theme_engine = new EP_Theme_Engine();
$theme_slug   = $theme_engine->resolve_form_theme( $schema );
$theme_engine->enqueue_form_theme( $theme_slug );

$success_message = sanitize_text_field( (string) ( $settings['successMessage'] ?? __( 'Thank you for your submission.', 'enterprise-forms' ) ) );
$submit_text     = sanitize_text_field( (string) ( $settings['submitButtonText'] ?? __( 'Submit', 'enterprise-forms' ) ) );
$redirect_url    = '';
if ( isset( $settings['thankYouRedirect'] ) && is_string( $settings['thankYouRedirect'] ) ) {
	$redirect_url = esc_url_raw( $settings['thankYouRedirect'] );
}

$endpoint = $form_id > 0
	? rest_url( 'enterprise-forms/v1/entries/' . $form_id )
	: rest_url( 'enterprise-forms/v1/entries/' );

wp_interactivity_state(
	'enterpriseForms',
	[
		'config' => [
			'restUrl'          => esc_url_raw( $endpoint ),
			'paymentIntentUrl' => esc_url_raw( rest_url( 'enterprise-forms/v1/payment-intent' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
		],
	]
);

$gateway_settings = class_exists( '\\EnterpriseForms\\EP_REST_Payments' ) ? EP_REST_Payments::get_frontend_gateway_settings() : [];
if ( isset( $gateway_settings['gateways'][ $payment_gateway ]['client_config'] ) && is_array( $gateway_settings['gateways'][ $payment_gateway ]['client_config'] ) ) {
	$payment_client_config = $gateway_settings['gateways'][ $payment_gateway ]['client_config'];
}
$stripe_publishable_key = class_exists( '\\EnterpriseForms\\EP_REST_Payments' ) ? EP_REST_Payments::get_publishable_key() : '';

$wrapper_attributes = get_block_wrapper_attributes(
	[
		'class'               => 'ep-form-container',
		'data-theme'          => $theme_slug,
		'data-form-id'        => (string) $form_id,
		'data-wp-interactive' => 'enterpriseForms',
		'data-wp-run'         => 'callbacks.afterSubmit',
		'data-wp-context'     => wp_json_encode(
			[
				'values'           => $initial_values,
				'errors'           => $initial_errors,
				'visibility'       => $initial_visibility,
				'isSubmitting'     => false,
				'isSuccess'        => false,
				'message'          => '',
				'successMessage'   => $success_message,
				'submitButtonText' => $submit_text,
				'redirectUrl'      => $redirect_url,
				'hasTracked'       => false,
				'requiresPayment'  => $requires_payment,
				'paymentGateway'   => $payment_gateway,
				'paymentClientConfig' => $payment_client_config,
				'stripePublishableKey' => $stripe_publishable_key,
				'paymentReady'     => false,
				'rules'            => $logic_rules,
				'currentStep'      => 0,
				'totalSteps'       => $page_count,
				'uploadProgress'   => [
					'active'     => false,
					'fileName'   => '',
					'percentage' => 0,
				],
				'uploadedFiles'    => [],
				'dropzoneActive'   => false,
			],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		),
	]
);
?>

<div <?php echo $wrapper_attributes; ?>>
	<?php if ( $form_id <= 0 ) : ?>
		<p class="ep-form-message ep-form-message-error"><?php esc_html_e( 'No form is configured. Select a form ID in block settings.', 'enterprise-forms' ); ?></p>
	<?php elseif ( empty( $fields ) ) : ?>
		<p class="ep-form-message ep-form-message-error"><?php esc_html_e( 'This form does not have published schema fields yet.', 'enterprise-forms' ); ?></p>
	<?php else : ?>
		<?php echo $renderer->render_form_html( $schema, $form_id, $endpoint ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div class="ep-success-message" data-wp-class--ep-is-visible="context.isSuccess" role="status" aria-live="polite">
			<p data-wp-text="context.message"></p>
		</div>
	<?php endif; ?>
</div>
