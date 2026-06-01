import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useMemo, useState } from '@wordpress/element';
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
}

interface PaymentLog {
	amount?: number;
	currency?: string;
	receipt_url?: string;
}

const ZERO_DECIMAL_CURRENCIES = [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];
const STATUS_FILTERS = [ 'all', 'unread', 'read', 'spam' ];
const BASE_EXPORT_COLUMNS = [ 'entry_id', 'entry_uuid', 'status', 'created_at' ];

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

const flattenPayload = ( payload: Record< string, unknown >, prefix = '' ): Array< [ string, string ] > => {
	const rows: Array< [ string, string ] > = [];

	Object.entries( payload ).forEach( ( [ rawKey, rawValue ] ) => {
		const key = prefix ? `${ prefix }.${ rawKey }` : rawKey;

		if ( rawValue && typeof rawValue === 'object' && ! Array.isArray( rawValue ) ) {
			rows.push( ...flattenPayload( rawValue as Record< string, unknown >, key ) );
			return;
		}

		rows.push( [ key, stringifyValue( rawValue ) ] );
	} );

	return rows;
};

const toColumnKey = ( rawKey: string ): string => rawKey.toLowerCase().replace( /[^a-z0-9_.-]/g, '' );

const getPaymentLog = ( payload: Record< string, unknown > ): PaymentLog | null => {
	const payment = payload.payment;
	return payment && typeof payment === 'object' && ! Array.isArray( payment ) ? ( payment as PaymentLog ) : null;
};

const formatPaymentAmount = ( payment: PaymentLog | null ): string => {
	if ( ! payment || typeof payment.amount !== 'number' ) {
		return '--';
	}

	const currency = ( payment.currency || 'usd' ).toUpperCase();
	const isZeroDecimal = ZERO_DECIMAL_CURRENCIES.includes( currency );
	const amount = isZeroDecimal ? payment.amount : payment.amount / 100;
	return `${ isZeroDecimal ? String( amount ) : amount.toFixed( 2 ) } ${ currency }`;
};

const getContactSummary = ( payload: Record< string, unknown > ): string => {
	const flattened = flattenPayload( payload );
	const email = flattened.find( ( [ key, value ] ) => /email/i.test( key ) && value.trim() !== '' )?.[ 1 ] || '';
	const name = flattened.find( ( [ key, value ] ) => /name/i.test( key ) && value.trim() !== '' )?.[ 1 ] || '';

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
		.filter( ( [ key ] ) => key !== 'ep_forms_nonce' && key !== 'ep_submission_token' && key !== 'hp_field' )
		.filter( ( [ , value ] ) => value.trim() !== '' )
		.slice( 0, 3 )
		.map( ( [ key, value ] ) => `${ key }: ${ value.length > 48 ? `${ value.slice( 0, 45 ) }...` : value }` );

	return values.length > 0 ? values.join( ' | ' ) : '--';
};

const parseFilenameFromHeader = ( headerValue: string | null ): string | null => {
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
	const { forms } = useEnterpriseForms();
	const [ entries, setEntries ] = useState< EntryRow[] >( [] );
	const [ isLoadingEntries, setIsLoadingEntries ] = useState( false );
	const [ isExporting, setIsExporting ] = useState( false );
	const [ entriesError, setEntriesError ] = useState< string | null >( null );
	const [ statusFilter, setStatusFilter ] = useState( 'all' );
	const [ searchTermInput, setSearchTermInput ] = useState( '' );
	const [ debouncedSearchTerm, setDebouncedSearchTerm ] = useState( '' );
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ] = useState( '' );
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const [ pageSize, setPageSize ] = useState( 25 );
	const [ totalEntries, setTotalEntries ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ activeEntryId, setActiveEntryId ] = useState< number | null >( null );
	const [ selectedExportColumns, setSelectedExportColumns ] = useState< string[] >( [] );
	const resolvedId = Number( formId ) || 0;
	const selectedForm = useMemo( () => forms.find( ( form ) => form.id === resolvedId ), [ forms, resolvedId ] );
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
	}, [ resolvedId, statusFilter, pageSize, debouncedSearchTerm, dateFrom, dateTo ] );

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
		if ( resolvedId <= 0 ) {
			setEntries( [] );
			setEntriesError( null );
			setTotalEntries( 0 );
			setTotalPages( 1 );
			setActiveEntryId( null );
			return;
		}

		const params = new URLSearchParams( {
			offset: String( offset ),
			limit: String( pageSize ),
		} );

		if ( statusFilter !== 'all' ) {
			params.set( 'status', statusFilter );
		}
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

				const nextItems = Array.isArray( response.items ) ? response.items : [];
				setEntries( nextItems );
				setTotalEntries( Number( response.total ?? nextItems.length ) );
				setTotalPages( Math.max( 1, Number( response.total_pages ?? 1 ) ) );
				setActiveEntryId( ( current ) => {
					if ( current && nextItems.some( ( entry ) => entry.id === current ) ) {
						return current;
					}
					return nextItems[ 0 ]?.id ?? null;
				} );
			} )
			.catch( () => {
				if ( isCancelled ) {
					return;
				}

				setEntries( [] );
				setEntriesError( __( 'Unable to load entries for this form.', 'enterprise-forms' ) );
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
	}, [ offset, pageSize, resolvedId, statusFilter, debouncedSearchTerm, dateFrom, dateTo ] );

	const toggleExportColumn = ( column: string ): void => {
		setSelectedExportColumns( ( current ) =>
			current.includes( column ) ? current.filter( ( value ) => value !== column ) : [ ...current, column ]
		);
	};

	const handleExport = async (): Promise< void > => {
		if ( resolvedId <= 0 || isExporting ) {
			return;
		}

		const params = new URLSearchParams();
		if ( statusFilter !== 'all' ) {
			params.set( 'status', statusFilter );
		}
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
		setEntriesError( null );

		try {
			const response = await apiFetch< Response >( {
				path: `/enterprise-forms/v1/entries/${ resolvedId }/export${ params.toString() ? `?${ params.toString() }` : '' }`,
				parse: false,
			} );

			if ( ! response.ok ) {
				throw new Error( 'export_failed' );
			}

			const blob = await response.blob();
			const downloadUrl = URL.createObjectURL( blob );
			const filename = parseFilenameFromHeader( response.headers.get( 'content-disposition' ) ) || `form-${ resolvedId }-entries.csv`;

			const anchor = document.createElement( 'a' );
			anchor.href = downloadUrl;
			anchor.download = filename;
			document.body.appendChild( anchor );
			anchor.click();
			anchor.remove();
			URL.revokeObjectURL( downloadUrl );
		} catch {
			setEntriesError( __( 'Unable to export entries right now.', 'enterprise-forms' ) );
		} finally {
			setIsExporting( false );
		}
	};

	return (
		<section className="p-6 lg:p-10">
			<div className="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
				<div>
					<h2 className="text-2xl font-semibold tracking-tight">{ __( 'Entries', 'enterprise-forms' ) }</h2>
					<p className="mt-2 text-sm text-slate-600">{ __( 'Review submissions with advanced filtering, full dataset search, and CSV export controls.', 'enterprise-forms' ) }</p>
				</div>
				<button
					type="button"
					onClick={ () => void handleExport() }
					disabled={ resolvedId <= 0 || isLoadingEntries || isExporting }
					className="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
				>
					{ isExporting ? __( 'Preparing CSV...', 'enterprise-forms' ) : __( 'Download CSV', 'enterprise-forms' ) }
				</button>
			</div>

			<div className="mb-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-2 lg:grid-cols-4">
				<div className="space-y-1">
					<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="ef-form-select">
						{ __( 'Form', 'enterprise-forms' ) }
					</label>
					<select
						id="ef-form-select"
						value={ resolvedId }
						onChange={ ( event ) => void navigate( `/entries/${ event.target.value }` ) }
						className="w-full rounded-md border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
						aria-label={ __( 'Select form entries', 'enterprise-forms' ) }
					>
						{ resolvedId === 0 && <option value="0" disabled>{ __( '-- Select a form --', 'enterprise-forms' ) }</option> }
						{ forms.map( ( form ) => (
							<option key={ form.id } value={ form.id }>{ form.title }</option>
						) ) }
					</select>
				</div>

				<div className="space-y-1">
					<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="ef-status-filter">
						{ __( 'Status', 'enterprise-forms' ) }
					</label>
					<select
						id="ef-status-filter"
						value={ statusFilter }
						onChange={ ( event ) => setStatusFilter( event.target.value ) }
						className="w-full rounded-md border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
						disabled={ resolvedId <= 0 }
					>
						{ STATUS_FILTERS.map( ( status ) => (
							<option key={ status } value={ status }>
								{ status === 'all' ? __( 'All statuses', 'enterprise-forms' ) : status }
							</option>
						) ) }
					</select>
				</div>

				<div className="space-y-1">
					<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="ef-page-size">
						{ __( 'Rows', 'enterprise-forms' ) }
					</label>
					<select
						id="ef-page-size"
						value={ pageSize }
						onChange={ ( event ) => setPageSize( Number( event.target.value ) || 25 ) }
						className="w-full rounded-md border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
						disabled={ resolvedId <= 0 }
					>
						<option value={ 25 }>25</option>
						<option value={ 50 }>50</option>
						<option value={ 100 }>100</option>
					</select>
				</div>

				<div className="space-y-1">
					<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="ef-entry-search">
						{ __( 'Search all entries', 'enterprise-forms' ) }
					</label>
					<input
						id="ef-entry-search"
						type="search"
						value={ searchTermInput }
						onChange={ ( event ) => setSearchTermInput( event.target.value ) }
						placeholder={ __( 'UUID, status, or any field value...', 'enterprise-forms' ) }
						className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
						disabled={ resolvedId <= 0 }
					/>
				</div>
			</div>

			<div className="mb-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-2 lg:grid-cols-4">
				<div className="space-y-1">
					<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="ef-date-from">
						{ __( 'Date from', 'enterprise-forms' ) }
					</label>
					<input
						id="ef-date-from"
						type="date"
						value={ dateFrom }
						onChange={ ( event ) => setDateFrom( event.target.value ) }
						className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
						disabled={ resolvedId <= 0 }
					/>
				</div>
				<div className="space-y-1">
					<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="ef-date-to">
						{ __( 'Date to', 'enterprise-forms' ) }
					</label>
					<input
						id="ef-date-to"
						type="date"
						value={ dateTo }
						onChange={ ( event ) => setDateTo( event.target.value ) }
						className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
						disabled={ resolvedId <= 0 }
					/>
				</div>
				<div className="md:col-span-2 space-y-1">
					<div className="flex items-center justify-between">
						<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">
							{ __( 'CSV columns', 'enterprise-forms' ) }
						</label>
						<button
							type="button"
							onClick={ () => setSelectedExportColumns( [] ) }
							className="text-xs font-medium text-slate-600 underline hover:no-underline"
							disabled={ resolvedId <= 0 || selectedExportColumns.length === 0 }
						>
							{ __( 'Use all columns', 'enterprise-forms' ) }
						</button>
					</div>
					<div className="max-h-28 overflow-y-auto rounded-md border border-slate-200 bg-slate-50 p-2">
						{ selectableColumns.map( ( column ) => {
							const checked = selectedExportColumns.includes( column );
							return (
								<label key={ column } className="flex items-center gap-2 py-0.5 text-xs text-slate-700">
									<input
										type="checkbox"
										checked={ checked }
										onChange={ () => toggleExportColumn( column ) }
										disabled={ resolvedId <= 0 }
									/>
									<span className="font-mono">{ column }</span>
								</label>
							);
						} ) }
					</div>
					<p className="text-[11px] text-slate-500">
						{ selectedExportColumns.length > 0
							? __( 'Only selected columns will be exported.', 'enterprise-forms' )
							: __( 'No columns selected: export will include all available columns.', 'enterprise-forms' ) }
					</p>
				</div>
			</div>

			<div className="rounded-2xl border border-slate-200 bg-white p-6">
				{ resolvedId === 0 ? (
					<p className="text-sm text-slate-700">{ __( 'Select a form above to view its entries.', 'enterprise-forms' ) }</p>
				) : isLoadingEntries ? (
					<p className="text-sm text-slate-700">{ __( 'Loading entries...', 'enterprise-forms' ) }</p>
				) : entriesError ? (
					<p className="text-sm text-red-700">{ entriesError }</p>
				) : entries.length === 0 ? (
					<p className="text-sm text-slate-700">{ __( 'No entries found for this form and filter set.', 'enterprise-forms' ) }</p>
				) : (
					<>
						<div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
							<span>{ selectedForm ? `${ __( 'Form', 'enterprise-forms' ) }: ${ selectedForm.title }` : '' }</span>
							<span>
								{ `${ __( 'Showing', 'enterprise-forms' ) } ${ offset + 1 }-${ Math.min( offset + entries.length, totalEntries ) } ${ __( 'of', 'enterprise-forms' ) } ${ totalEntries }` }
							</span>
						</div>

						<div className="overflow-x-auto">
							<table className="min-w-full divide-y divide-slate-200 text-sm">
								<thead>
									<tr className="text-left text-xs uppercase tracking-wide text-slate-500">
										<th className="px-2 py-2">{ __( 'Submitted', 'enterprise-forms' ) }</th>
										<th className="px-2 py-2">{ __( 'Contact', 'enterprise-forms' ) }</th>
										<th className="px-2 py-2">{ __( 'Highlights', 'enterprise-forms' ) }</th>
										<th className="px-2 py-2">{ __( 'Payment', 'enterprise-forms' ) }</th>
										<th className="px-2 py-2">{ __( 'Status', 'enterprise-forms' ) }</th>
										<th className="px-2 py-2">{ __( 'Actions', 'enterprise-forms' ) }</th>
									</tr>
								</thead>
								<tbody className="divide-y divide-slate-100">
									{ entries.map( ( entry ) => {
										const payment = getPaymentLog( entry.payload );
										const isActive = entry.id === activeEntryId;
										return (
											<tr key={ entry.id } className={ `align-top ${ isActive ? 'bg-slate-50/80' : '' }` }>
												<td className="px-2 py-2 whitespace-nowrap text-slate-700">
													<div>{ formatDate( entry.created_at ) }</div>
													<div className="font-mono text-[11px] text-slate-500">{ entry.uuid }</div>
												</td>
												<td className="px-2 py-2 text-slate-700">{ getContactSummary( entry.payload ) }</td>
												<td className="px-2 py-2 text-xs text-slate-600">{ getHighlights( entry.payload ) }</td>
												<td className="px-2 py-2 whitespace-nowrap text-slate-700">
													<div>{ formatPaymentAmount( payment ) }</div>
													{ payment?.receipt_url && (
														<a href={ payment.receipt_url } target="_blank" rel="noreferrer" className="text-xs font-medium text-slate-900 underline hover:no-underline">
															{ __( 'Receipt', 'enterprise-forms' ) }
														</a>
													) }
												</td>
												<td className="px-2 py-2 whitespace-nowrap text-slate-700">{ entry.status }</td>
												<td className="px-2 py-2 whitespace-nowrap">
													<button
														type="button"
														onClick={ () => setActiveEntryId( entry.id ) }
														className="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 transition hover:bg-slate-100"
													>
														{ __( 'View details', 'enterprise-forms' ) }
													</button>
												</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>

						<div className="mt-4 flex items-center justify-between border-t border-slate-200 pt-4">
							<button
								type="button"
								onClick={ () => setCurrentPage( ( page ) => Math.max( 1, page - 1 ) ) }
								disabled={ currentPage <= 1 }
								className="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
							>
								{ __( 'Previous', 'enterprise-forms' ) }
							</button>
							<span className="text-xs text-slate-600">
								{ `${ __( 'Page', 'enterprise-forms' ) } ${ currentPage } ${ __( 'of', 'enterprise-forms' ) } ${ totalPages }` }
							</span>
							<button
								type="button"
								onClick={ () => setCurrentPage( ( page ) => Math.min( totalPages, page + 1 ) ) }
								disabled={ currentPage >= totalPages }
								className="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
							>
								{ __( 'Next', 'enterprise-forms' ) }
							</button>
						</div>

						{ activeEntry && (
							<div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
								<div className="mb-2 flex items-center justify-between">
									<h3 className="text-sm font-semibold text-slate-900">{ __( 'Entry details', 'enterprise-forms' ) }</h3>
									<span className="font-mono text-[11px] text-slate-500">{ activeEntry.uuid }</span>
								</div>
								<div className="grid gap-2 text-xs text-slate-700 md:grid-cols-2">
									{ flattenPayload( activeEntry.payload ).map( ( [ key, value ] ) => (
										<div key={ key } className="rounded-md border border-slate-200 bg-white px-2 py-1.5">
											<div className="font-mono text-[11px] text-slate-500">{ key }</div>
											<div className="break-all text-slate-800">{ value || '--' }</div>
										</div>
									) ) }
								</div>
							</div>
						) }
					</>
				) }
			</div>
		</section>
	);
};

export default Entries;
