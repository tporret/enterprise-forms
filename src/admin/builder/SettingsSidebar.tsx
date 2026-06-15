import { store as blockEditorStore } from '@wordpress/block-editor';
import apiFetch from '@wordpress/api-fetch';
import { useDispatch, useSelect } from '@wordpress/data';
import { Button, Card, CardBody, CardHeader, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

interface SelectedBlockInfo {
	clientId: string;
	name: string;
	attributes: Record< string, unknown >;
}

interface CanvasBlockInfo {
	clientId: string;
	name: string;
	attributes: Record< string, unknown >;
}

type PaymentGateway = 'stripe' | 'braintree' | 'paypal' | 'square';

interface PaymentSettingsResponse {
	gateways?: Record< string, { label: string; configured: boolean; implemented?: boolean } >;
}

type UpdateBlockAttributes = ( clientId: string, attrs: Record< string, unknown > ) => void;
type RemoveBlock = ( clientId: string, selectPrevious?: boolean ) => void;

const PAYMENT_GATEWAY_LABELS: Record< PaymentGateway, string > = {
	stripe: 'Stripe',
	braintree: 'Braintree',
	paypal: 'PayPal',
	square: 'Square',
};

const useGatewayOptions = (): Array< { label: string; value: PaymentGateway } > => {
	const [ options, setOptions ] = useState< Array< { label: string; value: PaymentGateway } > >( [ { label: 'Stripe', value: 'stripe' } ] );

	useEffect( () => {
		let cancelled = false;

		apiFetch< PaymentSettingsResponse >( { path: '/enterprise-forms/v1/payments/settings' } )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				const configured = Object.entries( response.gateways || {} )
					.filter( ( [ , gateway ] ) => gateway.configured && gateway.implemented !== false )
					.map( ( [ value, gateway ] ) => ( {
						label: gateway.label || PAYMENT_GATEWAY_LABELS[ value as PaymentGateway ] || value,
						value: value as PaymentGateway,
					} ) );

				setOptions( configured.length > 0 ? configured : [ { label: 'Stripe', value: 'stripe' } ] );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setOptions( [ { label: 'Stripe', value: 'stripe' } ] );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	return options;
};

// Inline options builder used for Select and Radio blocks.
const OptionsEditor = ( {
	options,
	onChange,
}: {
	options: string[];
	onChange: ( opts: string[] ) => void;
} ): JSX.Element => {
	const [ draft, setDraft ] = useState( '' );

	const update = ( index: number, value: string ): void => {
		onChange( options.map( ( opt, i ) => ( i === index ? value : opt ) ) );
	};

	const remove = ( index: number ): void => {
		onChange( options.filter( ( _, i ) => i !== index ) );
	};

	const addDraft = (): void => {
		const trimmed = draft.trim();
		if ( trimmed ) {
			onChange( [ ...options, trimmed ] );
			setDraft( '' );
		}
	};

	return (
		<div className="mb-3">
			<p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
				{ __( 'Options', 'enterprise-forms' ) }
			</p>
			{ options.map( ( opt, i ) => (
				<div key={ i } className="mb-1 flex items-center gap-1">
					<input
						type="text"
						value={ opt }
						onChange={ ( e ) => update( i, e.target.value ) }
						className="min-w-0 flex-1 rounded border border-slate-300 px-2 py-1 text-sm"
					/>
					<button
						type="button"
						onClick={ () => remove( i ) }
						className="shrink-0 rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-500"
						aria-label={ __( 'Remove option', 'enterprise-forms' ) }
					>
						&#10005;
					</button>
				</div>
			) ) }
			<div className="mt-1 flex items-center gap-1">
				<input
					type="text"
					value={ draft }
					onChange={ ( e ) => setDraft( e.target.value ) }
					onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); addDraft(); } } }
					placeholder={ __( 'New option…', 'enterprise-forms' ) }
					className="min-w-0 flex-1 rounded border border-dashed border-slate-300 px-2 py-1 text-sm placeholder:text-slate-400"
				/>
				<button
					type="button"
					onClick={ addDraft }
					className="shrink-0 rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-100"
				>
					{ __( '+ Add', 'enterprise-forms' ) }
				</button>
			</div>
		</div>
	);
};

const SettingsSidebar = (): JSX.Element => {
	const gatewayOptions = useGatewayOptions();
	const selectedBlock = useSelect( ( select: ( store: unknown ) => Record< string, unknown > ) => {
		const editorStore = select( blockEditorStore );
		const getSelectedBlockClientId = editorStore.getSelectedBlockClientId as undefined | (() => string | null);
		const getBlock = editorStore.getBlock as undefined | (( clientId: string ) => { name?: string; attributes?: Record< string, unknown > });

		if ( ! getSelectedBlockClientId || ! getBlock ) {
			return null;
		}

		const clientId = getSelectedBlockClientId();
		if ( ! clientId ) {
			return null;
		}

		const block = getBlock( clientId );
		if ( ! block ) {
			return null;
		}

		return {
			clientId,
			name: block.name || '',
			attributes: block.attributes || {},
		} as SelectedBlockInfo;
	}, [] ) as SelectedBlockInfo | null;

	const { updateBlockAttributes, removeBlock } = useDispatch( blockEditorStore ) as {
		updateBlockAttributes: UpdateBlockAttributes;
		removeBlock: RemoveBlock;
	};

	const allBlocks = useSelect( ( select: ( store: unknown ) => Record< string, unknown > ) => {
		const editorStore = select( blockEditorStore );
		const getBlocks = editorStore.getBlocks as undefined | (() => Array< { clientId?: string; name?: string; attributes?: Record< string, unknown > } >);

		if ( ! getBlocks ) {
			return [];
		}

		return getBlocks().map( ( block ) => ( {
			clientId: block.clientId || '',
			name: block.name || '',
			attributes: block.attributes || {},
		} ) );
	}, [] ) as CanvasBlockInfo[];

	const updateAttribute = ( key: string, value: string | boolean | string[] ): void => {
		if ( ! selectedBlock ) {
			return;
		}

		updateBlockAttributes( selectedBlock.clientId, { [ key ]: value } );
	};

	const removeSelectedField = (): void => {
		if ( ! selectedBlock ) {
			return;
		}

		removeBlock( selectedBlock.clientId );
	};

	if ( ! selectedBlock ) {
		return (
			<Card>
				<CardHeader>
					<strong>{ __( 'Field Settings', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<p className="text-sm text-slate-600">{ __( 'Select a form block in the canvas to configure it.', 'enterprise-forms' ) }</p>
				</CardBody>
			</Card>
		);
	}

	const attrs = selectedBlock.attributes;

	if ( selectedBlock.name === 'ep/select' || selectedBlock.name === 'ep/radio' ) {
		const isSelect = selectedBlock.name === 'ep/select';
		const rawOptions = attrs.options;
		const options: string[] = Array.isArray( rawOptions )
			? ( rawOptions as unknown[] ).filter( ( s ): s is string => typeof s === 'string' )
			: typeof rawOptions === 'string'
			? ( rawOptions as string ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean )
			: [];
		return (
			<Card>
				<CardHeader>
					<strong>{ isSelect ? __( 'Select Settings', 'enterprise-forms' ) : __( 'Radio Settings', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Label', 'enterprise-forms' ) }
						value={ String( attrs.label ?? '' ) }
						onChange={ ( label ) => updateAttribute( 'label', label ) }
					/>
					<TextControl
						label={ __( 'Field Name', 'enterprise-forms' ) }
						value={ String( attrs.name ?? '' ) }
						onChange={ ( name ) => updateAttribute( 'name', name ) }
					/>
					<OptionsEditor
						options={ options }
						onChange={ ( opts ) => updateAttribute( 'options', opts ) }
					/>
					<ToggleControl
						label={ __( 'Required', 'enterprise-forms' ) }
						checked={ Boolean( attrs.required ) }
						onChange={ ( required ) => updateAttribute( 'required', required ) }
					/>
					<Button
						variant="secondary"
						onClick={ removeSelectedField }
						className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800"
					>
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	if ( selectedBlock.name === 'ep/checkbox-group' ) {
		const rawOptions = attrs.options;
		const options: string[] = Array.isArray( rawOptions )
			? ( rawOptions as unknown[] ).filter( ( s ): s is string => typeof s === 'string' )
			: typeof rawOptions === 'string'
			? ( rawOptions as string ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean )
			: [];

		return (
			<Card>
				<CardHeader>
					<strong>{ __( 'Multiple Choice Settings', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Label', 'enterprise-forms' ) }
						value={ String( attrs.label ?? '' ) }
						onChange={ ( label ) => updateAttribute( 'label', label ) }
					/>
					<TextControl
						label={ __( 'Field Name', 'enterprise-forms' ) }
						value={ String( attrs.name ?? '' ) }
						onChange={ ( name ) => updateAttribute( 'name', name ) }
					/>
					<OptionsEditor
						options={ options }
						onChange={ ( opts ) => updateAttribute( 'options', opts ) }
					/>
					<ToggleControl
						label={ __( 'Required', 'enterprise-forms' ) }
						checked={ Boolean( attrs.required ) }
						onChange={ ( required ) => updateAttribute( 'required', required ) }
					/>
					<Button
						variant="secondary"
						onClick={ removeSelectedField }
						className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800"
					>
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	if ( selectedBlock.name === 'ep/date' ) {
		return (
			<Card>
				<CardHeader>
					<strong>{ __( 'Date Settings', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Label', 'enterprise-forms' ) }
						value={ String( attrs.label ?? '' ) }
						onChange={ ( label ) => updateAttribute( 'label', label ) }
					/>
					<TextControl
						label={ __( 'Field Name', 'enterprise-forms' ) }
						value={ String( attrs.name ?? '' ) }
						onChange={ ( name ) => updateAttribute( 'name', name ) }
					/>
					<TextControl
						label={ __( 'Min Date (YYYY-MM-DD)', 'enterprise-forms' ) }
						value={ String( attrs.min ?? '' ) }
						onChange={ ( min ) => updateAttribute( 'min', min ) }
					/>
					<TextControl
						label={ __( 'Max Date (YYYY-MM-DD)', 'enterprise-forms' ) }
						value={ String( attrs.max ?? '' ) }
						onChange={ ( max ) => updateAttribute( 'max', max ) }
					/>
					<ToggleControl
						label={ __( 'Required', 'enterprise-forms' ) }
						checked={ Boolean( attrs.required ) }
						onChange={ ( required ) => updateAttribute( 'required', required ) }
					/>
					<Button
						variant="secondary"
						onClick={ removeSelectedField }
						className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800"
					>
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	if ( selectedBlock.name === 'ep/checkbox' || selectedBlock.name === 'ep/consent' ) {
		const header = selectedBlock.name === 'ep/consent'
			? __( 'Terms Consent', 'enterprise-forms' )
			: __( 'Checkbox Settings', 'enterprise-forms' );

		return (
			<Card>
				<CardHeader>
					<strong>{ header }</strong>
				</CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Label', 'enterprise-forms' ) }
						value={ String( attrs.label ?? '' ) }
						onChange={ ( label ) => updateAttribute( 'label', label ) }
					/>
					<TextControl
						label={ __( 'Field Name', 'enterprise-forms' ) }
						value={ String( attrs.name ?? '' ) }
						onChange={ ( name ) => updateAttribute( 'name', name ) }
					/>
					<TextControl
						label={ __( 'Checked Value', 'enterprise-forms' ) }
						value={ String( attrs.value ?? '1' ) }
						onChange={ ( value ) => updateAttribute( 'value', value ) }
					/>
					<ToggleControl
						label={ __( 'Required', 'enterprise-forms' ) }
						checked={ Boolean( attrs.required ) }
						onChange={ ( required ) => updateAttribute( 'required', required ) }
					/>
					<Button
						variant="secondary"
						onClick={ removeSelectedField }
						className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800"
					>
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	if ( selectedBlock.name === 'ep/hidden' ) {
		return (
			<Card>
				<CardHeader>
					<strong>{ __( 'Hidden Field', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Label', 'enterprise-forms' ) }
						value={ String( attrs.label ?? '' ) }
						onChange={ ( label ) => updateAttribute( 'label', label ) }
					/>
					<TextControl
						label={ __( 'Field Name', 'enterprise-forms' ) }
						value={ String( attrs.name ?? '' ) }
						onChange={ ( name ) => updateAttribute( 'name', name ) }
					/>
					<TextControl
						label={ __( 'Value', 'enterprise-forms' ) }
						value={ String( attrs.value ?? '' ) }
						onChange={ ( value ) => updateAttribute( 'value', value ) }
					/>
					<Button
						variant="secondary"
						onClick={ removeSelectedField }
						className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800"
					>
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	if ( selectedBlock.name === 'ep/rating' ) {
		return (
			<Card>
				<CardHeader>
					<strong>{ __( 'Rating Settings', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ String( attrs.label ?? '' ) } onChange={ ( label ) => updateAttribute( 'label', label ) } />
					<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ String( attrs.name ?? '' ) } onChange={ ( name ) => updateAttribute( 'name', name ) } />
					<TextControl label={ __( 'Min', 'enterprise-forms' ) } value={ String( attrs.min ?? '1' ) } onChange={ ( min ) => updateAttribute( 'min', min ) } />
					<TextControl label={ __( 'Max', 'enterprise-forms' ) } value={ String( attrs.max ?? '5' ) } onChange={ ( max ) => updateAttribute( 'max', max ) } />
					<TextControl label={ __( 'Step', 'enterprise-forms' ) } value={ String( attrs.step ?? '1' ) } onChange={ ( step ) => updateAttribute( 'step', step ) } />
					<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ Boolean( attrs.required ) } onChange={ ( required ) => updateAttribute( 'required', required ) } />
					<Button variant="secondary" onClick={ removeSelectedField } className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800">
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	if ( selectedBlock.name === 'ep/file' ) {
		return (
			<Card>
				<CardHeader>
					<strong>{ __( 'File Upload Settings', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ String( attrs.label ?? '' ) } onChange={ ( label ) => updateAttribute( 'label', label ) } />
					<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ String( attrs.name ?? '' ) } onChange={ ( name ) => updateAttribute( 'name', name ) } />
					<TextControl label={ __( 'Accepted Types (e.g. .pdf,image/*)', 'enterprise-forms' ) } value={ String( attrs.accept ?? '' ) } onChange={ ( accept ) => updateAttribute( 'accept', accept ) } />
					<TextControl label={ __( 'Max Size (MB)', 'enterprise-forms' ) } value={ String( attrs.maxSizeMb ?? '5' ) } onChange={ ( maxSizeMb ) => updateAttribute( 'maxSizeMb', maxSizeMb ) } />
					<ToggleControl label={ __( 'Allow Multiple Files', 'enterprise-forms' ) } checked={ Boolean( attrs.multiple ) } onChange={ ( multiple ) => updateAttribute( 'multiple', multiple ) } />
					<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ Boolean( attrs.required ) } onChange={ ( required ) => updateAttribute( 'required', required ) } />
					<Button variant="secondary" onClick={ removeSelectedField } className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800">
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	if ( selectedBlock.name === 'ep/file-upload' ) {
		const acceptedTypes = Array.isArray( attrs.acceptedFileTypes )
			? ( attrs.acceptedFileTypes as unknown[] ).filter( ( value ): value is string => typeof value === 'string' )
			: [];

		return (
			<Card>
				<CardHeader>
					<strong>{ __( 'Cloud File Upload', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ String( attrs.label ?? '' ) } onChange={ ( label ) => updateAttribute( 'label', label ) } />
					<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ String( attrs.name ?? '' ) } onChange={ ( name ) => updateAttribute( 'name', name ) } />
					<TextControl
						label={ __( 'Accepted Types (comma separated)', 'enterprise-forms' ) }
						value={ acceptedTypes.join( ',' ) }
						onChange={ ( value ) => updateAttribute( 'acceptedFileTypes', value.split( ',' ).map( ( item ) => item.trim() ).filter( Boolean ) ) }
					/>
					<TextControl
						label={ __( 'Max Size (bytes)', 'enterprise-forms' ) }
						value={ String( attrs.maxFileSize ?? '10485760' ) }
						onChange={ ( maxFileSize ) => updateAttribute( 'maxFileSize', maxFileSize ) }
					/>
					<ToggleControl label={ __( 'Allow Multiple Files', 'enterprise-forms' ) } checked={ Boolean( attrs.multiple ) } onChange={ ( multiple ) => updateAttribute( 'multiple', multiple ) } />
					<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ Boolean( attrs.required ) } onChange={ ( required ) => updateAttribute( 'required', required ) } />
					<Button variant="secondary" onClick={ removeSelectedField } className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800">
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	if ( selectedBlock.name === 'ep/page-break' ) {
		return (
			<Card>
				<CardHeader>
					<strong>{ __( 'Page Break', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<TextControl label={ __( 'Step Title', 'enterprise-forms' ) } value={ String( attrs.title ?? '' ) } onChange={ ( title ) => updateAttribute( 'title', title ) } />
					<TextControl label={ __( 'Step Description', 'enterprise-forms' ) } value={ String( attrs.description ?? '' ) } onChange={ ( description ) => updateAttribute( 'description', description ) } />
					<Button variant="secondary" onClick={ removeSelectedField } className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800">
						{ __( 'Remove Break', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	if ( selectedBlock.name === 'ep/submit' ) {
		return (
			<Card>
				<CardHeader>
					<strong>{ __( 'Submit Button', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Button Text', 'enterprise-forms' ) }
						value={ String( attrs.buttonText ?? 'Submit' ) }
						onChange={ ( buttonText ) => updateAttribute( 'buttonText', buttonText ) }
					/>
					<Button
						variant="secondary"
						onClick={ removeSelectedField }
						className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800"
					>
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	if ( selectedBlock.name === 'ep/payment-checkout' || selectedBlock.name === 'ep/stripe-checkout' ) {
		const amountSource = String( attrs.amountSource ?? 'static' ) === 'field' ? 'field' : 'static';
		const gateway = String( attrs.gateway ?? 'stripe' ) as PaymentGateway;
		const amountFieldOptions = allBlocks
			.filter( ( block ) => block.clientId !== selectedBlock.clientId )
			.filter( ( block ) => [ 'ep/select', 'ep/radio', 'ep/checkbox-group' ].includes( block.name ) )
			.map( ( block ) => {
				const name = String( block.attributes.name ?? '' ).trim();
				const label = String( block.attributes.label ?? name ).trim();

				return name ? { label: label || name, value: name } : null;
			} )
			.filter( ( option ): option is { label: string; value: string } => option !== null );

		return (
			<Card>
				<CardHeader>
					<strong>{ __( 'Payment Settings', 'enterprise-forms' ) }</strong>
				</CardHeader>
				<CardBody>
					<SelectControl
						label={ __( 'Gateway', 'enterprise-forms' ) }
						value={ gateway }
						options={ gatewayOptions }
						onChange={ ( nextGateway ) => updateAttribute( 'gateway', nextGateway ) }
					/>
					<SelectControl
						label={ __( 'Amount', 'enterprise-forms' ) }
						value={ amountSource }
						options={ [
							{ label: __( 'Static amount', 'enterprise-forms' ), value: 'static' },
							{ label: __( 'Map to field value', 'enterprise-forms' ), value: 'field' },
						] }
						onChange={ ( amountSource ) => updateAttribute( 'amountSource', amountSource ) }
					/>
					{ amountSource === 'field' ? (
						<SelectControl
							label={ __( 'Amount Field', 'enterprise-forms' ) }
							value={ String( attrs.amountField ?? '' ) }
							options={ [
								{ label: __( 'Select a field', 'enterprise-forms' ), value: '' },
								...amountFieldOptions,
							] }
							onChange={ ( amountField ) => updateAttribute( 'amountField', amountField ) }
						/>
					) : (
						<TextControl
							label={ __( 'Static Amount', 'enterprise-forms' ) }
							value={ String( attrs.amount ?? '0' ) }
							onChange={ ( amount ) => updateAttribute( 'amount', amount ) }
						/>
					) }
					<TextControl label={ __( 'Currency', 'enterprise-forms' ) } value={ String( attrs.currency ?? 'usd' ) } onChange={ ( currency ) => updateAttribute( 'currency', currency.toLowerCase() ) } />
					<TextControl label={ __( 'Payment Description', 'enterprise-forms' ) } value={ String( attrs.description ?? '' ) } onChange={ ( description ) => updateAttribute( 'description', description ) } />
					{ gateway === 'stripe' && (
						<ToggleControl label={ __( 'Apple Pay / Google Pay', 'enterprise-forms' ) } checked={ Boolean( attrs.enableWallets ?? true ) } onChange={ ( enableWallets ) => updateAttribute( 'enableWallets', enableWallets ) } />
					) }
					<Button variant="secondary" onClick={ removeSelectedField } className="mt-2 border-red-200 text-red-700 hover:border-red-300 hover:text-red-800">
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</CardBody>
			</Card>
		);
	}

	return (
		<Card>
			<CardHeader>
				<strong>{ __( 'Input Field', 'enterprise-forms' ) }</strong>
			</CardHeader>
			<CardBody>
				<TextControl
					label={ __( 'Label', 'enterprise-forms' ) }
					value={ String( attrs.label ?? '' ) }
					onChange={ ( label ) => updateAttribute( 'label', label ) }
				/>
				<TextControl
					label={ __( 'Field Name', 'enterprise-forms' ) }
					value={ String( attrs.name ?? '' ) }
					onChange={ ( name ) => updateAttribute( 'name', name ) }
				/>
				<TextControl
					label={ __( 'Placeholder', 'enterprise-forms' ) }
					value={ String( attrs.placeholder ?? '' ) }
					onChange={ ( placeholder ) => updateAttribute( 'placeholder', placeholder ) }
				/>
				<div className="mt-3">
					<ToggleControl
						label={ __( 'Required', 'enterprise-forms' ) }
						checked={ Boolean( attrs.required ) }
						onChange={ ( required ) => updateAttribute( 'required', required ) }
					/>
				</div>
				<div className="mt-4 flex justify-end">
					<Button
						variant="secondary"
						size="small"
						onClick={ removeSelectedField }
						className="border-red-200 text-red-700 hover:border-red-300 hover:text-red-800"
					>
						{ __( 'Remove Field', 'enterprise-forms' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
};

export default SettingsSidebar;
