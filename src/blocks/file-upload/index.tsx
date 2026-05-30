import { InspectorControls } from '@wordpress/block-editor';
import {
	TextControl,
	TextareaControl,
	ToggleControl,
	Button,
	Card,
	CardBody,
	CardHeader,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

interface FileUploadAttributes {
	label?: string;
	name?: string;
	required?: boolean;
	acceptedFileTypes?: string[];
	maxFileSize?: number;
	multiple?: boolean;
}

interface FileUploadProps {
	attributes: FileUploadAttributes;
	setAttributes: ( attrs: FileUploadAttributes ) => void;
}

const COMMON_FILE_TYPES = [
	{ label: 'PDF', value: 'pdf' },
	{ label: 'Word (.doc, .docx)', value: 'doc' },
	{ label: 'Excel (.xls, .xlsx)', value: 'xls' },
	{ label: 'Image (.jpg, .png, .gif)', value: 'jpg' },
	{ label: 'Text (.txt)', value: 'txt' },
	{ label: 'Video (.mp4, .mov)', value: 'mp4' },
];

export default function FileUploadEdit( {
	attributes,
	setAttributes,
}: FileUploadProps ): JSX.Element {
	const [ newFileType, setNewFileType ] = useState( '' );
	const acceptedTypes = attributes.acceptedFileTypes || [ 'pdf', 'doc', 'jpg' ];

	const addFileType = (): void => {
		if ( newFileType && ! acceptedTypes.includes( newFileType ) ) {
			setAttributes( {
				...attributes,
				acceptedFileTypes: [ ...acceptedTypes, newFileType ],
			} );
			setNewFileType( '' );
		}
	};

	const removeFileType = ( typeToRemove: string ): void => {
		setAttributes( {
			...attributes,
			acceptedFileTypes: acceptedTypes.filter( ( t ) => t !== typeToRemove ),
		} );
	};

	const formatBytes = ( bytes: number ): string => {
		if ( bytes === 0 ) return '0 Bytes';
		const k = 1024;
		const sizes = [ 'Bytes', 'KB', 'MB', 'GB' ];
		const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
		return Math.round( ( bytes / Math.pow( k, i ) ) * 100 ) / 100 + ' ' + sizes[ i ];
	};

	return (
		<>
			<InspectorControls>
				<div style={ { padding: '12px' } }>
					<Card>
						<CardHeader>
							<strong>{ __( 'File Upload Settings', 'enterprise-forms' ) }</strong>
						</CardHeader>
						<CardBody>
							<TextControl
								label={ __( 'Field Label', 'enterprise-forms' ) }
								value={ attributes.label || '' }
								onChange={ ( label ) =>
									setAttributes( { ...attributes, label } )
								}
							/>

							<TextControl
								label={ __( 'Field Name', 'enterprise-forms' ) }
								value={ attributes.name || '' }
								onChange={ ( name ) =>
									setAttributes( { ...attributes, name } )
								}
							/>

							<ToggleControl
								label={ __( 'Required', 'enterprise-forms' ) }
								checked={ attributes.required || false }
								onChange={ ( required ) =>
									setAttributes( { ...attributes, required } )
								}
							/>

							<ToggleControl
								label={ __( 'Allow Multiple Files', 'enterprise-forms' ) }
								checked={ attributes.multiple || false }
								onChange={ ( multiple ) =>
									setAttributes( { ...attributes, multiple } )
								}
							/>

							<div style={ { marginTop: '12px' } }>
								<label style={ { display: 'block', fontWeight: 'bold', marginBottom: '8px' } }>
									{ __( 'Max File Size', 'enterprise-forms' ) }
								</label>
								<select
									value={ attributes.maxFileSize || 10485760 }
									onChange={ ( e ) =>
										setAttributes( {
											...attributes,
											maxFileSize: Number( e.target.value ),
										} )
									}
									style={ { width: '100%', padding: '8px' } }
								>
									<option value={ 5242880 }>
										{ formatBytes( 5242880 ) } (5 MB)
									</option>
									<option value={ 10485760 }>
										{ formatBytes( 10485760 ) } (10 MB)
									</option>
									<option value={ 52428800 }>
										{ formatBytes( 52428800 ) } (50 MB)
									</option>
									<option value={ 104857600 }>
										{ formatBytes( 104857600 ) } (100 MB)
									</option>
								</select>
							</div>

							<div style={ { marginTop: '12px' } }>
								<label style={ { display: 'block', fontWeight: 'bold', marginBottom: '8px' } }>
									{ __( 'Accepted File Types', 'enterprise-forms' ) }
								</label>
								<div style={ { marginBottom: '8px' } }>
									{ acceptedTypes.map( ( type ) => (
										<div
											key={ type }
											style={ {
												display: 'flex',
												justifyContent: 'space-between',
												alignItems: 'center',
												padding: '6px',
												marginBottom: '4px',
												backgroundColor: '#f0f0f0',
												borderRadius: '3px',
											} }
										>
											<span style={ { fontSize: '12px' } }>{ type.toUpperCase() }</span>
											<button
												type="button"
												onClick={ () => removeFileType( type ) }
												style={ {
													backgroundColor: 'transparent',
													border: 'none',
													color: 'red',
													cursor: 'pointer',
													fontSize: '16px',
													padding: 0,
												} }
											>
												✕
											</button>
										</div>
									) ) }
								</div>
								<div style={ { display: 'flex', gap: '8px' } }>
									<input
										type="text"
										value={ newFileType }
										onChange={ ( e ) => setNewFileType( e.target.value ) }
										placeholder={ __( 'e.g., "pdf", "jpg"', 'enterprise-forms' ) }
										style={ { flex: 1, padding: '6px' } }
									/>
									<Button
										variant="secondary"
										onClick={ addFileType }
										size="small"
									>
										{ __( 'Add', 'enterprise-forms' ) }
									</Button>
								</div>
								<p style={ { fontSize: '12px', color: '#999', marginTop: '8px' } }>
									{ __( 'Common: pdf, doc, docx, xls, jpg, png, gif, txt, mp4', 'enterprise-forms' ) }
								</p>
							</div>
						</CardBody>
					</Card>
				</div>
			</InspectorControls>

			<div
				style={ {
					padding: '20px',
					margin: '10px 0',
					border: '2px dashed #ccc',
					borderRadius: '4px',
					backgroundColor: '#f9f9f9',
					textAlign: 'center',
				} }
			>
				<p style={ { margin: '0 0 10px 0', fontSize: '16px', fontWeight: 'bold' } }>
					📤 { attributes.label || 'Upload Files' }
				</p>
				<p style={ { margin: 0, fontSize: '12px', color: '#999' } }>
					{ attributes.required ? __( '(Required)', 'enterprise-forms' ) : __( '(Optional)', 'enterprise-forms' ) }
				</p>
				<p style={ { margin: '8px 0 0 0', fontSize: '11px', color: '#999' } }>
					{ __( 'Max: ', 'enterprise-forms' ) }
					{ formatBytes( attributes.maxFileSize || 10485760 ) }
				</p>
				{ acceptedTypes.length > 0 && (
					<p style={ { margin: '4px 0 0 0', fontSize: '11px', color: '#999' } }>
						{ __( 'Allowed: ', 'enterprise-forms' ) }
						{ acceptedTypes.map( ( t ) => t.toUpperCase() ).join( ', ' ) }
					</p>
				) }
			</div>
		</>
	);
}
