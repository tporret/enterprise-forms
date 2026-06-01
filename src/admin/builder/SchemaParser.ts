import type { ConditionalLogicRule, FormField, FormPage, FormSchema, FormSettings } from './schemaTypes';
import { createEmptySchema } from './schemaTypes';

interface ParsedBlock {
	name?: string;
	attributes?: Record< string, unknown >;
}

const makeFieldId = ( blockName: string, index: number ): string => {
	const shortName = blockName.includes( '/' ) ? blockName.split( '/' )[1] : blockName;
	return `${ shortName }_${ index + 1 }`;
};

const readString = ( input: unknown, fallback = '' ): string => {
	return typeof input === 'string' ? input : fallback;
};

const readBoolean = ( input: unknown, fallback = false ): boolean => {
	return typeof input === 'boolean' ? input : fallback;
};

const readGateway = ( input: unknown ): 'stripe' | 'braintree' | 'paypal' | 'square' => {
	if ( typeof input !== 'string' ) {
		return 'stripe';
	}

	if ( input === 'braintree' || input === 'paypal' || input === 'square' ) {
		return input;
	}

	return 'stripe';
};

export class SchemaParser {
	public static parseBlocks( blocks: unknown[], settings?: FormSettings, existingLogic: ConditionalLogicRule[] = [] ): FormSchema {
		if ( ! Array.isArray( blocks ) ) {
			return createEmptySchema();
		}

		const fields: FormField[] = [];
		const pages: FormPage[] = [];
		let currentPage: FormPage = {
			id: 'page-0',
			fields: [],
		};

		const commitPage = (): void => {
			if ( currentPage.fields.length === 0 ) {
				return;
			}

			pages.push( currentPage );
		};

		blocks.forEach( ( rawBlock, index ) => {
			const block = rawBlock as ParsedBlock;

			if ( block?.name === 'ep/page-break' ) {
				commitPage();
				currentPage = {
					id: `page-${ pages.length }`,
					title: readString( block.attributes?.title, '' ) || undefined,
					description: readString( block.attributes?.description, '' ) || undefined,
					fields: [],
				};
				return;
			}

			const field = this.mapBlockToField( block, index );
			if ( ! field ) {
				return;
			}

			fields.push( field );
			currentPage.fields.push( field );
		} );

		commitPage();
		const requiresPayment = fields.some( ( field ) => field.type === 'payment' );

		return {
			schema_version: '1.0.0',
			requires_payment: requiresPayment,
			fields,
			pages,
			logic: existingLogic,
			settings: settings ?? createEmptySchema().settings,
		};
	}

	private static mapBlockToField( block: ParsedBlock, index: number ): FormField | null {
		const blockName = block?.name || '';
		const attrs = block?.attributes || {};

		if ( blockName === 'ep/text-input' ) {
			return {
				id: makeFieldId( blockName, index ),
				type: 'text',
				label: readString( attrs.label, 'Text Field' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {},
				name: readString( attrs.name, `text_field_${ index + 1 }` ),
				placeholder: readString( attrs.placeholder, '' ),
			};
		}

		if ( blockName === 'ep/email' ) {
			return {
				id: makeFieldId( blockName, index ),
				type: 'email',
				label: readString( attrs.label, 'Email Address' ),
				required: readBoolean( attrs.required, true ),
				validation_rules: {
					pattern: '^\\S+@\\S+\\.\\S+$',
				},
				name: readString( attrs.name, 'email' ),
				placeholder: readString( attrs.placeholder, 'you@company.com' ),
			};
		}

		if ( blockName === 'ep/submit' ) {
			return {
				id: makeFieldId( blockName, index ),
				type: 'submit',
				label: 'Submit',
				required: false,
				validation_rules: {},
				button_text: readString( attrs.buttonText, 'Submit' ),
			};
		}

		if ( blockName === 'ep/payment-checkout' || blockName === 'ep/stripe-checkout' ) {
			const amountSource = readString( attrs.amountSource, 'static' ) === 'field' ? 'field' : 'static';
			return {
				id: makeFieldId( blockName, index ),
				type: 'payment',
				label: 'Payment',
				required: true,
				validation_rules: {},
				name: 'payment',
				gateway: readGateway( attrs.gateway ),
				amount_source: amountSource,
				amount: readString( attrs.amount, '0' ),
				amount_field: readString( attrs.amountField, '' ),
				currency: readString( attrs.currency, 'usd' ).toLowerCase(),
				description: readString( attrs.description, '' ),
				enable_wallets: readBoolean( attrs.enableWallets, true ),
			};
		}

		if ( blockName === 'ep/textarea' ) {
			return {
				id: makeFieldId( blockName, index ),
				type: 'textarea',
				label: readString( attrs.label, 'Message' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {},
				name: readString( attrs.name, `textarea_${ index + 1 }` ),
				placeholder: readString( attrs.placeholder, '' ),
			};
		}

		if ( blockName === 'ep/phone' ) {
			return {
				id: makeFieldId( blockName, index ),
				type: 'phone',
				label: readString( attrs.label, 'Phone Number' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {},
				name: readString( attrs.name, 'phone' ),
				placeholder: readString( attrs.placeholder, '' ),
			};
		}

		if ( blockName === 'ep/number' ) {
			const rawMin = readString( attrs.min, '' );
			const rawMax = readString( attrs.max, '' );
			return {
				id: makeFieldId( blockName, index ),
				type: 'number',
				label: readString( attrs.label, 'Number' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {
					...( rawMin !== '' ? { min: Number( rawMin ) } : {} ),
					...( rawMax !== '' ? { max: Number( rawMax ) } : {} ),
				},
				name: readString( attrs.name, 'number' ),
				placeholder: readString( attrs.placeholder, '' ),
			};
		}

		if ( blockName === 'ep/date' ) {
			const rawMin = readString( attrs.min, '' );
			const rawMax = readString( attrs.max, '' );
			return {
				id: makeFieldId( blockName, index ),
				type: 'date',
				label: readString( attrs.label, 'Select Date' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {
					...( rawMin !== '' ? { min_date: rawMin } : {} ),
					...( rawMax !== '' ? { max_date: rawMax } : {} ),
				},
				name: readString( attrs.name, 'date' ),
				placeholder: '',
			};
		}

		if ( blockName === 'ep/checkbox' ) {
			return {
				id: makeFieldId( blockName, index ),
				type: 'checkbox',
				label: readString( attrs.label, 'Checkbox option' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {},
				name: readString( attrs.name, 'checkbox' ),
				value: readString( attrs.value, '1' ),
			};
		}

		if ( blockName === 'ep/consent' ) {
			return {
				id: makeFieldId( blockName, index ),
				type: 'consent',
				label: readString( attrs.label, 'I agree to the terms and privacy policy.' ),
				required: readBoolean( attrs.required, true ),
				validation_rules: {},
				name: readString( attrs.name, 'terms_consent' ),
				value: readString( attrs.value, '1' ),
			};
		}

		if ( blockName === 'ep/hidden' ) {
			return {
				id: makeFieldId( blockName, index ),
				type: 'hidden',
				label: readString( attrs.label, 'Hidden Field' ),
				required: false,
				validation_rules: {},
				name: readString( attrs.name, 'hidden_field' ),
				value: readString( attrs.value, '' ),
			};
		}

		if ( blockName === 'ep/checkbox-group' ) {
			const rawOpts = attrs.options;
			const options = Array.isArray( rawOpts )
				? ( rawOpts as unknown[] ).filter( ( s ): s is string => typeof s === 'string' && s.trim() !== '' )
				: readString( rawOpts, '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
			return {
				id: makeFieldId( blockName, index ),
				type: 'checkbox_group',
				label: readString( attrs.label, 'Select all that apply' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {},
				name: readString( attrs.name, 'checkbox_group' ),
				options,
			};
		}

		if ( blockName === 'ep/rating' ) {
			const rawMin = readString( attrs.min, '1' );
			const rawMax = readString( attrs.max, '5' );
			const rawStep = readString( attrs.step, '1' );
			return {
				id: makeFieldId( blockName, index ),
				type: 'rating',
				label: readString( attrs.label, 'Rate your experience' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {
					min: Number( rawMin ) || 1,
					max: Number( rawMax ) || 5,
					step: Number( rawStep ) || 1,
				},
				name: readString( attrs.name, 'rating' ),
			};
		}

		if ( blockName === 'ep/file' || blockName === 'ep/file-upload' ) {
			const acceptedTypes = Array.isArray( attrs.acceptedFileTypes )
				? ( attrs.acceptedFileTypes as unknown[] )
					.filter( ( type ): type is string => typeof type === 'string' && type.trim() !== '' )
					.map( ( type ) => type.trim().replace( /^\./, '' ) )
				: [];
			const maxSizeBytes = Number( attrs.maxFileSize ?? 0 ) || 0;
			const maxSizeMb = blockName === 'ep/file-upload'
				? Math.max( 1, Math.round( maxSizeBytes / 1024 / 1024 ) || 10 )
				: Number( readString( attrs.maxSizeMb, '5' ) ) || 5;
			const acceptValue = blockName === 'ep/file-upload'
				? acceptedTypes.map( ( type ) => `.${ type }` ).join( ',' )
				: readString( attrs.accept, '' );
			return {
				id: makeFieldId( blockName, index ),
				type: 'file',
				label: readString( attrs.label, 'Upload file' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {
					max_size_mb: maxSizeMb,
					accept: acceptValue,
				},
				name: readString( attrs.name, 'file_upload' ),
				multiple: readBoolean( attrs.multiple, false ),
			};
		}

		if ( blockName === 'ep/select' ) {
			const rawOpts = attrs.options;
			const options = Array.isArray( rawOpts )
				? ( rawOpts as unknown[] ).filter( ( s ): s is string => typeof s === 'string' && s.trim() !== '' )
				: readString( rawOpts, '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
			return {
				id: makeFieldId( blockName, index ),
				type: 'select',
				label: readString( attrs.label, 'Select an option' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {},
				name: readString( attrs.name, 'select' ),
				options,
			};
		}

		if ( blockName === 'ep/radio' ) {
			const rawOpts = attrs.options;
			const options = Array.isArray( rawOpts )
				? ( rawOpts as unknown[] ).filter( ( s ): s is string => typeof s === 'string' && s.trim() !== '' )
				: readString( rawOpts, '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
			return {
				id: makeFieldId( blockName, index ),
				type: 'radio',
				label: readString( attrs.label, 'Choose one' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {},
				name: readString( attrs.name, 'radio' ),
				options,
			};
		}

		if ( blockName === 'ep/url' ) {
			return {
				id: makeFieldId( blockName, index ),
				type: 'url',
				label: readString( attrs.label, 'Website URL' ),
				required: readBoolean( attrs.required, false ),
				validation_rules: {},
				name: readString( attrs.name, 'url' ),
				placeholder: readString( attrs.placeholder, 'https://' ),
			};
		}

		return null;
	}
}
