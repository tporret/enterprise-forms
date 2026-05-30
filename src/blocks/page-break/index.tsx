import { InspectorControls } from '@wordpress/block-editor';
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

interface PageBreakAttributes {
	title?: string;
	description?: string;
	pageNumber?: number;
}

interface PageBreakProps {
	attributes: PageBreakAttributes;
	setAttributes: ( attrs: PageBreakAttributes ) => void;
}

export default function PageBreakEdit( {
	attributes,
	setAttributes,
}: PageBreakProps ): JSX.Element {
	return (
		<>
			<InspectorControls>
				<div style={ { padding: '12px' } }>
					<TextControl
						label={ __( 'Step Title', 'enterprise-forms' ) }
						value={ attributes.title || '' }
						onChange={ ( title ) => setAttributes( { ...attributes, title } ) }
						help={ __( 'Optional title for this page of the form.', 'enterprise-forms' ) }
					/>
					<TextControl
						label={ __( 'Step Description', 'enterprise-forms' ) }
						value={ attributes.description || '' }
						onChange={ ( description ) => setAttributes( { ...attributes, description } ) }
						help={ __( 'Optional description or instructions for this step.', 'enterprise-forms' ) }
					/>
				</div>
			</InspectorControls>

			<div
				style={ {
					padding: '20px',
					margin: '20px 0',
					textAlign: 'center',
					borderTop: '3px solid #999',
					backgroundColor: '#f5f5f5',
					borderRadius: '4px',
				} }
			>
				<p style={ { margin: 0, fontSize: '14px', fontWeight: 'bold', color: '#555' } }>
					{ __( '📄 Page Break', 'enterprise-forms' ) }
				</p>
				{ attributes.title && (
					<p style={ { margin: '8px 0 0 0', fontSize: '12px', color: '#888' } }>
						{ attributes.title }
					</p>
				) }
			</div>
		</>
	);
}
