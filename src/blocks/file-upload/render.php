<?php
/**
 * File Upload Block Render
 *
 * @package enterprise-forms
 * @var array $attributes Block attributes.
 * @var string $content Block content.
 * @var WP_Block $block Block object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$label = isset( $attributes['label'] ) ? wp_kses_post( $attributes['label'] ) : __( 'Upload Files', 'enterprise-forms' );
$name = isset( $attributes['name'] ) ? sanitize_key( $attributes['name'] ) : 'file_upload';
$required = isset( $attributes['required'] ) ? (bool) $attributes['required'] : false;
$multiple = isset( $attributes['multiple'] ) ? (bool) $attributes['multiple'] : false;
$accepted_types = isset( $attributes['acceptedFileTypes'] ) && is_array( $attributes['acceptedFileTypes'] ) ? $attributes['acceptedFileTypes'] : [ 'pdf', 'doc', 'jpg' ];
$max_size = isset( $attributes['maxFileSize'] ) ? (int) $attributes['maxFileSize'] : 10485760;

// Build accept attribute from file types
$accept_mime_types = array(
	'pdf'  => 'application/pdf',
	'doc'  => 'application/msword',
	'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'xls'  => 'application/vnd.ms-excel',
	'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'jpg'  => 'image/jpeg',
	'jpeg' => 'image/jpeg',
	'png'  => 'image/png',
	'gif'  => 'image/gif',
	'txt'  => 'text/plain',
	'mp4'  => 'video/mp4',
	'mov'  => 'video/quicktime',
);

$accept_attr = '';
foreach ( $accepted_types as $type ) {
	$type = strtolower( $type );
	if ( isset( $accept_mime_types[ $type ] ) ) {
		$accept_attr .= $accept_mime_types[ $type ] . ',';
	}
}
$accept_attr = rtrim( $accept_attr, ',' );

?>
<div class="ep-file-upload-field">
	<label for="<?php echo esc_attr( $name ); ?>" class="ep-field-label">
		<?php echo wp_kses_post( $label ); ?>
		<?php if ( $required ) : ?>
			<span class="ep-required" aria-label="<?php esc_attr_e( 'required', 'enterprise-forms' ); ?>">*</span>
		<?php endif; ?>
	</label>

	<div class="ep-dropzone" 
		data-wp-interactive="enterpriseForms"
		data-wp-on--drop="actions.handleFileDrop"
		data-wp-on--dragover="actions.handleDragOver"
		data-wp-on--dragleave="actions.handleDragLeave"
		data-wp-bind--class="context.dropzoneActive ? 'ep-dropzone-active' : ''"
		style="border: 2px dashed #ccc; padding: 30px; text-align: center; border-radius: 4px; cursor: pointer; transition: all 0.3s ease;">

		<p style="margin: 0 0 10px 0; font-weight: bold; color: #333;">
			<?php esc_html_e( 'Drag files here or click to select', 'enterprise-forms' ); ?>
		</p>

		<input
			type="file"
			id="<?php echo esc_attr( $name ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			data-ep-field="<?php echo esc_attr( $name ); ?>"
			data-ep-upload-field
			accept="<?php echo esc_attr( $accept_attr ); ?>"
			data-max-size="<?php echo esc_attr( (string) $max_size ); ?>"
			<?php if ( $multiple ) : ?>multiple<?php endif; ?>
			<?php if ( $required ) : ?>required<?php endif; ?>
			data-wp-on--change="actions.handleFileSelect"
			style="display: none;"
		/>

		<p style="margin: 8px 0; font-size: 12px; color: #666;">
			<?php
			/* translators: %s: max file size in MB */
			printf( esc_html__( 'Max file size: %s', 'enterprise-forms' ), esc_html( size_format( $max_size ) ) );
			?>
		</p>
		<p style="margin: 4px 0; font-size: 12px; color: #666;">
			<?php echo esc_html( implode( ', ', array_map( 'strtoupper', $accepted_types ) ) ); ?>
		</p>
	</div>

	<!-- File Upload Progress Bar -->
	<div class="ep-upload-progress" 
		data-wp-bind--hidden="!context.uploadProgress.active"
		style="margin-top: 12px; display: none;">
		<p style="margin: 0 0 8px 0; font-size: 12px; font-weight: bold;">
			<span data-wp-text="context.uploadProgress.fileName"></span>
		</p>
		<div style="width: 100%; height: 24px; background: #e0e0e0; border-radius: 4px; overflow: hidden;">
			<div class="ep-progress-bar"
				data-wp-bind--style--width="context.uploadProgress.percentage + '%'"
				style="height: 100%; background: #4CAF50; transition: width 0.3s ease; width: 0%;"></div>
		</div>
		<p style="margin: 4px 0 0 0; font-size: 11px; color: #666;">
			<span data-wp-text="context.uploadProgress.percentage"></span>%
		</p>
	</div>

	<!-- Uploaded Files List -->
	<div class="ep-uploaded-files"
		data-wp-bind--hidden="context.uploadedFiles.length === 0"
		style="margin-top: 12px;">
		<p style="margin: 0 0 8px 0; font-size: 12px; font-weight: bold;">
			<?php esc_html_e( 'Uploaded Files:', 'enterprise-forms' ); ?>
		</p>
		<ul style="list-style: none; padding: 0; margin: 0;">
			<template data-wp-each--file="context.uploadedFiles">
				<li style="padding: 6px; background: #f0f0f0; margin-bottom: 4px; border-radius: 3px; display: flex; justify-content: space-between; align-items: center;">
					<span data-wp-text="context.file.name" style="font-size: 12px; flex: 1;"></span>
					<button type="button"
						data-wp-on--click="actions.removeUploadedFile"
						data-file-id="context.file.id"
						style="background: none; border: none; color: red; cursor: pointer; font-weight: bold;"
						aria-label="<?php esc_attr_e( 'Remove file', 'enterprise-forms' ); ?>">
						✕
					</button>
				</li>
			</template>
		</ul>
	</div>

	<!-- Error Message -->
	<div class="ep-field-error"
		data-wp-bind--hidden="!context.errors.<?php echo esc_attr( $name ); ?>"
		style="color: #d32f2f; font-size: 12px; margin-top: 4px; display: none;">
		<span data-wp-text="context.errors.<?php echo esc_attr( $name ); ?>"></span>
	</div>
</div>

<style>
	.ep-dropzone {
		transition: all 0.3s ease;
	}

	.ep-dropzone-active {
		background-color: #e3f2fd !important;
		border-color: #2196F3 !important;
		box-shadow: 0 0 8px rgba(33, 150, 243, 0.3);
	}

	.ep-progress-bar {
		transition: width 0.3s ease;
	}

	.ep-required {
		color: #d32f2f;
		font-weight: bold;
		margin-left: 4px;
	}
</style>
