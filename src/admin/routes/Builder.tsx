import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { BlockEditorProvider } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useNavigate, useParams } from 'react-router-dom';
import AutoSaveProvider from '../builder/AutoSaveProvider';
import Canvas from '../builder/Canvas';
import { epFormRegistry } from '../builder/epFormRegistry';
import { LogicBuilder } from '../builder/LogicBuilder';
import NotificationSettingsPanel from '../builder/NotificationSettingsPanel';
import SpamPreventionSettingsPanel from '../builder/SpamPreventionSettingsPanel';
import SettingsSidebar from '../builder/SettingsSidebar';
import ThemeSettingsPanel from '../builder/ThemeSettingsPanel';
import { useBuilderState, type BuilderSaveState } from '../builder/useBuilderState';
import { useEnterpriseForms } from '../hooks/useEnterpriseForms';

interface ParsedSchemaField {
	type?: string;
	label?: string;
	name?: string;
	placeholder?: string;
	required?: boolean;
	value?: string;
	multiple?: boolean;
	button_text?: string;
	options?: unknown;
	gateway?: 'stripe' | 'braintree' | 'paypal' | 'square';
	amount_source?: 'static' | 'field';
	amount?: string;
	amount_field?: string;
	currency?: string;
	description?: string;
	enable_wallets?: boolean;
	validation_rules?: {
		min?: number;
		max?: number;
		min_date?: string;
		max_date?: string;
		step?: number;
		max_size_mb?: number;
		accept?: string;
	};
}

interface ParsedSchema {
	fields?: ParsedSchemaField[];
	requires_payment?: boolean;
	pages?: Array< {
		id?: string;
		title?: string;
		description?: string;
		fields?: ParsedSchemaField[];
	} >;
	logic?: Array< {
		id?: string;
		field_id?: string;
		operator?: string;
		value?: string;
		action?: string;
		target_field_id?: string;
	} >;
	settings?: {
		theme?: string;
		enableHoneypot?: boolean;
		notification?: {
			enabled?: boolean;
			recipients?: string;
			included_field_ids?: string[] | null;
		};
		spam_prevention?: {
			enable_honeypot?: boolean;
			submission_rate_limit?: number;
			submission_rate_window?: number;
			duplicate_submission_window?: number;
		};
	};
}

interface SpamPreventionSettings {
	enable_honeypot: boolean;
	submission_rate_limit: number;
	submission_rate_window: number;
	duplicate_submission_window: number;
}

interface WpFormSchemaResponse {
	meta?: {
		ep_form_schema?: string;
	};
}

const createDefaultBlocks = (): unknown[] => [
	createBlock( 'ep/text-input' ),
	createBlock( 'ep/email' ),
	createBlock( 'ep/submit' ),
];

const toOptionsArray = ( value: unknown ): string[] => {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	return value
		.filter( ( option ): option is string => typeof option === 'string' )
		.map( ( option ) => option.trim() )
		.filter( Boolean );
};

const toAcceptedFileTypes = ( accept: string ): string[] => {
	if ( ! accept ) {
		return [ 'pdf', 'doc', 'jpg' ];
	}

	return accept
		.split( ',' )
		.map( ( type ) => type.trim().replace( /^\./, '' ) )
		.filter( Boolean );
};

const createBlockFromField = ( field: ParsedSchemaField ) => {
	if ( field.type === 'text' ) {
		return createBlock( 'ep/text-input', {
			label: field.label ?? 'Text Field',
			name: field.name ?? 'text',
			placeholder: field.placeholder ?? '',
			required: Boolean( field.required ),
		} );
	}

	if ( field.type === 'email' ) {
		return createBlock( 'ep/email', {
			label: field.label ?? 'Email Address',
			name: field.name ?? 'email',
			placeholder: field.placeholder ?? 'you@company.com',
			required: Boolean( field.required ),
		} );
	}

	if ( field.type === 'submit' ) {
		return createBlock( 'ep/submit', {
			buttonText: field.button_text ?? 'Submit',
		} );
	}

	if ( field.type === 'payment' ) {
		return createBlock( 'ep/payment-checkout', {
			gateway: field.gateway ?? 'stripe',
			amountSource: field.amount_source ?? 'static',
			amount: field.amount ?? '0',
			amountField: field.amount_field ?? '',
			currency: field.currency ?? 'usd',
			description: field.description ?? '',
			enableWallets: field.enable_wallets ?? true,
		} );
	}

	if ( field.type === 'textarea' ) {
		return createBlock( 'ep/textarea', {
			label: field.label ?? 'Message',
			name: field.name ?? 'textarea',
			placeholder: field.placeholder ?? '',
			required: Boolean( field.required ),
		} );
	}

	if ( field.type === 'phone' ) {
		return createBlock( 'ep/phone', {
			label: field.label ?? 'Phone Number',
			name: field.name ?? 'phone',
			placeholder: field.placeholder ?? '',
			required: Boolean( field.required ),
		} );
	}

	if ( field.type === 'number' ) {
		return createBlock( 'ep/number', {
			label: field.label ?? 'Number',
			name: field.name ?? 'number',
			placeholder: field.placeholder ?? '',
			required: Boolean( field.required ),
			min: field.validation_rules?.min != null ? String( field.validation_rules.min ) : '',
			max: field.validation_rules?.max != null ? String( field.validation_rules.max ) : '',
		} );
	}

	if ( field.type === 'date' ) {
		return createBlock( 'ep/date', {
			label: field.label ?? 'Select Date',
			name: field.name ?? 'date',
			required: Boolean( field.required ),
			min: field.validation_rules?.min_date ?? '',
			max: field.validation_rules?.max_date ?? '',
		} );
	}

	if ( field.type === 'url' ) {
		return createBlock( 'ep/url', {
			label: field.label ?? 'Website URL',
			name: field.name ?? 'url',
			placeholder: field.placeholder ?? 'https://',
			required: Boolean( field.required ),
		} );
	}

	if ( field.type === 'select' ) {
		return createBlock( 'ep/select', {
			label: field.label ?? 'Select an option',
			name: field.name ?? 'select',
			required: Boolean( field.required ),
			options: toOptionsArray( field.options ),
		} );
	}

	if ( field.type === 'radio' ) {
		return createBlock( 'ep/radio', {
			label: field.label ?? 'Choose one',
			name: field.name ?? 'radio',
			required: Boolean( field.required ),
			options: toOptionsArray( field.options ),
		} );
	}

	if ( field.type === 'checkbox' ) {
		return createBlock( 'ep/checkbox', {
			label: field.label ?? 'Checkbox option',
			name: field.name ?? 'checkbox',
			required: Boolean( field.required ),
			value: field.value ?? '1',
		} );
	}

	if ( field.type === 'consent' ) {
		return createBlock( 'ep/consent', {
			label: field.label ?? 'I agree to the terms and privacy policy.',
			name: field.name ?? 'terms_consent',
			required: Boolean( field.required ),
			value: field.value ?? '1',
		} );
	}

	if ( field.type === 'hidden' ) {
		return createBlock( 'ep/hidden', {
			label: field.label ?? 'Hidden Field',
			name: field.name ?? 'hidden_field',
			value: field.value ?? '',
		} );
	}

	if ( field.type === 'checkbox_group' ) {
		return createBlock( 'ep/checkbox-group', {
			label: field.label ?? 'Select all that apply',
			name: field.name ?? 'checkbox_group',
			required: Boolean( field.required ),
			options: toOptionsArray( field.options ),
		} );
	}

	if ( field.type === 'rating' ) {
		return createBlock( 'ep/rating', {
			label: field.label ?? 'Rate your experience',
			name: field.name ?? 'rating',
			required: Boolean( field.required ),
			min: field.validation_rules?.min != null ? String( field.validation_rules.min ) : '1',
			max: field.validation_rules?.max != null ? String( field.validation_rules.max ) : '5',
			step: field.validation_rules?.step != null ? String( field.validation_rules.step ) : '1',
		} );
	}

	if ( field.type === 'file' ) {
		return createBlock( 'ep/file-upload', {
			label: field.label ?? 'Upload file',
			name: field.name ?? 'file_upload',
			required: Boolean( field.required ),
			acceptedFileTypes: toAcceptedFileTypes( field.validation_rules?.accept ?? '' ),
			maxFileSize: ( field.validation_rules?.max_size_mb ?? 10 ) * 1024 * 1024,
			multiple: Boolean( field.multiple ),
		} );
	}

	return null;
};

const createBlocksFromSchema = ( schemaRaw: string ): unknown[] => {
	if ( ! schemaRaw ) {
		return createDefaultBlocks();
	}

	try {
		const parsed = JSON.parse( schemaRaw ) as ParsedSchema;
		const pageBlocks = Array.isArray( parsed.pages ) && parsed.pages.length > 0
			? parsed.pages.flatMap( ( page, pageIndex ) => {
				const pageFields = Array.isArray( page.fields ) ? page.fields : [];
				const blocks = pageFields
					.map( createBlockFromField )
					.filter( ( block ): block is NonNullable< ReturnType< typeof createBlock > > => block !== null );

				if ( pageIndex === 0 ) {
					return blocks;
				}

				return [
					createBlock( 'ep/page-break', {
						title: page.title ?? '',
						description: page.description ?? '',
					} ),
					...blocks,
				];
			} )
			: [];

		const fields = Array.isArray( parsed.fields ) ? parsed.fields : [];
		const blocks = pageBlocks.length > 0
			? pageBlocks
			: fields
				.map( createBlockFromField )
				.filter( ( block ): block is NonNullable< ReturnType< typeof createBlock > > => block !== null );

		return blocks.length > 0 ? blocks : createDefaultBlocks();
	} catch {
		return createDefaultBlocks();
	}
};

const getNotificationSettingsFromSchema = ( schemaRaw: string ): { enabled: boolean; recipients: string; included_field_ids: string[] | null } => {
	if ( ! schemaRaw ) {
		return {
			enabled: true,
			recipients: '',
			included_field_ids: null,
		};
	}

	try {
		const parsed = JSON.parse( schemaRaw ) as ParsedSchema;
		return {
			enabled: parsed.settings?.notification?.enabled !== false,
			recipients: typeof parsed.settings?.notification?.recipients === 'string'
				? parsed.settings.notification.recipients
				: '',
			included_field_ids: Array.isArray( parsed.settings?.notification?.included_field_ids )
				? parsed.settings!.notification!.included_field_ids as string[]
				: null,
		};
	} catch {
		return {
			enabled: true,
			recipients: '',
			included_field_ids: null,
		};
	}
};

const getThemeFromSchema = ( schemaRaw: string ): string => {
	if ( ! schemaRaw ) {
		return 'chameleon';
	}

	try {
		const parsed = JSON.parse( schemaRaw ) as ParsedSchema;
		return typeof parsed.settings?.theme === 'string' && parsed.settings.theme.trim() !== ''
			? parsed.settings.theme
			: 'chameleon';
	} catch {
		return 'chameleon';
	}
};

const clampInt = ( value: number, min: number, max: number ): number => {
	if ( Number.isNaN( value ) ) {
		return min;
	}

	return Math.min( max, Math.max( min, Math.round( value ) ) );
};

const getSpamPreventionSettingsFromSchema = ( schemaRaw: string ): SpamPreventionSettings => {
	const defaults: SpamPreventionSettings = {
		enable_honeypot: true,
		submission_rate_limit: 10,
		submission_rate_window: 60,
		duplicate_submission_window: 300,
	};

	if ( ! schemaRaw ) {
		return defaults;
	}

	try {
		const parsed = JSON.parse( schemaRaw ) as ParsedSchema;
		const legacyEnableHoneypot = typeof parsed.settings?.enableHoneypot === 'boolean'
			? parsed.settings.enableHoneypot
			: defaults.enable_honeypot;
		const spam = parsed.settings?.spam_prevention;

		return {
			enable_honeypot: typeof spam?.enable_honeypot === 'boolean' ? spam.enable_honeypot : legacyEnableHoneypot,
			submission_rate_limit: clampInt( Number( spam?.submission_rate_limit ?? defaults.submission_rate_limit ), 1, 1000 ),
			submission_rate_window: clampInt( Number( spam?.submission_rate_window ?? defaults.submission_rate_window ), 1, 86400 ),
			duplicate_submission_window: clampInt( Number( spam?.duplicate_submission_window ?? defaults.duplicate_submission_window ), 1, 86400 ),
		};
	} catch {
		return defaults;
	}
};

const getRequiresPaymentFromSchema = ( schemaRaw: string ): boolean => {
	if ( ! schemaRaw ) {
		return false;
	}

	try {
		const parsed = JSON.parse( schemaRaw ) as ParsedSchema;
		return Boolean( parsed.requires_payment ) || ( Array.isArray( parsed.fields ) && parsed.fields.some( ( field ) => field.type === 'payment' ) );
	} catch {
		return false;
	}
};

const getLogicFromSchema = ( schemaRaw: string ) => {
	if ( ! schemaRaw ) {
		return [];
	}

	try {
		const parsed = JSON.parse( schemaRaw ) as ParsedSchema;
		if ( ! Array.isArray( parsed.logic ) ) {
			return [];
		}

		return parsed.logic
			.filter( ( rule ) =>
				typeof rule?.field_id === 'string' &&
				typeof rule?.target_field_id === 'string' &&
				typeof rule?.action === 'string' &&
				typeof rule?.operator === 'string'
			)
			.map( ( rule, index ) => ( {
				id: typeof rule.id === 'string' && rule.id !== '' ? rule.id : `rule-${ index + 1 }`,
				field_id: rule.field_id as string,
				operator: rule.operator as 'equals' | 'not_equals' | 'contains' | 'is_empty' | 'is_not_empty',
				value: typeof rule.value === 'string' ? rule.value : '',
				action: rule.action as 'show' | 'hide' | 'require' | 'disable',
				target_field_id: rule.target_field_id as string,
			} ) );
	} catch {
		return [];
	}
};

const SaveIndicator = ( { state }: { state: BuilderSaveState } ): JSX.Element | null => {
	if ( state === 'idle' ) {
		return null;
	}

	const config = {
		saving: { dot: 'bg-amber-400 animate-pulse', label: __( 'Saving\u2026', 'enterprise-forms' ), textClass: 'text-slate-500' },
		saved:  { dot: 'bg-green-500',               label: __( 'Saved',        'enterprise-forms' ), textClass: 'text-green-700' },
		error:  { dot: 'bg-red-500',                 label: __( 'Save failed',  'enterprise-forms' ), textClass: 'text-red-600'  },
	}[ state ];

	return (
		<span className="flex items-center gap-1.5 text-xs font-medium">
			<span className={ `inline-block h-2 w-2 rounded-full ${ config.dot }` } aria-hidden="true" />
			<span className={ config.textClass }>{ config.label }</span>
		</span>
	);
};

const Builder = (): JSX.Element => {
	const { formId } = useParams< 'formId' >();
	const navigate = useNavigate();
	const { forms, createForm } = useEnterpriseForms();
	const [ blocks, setBlocks ] = useState< unknown[] >( [] );
	const [ isCreating, setIsCreating ] = useState( false );
	const hydratedFormIdRef = useRef< number | null >( null );
	const saveState  = useBuilderState( ( state ) => state.saveState );
	const saveError  = useBuilderState( ( state ) => state.error );
	const formTitle  = useBuilderState( ( state ) => state.formTitle );
	const setFormTitle = useBuilderState( ( state ) => state.setFormTitle );
	const setSchema = useBuilderState( ( state ) => state.setSchema );
	const builderSchema = useBuilderState( ( state ) => state.schema );

	const resolvedId = Number( formId ) || 0;

	useEffect( () => {
		epFormRegistry.ensureRegistered();
	}, [] );

	useEffect( () => {
		if ( resolvedId <= 0 ) {
			hydratedFormIdRef.current = 0;
			setBlocks( [] );
			return;
		}

		if ( hydratedFormIdRef.current === resolvedId ) {
			return;
		}

		let isCancelled = false;
		const selectedForm = forms.find( ( form ) => form.id === resolvedId );

		apiFetch< WpFormSchemaResponse >( {
			path: `/wp/v2/ep-forms/${ resolvedId }?context=edit&_fields=meta`,
		} )
			.then( ( form ) => {
				if ( isCancelled ) {
					return;
				}
				const schemaRaw = form.meta?.ep_form_schema ?? selectedForm?.metaSchemaRaw ?? '';
				const notificationSettings = getNotificationSettingsFromSchema( schemaRaw );
				const spamPreventionSettings = getSpamPreventionSettingsFromSchema( schemaRaw );
				const theme = getThemeFromSchema( schemaRaw );
				setSchema( {
					schema_version: '1.0.0',
					requires_payment: getRequiresPaymentFromSchema( schemaRaw ),
					fields: [],
					pages: [],
					logic: getLogicFromSchema( schemaRaw ),
					settings: {
						theme,
						notification: notificationSettings,
						spam_prevention: spamPreventionSettings,
					},
				} );
				setBlocks( createBlocksFromSchema( schemaRaw ) );
				hydratedFormIdRef.current = resolvedId;
			} )
			.catch( () => {
				if ( isCancelled ) {
					return;
				}
				const fallbackSchemaRaw = selectedForm?.metaSchemaRaw ?? '';
				const notificationSettings = getNotificationSettingsFromSchema( fallbackSchemaRaw );
				const spamPreventionSettings = getSpamPreventionSettingsFromSchema( fallbackSchemaRaw );
				const theme = getThemeFromSchema( fallbackSchemaRaw );
				setSchema( {
					schema_version: '1.0.0',
					requires_payment: getRequiresPaymentFromSchema( fallbackSchemaRaw ),
					fields: [],
					pages: [],
					logic: getLogicFromSchema( fallbackSchemaRaw ),
					settings: {
						theme,
						notification: notificationSettings,
						spam_prevention: spamPreventionSettings,
					},
				} );
				setBlocks( createBlocksFromSchema( fallbackSchemaRaw ) );
				hydratedFormIdRef.current = resolvedId;
			} );

		return () => {
			isCancelled = true;
		};
	}, [ resolvedId, forms, setSchema ] );

	const handleCreate = async (): Promise< void > => {
		setIsCreating( true );
		try {
			const newId = await createForm();
			void navigate( `/builder/${ newId }` );
		} finally {
			setIsCreating( false );
		}
	};

	return (
		<section className="p-6 lg:p-10">

			{ /* Top bar: form selector + save indicator */ }
			<div className="mb-5 flex items-center justify-between gap-3">
				<label className="flex items-center gap-2 text-sm font-medium text-slate-700">
					{ __( 'Select Form', 'enterprise-forms' ) }
					<select
						value={ resolvedId }
						onChange={ ( e ) => void navigate( `/builder/${ e.target.value }` ) }
						className="rounded-md border border-slate-300 bg-white py-1.5 pl-3 pr-8 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
						aria-label={ __( 'Select form', 'enterprise-forms' ) }
					>
						{ resolvedId === 0 && (
							<option value="0" disabled>{ __( '— Select a form —', 'enterprise-forms' ) }</option>
						) }
						{ forms.map( ( form ) => (
							<option key={ form.id } value={ form.id }>{ form.title }</option>
						) ) }
					</select>
				</label>
				<SaveIndicator state={ saveState } />
			</div>

			{ /* Editable title */ }
			<input
				type="text"
				value={ formTitle }
				onChange={ ( e ) => setFormTitle( e.target.value ) }
				placeholder={ __( 'Form title\u2026', 'enterprise-forms' ) }
				disabled={ resolvedId === 0 }
				className="mb-1 w-full bg-transparent text-2xl font-semibold tracking-tight text-slate-900 placeholder:text-slate-400 focus:outline-none disabled:cursor-not-allowed disabled:opacity-40"
				aria-label={ __( 'Form title', 'enterprise-forms' ) }
			/>
			<p className="mb-6 text-sm text-slate-600">
				{ __( 'Compose form blocks in a block-native canvas.', 'enterprise-forms' ) }
			</p>

			{ saveError && (
				<div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
					{ saveError }
				</div>
			) }

			{ resolvedId === 0 ? (
				<div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">
					{ __( 'Select a form from the dropdown above, or ', 'enterprise-forms' ) }
					<button
						type="button"
						onClick={ () => { void handleCreate(); } }
						disabled={ isCreating }
						className="font-medium text-slate-900 underline hover:no-underline disabled:opacity-50"
					>
						{ __( 'create a new one', 'enterprise-forms' ) }
					</button>
					{ __( ' to get started.', 'enterprise-forms' ) }
				</div>
			) : (
				<AutoSaveProvider formId={ formId ?? '0' } blocks={ blocks }>
					<BlockEditorProvider
						key={ resolvedId }
						value={ blocks }
						onInput={ setBlocks }
						onChange={ setBlocks }
						settings={ {
							allowedBlockTypes: epFormRegistry.allowedBlockTypes,
							hasFixedToolbar: true,
							focusMode: false,
						} }
					>
						<div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
							<Canvas formId={ formId ?? '0' } blocks={ blocks } onChangeBlocks={ setBlocks } />
							<div className="space-y-4">
							<SettingsSidebar />
								<LogicBuilder
									fields={ builderSchema.fields }
									logic={ builderSchema.logic }
									onChange={ ( logic ) => setSchema( { ...builderSchema, logic } ) }
								/>
							<NotificationSettingsPanel />
							<SpamPreventionSettingsPanel />
								<ThemeSettingsPanel />
							</div>
						</div>
					</BlockEditorProvider>
				</AutoSaveProvider>
			) }
		</section>
	);
};

export default Builder;



