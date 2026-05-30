import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import './style.scss';

interface EpFormPost {
	id: number;
	title?: {
		rendered?: string;
	};
}

registerBlockType( 'enterprise-forms/renderer', {
	attributes: {
		formId: {
			type: 'number',
			default: 0,
		},
	},
	edit: ( { attributes, setAttributes }: { attributes: { formId?: number }; setAttributes: ( attrs: { formId: number } ) => void } ) => {
		const blockProps = useBlockProps();
		const formId = attributes.formId ?? 0;

		const forms = useSelect( ( select: unknown ) => {
			const core = ( select as ( storeName: string ) => unknown )( 'core' ) as {
				getEntityRecords: (
					kind: string,
					name: string,
					query?: Record< string, unknown >
				) => EpFormPost[] | null;
			};

			return core.getEntityRecords( 'postType', 'ep_form', {
				per_page: -1,
				orderby: 'title',
				order: 'asc',
				status: [ 'publish', 'draft', 'private' ],
			} );
		}, [] );

		const formOptions = [
			{ label: __( 'Select a form', 'enterprise-forms' ), value: '0' },
			...( forms || [] ).map( ( form: EpFormPost ) => {
				const title = form.title?.rendered?.trim() || __( '(Untitled form)', 'enterprise-forms' );
				return {
					label: `${ title } (ID: ${ form.id })`,
					value: String( form.id ),
				};
			} ),
		];

		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody title={ __( 'Renderer Settings', 'enterprise-forms' ) } initialOpen>
						<SelectControl
							label={ __( 'Form', 'enterprise-forms' ) }
							value={ String( formId ) }
							options={ formOptions }
							onChange={ ( value ) => setAttributes( { formId: Number( value ) || 0 } ) }
							help={ __( 'Choose a saved form by title and ID.', 'enterprise-forms' ) }
						/>
					</PanelBody>
				</InspectorControls>

				<p>{ __( 'Enterprise Form Renderer (Schema Preview)', 'enterprise-forms' ) }</p>
				<p>
					{ __( 'Connected Form ID:', 'enterprise-forms' ) } <strong>{ formId }</strong>
				</p>
				<p>{ __( 'Frontend output is rendered from ep_form_schema meta.', 'enterprise-forms' ) }</p>
			</div>
		);
	},
} );
