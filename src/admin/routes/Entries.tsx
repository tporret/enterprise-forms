import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Modal } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useNavigate, useParams } from 'react-router-dom';
import { useEnterpriseForms } from '../hooks/useEnterpriseForms';

interface EntryRow {
	id: number;
	uuid: string;
	form_id: number;
	status: string;
	payload: Record< string, unknown >;
	created_at: string;
}

interface EntriesResponse {
	items: EntryRow[];
	offset: number;
	limit: number;
	total?: number;
	total_pages?: number;
	counts: Record< string, number >;
	can_edit: boolean;
	can_export: boolean;
}

type EntryAction = 'read' | 'unread' | 'spam' | 'trash' | 'restore' | 'delete';

interface BulkResponse {
	updated: number[];
	errors: Array< { id: number; message: string } >;
}

interface PaymentLog {
	amount?: number;
	currency?: string;
	receipt_url?: string;
}

const ZERO_DECIMAL_CURRENCIES = [
	'BIF',
	'CLP',
	'DJF',
	'GNF',
	'JPY',
	'KMF',
	'KRW',
	'MGA',
	'PYG',
	'RWF',
	'UGX',
	'VND',
	'VUV',
	'XAF',
	'XOF',
	'XPF',
];
const STATUS_FILTERS = [
	{ value: 'inbox', label: __( 'Inbox', 'enterprise-forms' ) },
	{ value: 'unread', label: __( 'Unread', 'enterprise-forms' ) },
	{ value: 'read', label: __( 'Read', 'enterprise-forms' ) },
	{ value: 'spam', label: __( 'Spam', 'enterprise-forms' ) },
	{ value: 'trash', label: __( 'Trash', 'enterprise-forms' ) },
];
const ENTRY_ACTIONS: Array< { value: EntryAction; label: string } > = [
	{ value: 'read', label: __( 'Mark as read', 'enterprise-forms' ) },
	{ value: 'unread', label: __( 'Mark as unread', 'enterprise-forms' ) },
	{ value: 'spam', label: __( 'Mark as spam', 'enterprise-forms' ) },
	{ value: 'trash', label: __( 'Move to Trash', 'enterprise-forms' ) },
	{ value: 'restore', label: __( 'Restore to Inbox', 'enterprise-forms' ) },
	{ value: 'delete', label: __( 'Delete permanently', 'enterprise-forms' ) },
];
const BASE_EXPORT_COLUMNS = [
	'entry_id',
	'entry_uuid',
	'status',
	'created_at',
];

const formatDate = ( value: string ): string => {
	const parsed = new Date( value );
	if ( Number.isNaN( parsed.getTime() ) ) {
		return value;
	}

	return new Intl.DateTimeFormat( undefined, {
		year: 'numeric',
		month: 'short',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
	} ).format( parsed );
};

const stringifyValue = ( value: unknown ): string => {
	if ( value === null || value === undefined ) {
		return '';
	}

	if ( typeof value === 'string' ) {
		return value;
	}

	if ( typeof value === 'number' || typeof value === 'boolean' ) {
		return String( value );
	}

	try {
		return JSON.stringify( value );
	} catch {
		return '';
	}
};

const flattenPayload = (
	payload: Record< string, unknown >,
	prefix = ''
): Array< [ string, string ] > => {
	const rows: Array< [ string, string ] > = [];

	Object.entries( payload ).forEach( ( [ rawKey, rawValue ] ) => {
		const key = prefix ? `${ prefix }.${ rawKey }` : rawKey;

		if (
			rawValue &&
			typeof rawValue === 'object' &&
			! Array.isArray( rawValue )
		) {
			rows.push(
				...flattenPayload( rawValue as Record< string, unknown >, key )
			);
			return;
		}

		rows.push( [ key, stringifyValue( rawValue ) ] );
	} );

	return rows;
};

const toColumnKey = ( rawKey: string ): string =>
	rawKey.toLowerCase().replace( /[^a-z0-9_.-]/g, '' );

const getPaymentLog = (
	payload: Record< string, unknown >
): PaymentLog | null => {
	const payment = payload.payment;
	return payment && typeof payment === 'object' && ! Array.isArray( payment )
		? ( payment as PaymentLog )
		: null;
};

const formatPaymentAmount = ( payment: PaymentLog | null ): string => {
	if ( ! payment || typeof payment.amount !== 'number' ) {
		return '--';
	}

	const currency = ( payment.currency || 'usd' ).toUpperCase();
	const isZeroDecimal = ZERO_DECIMAL_CURRENCIES.includes( currency );
	const amount = isZeroDecimal ? payment.amount : payment.amount / 100;
	return `${
		isZeroDecimal ? String( amount ) : amount.toFixed( 2 )
	} ${ currency }`;
};

const formatEntryStatus = ( status: string ): string => {
	return status ? status.charAt( 0 ).toUpperCase() + status.slice( 1 ) : '--';
};

const getStatusClass = ( status: string ): string => {
	if ( status === 'unread' ) {
		return 'border-amber-200 bg-amber-50 text-amber-700';
	}
	if ( status === 'read' ) {
		return 'border-emerald-200 bg-emerald-50 text-emerald-700';
	}
	return 'border-slate-200 bg-slate-100 text-slate-600';
};

const getContactSummary = ( payload: Record< string, unknown > ): string => {
	const flattened = flattenPayload( payload );
	const email =
		flattened.find(
			( [ key, value ] ) => /email/i.test( key ) && value.trim() !== ''
		)?.[ 1 ] || '';
	const name =
		flattened.find(
			( [ key, value ] ) => /name/i.test( key ) && value.trim() !== ''
		)?.[ 1 ] || '';

	if ( name && email ) {
		return `${ name } (${ email })`;
	}

	if ( email ) {
		return email;
	}

	if ( name ) {
		return name;
	}

	return '--';
};

const getHighlights = ( payload: Record< string, unknown > ): string => {
	const values = flattenPayload( payload )
		.filter( ( [ key ] ) => ! key.startsWith( 'payment' ) )
		.filter(
			( [ key ] ) =>
				key !== 'ep_forms_nonce' &&
				key !== 'ep_submission_token' &&
				key !== 'hp_field'
		)
		.filter( ( [ , value ] ) => value.trim() !== '' )
		.slice( 0, 3 )
		.map(
			( [ key, value ] ) =>
				`${ key }: ${
					value.length > 48 ? `${ value.slice( 0, 45 ) }...` : value
				}`
		);

	return values.length > 0 ? values.join( ' | ' ) : '--';
};

const parseFilenameFromHeader = (
	headerValue: string | null
): string | null => {
	if ( ! headerValue ) {
		return null;
	}

	const utf8Match = headerValue.match( /filename\*=UTF-8''([^;]+)/i );
	if ( utf8Match?.[ 1 ] ) {
		return decodeURIComponent( utf8Match[ 1 ] );
	}

	const basicMatch = headerValue.match( /filename="?([^";]+)"?/i );
	return basicMatch?.[ 1 ] ?? null;
};

const Entries = (): JSX.Element => {
	const { formId } = useParams< 'formId' >();
	const navigate = useNavigate();
	const {
		forms,
		refresh: refreshForms,
		error: formsError,
	} = useEnterpriseForms();
	const [ entries, setEntries ] = useState< EntryRow[] >( [] );
	const [ isLoadingEntries, setIsLoadingEntries ] = useState( false );
	const [ isExporting, setIsExporting ] = useState( false );
	const [ entriesError, setEntriesError ] = useState< string | null >( null );
	const [ statusFilter, setStatusFilter ] = useState( 'inbox' );
	const [ counts, setCounts ] = useState< Record< string, number > >( {} );
	const [ selectedIds, setSelectedIds ] = useState< number[] >( [] );
	const [ bulkAction, setBulkAction ] = useState< EntryAction | '' >( '' );
	const [ isUpdating, setIsUpdating ] = useState( false );
	const [ actionError, setActionError ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< string | null >( null );
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ canEdit, setCanEdit ] = useState( false );
	const [ canExport, setCanExport ] = useState( false );
	const [ deleteIds, setDeleteIds ] = useState< number[] | null >( null );
	const detailsRef = useRef< HTMLElement | null >( null );
	const [ searchTermInput, setSearchTermInput ] = useState( '' );
	const [ debouncedSearchTerm, setDebouncedSearchTerm ] = useState( '' );
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ] = useState( '' );
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const [ pageSize, setPageSize ] = useState( 25 );
	const [ totalEntries, setTotalEntries ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ activeEntryId, setActiveEntryId ] = useState< number | null >(
		null
	);
	const [ selectedExportColumns, setSelectedExportColumns ] = useState<
		string[]
	>( [] );
	const resolvedId = Number( formId ) || 0;
	const selectedForm = useMemo(
		() => forms.find( ( form ) => form.id === resolvedId ),
		[ forms, resolvedId ]
	);
	const offset = ( currentPage - 1 ) * pageSize;

	useEffect( () => {
		const timeoutId = window.setTimeout( () => {
			setDebouncedSearchTerm( searchTermInput.trim() );
		}, 350 );

		return () => {
			window.clearTimeout( timeoutId );
		};
	}, [ searchTermInput ] );

	useEffect( () => {
		setCurrentPage( 1 );
	}, [
		resolvedId,
		statusFilter,
		pageSize,
		debouncedSearchTerm,
		dateFrom,
		dateTo,
	] );

	const availablePayloadColumns = useMemo( () => {
		const index: Record< string, true > = {};
		entries.forEach( ( entry ) => {
			flattenPayload( entry.payload ).forEach( ( [ key ] ) => {
				const normalized = toColumnKey( key );
				if ( normalized !== '' ) {
					index[ normalized ] = true;
				}
			} );
		} );

		return Object.keys( index ).sort();
	}, [ entries ] );

	const selectableColumns = useMemo(
		() => [ ...BASE_EXPORT_COLUMNS, ...availablePayloadColumns ],
		[ availablePayloadColumns ]
	);

	const activeEntry = useMemo(
		() => entries.find( ( entry ) => entry.id === activeEntryId ) ?? null,
		[ entries, activeEntryId ]
	);
	useEffect( () => {
		if ( activeEntryId !== null ) {
			detailsRef.current?.focus();
		}
	}, [ activeEntryId ] );

	const actions = ENTRY_ACTIONS.filter( ( action ) =>
		statusFilter === 'trash'
			? [ 'restore', 'delete' ].includes( action.value )
			: ! [ 'restore', 'delete' ].includes( action.value )
	);

	const handleAction = async (
		ids: number[],
		action: EntryAction,
		confirmed = false
	): Promise< void > => {
		if ( ! canEdit || isUpdating || isLoadingEntries || ids.length === 0 ) {
			return;
		}
		if ( action === 'delete' && ! confirmed ) {
			setDeleteIds( ids );
			return;
		}
		setDeleteIds( null );

		setIsUpdating( true );
		setActionError( null );
		setNotice( null );
		try {
			const response = await apiFetch< BulkResponse >( {
				path: `/enterprise-forms/v1/entries/${ resolvedId }/bulk`,
				method: 'POST',
				data: { ids, action, confirm_delete: action === 'delete' },
			} );
			if ( response.updated.length > 0 ) {
				setNotice(
					sprintf(
						/* translators: %d: number of updated entries. */
						_n(
							'%d entry updated.',
							'%d entries updated.',
							response.updated.length,
							'enterprise-forms'
						),
						response.updated.length
					)
				);
			}
			if ( response.errors.length > 0 ) {
				setActionError(
					response.errors
						.map(
							( error ) => `#${ error.id }: ${ error.message }`
						)
						.join( ' ' )
				);
			}
			setSelectedIds( [] );
			setRefreshKey( ( key ) => key + 1 );
		} catch ( error ) {
			setActionError(
				error &&
					typeof error === 'object' &&
					'message' in error &&
					typeof error.message === 'string'
					? error.message
					: __(
							'Unable to update entries. Please refresh and try again.',
							'enterprise-forms'
					  )
			);
			setRefreshKey( ( key ) => key + 1 );
		} finally {
			await refreshForms();
			setIsUpdating( false );
		}
	};

	useEffect( () => {
		setSelectedIds( [] );
		setBulkAction( '' );
		if ( resolvedId <= 0 ) {
			setEntries( [] );
			setEntriesError( null );
			setTotalEntries( 0 );
			setTotalPages( 1 );
			setActiveEntryId( null );
			setCounts( {} );
			setCanEdit( false );
			setCanExport( false );
			return;
		}

		const params = new URLSearchParams( {
			offset: String( offset ),
			limit: String( pageSize ),
		} );

		params.set( 'status', statusFilter );
		if ( debouncedSearchTerm !== '' ) {
			params.set( 'search', debouncedSearchTerm );
		}
		if ( dateFrom !== '' ) {
			params.set( 'date_from', dateFrom );
		}
		if ( dateTo !== '' ) {
			params.set( 'date_to', dateTo );
		}

		let isCancelled = false;
		setIsLoadingEntries( true );
		setEntriesError( null );

		apiFetch< EntriesResponse >( {
			path: `/enterprise-forms/v1/entries/${ resolvedId }?${ params.toString() }`,
		} )
			.then( ( response ) => {
				if ( isCancelled ) {
					return;
				}

				const nextItems = Array.isArray( response.items )
					? response.items
					: [];
				setEntries( nextItems );
				setTotalEntries( Number( response.total ?? nextItems.length ) );
				setTotalPages(
					Math.max( 1, Number( response.total_pages ?? 1 ) )
				);
				setCounts( response.counts );
				setCanEdit( response.can_edit );
				setCanExport( response.can_export );
				if ( currentPage > Math.max( 1, response.total_pages ?? 1 ) ) {
					setCurrentPage( Math.max( 1, response.total_pages ?? 1 ) );
				}
				setActiveEntryId( ( current ) => {
					if (
						current &&
						nextItems.some( ( entry ) => entry.id === current )
					) {
						return current;
					}
					return null;
				} );
			} )
			.catch( () => {
				if ( isCancelled ) {
					return;
				}

				setEntries( [] );
				setEntriesError(
					__(
						'Unable to load entries for this form.',
						'enterprise-forms'
					)
				);
				setTotalEntries( 0 );
				setTotalPages( 1 );
				setActiveEntryId( null );
			} )
			.finally( () => {
				if ( isCancelled ) {
					return;
				}

				setIsLoadingEntries( false );
			} );

		return () => {
			isCancelled = true;
		};
	}, [
		offset,
		pageSize,
		resolvedId,
		statusFilter,
		debouncedSearchTerm,
		dateFrom,
		dateTo,
		refreshKey,
		currentPage,
	] );

	const toggleExportColumn = ( column: string ): void => {
		setSelectedExportColumns( ( current ) =>
			current.includes( column )
				? current.filter( ( value ) => value !== column )
				: [ ...current, column ]
		);
	};

	const handleExport = async (): Promise< void > => {
		if ( resolvedId <= 0 || isExporting ) {
			return;
		}

		const params = new URLSearchParams();
		params.set( 'status', statusFilter );
		if ( debouncedSearchTerm !== '' ) {
			params.set( 'search', debouncedSearchTerm );
		}
		if ( dateFrom !== '' ) {
			params.set( 'date_from', dateFrom );
		}
		if ( dateTo !== '' ) {
			params.set( 'date_to', dateTo );
		}
		if ( selectedExportColumns.length > 0 ) {
			params.set( 'columns', selectedExportColumns.join( ',' ) );
		}

		setIsExporting( true );
		setActionError( null );

		try {
			const response = await apiFetch< Response, false >( {
				path: `/enterprise-forms/v1/entries/${ resolvedId }/export${
					params.toString() ? `?${ params.toString() }` : ''
				}`,
				parse: false,
			} );

			if ( ! response.ok ) {
				throw new Error( 'export_failed' );
			}

			const blob = await response.blob();
			const downloadUrl = URL.createObjectURL( blob );
			const filename =
				parseFilenameFromHeader(
					response.headers.get( 'content-disposition' )
				) || `form-${ resolvedId }-entries.csv`;

			const anchor = document.createElement( 'a' );
			anchor.href = downloadUrl;
			anchor.download = filename;
			document.body.appendChild( anchor );
			anchor.click();
			anchor.remove();
			URL.revokeObjectURL( downloadUrl );
		} catch {
			setActionError(
				__( 'Unable to export entries right now.', 'enterprise-forms' )
			);
		} finally {
			setIsExporting( false );
		}
	};

	return (
		<section className="ef-entries p-6 lg:p-10">
			<fieldset disabled={ isUpdating } className="min-w-0">
				{ formsError && (
					<p role="alert" className="mb-4 text-sm text-red-700">
						{ __(
							'Unable to refresh the form list. Entries for the current form can still be reviewed.',
							'enterprise-forms'
						) }
					</p>
				) }
				<div className="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
					<div>
						<h2 className="text-2xl font-semibold tracking-tight">
							{ __( 'Entries', 'enterprise-forms' ) }
						</h2>
						<p className="mt-2 text-sm text-slate-600">
							{ __(
								'Review your inbox, select entries for bulk actions, or open a submission to see its details.',
								'enterprise-forms'
							) }
						</p>
					</div>
					<button
						type="button"
						onClick={ () => void handleExport() }
						disabled={
							resolvedId <= 0 ||
							isLoadingEntries ||
							isExporting ||
							! canExport
						}
						className="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
					>
						{ isExporting
							? __( 'Preparing CSV…', 'enterprise-forms' )
							: __( 'Download CSV', 'enterprise-forms' ) }
					</button>
				</div>

				<div
					className="mb-4 flex flex-wrap gap-2 border-b border-slate-200 pb-3"
					role="group"
					aria-label={ __( 'Submission views', 'enterprise-forms' ) }
				>
					{ STATUS_FILTERS.map( ( status ) => (
						<button
							key={ status.value }
							type="button"
							aria-pressed={ statusFilter === status.value }
							disabled={ resolvedId <= 0 }
							onClick={ () => {
								setStatusFilter( status.value );
								setActiveEntryId( null );
							} }
							className={ `rounded-md px-4 py-2 text-sm font-medium ${
								statusFilter === status.value
									? 'bg-slate-900 text-white'
									: 'bg-slate-100 text-slate-700 hover:bg-slate-200'
							}` }
						>
							{ status.label }{ ' ' }
							<span className="ml-1 opacity-75">
								{ counts[ status.value ] ?? 0 }
							</span>
						</button>
					) ) }
				</div>
				<p className="mb-4 text-xs text-slate-500">
					{ __(
						'View counts include all entries in this form, before search and date filters. Inbox excludes Spam and Trash.',
						'enterprise-forms'
					) }
				</p>
				{ actionError && (
					<p
						role="alert"
						className="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
					>
						{ actionError }
					</p>
				) }
				{ notice && (
					<p
						role="status"
						className="mb-4 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"
					>
						{ notice }
					</p>
				) }
				{ isUpdating && (
					<p role="status" className="mb-4 text-sm">
						{ __( 'Updating entries…', 'enterprise-forms' ) }
					</p>
				) }

				<div className="mb-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-3">
					<div className="space-y-1">
						<label
							className="block text-xs font-semibold uppercase tracking-wide text-slate-500"
							htmlFor="ef-form-select"
						>
							{ __( 'Form', 'enterprise-forms' ) }
						</label>
						<select
							id="ef-form-select"
							value={ resolvedId }
							onChange={ ( event ) => {
								setActiveEntryId( null );
								setCounts( {} );
								setNotice( null );
								setActionError( null );
								void navigate(
									`/entries/${ event.target.value }`
								);
							} }
							className="w-full rounded-md border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
							aria-label={ __(
								'Select form entries',
								'enterprise-forms'
							) }
						>
							{ resolvedId === 0 && (
								<option value="0" disabled>
									{ __(
										'-- Select a form --',
										'enterprise-forms'
									) }
								</option>
							) }
							{ forms.map( ( form ) => (
								<option key={ form.id } value={ form.id }>
									{ form.title }
								</option>
							) ) }
						</select>
					</div>

					<div className="space-y-1">
						<label
							className="block text-xs font-semibold uppercase tracking-wide text-slate-500"
							htmlFor="ef-page-size"
						>
							{ __( 'Rows', 'enterprise-forms' ) }
						</label>
						<select
							id="ef-page-size"
							value={ pageSize }
							onChange={ ( event ) =>
								setPageSize(
									Number( event.target.value ) || 25
								)
							}
							className="w-full rounded-md border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
							disabled={ resolvedId <= 0 }
						>
							<option value={ 25 }>25</option>
							<option value={ 50 }>50</option>
							<option value={ 100 }>100</option>
						</select>
					</div>

					<div className="space-y-1">
						<label
							className="block text-xs font-semibold uppercase tracking-wide text-slate-500"
							htmlFor="ef-entry-search"
						>
							{ __( 'Search all entries', 'enterprise-forms' ) }
						</label>
						<input
							id="ef-entry-search"
							type="search"
							value={ searchTermInput }
							onChange={ ( event ) =>
								setSearchTermInput( event.target.value )
							}
							placeholder={ __(
								'UUID, status, or any field value…',
								'enterprise-forms'
							) }
							className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
							disabled={ resolvedId <= 0 }
						/>
					</div>
				</div>

				<div className="mb-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-2 lg:grid-cols-4">
					<div className="space-y-1">
						<label
							className="block text-xs font-semibold uppercase tracking-wide text-slate-500"
							htmlFor="ef-date-from"
						>
							{ __( 'Date from', 'enterprise-forms' ) }
						</label>
						<input
							id="ef-date-from"
							type="date"
							value={ dateFrom }
							onChange={ ( event ) =>
								setDateFrom( event.target.value )
							}
							className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
							disabled={ resolvedId <= 0 }
						/>
					</div>
					<div className="space-y-1">
						<label
							className="block text-xs font-semibold uppercase tracking-wide text-slate-500"
							htmlFor="ef-date-to"
						>
							{ __( 'Date to', 'enterprise-forms' ) }
						</label>
						<input
							id="ef-date-to"
							type="date"
							value={ dateTo }
							onChange={ ( event ) =>
								setDateTo( event.target.value )
							}
							className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
							disabled={ resolvedId <= 0 }
						/>
					</div>
					<details className="md:col-span-2 space-y-1">
						<summary className="cursor-pointer text-sm font-medium text-slate-700">
							{ __(
								'Customize CSV columns',
								'enterprise-forms'
							) }
						</summary>
						<div className="flex items-center justify-between">
							<span className="block text-xs font-semibold uppercase tracking-wide text-slate-500">
								{ __( 'CSV columns', 'enterprise-forms' ) }
							</span>
							<button
								type="button"
								onClick={ () => setSelectedExportColumns( [] ) }
								className="text-xs font-medium text-slate-600 underline hover:no-underline"
								disabled={
									resolvedId <= 0 ||
									selectedExportColumns.length === 0
								}
							>
								{ __( 'Use all columns', 'enterprise-forms' ) }
							</button>
						</div>
						<div className="max-h-28 overflow-y-auto rounded-md border border-slate-200 bg-slate-50 p-2">
							{ selectableColumns.map( ( column ) => {
								const checked =
									selectedExportColumns.includes( column );
								return (
									<label
										key={ column }
										htmlFor={ `ef-export-${ column }` }
										className="flex items-center gap-2 py-0.5 text-xs text-slate-700"
									>
										<input
											id={ `ef-export-${ column }` }
											type="checkbox"
											checked={ checked }
											onChange={ () =>
												toggleExportColumn( column )
											}
											disabled={ resolvedId <= 0 }
										/>
										<span className="font-mono">
											{ column }
										</span>
									</label>
								);
							} ) }
						</div>
						<p className="text-[11px] text-slate-500">
							{ selectedExportColumns.length > 0
								? __(
										'Only selected columns will be exported.',
										'enterprise-forms'
								  )
								: __(
										'No columns selected: export will include all available columns.',
										'enterprise-forms'
								  ) }
						</p>
					</details>
				</div>

				<div className="mb-4 flex flex-wrap items-center gap-3">
					<button
						type="button"
						className="ef-entry-button"
						onClick={ () => {
							setSearchTermInput( '' );
							setDateFrom( '' );
							setDateTo( '' );
						} }
						disabled={ ! searchTermInput && ! dateFrom && ! dateTo }
					>
						{ __( 'Clear filters', 'enterprise-forms' ) }
					</button>
					<button
						type="button"
						className="ef-entry-button"
						disabled={ isLoadingEntries || resolvedId <= 0 }
						onClick={ () => setRefreshKey( ( key ) => key + 1 ) }
					>
						{ __( 'Refresh', 'enterprise-forms' ) }
					</button>
				</div>
				<div className="rounded-2xl border border-slate-200 bg-white p-6">
					{ resolvedId === 0 && (
						<p className="text-sm text-slate-700">
							{ __(
								'Select a form above to view its entries.',
								'enterprise-forms'
							) }
						</p>
					) }
					{ resolvedId > 0 && isLoadingEntries && (
						<p className="text-sm text-slate-700">
							{ __( 'Loading entries…', 'enterprise-forms' ) }
						</p>
					) }
					{ resolvedId > 0 && ! isLoadingEntries && entriesError && (
						<p role="alert" className="text-sm text-red-700">
							{ entriesError }
						</p>
					) }
					{ resolvedId > 0 &&
						! isLoadingEntries &&
						! entriesError &&
						entries.length === 0 && (
							<p className="text-sm text-slate-700">
								{ __(
									'No entries found for this form and filter set.',
									'enterprise-forms'
								) }
							</p>
						) }
					{ resolvedId > 0 &&
						! isLoadingEntries &&
						! entriesError &&
						entries.length > 0 && (
							<>
								<div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
									<span>
										{ selectedForm
											? `${ __(
													'Form',
													'enterprise-forms'
											  ) }: ${ selectedForm.title }`
											: '' }
									</span>
									<span>
										{ `${ __(
											'Showing',
											'enterprise-forms'
										) } ${ offset + 1 }-${ Math.min(
											offset + entries.length,
											totalEntries
										) } ${ __(
											'of',
											'enterprise-forms'
										) } ${ totalEntries }` }
									</span>
								</div>

								<div className="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 p-3">
									<label
										htmlFor="ef-select-page"
										className="flex items-center gap-2 text-sm"
									>
										<input
											id="ef-select-page"
											disabled={ ! canEdit }
											type="checkbox"
											aria-label={ __(
												'Select all entries on this page',
												'enterprise-forms'
											) }
											checked={
												entries.length > 0 &&
												selectedIds.length ===
													entries.length
											}
											ref={ ( node ) => {
												if ( node ) {
													node.indeterminate =
														selectedIds.length >
															0 &&
														selectedIds.length <
															entries.length;
												}
											} }
											onChange={ ( event ) =>
												setSelectedIds(
													event.target.checked
														? entries.map(
																( entry ) =>
																	entry.id
														  )
														: []
												)
											}
										/>
										{ sprintf(
											/* translators: %d: number of selected entries. */
											__(
												'%d selected on this page',
												'enterprise-forms'
											),
											selectedIds.length
										) }
									</label>
									<select
										disabled={ ! canEdit }
										aria-label={ __(
											'Bulk action',
											'enterprise-forms'
										) }
										value={ bulkAction }
										onChange={ ( event ) =>
											setBulkAction(
												event.target.value as
													| EntryAction
													| ''
											)
										}
										className="rounded-md border border-slate-300 px-3 py-2 text-sm"
									>
										<option value="">
											{ __(
												'Bulk actions',
												'enterprise-forms'
											) }
										</option>
										{ actions.map( ( action ) => (
											<option
												key={ action.value }
												value={ action.value }
											>
												{ action.label }
											</option>
										) ) }
									</select>
									<button
										type="button"
										className="ef-entry-button"
										disabled={
											! canEdit ||
											! bulkAction ||
											selectedIds.length === 0
										}
										onClick={ () => {
											if ( bulkAction ) {
												void handleAction(
													selectedIds,
													bulkAction
												);
											}
										} }
									>
										{ __( 'Apply', 'enterprise-forms' ) }
									</button>
								</div>
								<div
									className={
										activeEntry
											? 'ef-entry-review-grid'
											: ''
									}
								>
									<div className="min-w-0">
										<div className="overflow-x-auto">
											<table
												className="min-w-full divide-y divide-slate-200 text-sm"
												aria-label={ __(
													'Form submissions',
													'enterprise-forms'
												) }
											>
												<thead>
													<tr className="text-left text-xs uppercase tracking-wide text-slate-500">
														<th className="px-2 py-2">
															<span className="sr-only">
																{ __(
																	'Select',
																	'enterprise-forms'
																) }
															</span>
														</th>
														<th className="px-2 py-2">
															{ __(
																'Submitted',
																'enterprise-forms'
															) }
														</th>
														<th className="px-2 py-2">
															{ __(
																'Contact',
																'enterprise-forms'
															) }
														</th>
														<th className="px-2 py-2">
															{ __(
																'Highlights',
																'enterprise-forms'
															) }
														</th>
														<th className="px-2 py-2">
															{ __(
																'Payment',
																'enterprise-forms'
															) }
														</th>
														<th className="px-2 py-2">
															{ __(
																'Status',
																'enterprise-forms'
															) }
														</th>
														<th className="px-2 py-2">
															{ __(
																'Actions',
																'enterprise-forms'
															) }
														</th>
													</tr>
												</thead>
												<tbody className="divide-y divide-slate-100">
													{ entries.map(
														( entry ) => {
															const payment =
																getPaymentLog(
																	entry.payload
																);
															const isActive =
																entry.id ===
																activeEntryId;
															let rowClass =
																entry.status ===
																'unread'
																	? 'bg-amber-50/40 font-medium'
																	: '';
															if ( isActive ) {
																rowClass =
																	'bg-blue-50';
															}
															return (
																<tr
																	key={
																		entry.id
																	}
																	className={ `align-top ${ rowClass }` }
																>
																	<td className="px-2 py-2">
																		<input
																			type="checkbox"
																			disabled={
																				! canEdit
																			}
																			aria-label={ sprintf(
																				/* translators: %d: entry ID. */
																				__(
																					'Select entry #%d',
																					'enterprise-forms'
																				),
																				entry.id
																			) }
																			checked={ selectedIds.includes(
																				entry.id
																			) }
																			onChange={ (
																				event
																			) =>
																				setSelectedIds(
																					(
																						current
																					) =>
																						event
																							.target
																							.checked
																							? [
																									...current,
																									entry.id,
																							  ]
																							: current.filter(
																									(
																										id
																									) =>
																										id !==
																										entry.id
																							  )
																				)
																			}
																		/>
																	</td>
																	<td className="px-2 py-2 whitespace-nowrap text-slate-700">
																		<div>
																			{ formatDate(
																				entry.created_at
																			) }
																		</div>
																		<div className="font-mono text-[11px] text-slate-500">
																			#
																			{
																				entry.id
																			}
																		</div>
																	</td>
																	<td className="px-2 py-2 text-slate-700">
																		{ getContactSummary(
																			entry.payload
																		) }
																	</td>
																	<td className="px-2 py-2 text-xs text-slate-600">
																		{ getHighlights(
																			entry.payload
																		) }
																	</td>
																	<td className="px-2 py-2 whitespace-nowrap text-slate-700">
																		<div>
																			{ formatPaymentAmount(
																				payment
																			) }
																		</div>
																		{ payment?.receipt_url && (
																			<a
																				href={
																					payment.receipt_url
																				}
																				target="_blank"
																				rel="noreferrer"
																				className="text-xs font-medium text-slate-900 underline hover:no-underline"
																			>
																				{ __(
																					'Receipt',
																					'enterprise-forms'
																				) }
																			</a>
																		) }
																	</td>
																	<td className="px-2 py-2 whitespace-nowrap text-slate-700">
																		<span
																			className={ `inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${ getStatusClass(
																				entry.status
																			) }` }
																		>
																			{ formatEntryStatus(
																				entry.status
																			) }
																		</span>
																	</td>
																	<td className="px-2 py-2 whitespace-nowrap">
																		<button
																			type="button"
																			onClick={ () =>
																				setActiveEntryId(
																					entry.id
																				)
																			}
																			aria-pressed={
																				isActive
																			}
																			aria-controls="ef-entry-details"
																			className="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 transition hover:bg-slate-100"
																		>
																			{ __(
																				'View details',
																				'enterprise-forms'
																			) }
																		</button>
																	</td>
																</tr>
															);
														}
													) }
												</tbody>
											</table>
										</div>

										<div className="mt-4 flex items-center justify-between border-t border-slate-200 pt-4">
											<button
												type="button"
												onClick={ () =>
													setCurrentPage( ( page ) =>
														Math.max( 1, page - 1 )
													)
												}
												disabled={ currentPage <= 1 }
												className="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
											>
												{ __(
													'Previous',
													'enterprise-forms'
												) }
											</button>
											<span className="text-xs text-slate-600">
												{ `${ __(
													'Page',
													'enterprise-forms'
												) } ${ currentPage } ${ __(
													'of',
													'enterprise-forms'
												) } ${ totalPages }` }
											</span>
											<button
												type="button"
												onClick={ () =>
													setCurrentPage( ( page ) =>
														Math.min(
															totalPages,
															page + 1
														)
													)
												}
												disabled={
													currentPage >= totalPages
												}
												className="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
											>
												{ __(
													'Next',
													'enterprise-forms'
												) }
											</button>
										</div>
									</div>
									{ activeEntry && (
										<aside
											id="ef-entry-details"
											ref={ detailsRef }
											tabIndex={ -1 }
											aria-label={ __(
												'Entry details',
												'enterprise-forms'
											) }
											className="ef-entry-details rounded-xl border border-slate-200 bg-slate-50 p-4"
										>
											<div className="mb-3 flex flex-wrap items-center gap-2">
												<button
													type="button"
													className="ef-entry-button"
													disabled={
														entries[ 0 ]?.id ===
														activeEntry.id
													}
													onClick={ () =>
														setActiveEntryId(
															entries[
																entries.indexOf(
																	activeEntry
																) - 1
															].id
														)
													}
												>
													{ __(
														'Previous entry',
														'enterprise-forms'
													) }
												</button>
												<button
													type="button"
													className="ef-entry-button"
													disabled={
														entries[
															entries.length - 1
														]?.id === activeEntry.id
													}
													onClick={ () =>
														setActiveEntryId(
															entries[
																entries.indexOf(
																	activeEntry
																) + 1
															].id
														)
													}
												>
													{ __(
														'Next entry',
														'enterprise-forms'
													) }
												</button>
												<button
													type="button"
													className="ef-entry-button"
													onClick={ () =>
														setActiveEntryId( null )
													}
												>
													{ __(
														'Close details',
														'enterprise-forms'
													) }
												</button>
											</div>
											<div className="mb-4 flex items-center justify-between gap-2">
												<h3 className="text-sm font-semibold text-slate-900">
													{ sprintf(
														/* translators: %d: entry ID. */
														__(
															'Entry #%d',
															'enterprise-forms'
														),
														activeEntry.id
													) }
												</h3>
												<div className="flex flex-wrap items-center gap-2">
													<span
														className={ `inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium ${ getStatusClass(
															activeEntry.status
														) }` }
													>
														{ formatEntryStatus(
															activeEntry.status
														) }
													</span>
												</div>
											</div>
											<p className="mb-2 break-all text-xs text-slate-500">
												{ activeEntry.uuid } ·{ ' ' }
												{ formatDate(
													activeEntry.created_at
												) }
											</p>
											<p className="mb-3 text-xs text-slate-500">
												{ __(
													'Opening details does not mark an entry as read. Use the actions below when you finish reviewing.',
													'enterprise-forms'
												) }
											</p>
											<div className="mb-4 flex flex-wrap gap-2">
												{ canEdit &&
													actions.map( ( action ) => (
														<button
															type="button"
															key={ action.value }
															className="ef-entry-button"
															disabled={
																action.value ===
																activeEntry.status
															}
															onClick={ () =>
																void handleAction(
																	[
																		activeEntry.id,
																	],
																	action.value
																)
															}
														>
															{ action.label }
														</button>
													) ) }
											</div>
											<dl className="grid gap-2 text-sm text-slate-700">
												{ flattenPayload(
													activeEntry.payload
												).map( ( [ key, value ] ) => (
													<div
														key={ key }
														className="rounded-md border border-slate-200 bg-white px-2 py-1.5"
													>
														<dt className="font-mono text-xs text-slate-500">
															{ key }
														</dt>
														<dd className="whitespace-pre-wrap break-words text-slate-800">
															{ value || '--' }
														</dd>
													</div>
												) ) }
											</dl>
										</aside>
									) }
								</div>
							</>
						) }
				</div>
			</fieldset>
			{ deleteIds && (
				<Modal
					title={ __(
						'Permanently delete entries',
						'enterprise-forms'
					) }
					onRequestClose={ () => setDeleteIds( null ) }
					focusOnMount="firstContentElement"
				>
					<p>
						{ sprintf(
							/* translators: %d: number of entries. */
							_n(
								'Permanently delete %d entry? This cannot be undone.',
								'Permanently delete %d entries? This cannot be undone.',
								deleteIds.length,
								'enterprise-forms'
							),
							deleteIds.length
						) }
					</p>
					<p>
						{ __(
							'Associated payment records and uploaded files are not deleted.',
							'enterprise-forms'
						) }
					</p>
					<div className="mt-4 flex gap-3">
						<Button
							variant="secondary"
							onClick={ () => setDeleteIds( null ) }
						>
							{ __( 'Cancel', 'enterprise-forms' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () =>
								void handleAction( deleteIds, 'delete', true )
							}
						>
							{ __( 'Delete permanently', 'enterprise-forms' ) }
						</Button>
					</div>
				</Modal>
			) }
		</section>
	);
};

export default Entries;
