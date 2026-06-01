import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls } from '@wordpress/block-editor';
import apiFetch from '@wordpress/api-fetch';
import { PanelBody, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import FileUploadEdit from '../../blocks/file-upload/index';
import PageBreakEdit from '../../blocks/page-break/index';

interface BaseFieldAttributes {
	label: string;
	name: string;
	required: boolean;
}

interface TextLikeAttributes extends BaseFieldAttributes {
	placeholder: string;
}

interface SubmitAttributes {
	buttonText: string;
}

interface PaymentAttributes {
	gateway: 'stripe' | 'braintree' | 'paypal' | 'square';
	amountSource: 'static' | 'field';
	amount: string;
	amountField: string;
	currency: string;
	description: string;
	enableWallets: boolean;
}

interface PaymentSettingsResponse {
	gateways?: Record< string, { label: string; configured: boolean; implemented?: boolean } >;
}

interface NumberAttributes extends TextLikeAttributes {
	min: string;
	max: string;
}

interface DateAttributes extends BaseFieldAttributes {
	min: string;
	max: string;
}

interface CheckboxAttributes extends BaseFieldAttributes {
	value: string;
}

interface HiddenAttributes {
	label: string;
	name: string;
	value: string;
}

interface RatingAttributes extends BaseFieldAttributes {
	min: string;
	max: string;
	step: string;
}

interface FileAttributes extends BaseFieldAttributes {
	accept: string;
	multiple: boolean;
	maxSizeMb: string;
}

interface SelectRadioAttributes extends BaseFieldAttributes {
	options: string[];
}

interface BlockEditProps< TAttributes > {
	attributes: TAttributes;
	setAttributes: ( attrs: Partial< TAttributes > ) => void;
}

// Shared dynamic option builder used by Select and Radio blocks.
const OptionsBuilder = ( { options, onChange }: { options: string[]; onChange: ( opts: string[] ) => void } ): JSX.Element => {
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
			<p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-600">
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

const EP_TEXT_INPUT_BLOCK = 'ep/text-input';
const EP_EMAIL_BLOCK = 'ep/email';
const EP_SUBMIT_BLOCK = 'ep/submit';
const EP_PAYMENT_CHECKOUT_BLOCK = 'ep/payment-checkout';
const EP_STRIPE_CHECKOUT_BLOCK = 'ep/stripe-checkout';
const EP_TEXTAREA_BLOCK = 'ep/textarea';
const EP_PHONE_BLOCK = 'ep/phone';
const EP_NUMBER_BLOCK = 'ep/number';
const EP_DATE_BLOCK = 'ep/date';
const EP_CHECKBOX_BLOCK = 'ep/checkbox';
const EP_CONSENT_BLOCK = 'ep/consent';
const EP_HIDDEN_BLOCK = 'ep/hidden';
const EP_CHECKBOX_GROUP_BLOCK = 'ep/checkbox-group';
const EP_RATING_BLOCK = 'ep/rating';
const EP_FILE_BLOCK = 'ep/file';
const EP_FILE_UPLOAD_BLOCK = 'ep/file-upload';
const EP_SELECT_BLOCK = 'ep/select';
const EP_RADIO_BLOCK = 'ep/radio';
const EP_URL_BLOCK = 'ep/url';
const EP_PAGE_BREAK_BLOCK = 'ep/page-break';

const ALLOWED_BLOCKS = [
	EP_TEXT_INPUT_BLOCK, EP_EMAIL_BLOCK, EP_TEXTAREA_BLOCK,
	EP_PHONE_BLOCK, EP_NUMBER_BLOCK, EP_DATE_BLOCK, EP_URL_BLOCK,
	EP_CHECKBOX_BLOCK, EP_CONSENT_BLOCK, EP_HIDDEN_BLOCK,
	EP_CHECKBOX_GROUP_BLOCK, EP_RATING_BLOCK, EP_FILE_BLOCK,
	EP_FILE_UPLOAD_BLOCK, EP_SELECT_BLOCK, EP_RADIO_BLOCK,
	EP_PAGE_BREAK_BLOCK, EP_PAYMENT_CHECKOUT_BLOCK, EP_SUBMIT_BLOCK,
];

const PAYMENT_GATEWAY_LABELS: Record< PaymentAttributes['gateway'], string > = {
	stripe: 'Stripe',
	braintree: 'Braintree',
	paypal: 'PayPal',
	square: 'Square',
};

const useGatewayOptions = (): Array< { label: string; value: PaymentAttributes['gateway'] } > => {
	const [ options, setOptions ] = useState< Array< { label: string; value: PaymentAttributes['gateway'] } > >( [
		{ label: 'Stripe', value: 'stripe' },
	] );

	useEffect( () => {
		let cancelled = false;

		apiFetch< PaymentSettingsResponse >( { path: '/enterprise-forms/v1/payments/settings' } )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				const nextOptions = Object.entries( response.gateways || {} )
					.filter( ( [ , gateway ] ) => gateway.configured && gateway.implemented !== false )
					.map( ( [ value, gateway ] ) => ( {
						label: gateway.label || PAYMENT_GATEWAY_LABELS[ value as PaymentAttributes['gateway'] ] || value,
						value: value as PaymentAttributes['gateway'],
					} ) );

				setOptions( nextOptions.length > 0 ? nextOptions : [ { label: 'Stripe', value: 'stripe' } ] );
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

let registered = false;

const registerEpTextInput = (): void => {
	registerBlockType( EP_TEXT_INPUT_BLOCK, {
		title: __( 'Text Input', 'enterprise-forms' ),
		icon: 'editor-textcolor',
		category: 'widgets',
		supports: {
			html: false,
			color: false,
			typography: false,
		},
		attributes: {
			label: { type: 'string', default: 'Text Field' },
			name: { type: 'string', default: 'text_field' },
			placeholder: { type: 'string', default: '' },
			required: { type: 'boolean', default: false },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< TextLikeAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Text Input Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl
							label={ __( 'Label', 'enterprise-forms' ) }
							value={ attributes.label }
							onChange={ ( label ) => setAttributes( { label } ) }
						/>
						<TextControl
							label={ __( 'Field Name', 'enterprise-forms' ) }
							value={ attributes.name }
							onChange={ ( name ) => setAttributes( { name } ) }
						/>
						<TextControl
							label={ __( 'Placeholder', 'enterprise-forms' ) }
							value={ attributes.placeholder }
							onChange={ ( placeholder ) => setAttributes( { placeholder } ) }
						/>
						<ToggleControl
							label={ __( 'Required', 'enterprise-forms' ) }
							checked={ attributes.required }
							onChange={ ( required ) => setAttributes( { required } ) }
						/>
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<input className="w-full rounded-md border border-slate-300 px-3 py-2" placeholder={ attributes.placeholder } disabled />
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpEmail = (): void => {
	registerBlockType( EP_EMAIL_BLOCK, {
		title: __( 'Email Input', 'enterprise-forms' ),
		icon: 'email',
		category: 'widgets',
		supports: {
			html: false,
			color: false,
			typography: false,
		},
		attributes: {
			label: { type: 'string', default: 'Email Address' },
			name: { type: 'string', default: 'email' },
			placeholder: { type: 'string', default: 'you@company.com' },
			required: { type: 'boolean', default: true },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< TextLikeAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Email Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl
							label={ __( 'Label', 'enterprise-forms' ) }
							value={ attributes.label }
							onChange={ ( label ) => setAttributes( { label } ) }
						/>
						<TextControl
							label={ __( 'Field Name', 'enterprise-forms' ) }
							value={ attributes.name }
							onChange={ ( name ) => setAttributes( { name } ) }
						/>
						<TextControl
							label={ __( 'Placeholder', 'enterprise-forms' ) }
							value={ attributes.placeholder }
							onChange={ ( placeholder ) => setAttributes( { placeholder } ) }
						/>
						<ToggleControl
							label={ __( 'Required', 'enterprise-forms' ) }
							checked={ attributes.required }
							onChange={ ( required ) => setAttributes( { required } ) }
						/>
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<input className="w-full rounded-md border border-slate-300 px-3 py-2" type="email" placeholder={ attributes.placeholder } disabled />
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpSubmit = (): void => {
	registerBlockType( EP_SUBMIT_BLOCK, {
		title: __( 'Submit Button', 'enterprise-forms' ),
		icon: 'yes-alt',
		category: 'widgets',
		supports: {
			html: false,
			color: false,
			typography: false,
		},
		attributes: {
			buttonText: { type: 'string', default: 'Submit' },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< SubmitAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Button Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl
							label={ __( 'Button Text', 'enterprise-forms' ) }
							value={ attributes.buttonText }
							onChange={ ( buttonText ) => setAttributes( { buttonText } ) }
						/>
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<button className="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white" type="button" disabled>
						{ attributes.buttonText }
					</button>
				</div>
			</>
		),
		save: () => null,
	} );
};

const PaymentCheckoutEdit = ( { attributes, setAttributes }: BlockEditProps< PaymentAttributes > ): JSX.Element => {
	const gatewayOptions = useGatewayOptions();
	const selectedGateway = attributes.gateway || 'stripe';

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Payment Settings', 'enterprise-forms' ) } initialOpen>
					<SelectControl
						label={ __( 'Gateway', 'enterprise-forms' ) }
						value={ selectedGateway }
						options={ gatewayOptions }
						onChange={ ( gateway ) => setAttributes( { gateway: gateway as PaymentAttributes['gateway'] } ) }
					/>
					<SelectControl
						label={ __( 'Amount', 'enterprise-forms' ) }
						value={ attributes.amountSource }
						options={ [
							{ label: __( 'Static amount', 'enterprise-forms' ), value: 'static' },
							{ label: __( 'Map to field value', 'enterprise-forms' ), value: 'field' },
						] }
						onChange={ ( amountSource ) => setAttributes( { amountSource: amountSource as 'static' | 'field' } ) }
					/>
					{ attributes.amountSource === 'static' ? (
						<TextControl label={ __( 'Static Amount', 'enterprise-forms' ) } value={ attributes.amount } onChange={ ( amount ) => setAttributes( { amount } ) } />
					) : (
						<TextControl label={ __( 'Amount Field Name', 'enterprise-forms' ) } value={ attributes.amountField } onChange={ ( amountField ) => setAttributes( { amountField } ) } />
					) }
					<TextControl label={ __( 'Currency', 'enterprise-forms' ) } value={ attributes.currency } onChange={ ( currency ) => setAttributes( { currency: currency.toLowerCase() } ) } />
					<TextControl label={ __( 'Payment Description', 'enterprise-forms' ) } value={ attributes.description } onChange={ ( description ) => setAttributes( { description } ) } />
					{ selectedGateway === 'stripe' && (
						<ToggleControl label={ __( 'Apple Pay / Google Pay', 'enterprise-forms' ) } checked={ Boolean( attributes.enableWallets ) } onChange={ ( enableWallets ) => setAttributes( { enableWallets } ) } />
					) }
				</PanelBody>
			</InspectorControls>
			<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
				<div className="mb-2 text-sm font-medium text-slate-800">{ __( 'Payment Checkout', 'enterprise-forms' ) }</div>
				<div className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-600">
					<div>{ PAYMENT_GATEWAY_LABELS[ selectedGateway ] || selectedGateway }</div>
					<div>
						{ attributes.amountSource === 'static'
							? `${ attributes.amount || '0' } ${ attributes.currency.toUpperCase() }`
							: __( 'Amount mapped from a form field', 'enterprise-forms' ) }
					</div>
				</div>
			</div>
		</>
	);
};

const registerEpPaymentCheckout = ( blockName = EP_PAYMENT_CHECKOUT_BLOCK ): void => {
	registerBlockType( blockName, {
		title: __( 'Payment Checkout', 'enterprise-forms' ),
		icon: 'money-alt',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			gateway: { type: 'string', default: 'stripe' },
			amountSource: { type: 'string', default: 'static' },
			amount: { type: 'string', default: '0' },
			amountField: { type: 'string', default: '' },
			currency: { type: 'string', default: 'usd' },
			description: { type: 'string', default: '' },
			enableWallets: { type: 'boolean', default: true },
		},
		edit: PaymentCheckoutEdit,
		save: () => null,
	} );
};

const registerEpTextarea = (): void => {
	registerBlockType( EP_TEXTAREA_BLOCK, {
		title: __( 'Textarea', 'enterprise-forms' ),
		icon: 'editor-alignleft',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Message' },
			name: { type: 'string', default: 'message' },
			placeholder: { type: 'string', default: '' },
			required: { type: 'boolean', default: false },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< TextLikeAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Textarea Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<TextControl label={ __( 'Placeholder', 'enterprise-forms' ) } value={ attributes.placeholder } onChange={ ( placeholder ) => setAttributes( { placeholder } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<textarea className="w-full resize-none rounded-md border border-slate-300 px-3 py-2" rows={ 4 } placeholder={ attributes.placeholder } disabled />
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpPhone = (): void => {
	registerBlockType( EP_PHONE_BLOCK, {
		title: __( 'Phone Number', 'enterprise-forms' ),
		icon: 'phone',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Phone Number' },
			name: { type: 'string', default: 'phone' },
			placeholder: { type: 'string', default: '+1 (555) 000-0000' },
			required: { type: 'boolean', default: false },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< TextLikeAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Phone Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<TextControl label={ __( 'Placeholder', 'enterprise-forms' ) } value={ attributes.placeholder } onChange={ ( placeholder ) => setAttributes( { placeholder } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<input className="w-full rounded-md border border-slate-300 px-3 py-2" type="tel" placeholder={ attributes.placeholder } disabled />
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpNumber = (): void => {
	registerBlockType( EP_NUMBER_BLOCK, {
		title: __( 'Number', 'enterprise-forms' ),
		icon: 'calculator',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Number' },
			name: { type: 'string', default: 'number' },
			placeholder: { type: 'string', default: '' },
			required: { type: 'boolean', default: false },
			min: { type: 'string', default: '' },
			max: { type: 'string', default: '' },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< NumberAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Number Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<TextControl label={ __( 'Placeholder', 'enterprise-forms' ) } value={ attributes.placeholder } onChange={ ( placeholder ) => setAttributes( { placeholder } ) } />
						<TextControl label={ __( 'Min value', 'enterprise-forms' ) } value={ attributes.min } onChange={ ( min ) => setAttributes( { min } ) } />
						<TextControl label={ __( 'Max value', 'enterprise-forms' ) } value={ attributes.max } onChange={ ( max ) => setAttributes( { max } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<input className="w-full rounded-md border border-slate-300 px-3 py-2" type="number" placeholder={ attributes.placeholder } disabled />
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpDate = (): void => {
	registerBlockType( EP_DATE_BLOCK, {
		title: __( 'Date Picker', 'enterprise-forms' ),
		icon: 'calendar-alt',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Select Date' },
			name: { type: 'string', default: 'date' },
			required: { type: 'boolean', default: false },
			min: { type: 'string', default: '' },
			max: { type: 'string', default: '' },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< DateAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Date Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<TextControl label={ __( 'Min Date (YYYY-MM-DD)', 'enterprise-forms' ) } value={ attributes.min } onChange={ ( min ) => setAttributes( { min } ) } />
						<TextControl label={ __( 'Max Date (YYYY-MM-DD)', 'enterprise-forms' ) } value={ attributes.max } onChange={ ( max ) => setAttributes( { max } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<input className="w-full rounded-md border border-slate-300 px-3 py-2" type="date" min={ attributes.min || undefined } max={ attributes.max || undefined } disabled />
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpCheckbox = (): void => {
	registerBlockType( EP_CHECKBOX_BLOCK, {
		title: __( 'Checkbox', 'enterprise-forms' ),
		icon: 'yes',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Checkbox option' },
			name: { type: 'string', default: 'checkbox' },
			required: { type: 'boolean', default: false },
			value: { type: 'string', default: '1' },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< CheckboxAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Checkbox Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<TextControl label={ __( 'Checked Value', 'enterprise-forms' ) } value={ attributes.value } onChange={ ( value ) => setAttributes( { value } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="flex items-center gap-2 text-sm text-slate-700">
						<input type="checkbox" value={ attributes.value } disabled />
						{ attributes.label }
					</label>
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpConsent = (): void => {
	registerBlockType( EP_CONSENT_BLOCK, {
		title: __( 'Terms Consent', 'enterprise-forms' ),
		icon: 'privacy',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'I agree to the terms and privacy policy.' },
			name: { type: 'string', default: 'terms_consent' },
			required: { type: 'boolean', default: true },
			value: { type: 'string', default: '1' },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< CheckboxAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Consent Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Consent Text', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<TextControl label={ __( 'Checked Value', 'enterprise-forms' ) } value={ attributes.value } onChange={ ( value ) => setAttributes( { value } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="flex items-center gap-2 text-sm text-slate-700">
						<input type="checkbox" value={ attributes.value } disabled />
						{ attributes.label }
					</label>
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpHidden = (): void => {
	registerBlockType( EP_HIDDEN_BLOCK, {
		title: __( 'Hidden Field', 'enterprise-forms' ),
		icon: 'hidden',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Hidden Field' },
			name: { type: 'string', default: 'hidden_field' },
			value: { type: 'string', default: '' },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< HiddenAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Hidden Field Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<TextControl label={ __( 'Value', 'enterprise-forms' ) } value={ attributes.value } onChange={ ( value ) => setAttributes( { value } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
					<strong>{ attributes.label }</strong>
					<div>{ __( 'Name:', 'enterprise-forms' ) } { attributes.name }</div>
					<div>{ __( 'Value:', 'enterprise-forms' ) } { attributes.value || '—' }</div>
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpCheckboxGroup = (): void => {
	registerBlockType( EP_CHECKBOX_GROUP_BLOCK, {
		title: __( 'Multiple Choice', 'enterprise-forms' ),
		icon: 'editor-ul',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Select all that apply' },
			name: { type: 'string', default: 'checkbox_group' },
			required: { type: 'boolean', default: false },
			options: { type: 'array', items: { type: 'string' }, default: [ 'Option 1', 'Option 2', 'Option 3' ] },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< SelectRadioAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Multiple Choice Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<OptionsBuilder options={ attributes.options } onChange={ ( options ) => setAttributes( { options } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<div className="space-y-1">
						{ attributes.options.map( ( opt, i ) => (
							<label key={ i } className="flex items-center gap-2 text-sm text-slate-700">
								<input type="checkbox" name={ attributes.name } value={ opt } disabled />
								{ opt }
							</label>
						) ) }
					</div>
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpRating = (): void => {
	registerBlockType( EP_RATING_BLOCK, {
		title: __( 'Stars Rating', 'enterprise-forms' ),
		icon: 'star-filled',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Rate your experience' },
			name: { type: 'string', default: 'rating' },
			required: { type: 'boolean', default: false },
			min: { type: 'string', default: '1' },
			max: { type: 'string', default: '5' },
			step: { type: 'string', default: '1' },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< RatingAttributes > ) => {
			const min = Math.max( 1, Number( attributes.min ) || 1 );
			const max = Math.max( min, Number( attributes.max ) || 5 );
			const stars = Array.from( { length: Math.min( 10, max - min + 1 ) }, ( _, idx ) => min + idx );

			return (
				<>
					<InspectorControls>
						<PanelBody title={ __( 'Rating Settings', 'enterprise-forms' ) } initialOpen>
							<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
							<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
							<TextControl label={ __( 'Min', 'enterprise-forms' ) } value={ attributes.min } onChange={ ( minVal ) => setAttributes( { min: minVal } ) } />
							<TextControl label={ __( 'Max', 'enterprise-forms' ) } value={ attributes.max } onChange={ ( maxVal ) => setAttributes( { max: maxVal } ) } />
							<TextControl label={ __( 'Step', 'enterprise-forms' ) } value={ attributes.step } onChange={ ( step ) => setAttributes( { step } ) } />
							<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
						</PanelBody>
					</InspectorControls>
					<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
						<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
						<div className="flex gap-1 text-xl text-amber-400">
							{ stars.map( ( score ) => (
								<span key={ score } aria-hidden="true">★</span>
							) ) }
						</div>
					</div>
				</>
			);
		},
		save: () => null,
	} );
};

const registerEpFile = (): void => {
	registerBlockType( EP_FILE_BLOCK, {
		title: __( 'File Upload', 'enterprise-forms' ),
		icon: 'upload',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Upload file' },
			name: { type: 'string', default: 'file_upload' },
			required: { type: 'boolean', default: false },
			accept: { type: 'string', default: '' },
			multiple: { type: 'boolean', default: false },
			maxSizeMb: { type: 'string', default: '5' },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< FileAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'File Upload Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<TextControl label={ __( 'Accepted Types (e.g. .pdf,image/*)', 'enterprise-forms' ) } value={ attributes.accept } onChange={ ( accept ) => setAttributes( { accept } ) } />
						<TextControl label={ __( 'Max Size (MB)', 'enterprise-forms' ) } value={ attributes.maxSizeMb } onChange={ ( maxSizeMb ) => setAttributes( { maxSizeMb } ) } />
						<ToggleControl label={ __( 'Allow Multiple Files', 'enterprise-forms' ) } checked={ attributes.multiple } onChange={ ( multiple ) => setAttributes( { multiple } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<input className="w-full rounded-md border border-slate-300 px-3 py-2" type="file" accept={ attributes.accept || undefined } multiple={ attributes.multiple } disabled />
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpFileUpload = (): void => {
	registerBlockType( EP_FILE_UPLOAD_BLOCK, {
		title: __( 'Cloud File Upload', 'enterprise-forms' ),
		icon: 'upload',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Upload Files' },
			name: { type: 'string', default: 'file_upload' },
			required: { type: 'boolean', default: false },
			acceptedFileTypes: { type: 'array', items: { type: 'string' }, default: [ 'pdf', 'doc', 'jpg' ] },
			maxFileSize: { type: 'number', default: 10485760 },
			multiple: { type: 'boolean', default: false },
		},
		edit: FileUploadEdit,
		save: () => null,
	} );
};

const registerEpPageBreak = (): void => {
	registerBlockType( EP_PAGE_BREAK_BLOCK, {
		title: __( 'Page Break', 'enterprise-forms' ),
		icon: 'images-alt2',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			title: { type: 'string', default: '' },
			description: { type: 'string', default: '' },
			pageNumber: { type: 'number', default: 0 },
		},
		edit: PageBreakEdit,
		save: () => null,
	} );
};

const registerEpSelect = (): void => {
	registerBlockType( EP_SELECT_BLOCK, {
		title: __( 'Select / Dropdown', 'enterprise-forms' ),
		icon: 'list-view',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Select an option' },
			name: { type: 'string', default: 'select' },
			required: { type: 'boolean', default: false },
			options: { type: 'array', items: { type: 'string' }, default: [ 'Option 1', 'Option 2', 'Option 3' ] },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< SelectRadioAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Select Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<OptionsBuilder options={ attributes.options } onChange={ ( options ) => setAttributes( { options } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<select className="w-full rounded-md border border-slate-300 bg-white px-3 py-2" disabled>
						<option value="">{ __( '— Choose —', 'enterprise-forms' ) }</option>
						{ attributes.options.map( ( opt, i ) => (
							<option key={ i } value={ opt }>{ opt }</option>
						) ) }
					</select>
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpRadio = (): void => {
	registerBlockType( EP_RADIO_BLOCK, {
		title: __( 'Radio Buttons', 'enterprise-forms' ),
		icon: 'marker',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Choose one' },
			name: { type: 'string', default: 'radio' },
			required: { type: 'boolean', default: false },
			options: { type: 'array', items: { type: 'string' }, default: [ 'Option 1', 'Option 2', 'Option 3' ] },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< SelectRadioAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Radio Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<OptionsBuilder options={ attributes.options } onChange={ ( options ) => setAttributes( { options } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<div className="space-y-1">
						{ attributes.options.map( ( opt, i ) => (
							<label key={ i } className="flex items-center gap-2 text-sm text-slate-700">
								<input type="radio" name={ attributes.name } value={ opt } disabled />
								{ opt }
							</label>
						) ) }
					</div>
				</div>
			</>
		),
		save: () => null,
	} );
};

const registerEpUrl = (): void => {
	registerBlockType( EP_URL_BLOCK, {
		title: __( 'URL', 'enterprise-forms' ),
		icon: 'admin-links',
		category: 'widgets',
		supports: { html: false, color: false, typography: false },
		attributes: {
			label: { type: 'string', default: 'Website URL' },
			name: { type: 'string', default: 'url' },
			placeholder: { type: 'string', default: 'https://' },
			required: { type: 'boolean', default: false },
		},
		edit: ( { attributes, setAttributes }: BlockEditProps< TextLikeAttributes > ) => (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'URL Settings', 'enterprise-forms' ) } initialOpen>
						<TextControl label={ __( 'Label', 'enterprise-forms' ) } value={ attributes.label } onChange={ ( label ) => setAttributes( { label } ) } />
						<TextControl label={ __( 'Field Name', 'enterprise-forms' ) } value={ attributes.name } onChange={ ( name ) => setAttributes( { name } ) } />
						<TextControl label={ __( 'Placeholder', 'enterprise-forms' ) } value={ attributes.placeholder } onChange={ ( placeholder ) => setAttributes( { placeholder } ) } />
						<ToggleControl label={ __( 'Required', 'enterprise-forms' ) } checked={ attributes.required } onChange={ ( required ) => setAttributes( { required } ) } />
					</PanelBody>
				</InspectorControls>
				<div className="ef-field-card rounded-xl border border-slate-300 bg-white p-4">
					<label className="mb-2 block text-sm font-medium text-slate-800">{ attributes.label }</label>
					<input className="w-full rounded-md border border-slate-300 px-3 py-2" type="url" placeholder={ attributes.placeholder } disabled />
				</div>
			</>
		),
		save: () => null,
	} );
};

const ensureRegistered = (): void => {
	if ( registered ) {
		return;
	}

	registerEpTextInput();
	registerEpEmail();
	registerEpTextarea();
	registerEpPhone();
	registerEpNumber();
	registerEpDate();
	registerEpUrl();
	registerEpCheckbox();
	registerEpConsent();
	registerEpHidden();
	registerEpCheckboxGroup();
	registerEpRating();
	registerEpFile();
	registerEpFileUpload();
	registerEpSelect();
	registerEpRadio();
	registerEpPageBreak();
	registerEpPaymentCheckout();
	registerEpPaymentCheckout( EP_STRIPE_CHECKOUT_BLOCK );
	registerEpSubmit();
	registered = true;
};

export interface EpFormRegistry {
	allowedBlockTypes: string[];
	ensureRegistered: () => void;
}

export const epFormRegistry: EpFormRegistry = {
	allowedBlockTypes: ALLOWED_BLOCKS,
	ensureRegistered,
};
