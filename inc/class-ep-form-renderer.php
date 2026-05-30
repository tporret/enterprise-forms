<?php
namespace EnterpriseForms;

/**
 * Maps stored form schema to Interactivity API-ready HTML.
 */
class EP_Form_Renderer {
	/**
	 * @param array<string, mixed> $schema
	 */
	public function render_form_html( array $schema, int $form_id, string $endpoint = '' ): string {
		$fields = $this->extract_fields( $schema );
		$pages = $this->extract_pages( $schema );
		$is_multi_step = count( $pages ) > 1;
		$has_submit = false;

		foreach ( $fields as $field ) {
			if ( is_array( $field ) && sanitize_key( (string) ( $field['type'] ?? '' ) ) === 'submit' ) {
				$has_submit = true;
				break;
			}
		}

		$submit_button_text = $this->resolve_submit_button_text( $fields );

		ob_start();
		?>
		<form
			class="ep-form"
			method="post"
			data-wp-on--submit="actions.submitForm"
			data-wp-class--ep-is-gone="context.isSuccess"
			novalidate
		>
			<?php if ( $is_multi_step ) : ?>
				<?php foreach ( $pages as $page_index => $page ) : ?>
					<div class="ep-form-page" data-wp-bind--hidden="context.currentStep !== <?php echo esc_attr( (string) $page_index ); ?>">
						<?php if ( ! empty( $page['title'] ) ) : ?>
							<h3 class="ep-form-step-title"><?php echo esc_html( (string) $page['title'] ); ?></h3>
						<?php endif; ?>
						<?php if ( ! empty( $page['description'] ) ) : ?>
							<p class="ep-form-step-description"><?php echo esc_html( (string) $page['description'] ); ?></p>
						<?php endif; ?>
						<?php foreach ( $page['fields'] as $field_index => $field ) : ?>
							<?php
							$field_type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
							if ( 'submit' === $field_type ) {
								continue;
							}

							$global_index = $this->find_field_index( $fields, $field, $field_index );
							$field_name = $this->resolve_field_name( $field, $global_index );
							$field_html = $this->render_field( $field, $field_name, $form_id, $global_index, $schema );

							if ( '' === $field_html ) {
								continue;
							}
							?>
							<?php if ( $this->has_conditional_logic( $field, $schema ) ) : ?>
								<div class="ep-field-visibility" data-wp-bind--hidden="!context.visibility.<?php echo esc_attr( $field_name ); ?>">
									<?php echo $field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							<?php else : ?>
								<?php echo $field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<?php foreach ( $fields as $index => $field ) : ?>
					<?php
					if ( ! is_array( $field ) ) {
						continue;
					}

					$field_name = $this->resolve_field_name( $field, $index );
					$field_html = $this->render_field( $field, $field_name, $form_id, $index, $schema );

					if ( '' === $field_html ) {
						continue;
					}
					?>
					<?php if ( $this->has_conditional_logic( $field, $schema ) ) : ?>
						<div class="ep-field-visibility" data-wp-bind--hidden="!context.visibility.<?php echo esc_attr( $field_name ); ?>">
							<?php echo $field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php else : ?>
						<?php echo $field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>

			<div class="ep-form-row ep-form-row-hidden" aria-hidden="true">
				<input
					type="text"
					class="ep-honeypot"
					name="hp_field"
					tabindex="-1"
					autocomplete="off"
					data-ep-field="hp_field"
					data-wp-bind--value="context.values.hp_field"
					data-wp-on--input="actions.updateValue"
					data-wp-class--ep-error="context.errors.hp_field"
				/>
			</div>

			<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
			<input
				type="hidden"
				name="schema_version"
				value="<?php echo esc_attr( $this->resolve_schema_version( $schema ) ); ?>"
			/>
			<?php wp_nonce_field( 'ep_forms_public_submit', 'ep_forms_nonce' ); ?>

			<?php if ( $is_multi_step ) : ?>
				<div class="ep-form-row ep-form-navigation">
					<button
						type="button"
						class="ep-nav-button ep-nav-button-secondary"
						data-wp-on--click="actions.prevStep"
						data-wp-bind--hidden="context.currentStep === 0"
					>
						<?php esc_html_e( 'Previous', 'enterprise-forms' ); ?>
					</button>
					<div class="ep-form-progress" aria-hidden="true">
						<div class="ep-form-progress-track">
							<div class="ep-form-progress-fill" data-wp-bind--style--width="(( context.currentStep + 1 ) / context.totalSteps ) * 100 + '%' "></div>
						</div>
					</div>
					<button
						type="button"
						class="ep-nav-button"
						data-wp-on--click="actions.nextStep"
						data-wp-bind--hidden="context.currentStep >= context.totalSteps - 1"
					>
						<?php esc_html_e( 'Next', 'enterprise-forms' ); ?>
					</button>
					<button
						type="submit"
						class="ep-submit-button"
						data-wp-bind--hidden="context.currentStep !== context.totalSteps - 1"
						data-wp-bind--disabled="context.isSubmitting"
						data-wp-class--is-submitting="context.isSubmitting"
					>
						<span data-wp-bind--hidden="context.isSubmitting"><?php echo esc_html( $submit_button_text ); ?></span>
						<span data-wp-bind--hidden="!context.isSubmitting"><?php esc_html_e( 'Submitting...', 'enterprise-forms' ); ?></span>
					</button>
				</div>
			<?php elseif ( ! $has_submit ) : ?>
				<div class="ep-form-row ep-submit-row">
					<button
						type="submit"
						class="ep-submit-button"
						data-wp-bind--disabled="context.isSubmitting"
						data-wp-class--is-submitting="context.isSubmitting"
					>
						<span data-wp-bind--hidden="context.isSubmitting"><?php esc_html_e( 'Submit', 'enterprise-forms' ); ?></span>
						<span data-wp-bind--hidden="!context.isSubmitting"><?php esc_html_e( 'Submitting...', 'enterprise-forms' ); ?></span>
					</button>
				</div>
			<?php endif; ?>

			<p class="ep-form-message ep-form-message-error" data-wp-show="context.message" data-wp-text="context.message"></p>
		</form>
		<style>
			.ep-form-page {
				animation: epFadeInSlideUp 0.28s ease-out;
			}

			.ep-form-step-title {
				margin: 0 0 0.5rem;
			}

			.ep-form-step-description {
				margin: 0 0 1rem;
				color: #475569;
			}

			.ep-form-navigation {
				display: grid;
				grid-template-columns: auto 1fr auto auto;
				gap: 0.75rem;
				align-items: center;
			}

			.ep-form-progress-track {
				height: 0.35rem;
				background: #e2e8f0;
				border-radius: 999px;
				overflow: hidden;
			}

			.ep-form-progress-fill {
				height: 100%;
				width: 0;
				background: linear-gradient(90deg, #2563eb, #0f766e);
				transition: width 0.25s ease;
			}

			.ep-nav-button {
				padding: 0.75rem 1rem;
				border: 0;
				border-radius: 0.75rem;
				background: #0f172a;
				color: #fff;
				cursor: pointer;
			}

			.ep-nav-button-secondary {
				background: #e2e8f0;
				color: #0f172a;
			}

			@keyframes epFadeInSlideUp {
				from {
					opacity: 0;
					transform: translateY(10px);
				}

				to {
					opacity: 1;
					transform: translateY(0);
				}
			}
		</style>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $schema
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_fields( array $schema ): array {
		if ( isset( $schema['pages'] ) && is_array( $schema['pages'] ) ) {
			$fields = [];

			foreach ( $schema['pages'] as $page ) {
				if ( ! is_array( $page ) || ! isset( $page['fields'] ) || ! is_array( $page['fields'] ) ) {
					continue;
				}

				foreach ( $page['fields'] as $field ) {
					if ( is_array( $field ) ) {
						$fields[] = $field;
					}
				}
			}

			if ( ! empty( $fields ) ) {
				return $fields;
			}
		}

		if ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
			return array_values( array_filter( $schema['fields'], 'is_array' ) );
		}

		$fields = [];
		$steps  = isset( $schema['steps'] ) && is_array( $schema['steps'] ) ? $schema['steps'] : [];

		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) || ! isset( $step['fields'] ) || ! is_array( $step['fields'] ) ) {
				continue;
			}

			foreach ( $step['fields'] as $field ) {
				if ( is_array( $field ) ) {
					$fields[] = $field;
				}
			}
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $schema
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_pages( array $schema ): array {
		if ( isset( $schema['pages'] ) && is_array( $schema['pages'] ) ) {
			$pages = [];

			foreach ( $schema['pages'] as $index => $page ) {
				if ( ! is_array( $page ) ) {
					continue;
				}

				$fields = isset( $page['fields'] ) && is_array( $page['fields'] )
					? array_values( array_filter( $page['fields'], 'is_array' ) )
					: [];

				if ( empty( $fields ) ) {
					continue;
				}

				$pages[] = [
					'id'          => sanitize_key( (string) ( $page['id'] ?? 'page_' . $index ) ),
					'title'       => sanitize_text_field( (string) ( $page['title'] ?? '' ) ),
					'description' => sanitize_text_field( (string) ( $page['description'] ?? '' ) ),
					'fields'      => $fields,
				];
			}

			if ( ! empty( $pages ) ) {
				return $pages;
			}
		}

		$fields = $this->extract_fields( $schema );

		return empty( $fields )
			? []
			: [
				[
					'id'          => 'page-0',
					'title'       => '',
					'description' => '',
					'fields'      => $fields,
				],
			];
	}

	/**
	 * @param array<int, array<string, mixed>> $fields
	 */
	private function resolve_submit_button_text( array $fields ): string {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( sanitize_key( (string) ( $field['type'] ?? '' ) ) !== 'submit' ) {
				continue;
			}

			$text = sanitize_text_field( (string) ( $field['button_text'] ?? '' ) );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return __( 'Submit', 'enterprise-forms' );
	}

	/**
	 * @param array<int, array<string, mixed>> $fields
	 * @param array<string, mixed>             $needle
	 */
	private function find_field_index( array $fields, array $needle, int $fallback ): int {
		$needle_id = sanitize_key( (string) ( $needle['id'] ?? '' ) );

		if ( '' !== $needle_id ) {
			foreach ( $fields as $index => $field ) {
				if ( sanitize_key( (string) ( $field['id'] ?? '' ) ) === $needle_id ) {
					return $index;
				}
			}
		}

		return $fallback;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function resolve_field_name( array $field, int $index ): string {
		$raw_name = (string) ( $field['name'] ?? $field['id'] ?? 'field_' . $index );
		$name     = sanitize_key( $raw_name );
		return '' === $name ? 'field_' . $index : $name;
	}

	/**
	 * @param array<string, mixed> $field
	 * @param array<string, mixed> $schema
	 */
	private function has_conditional_logic( array $field, array $schema ): bool {
		if ( ! empty( $field['conditionalLogic'] ) || ! empty( $field['conditional_logic'] ) ) {
			return true;
		}

		$logic    = isset( $schema['logic'] ) && is_array( $schema['logic'] ) ? $schema['logic'] : [];
		$field_id = sanitize_key( (string) ( $field['id'] ?? '' ) );

		if ( '' === $field_id ) {
			return false;
		}

		foreach ( $logic as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$target_field = sanitize_key( (string) ( $rule['target_field_id'] ?? '' ) );
			if ( $target_field === $field_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private function resolve_schema_version( array $schema ): string {
		$version = (string) ( $schema['schema_version'] ?? $schema['version'] ?? '1.0.0' );
		return sanitize_text_field( $version );
	}

	/**
	 * @param array<string, mixed> $field
	 * @param array<string, mixed> $schema
	 */
	private function render_field( array $field, string $field_name, int $form_id, int $index, array $schema ): string {
		$field_type        = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
		$field_label       = sanitize_text_field( (string) ( $field['label'] ?? __( 'Field', 'enterprise-forms' ) ) );
		$field_placeholder = sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) );
		$field_id          = 'ep-field-' . $form_id . '-' . $index;
		$is_required       = ! empty( $field['required'] );

		$rules     = isset( $field['validation_rules'] ) && is_array( $field['validation_rules'] ) ? $field['validation_rules'] : [];
		$options   = $this->extract_options( $field );
		$value_path = 'context.values.' . $field_name;
		$error_path = 'context.errors.' . $field_name;

		ob_start();

		switch ( $field_type ) {
			case 'checkbox_group':
				?>
				<fieldset class="ep-field-wrapper ep-form-row ep-choice-group">
					<legend class="ep-label"><?php echo esc_html( $field_label ); ?></legend>
					<?php foreach ( $options as $option_index => $option ) : ?>
						<label class="ep-choice" for="<?php echo esc_attr( $field_id . '-' . $option_index ); ?>">
							<input
								type="checkbox"
								id="<?php echo esc_attr( $field_id . '-' . $option_index ); ?>"
								name="<?php echo esc_attr( $field_name ); ?>[]"
								value="<?php echo esc_attr( $option ); ?>"
								data-ep-field="<?php echo esc_attr( $field_name ); ?>"
								data-wp-bind--value="<?php echo esc_attr( $value_path ); ?>"
								data-wp-on--input="actions.updateValue"
								data-wp-class--ep-error="<?php echo esc_attr( $error_path ); ?>"
							/>
							<span><?php echo esc_html( $option ); ?></span>
						</label>
					<?php endforeach; ?>
					<p class="ep-error-message" data-wp-show="<?php echo esc_attr( $error_path ); ?>" data-wp-text="<?php echo esc_attr( $error_path ); ?>"></p>
				</fieldset>
				<?php
				break;

			case 'rating':
				$min = isset( $rules['min'] ) ? max( 1, (int) $rules['min'] ) : 1;
				$max = isset( $rules['max'] ) ? max( $min, (int) $rules['max'] ) : 5;
				?>
				<fieldset class="ep-field-wrapper ep-form-row ep-choice-group">
					<legend class="ep-label"><?php echo esc_html( $field_label ); ?></legend>
					<?php for ( $score = $min; $score <= $max; $score++ ) : ?>
						<label class="ep-choice" for="<?php echo esc_attr( $field_id . '-score-' . $score ); ?>">
							<input
								type="radio"
								id="<?php echo esc_attr( $field_id . '-score-' . $score ); ?>"
								name="<?php echo esc_attr( $field_name ); ?>"
								value="<?php echo esc_attr( (string) $score ); ?>"
								data-ep-field="<?php echo esc_attr( $field_name ); ?>"
								data-wp-bind--value="<?php echo esc_attr( $value_path ); ?>"
								data-wp-on--input="actions.updateValue"
								data-wp-class--ep-error="<?php echo esc_attr( $error_path ); ?>"
								<?php echo ( $is_required && $score === $min ) ? 'required' : ''; ?>
							/>
							<span><?php echo esc_html( str_repeat( '★', $score ) ); ?></span>
						</label>
					<?php endfor; ?>
					<p class="ep-error-message" data-wp-show="<?php echo esc_attr( $error_path ); ?>" data-wp-text="<?php echo esc_attr( $error_path ); ?>"></p>
				</fieldset>
				<?php
				break;

			case 'textarea':
				?>
				<div class="ep-field-wrapper ep-form-row">
					<label class="ep-label" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field_label ); ?></label>
					<textarea
						id="<?php echo esc_attr( $field_id ); ?>"
						name="<?php echo esc_attr( $field_name ); ?>"
						placeholder="<?php echo esc_attr( $field_placeholder ); ?>"
						<?php echo $is_required ? 'required' : ''; ?>
						data-ep-field="<?php echo esc_attr( $field_name ); ?>"
						data-wp-bind--value="<?php echo esc_attr( $value_path ); ?>"
						data-wp-on--input="actions.updateValue"
						data-wp-class--ep-error="<?php echo esc_attr( $error_path ); ?>"
					></textarea>
					<p class="ep-error-message" data-wp-show="<?php echo esc_attr( $error_path ); ?>" data-wp-text="<?php echo esc_attr( $error_path ); ?>"></p>
				</div>
				<?php
				break;

			case 'select':
				?>
				<div class="ep-field-wrapper ep-form-row">
					<label class="ep-label" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field_label ); ?></label>
					<select
						id="<?php echo esc_attr( $field_id ); ?>"
						name="<?php echo esc_attr( $field_name ); ?>"
						<?php echo $is_required ? 'required' : ''; ?>
						data-ep-field="<?php echo esc_attr( $field_name ); ?>"
						data-wp-bind--value="<?php echo esc_attr( $value_path ); ?>"
						data-wp-on--input="actions.updateValue"
						data-wp-class--ep-error="<?php echo esc_attr( $error_path ); ?>"
					>
						<option value=""><?php esc_html_e( 'Select an option', 'enterprise-forms' ); ?></option>
						<?php foreach ( $options as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $option ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="ep-error-message" data-wp-show="<?php echo esc_attr( $error_path ); ?>" data-wp-text="<?php echo esc_attr( $error_path ); ?>"></p>
				</div>
				<?php
				break;

			case 'radio':
				?>
				<fieldset class="ep-field-wrapper ep-form-row ep-choice-group">
					<legend class="ep-label"><?php echo esc_html( $field_label ); ?></legend>
					<?php foreach ( $options as $option_index => $option ) : ?>
						<label class="ep-choice" for="<?php echo esc_attr( $field_id . '-' . $option_index ); ?>">
							<input
								type="radio"
								id="<?php echo esc_attr( $field_id . '-' . $option_index ); ?>"
								name="<?php echo esc_attr( $field_name ); ?>"
								value="<?php echo esc_attr( $option ); ?>"
								data-ep-field="<?php echo esc_attr( $field_name ); ?>"
								data-wp-bind--value="<?php echo esc_attr( $value_path ); ?>"
								data-wp-on--input="actions.updateValue"
								data-wp-class--ep-error="<?php echo esc_attr( $error_path ); ?>"
								<?php echo ( $is_required && 0 === $option_index ) ? 'required' : ''; ?>
							/>
							<span><?php echo esc_html( $option ); ?></span>
						</label>
					<?php endforeach; ?>
					<p class="ep-error-message" data-wp-show="<?php echo esc_attr( $error_path ); ?>" data-wp-text="<?php echo esc_attr( $error_path ); ?>"></p>
				</fieldset>
				<?php
				break;

			case 'checkbox':
			case 'consent':
				?>
				<div class="ep-field-wrapper ep-form-row ep-check-row">
					<label class="ep-choice" for="<?php echo esc_attr( $field_id ); ?>">
						<input
							type="checkbox"
							id="<?php echo esc_attr( $field_id ); ?>"
							name="<?php echo esc_attr( $field_name ); ?>"
							value="1"
							<?php echo $is_required ? 'required' : ''; ?>
							data-ep-field="<?php echo esc_attr( $field_name ); ?>"
							data-wp-bind--value="<?php echo esc_attr( $value_path ); ?>"
							data-wp-on--input="actions.updateValue"
							data-wp-class--ep-error="<?php echo esc_attr( $error_path ); ?>"
						/>
						<span><?php echo esc_html( $field_label ); ?></span>
					</label>
					<p class="ep-error-message" data-wp-show="<?php echo esc_attr( $error_path ); ?>" data-wp-text="<?php echo esc_attr( $error_path ); ?>"></p>
				</div>
				<?php
				break;

			case 'payment':
				$currency = strtoupper( sanitize_text_field( (string) ( $field['currency'] ?? 'USD' ) ) );
				$gateway = sanitize_key( (string) ( $field['gateway'] ?? 'stripe' ) );
				$amount_source = sanitize_key( (string) ( $field['amount_source'] ?? 'static' ) );
				$amount_label = 'field' === $amount_source
					? __( 'Calculated from your selection', 'enterprise-forms' )
					: sanitize_text_field( (string) ( $field['amount'] ?? '' ) ) . ' ' . $currency;
				?>
				<div class="ep-form-row ep-payment-row" data-ep-payment-block>
					<div class="ep-label"><?php esc_html_e( 'Payment', 'enterprise-forms' ); ?></div>
					<p class="ep-payment-summary"><?php echo esc_html( $amount_label ); ?></p>
					<div class="ep-payment-container ep-<?php echo esc_attr( $gateway ); ?>-payment-element" data-ep-payment-container data-ep-gateway="<?php echo esc_attr( $gateway ); ?>"></div>
					<input type="hidden" name="payment_token" data-ep-field="payment_token" data-wp-bind--value="context.values.payment_token" />
					<input type="hidden" name="payment_intent_id" data-ep-field="payment_intent_id" data-wp-bind--value="context.values.payment_intent_id" />
					<p class="ep-error-message" data-wp-show="context.errors.payment_intent_id" data-wp-text="context.errors.payment_intent_id"></p>
				</div>
				<?php
				break;

			case 'submit':
				$button_text = sanitize_text_field( (string) ( $field['button_text'] ?? __( 'Submit', 'enterprise-forms' ) ) );
				?>
				<div class="ep-form-row ep-submit-row">
					<button
						type="submit"
						class="ep-submit-button"
						data-wp-bind--disabled="context.isSubmitting"
						data-wp-class--is-submitting="context.isSubmitting"
					>
						<span data-wp-bind--hidden="context.isSubmitting"><?php echo esc_html( $button_text ); ?></span>
						<span data-wp-bind--hidden="!context.isSubmitting"><?php esc_html_e( 'Submitting...', 'enterprise-forms' ); ?></span>
					</button>
				</div>
				<?php
				break;

			case 'hidden':
				$hidden_value = sanitize_text_field( (string) ( $field['value'] ?? '' ) );
				?>
				<input
					type="hidden"
					id="<?php echo esc_attr( $field_id ); ?>"
					name="<?php echo esc_attr( $field_name ); ?>"
					value="<?php echo esc_attr( $hidden_value ); ?>"
					data-ep-field="<?php echo esc_attr( $field_name ); ?>"
					data-wp-bind--value="<?php echo esc_attr( $value_path ); ?>"
					data-wp-on--input="actions.updateValue"
					data-wp-class--ep-error="<?php echo esc_attr( $error_path ); ?>"
				/>
				<?php
				break;

			case 'file':
				$accept = isset( $rules['accept'] ) ? sanitize_text_field( (string) $rules['accept'] ) : '';
				$max_size_bytes = isset( $rules['max_size_mb'] ) ? max( 1, (int) $rules['max_size_mb'] ) * 1024 * 1024 : 10 * 1024 * 1024;
				?>
				<div class="ep-form-row ep-file-upload-field">
					<label class="ep-label" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field_label ); ?></label>
					<input
						type="file"
						id="<?php echo esc_attr( $field_id ); ?>"
						name="<?php echo esc_attr( $field_name ); ?>"
						data-ep-upload-field
						<?php if ( '' !== $accept ) : ?>accept="<?php echo esc_attr( $accept ); ?>"<?php endif; ?>
						data-max-size="<?php echo esc_attr( (string) $max_size_bytes ); ?>"
						<?php echo ! empty( $field['multiple'] ) ? 'multiple' : ''; ?>
						<?php echo $is_required ? 'required' : ''; ?>
						data-ep-field="<?php echo esc_attr( $field_name ); ?>"
						data-wp-on--change="actions.handleFileSelect"
						data-wp-class--ep-error="<?php echo esc_attr( $error_path ); ?>"
					/>
					<div class="ep-upload-progress" data-wp-bind--hidden="!context.uploadProgress.active">
						<p><span data-wp-text="context.uploadProgress.fileName"></span></p>
						<div class="ep-form-progress-track">
							<div class="ep-form-progress-fill" data-wp-bind--style--width="context.uploadProgress.percentage + '%' "></div>
						</div>
					</div>
					<p class="ep-error-message" data-wp-show="<?php echo esc_attr( $error_path ); ?>" data-wp-text="<?php echo esc_attr( $error_path ); ?>"></p>
				</div>
				<?php
				break;

			default:
				$input_type = $this->resolve_input_type( $field_type );
				$date_min   = isset( $rules['min_date'] ) ? sanitize_text_field( (string) $rules['min_date'] ) : '';
				$date_max   = isset( $rules['max_date'] ) ? sanitize_text_field( (string) $rules['max_date'] ) : '';
				?>
				<div class="ep-form-row">
					<label class="ep-label" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field_label ); ?></label>
					<input
						type="<?php echo esc_attr( $input_type ); ?>"
						id="<?php echo esc_attr( $field_id ); ?>"
						name="<?php echo esc_attr( $field_name ); ?>"
						placeholder="<?php echo esc_attr( $field_placeholder ); ?>"
						<?php if ( 'date' === $field_type && '' !== $date_min ) : ?>min="<?php echo esc_attr( $date_min ); ?>"<?php endif; ?>
						<?php if ( 'date' === $field_type && '' !== $date_max ) : ?>max="<?php echo esc_attr( $date_max ); ?>"<?php endif; ?>
						<?php echo $is_required ? 'required' : ''; ?>
						data-ep-field="<?php echo esc_attr( $field_name ); ?>"
						data-wp-bind--value="<?php echo esc_attr( $value_path ); ?>"
						data-wp-on--input="actions.updateValue"
						data-wp-class--ep-error="<?php echo esc_attr( $error_path ); ?>"
					/>
					<p class="ep-error-message" data-wp-show="<?php echo esc_attr( $error_path ); ?>" data-wp-text="<?php echo esc_attr( $error_path ); ?>"></p>
				</div>
				<?php
				break;
		}

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array<int, string>
	 */
	private function extract_options( array $field ): array {
		$raw_options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
		$options     = [];

		foreach ( $raw_options as $option ) {
			if ( is_string( $option ) ) {
				$value = sanitize_text_field( $option );
				if ( '' !== $value ) {
					$options[] = $value;
				}
				continue;
			}

			if ( is_array( $option ) ) {
				$value = (string) ( $option['value'] ?? $option['label'] ?? '' );
				$value = sanitize_text_field( $value );
				if ( '' !== $value ) {
					$options[] = $value;
				}
			}
		}

		return $options;
	}

	private function resolve_input_type( string $field_type ): string {
		if ( in_array( $field_type, [ 'email', 'number', 'url', 'tel', 'date', 'time' ], true ) ) {
			return $field_type;
		}

		if ( 'phone' === $field_type ) {
			return 'tel';
		}

		return 'text';
	}
}
