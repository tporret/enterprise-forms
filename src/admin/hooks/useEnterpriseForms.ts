import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';

export interface EnterpriseFormRow {
	id: number;
	title: string;
	status: 'publish' | 'draft' | string;
	schemaVersion: string;
	submissionCount: number;
	lastModified: string;
	metaSchemaRaw: string;
	notificationEnabled: boolean;
	notificationLabel: string;
	notificationTransport: string;
}

interface WpEnvelopeResponse< T > {
	body: T;
	headers: {
		'X-WP-Total': string;
		'X-WP-TotalPages': string;
	};
	status: number;
}

interface WpFormApiItem {
	id: number;
	status?: string;
	title?: { rendered?: string };
	modified?: string;
	meta?: {
		ep_form_schema?: string;
	};
}

interface EntryCountsResponse {
	counts: Record< string, number >;
}

interface NotificationFormStatus {
	enabled?: boolean;
	has_custom_recipients?: boolean;
	using_admin_fallback?: boolean;
	resolved_recipients?: string[];
}

interface NotificationStatusesResponse {
	transport?: {
		provider?: string;
		mode?: string;
	};
	forms?: Record< string, NotificationFormStatus >;
}

interface UseEnterpriseFormsResult {
	forms: EnterpriseFormRow[];
	isLoading: boolean;
	isRefreshing: boolean;
	error: string | null;
	createForm: () => Promise< number >;
	duplicateForm: ( formId: number ) => Promise<void>;
	deleteForm: ( formId: number ) => Promise<void>;
	refresh: () => Promise<void>;
}

interface CacheShape {
	data: EnterpriseFormRow[] | null;
	lastFetched: number;
	promise: Promise< EnterpriseFormRow[] > | null;
}

const CACHE_TTL_MS = 60_000;

const formsCache: CacheShape = {
	data: null,
	lastFetched: 0,
	promise: null,
};

const parseSchemaVersion = ( schemaRaw: string ): string => {
	if ( ! schemaRaw ) {
		return '1.0.0';
	}

	try {
		const parsed = JSON.parse( schemaRaw ) as { version?: string; schema_version?: string };
		return parsed.version || parsed.schema_version || '1.0.0';
	} catch {
		return '1.0.0';
	}
};

const parseNotificationEnabled = ( schemaRaw: string ): boolean => {
	if ( ! schemaRaw ) {
		return true;
	}

	try {
		const parsed = JSON.parse( schemaRaw ) as {
			settings?: {
				notification?: {
					enabled?: boolean;
				};
			};
		};
		return parsed.settings?.notification?.enabled !== false;
	} catch {
		return true;
	}
};

const normalizeRows = (
	forms: WpFormApiItem[],
	counts: Record< string, number >,
	notificationForms: Record< string, NotificationFormStatus >,
	notificationTransport: string
): EnterpriseFormRow[] => {
	return forms
		.map( ( form ) => {
			const schemaRaw = form.meta?.ep_form_schema ?? '';
			const notificationStatus = notificationForms[ String( form.id ) ];
			const notificationEnabled = typeof notificationStatus?.enabled === 'boolean'
				? notificationStatus.enabled
				: parseNotificationEnabled( schemaRaw );

			let notificationLabel = notificationEnabled ? 'Enabled' : 'Disabled';
			if ( notificationEnabled && notificationStatus?.using_admin_fallback ) {
				notificationLabel = 'Enabled (Admin Fallback)';
			}
			if ( notificationEnabled && notificationStatus?.has_custom_recipients ) {
				notificationLabel = 'Enabled (Custom Recipients)';
			}

			return {
				id: form.id,
				title: form.title?.rendered || 'Untitled form',
				status: form.status || 'draft',
				schemaVersion: parseSchemaVersion( schemaRaw ),
				submissionCount: counts[ String( form.id ) ] ?? 0,
				lastModified: form.modified || '',
				metaSchemaRaw: schemaRaw,
				notificationEnabled,
				notificationLabel,
				notificationTransport,
			};
		} )
		.sort( ( a, b ) => new Date( b.lastModified ).getTime() - new Date( a.lastModified ).getTime() );
};

const fetchAllForms = async (): Promise< WpFormApiItem[] > => {
	const merged: WpFormApiItem[] = [];
	let page = 1;
	let totalPages = 1;

	while ( page <= totalPages ) {
		const envelope = await apiFetch< WpEnvelopeResponse< WpFormApiItem[] > >( {
			path: `/wp/v2/ep-forms?context=edit&status=any&per_page=100&page=${ page }&_fields=id,title,status,modified,meta&_envelope=1`,
		} );
		merged.push( ...envelope.body );
		totalPages = Number( envelope.headers[ 'X-WP-TotalPages' ] || '1' );
		page += 1;
	}

	return merged;
};

const fetchFormEntryCounts = async ( formIds: number[] ): Promise< Record< string, number > > => {
	if ( formIds.length === 0 ) {
		return {};
	}

	const chunks: number[][] = [];
	for ( let index = 0; index < formIds.length; index += 200 ) {
		chunks.push( formIds.slice( index, index + 200 ) );
	}

	const countMaps = await Promise.all(
		chunks.map( async ( chunk ) => {
			const response = await apiFetch< EntryCountsResponse >( {
				path: `/enterprise-forms/v1/forms/entry-counts?form_ids=${ chunk.join( ',' ) }`,
			} );
			return response.counts;
		} )
	);

	return countMaps.reduce<Record< string, number >>( ( acc, map ) => ( { ...acc, ...map } ), {} );
};

const fetchFormNotificationStatuses = async ( formIds: number[] ): Promise< {
	notificationForms: Record< string, NotificationFormStatus >;
	notificationTransport: string;
} > => {
	if ( formIds.length === 0 ) {
		return {
			notificationForms: {},
			notificationTransport: 'WordPress default',
		};
	}

	const response = await apiFetch< NotificationStatusesResponse >( {
		path: `/enterprise-forms/v1/notifications/statuses?form_ids=${ formIds.join( ',' ) }`,
	} );

	const provider = response.transport?.provider || 'WordPress default';
	const mode = response.transport?.mode || 'wp_mail';

	return {
		notificationForms: response.forms ?? {},
		notificationTransport: `${ provider } (${ mode })`,
	};
};

const fetchEnterpriseForms = async (): Promise< EnterpriseFormRow[] > => {
	const forms = await fetchAllForms();
	const counts = await fetchFormEntryCounts( forms.map( ( form ) => form.id ) );
	const { notificationForms, notificationTransport } = await fetchFormNotificationStatuses( forms.map( ( form ) => form.id ) );
	return normalizeRows( forms, counts, notificationForms, notificationTransport );
};

export const useEnterpriseForms = (): UseEnterpriseFormsResult => {
	const [ forms, setForms ] = useState< EnterpriseFormRow[] >( formsCache.data ?? [] );
	const [ isLoading, setIsLoading ] = useState( ! formsCache.data );
	const [ isRefreshing, setIsRefreshing ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const refresh = useCallback( async ( force = false ) => {
		const hasFreshCache = ! force && formsCache.data && Date.now() - formsCache.lastFetched < CACHE_TTL_MS;
		if ( hasFreshCache ) {
			setForms( formsCache.data ?? [] );
			setIsLoading( false );
			setError( null );
			return;
		}

		if ( ! formsCache.data ) {
			setIsLoading( true );
		}
		setIsRefreshing( true );
		setError( null );

		if ( ! formsCache.promise ) {
			formsCache.promise = fetchEnterpriseForms();
		}

		try {
			const nextData = await formsCache.promise;
			formsCache.data = nextData;
			formsCache.lastFetched = Date.now();
			setForms( nextData );
		} catch {
			setError( 'Unable to load forms.' );
		} finally {
			formsCache.promise = null;
			setIsLoading( false );
			setIsRefreshing( false );
		}
	}, [] );

	useEffect( () => {
		refresh();
	}, [ refresh ] );

	const duplicateForm = useCallback( async ( formId: number ) => {
		const source = forms.find( ( form ) => form.id === formId );
		if ( ! source ) {
			throw new Error( 'Form not found.' );
		}

		const tempId = -Math.floor( Date.now() / 10 );
		const previousSnapshot = forms;
		const optimisticRow: EnterpriseFormRow = {
			...source,
			id: tempId,
			title: `${ source.title } (Copy)`,
			status: 'draft',
			lastModified: new Date().toISOString(),
			submissionCount: 0,
			notificationEnabled: source.notificationEnabled,
			notificationLabel: source.notificationLabel,
			notificationTransport: source.notificationTransport,
		};

		setForms( ( current ) => {
			const next = current.map( ( form ) => ( form.id === formId ? optimisticRow : form ) );
			formsCache.data = next;
			return next;
		} );

		try {
			const created = await apiFetch< WpFormApiItem >( {
				path: '/wp/v2/ep-forms',
				method: 'POST',
				data: {
					title: `${ source.title } (Copy)`,
					status: 'draft',
					meta: {
						ep_form_schema: source.metaSchemaRaw,
					},
				},
			} );

			const normalized: EnterpriseFormRow = {
				id: created.id,
				title: created.title?.rendered || `${ source.title } (Copy)`,
				status: created.status || 'draft',
				schemaVersion: source.schemaVersion,
				submissionCount: 0,
				lastModified: created.modified || optimisticRow.lastModified,
				metaSchemaRaw: created.meta?.ep_form_schema || source.metaSchemaRaw,
				notificationEnabled: source.notificationEnabled,
				notificationLabel: source.notificationLabel,
				notificationTransport: source.notificationTransport,
			};

			setForms( ( current ) => {
				const next = current.map( ( form ) => ( form.id === tempId ? normalized : form ) );
				formsCache.data = next;
				return next;
			} );
		} catch ( err ) {
			setForms( previousSnapshot );
			formsCache.data = previousSnapshot;
			throw err;
		}
	}, [ forms ] );

	const createForm = useCallback( async (): Promise< number > => {
		const created = await apiFetch< WpFormApiItem >( {
			path: '/wp/v2/ep-forms',
			method: 'POST',
			data: {
				title: 'New Form',
				status: 'draft',
			},
		} );

		const newRow: EnterpriseFormRow = {
			id: created.id,
			title: created.title?.rendered || 'New Form',
			status: created.status || 'draft',
			schemaVersion: '1.0.0',
			submissionCount: 0,
			lastModified: created.modified || new Date().toISOString(),
			metaSchemaRaw: '',
			notificationEnabled: true,
			notificationLabel: 'Enabled (Admin Fallback)',
			notificationTransport: 'WordPress default (wp_mail)',
		};

		setForms( ( current ) => {
			const next = [ newRow, ...current ];
			formsCache.data = next;
			return next;
		} );

		return created.id;
	}, [] );

	const deleteForm = useCallback( async ( formId: number ) => {
		const previousSnapshot = forms;
		const nextSnapshot = forms.filter( ( form ) => form.id !== formId );

		setForms( nextSnapshot );
		formsCache.data = nextSnapshot;

		try {
			await apiFetch( {
				path: `/wp/v2/ep-forms/${ formId }?force=true`,
				method: 'DELETE',
			} );
		} catch ( err ) {
			setForms( previousSnapshot );
			formsCache.data = previousSnapshot;
			throw err;
		}
	}, [ forms ] );

	return useMemo(
		() => ( {
			forms,
			isLoading,
			isRefreshing,
			error,
			createForm,
			duplicateForm,
			deleteForm,
			refresh: () => refresh( true ),
		} ),
		[ forms, isLoading, isRefreshing, error, createForm, duplicateForm, deleteForm, refresh ]
	);
};
