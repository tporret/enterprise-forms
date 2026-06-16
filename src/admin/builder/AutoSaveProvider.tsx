import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef } from '@wordpress/element';
import { SchemaParser } from './SchemaParser';
import type { FormSchema } from './schemaTypes';
import { useBuilderState, type FormPostStatus } from './useBuilderState';

interface AutoSaveProviderProps {
	formId: string;
	blocks: unknown[];
	children: JSX.Element;
}

interface WindowWithWpApiSettings extends Window {
	wpApiSettings?: {
		nonce?: string;
	};
}

interface WpFormTitleResponse {
	title?: { raw?: string; rendered?: string };
	status?: string;
}

const normalizeFormStatus = ( status?: string ): FormPostStatus => {
	if ( status === 'publish' || status === 'inactive' ) {
		return status;
	}

	return 'draft';
};

const sanitizeText = ( value: string ): string => {
	return value.replace( /<[^>]*>/g, '' ).trim();
};

const clampInt = ( value: number, min: number, max: number ): number => {
	if ( Number.isNaN( value ) ) {
		return min;
	}

	return Math.min( max, Math.max( min, Math.round( value ) ) );
};

const sanitizeSchemaPayload = ( schema: FormSchema ): FormSchema => {
	return {
		schema_version: '1.0.0',
		requires_payment: Boolean( schema.requires_payment ),
		fields: schema.fields.map( ( field ) => ( {
			...field,
			id: sanitizeText( field.id ),
			type: sanitizeText( field.type ),
			label: sanitizeText( field.label ),
			required: Boolean( field.required ),
			name: field.name ? sanitizeText( field.name ) : undefined,
			placeholder: field.placeholder ? sanitizeText( field.placeholder ) : undefined,
			value: field.value ? sanitizeText( field.value ) : undefined,
			multiple: Boolean( field.multiple ),
			button_text: field.button_text ? sanitizeText( field.button_text ) : undefined,
			amount_source: field.amount_source === 'field' ? 'field' : field.amount_source === 'static' ? 'static' : undefined,
			amount: field.amount ? sanitizeText( field.amount ) : undefined,
			amount_field: field.amount_field ? sanitizeText( field.amount_field ) : undefined,
			currency: field.currency ? sanitizeText( field.currency ).toLowerCase() : undefined,
			description: field.description ? sanitizeText( field.description ) : undefined,
			options: Array.isArray( field.options )
				? field.options.map( sanitizeText ).filter( Boolean )
				: undefined,
			validation_rules: {
				...field.validation_rules,
				pattern: field.validation_rules.pattern ? sanitizeText( field.validation_rules.pattern ) : undefined,
				min_date: field.validation_rules.min_date ? sanitizeText( field.validation_rules.min_date ) : undefined,
				max_date: field.validation_rules.max_date ? sanitizeText( field.validation_rules.max_date ) : undefined,
				accept: field.validation_rules.accept ? sanitizeText( field.validation_rules.accept ) : undefined,
			},
		} ) ),
		pages: schema.pages.map( ( page ) => ( {
			id: sanitizeText( page.id ),
			title: page.title ? sanitizeText( page.title ) : undefined,
			description: page.description ? sanitizeText( page.description ) : undefined,
			fields: page.fields.map( ( field ) => ( {
				...field,
				id: sanitizeText( field.id ),
				type: sanitizeText( field.type ),
				label: sanitizeText( field.label ),
				required: Boolean( field.required ),
				name: field.name ? sanitizeText( field.name ) : undefined,
				placeholder: field.placeholder ? sanitizeText( field.placeholder ) : undefined,
				value: field.value ? sanitizeText( field.value ) : undefined,
				multiple: Boolean( field.multiple ),
				button_text: field.button_text ? sanitizeText( field.button_text ) : undefined,
				amount_source: field.amount_source === 'field' ? 'field' : field.amount_source === 'static' ? 'static' : undefined,
				amount: field.amount ? sanitizeText( field.amount ) : undefined,
				amount_field: field.amount_field ? sanitizeText( field.amount_field ) : undefined,
				currency: field.currency ? sanitizeText( field.currency ).toLowerCase() : undefined,
				description: field.description ? sanitizeText( field.description ) : undefined,
				options: Array.isArray( field.options )
					? field.options.map( sanitizeText ).filter( Boolean )
					: undefined,
				validation_rules: {
					...field.validation_rules,
					pattern: field.validation_rules.pattern ? sanitizeText( field.validation_rules.pattern ) : undefined,
					min_date: field.validation_rules.min_date ? sanitizeText( field.validation_rules.min_date ) : undefined,
					max_date: field.validation_rules.max_date ? sanitizeText( field.validation_rules.max_date ) : undefined,
					accept: field.validation_rules.accept ? sanitizeText( field.validation_rules.accept ) : undefined,
				},
			} ) ),
		} ) ),
		logic: schema.logic.map( ( rule ) => ( {
			...rule,
			id: sanitizeText( rule.id ),
			field_id: sanitizeText( rule.field_id ),
			value: sanitizeText( rule.value ),
			target_field_id: sanitizeText( rule.target_field_id ),
		} ) ),
		settings: {
			theme: sanitizeText( schema.settings.theme || 'chameleon' ) || 'chameleon',
			notification: {
				enabled: Boolean( schema.settings.notification.enabled ),
				recipients: sanitizeText( schema.settings.notification.recipients || '' ),
				included_field_ids: Array.isArray( schema.settings.notification.included_field_ids )
					? schema.settings.notification.included_field_ids
						.map( ( id ) => sanitizeText( id ) )
						.filter( Boolean )
					: null,
			},
			spam_prevention: {
				enable_honeypot: Boolean( schema.settings.spam_prevention?.enable_honeypot ?? true ),
				submission_rate_limit: clampInt( Number( schema.settings.spam_prevention?.submission_rate_limit ?? 10 ), 1, 1000 ),
				submission_rate_window: clampInt( Number( schema.settings.spam_prevention?.submission_rate_window ?? 60 ), 1, 86400 ),
				duplicate_submission_window: clampInt( Number( schema.settings.spam_prevention?.duplicate_submission_window ?? 300 ), 1, 86400 ),
			},
		},
	};
};

const AutoSaveProvider = ( { formId, blocks, children }: AutoSaveProviderProps ): JSX.Element => {
	const setFormId = useBuilderState( ( state ) => state.setFormId );
	const setSchema = useBuilderState( ( state ) => state.setSchema );
	const setSaveState = useBuilderState( ( state ) => state.setSaveState );
	const setError = useBuilderState( ( state ) => state.setError );
	const formTitle = useBuilderState( ( state ) => state.formTitle );
	const formStatus = useBuilderState( ( state ) => state.formStatus );
	const schema = useBuilderState( ( state ) => state.schema );
	const schemaSettings = useBuilderState( ( state ) => state.schema.settings );
	const setFormTitle = useBuilderState( ( state ) => state.setFormTitle );
	const setFormStatus = useBuilderState( ( state ) => state.setFormStatus );
	const latestSchemaRef = useRef< FormSchema | null >( null );
	const latestTitleRef = useRef< string >( '' );
	const latestStatusRef = useRef< FormPostStatus >( 'draft' );

	useEffect( () => {
		setFormId( Number( formId ) || 0 );
	}, [ formId, setFormId ] );

	useEffect( () => {
		const parsedSchema = SchemaParser.parseBlocks( blocks, schemaSettings, schema.logic );
		setSchema( parsedSchema );
		latestSchemaRef.current = parsedSchema;
		setSaveState( 'idle' );
	}, [ blocks, schema.logic, schemaSettings, setSchema, setSaveState ] );

	useEffect( () => {
		latestSchemaRef.current = schema;
	}, [ schema ] );

	// Load the form title from the REST API whenever formId changes.
	useEffect( () => {
		const resolvedFormId = Number( formId ) || 0;
		if ( resolvedFormId <= 0 ) {
			setFormTitle( '' );
			setFormStatus( 'draft' );
			return;
		}

		apiFetch< WpFormTitleResponse >( {
			path: `/wp/v2/ep-forms/${ resolvedFormId }?context=edit&_fields=title,status`,
		} )
			.then( ( form ) => {
				const title = form.title?.raw ?? form.title?.rendered ?? '';
				const status = normalizeFormStatus( form.status );
				setFormTitle( title );
				setFormStatus( status );
				latestTitleRef.current = title;
				latestStatusRef.current = status;
			} )
			.catch( () => { /* silent — title stays as typed */ } );
	}, [ formId, setFormStatus, setFormTitle ] );

	// Keep the title ref in sync so the debounced save always gets the latest value.
	useEffect( () => {
		latestTitleRef.current = formTitle;
	}, [ formTitle ] );

	useEffect( () => {
		latestStatusRef.current = formStatus;
	}, [ formStatus ] );

	useEffect( () => {
		const resolvedFormId = Number( formId ) || 0;
		if ( resolvedFormId <= 0 ) {
			return;
		}

		const timer = window.setTimeout( async () => {
			const schema = latestSchemaRef.current;
			if ( ! schema ) {
				return;
			}

			setSaveState( 'saving' );
			setError( null );

			try {
				const nonce = ( window as WindowWithWpApiSettings ).wpApiSettings?.nonce || '';
				const sanitizedSchema = sanitizeSchemaPayload( schema );

				await apiFetch( {
					path: `/wp/v2/ep-forms/${ resolvedFormId }`,
					method: 'POST',
					headers: {
						'X-WP-Nonce': nonce,
					},
					data: {
						title: latestTitleRef.current,
						status: latestStatusRef.current,
						meta: {
							ep_form_schema: JSON.stringify( sanitizedSchema ),
						},
					},
				} );

				setSaveState( 'saved' );
				window.setTimeout( () => setSaveState( 'idle' ), 3000 );
			} catch {
				setSaveState( 'error' );
				setError( 'Auto-save failed.' );
			}
		}, 3000 );

		return () => {
			window.clearTimeout( timer );
		};
	}, [ formId, formStatus, schema, formTitle, setError, setSaveState ] );

	return children;
};

export default AutoSaveProvider;
