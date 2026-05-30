import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
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
}

interface PaymentLog {
	transaction_id?: string;
	amount?: number;
	currency?: string;
	receipt_url?: string;
}

const formatPayload = ( payload: Record< string, unknown > ): string => {
	const raw = JSON.stringify( payload );
	if ( raw.length > 200 ) {
		return `${ raw.slice( 0, 197 ) }...`;
	}

	return raw;
};

const getPaymentLog = ( payload: Record< string, unknown > ): PaymentLog | null => {
	const payment = payload.payment;
	return payment && typeof payment === 'object' && ! Array.isArray( payment ) ? payment as PaymentLog : null;
};

const formatPaymentAmount = ( payment: PaymentLog | null ): string => {
	if ( ! payment || typeof payment.amount !== 'number' ) {
		return '—';
	}

	const currency = ( payment.currency || 'usd' ).toUpperCase();
	const zeroDecimalCurrencies = [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];
	const isZeroDecimal = zeroDecimalCurrencies.includes( currency );
	const amount = isZeroDecimal ? payment.amount : payment.amount / 100;
	return `${ isZeroDecimal ? String( amount ) : amount.toFixed( 2 ) } ${ currency }`;
};

const Entries = (): JSX.Element => {
	const { formId } = useParams< 'formId' >();
	const navigate = useNavigate();
	const { forms } = useEnterpriseForms();
	const [ entries, setEntries ] = useState< EntryRow[] >( [] );
	const [ isLoadingEntries, setIsLoadingEntries ] = useState( false );
	const [ entriesError, setEntriesError ] = useState< string | null >( null );
	const resolvedId = Number( formId ) || 0;

	useEffect( () => {
		if ( resolvedId <= 0 ) {
			setEntries( [] );
			setEntriesError( null );
			return;
		}

		let isCancelled = false;
		setIsLoadingEntries( true );
		setEntriesError( null );

		apiFetch< EntriesResponse >( {
			path: `/enterprise-forms/v1/entries/${ resolvedId }?offset=0&limit=100`,
		} )
			.then( ( response ) => {
				if ( isCancelled ) {
					return;
				}

				setEntries( Array.isArray( response.items ) ? response.items : [] );
			} )
			.catch( () => {
				if ( isCancelled ) {
					return;
				}

				setEntries( [] );
				setEntriesError( __( 'Unable to load entries for this form.', 'enterprise-forms' ) );
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
	}, [ resolvedId ] );

	return (
		<section className="p-6 lg:p-10">
			<div className="mb-6">
				<h2 className="text-2xl font-semibold tracking-tight">{ __( 'Entries', 'enterprise-forms' ) }</h2>
				<p className="mt-2 text-sm text-slate-600">{ __( 'View encrypted submissions by form.', 'enterprise-forms' ) }</p>
			</div>

			<div className="mb-4">
				<select
					value={ resolvedId }
					onChange={ ( event ) => void navigate( `/entries/${ event.target.value }` ) }
					className="rounded-md border border-slate-300 bg-white py-1.5 pl-3 pr-8 text-sm text-slate-700 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
					aria-label={ __( 'Select form entries', 'enterprise-forms' ) }
				>
					{ resolvedId === 0 && (
						<option value="0" disabled>{ __( '— Select a form —', 'enterprise-forms' ) }</option>
					) }
					{ forms.map( ( form ) => (
						<option key={ form.id } value={ form.id }>{ form.title }</option>
					) ) }
				</select>
			</div>

			<div className="rounded-2xl border border-slate-200 bg-white p-6">
				{ resolvedId === 0 ? (
					<p className="text-sm text-slate-700">{ __( 'Select a form above to view its entries.', 'enterprise-forms' ) }</p>
				) : isLoadingEntries ? (
					<p className="text-sm text-slate-700">{ __( 'Loading entries…', 'enterprise-forms' ) }</p>
				) : entriesError ? (
					<p className="text-sm text-red-700">{ entriesError }</p>
				) : entries.length === 0 ? (
					<p className="text-sm text-slate-700">{ __( 'No entries found for this form.', 'enterprise-forms' ) }</p>
				) : (
					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-slate-200 text-sm">
							<thead>
								<tr className="text-left text-xs uppercase tracking-wide text-slate-500">
									<th className="px-2 py-2">{ __( 'Date', 'enterprise-forms' ) }</th>
									<th className="px-2 py-2">{ __( 'Status', 'enterprise-forms' ) }</th>
									<th className="px-2 py-2">{ __( 'Entry UUID', 'enterprise-forms' ) }</th>
									<th className="px-2 py-2">{ __( 'Payment', 'enterprise-forms' ) }</th>
									<th className="px-2 py-2">{ __( 'Payload', 'enterprise-forms' ) }</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-slate-100">
								{ entries.map( ( entry ) => {
									const payment = getPaymentLog( entry.payload );
									return (
										<tr key={ entry.id } className="align-top">
											<td className="px-2 py-2 whitespace-nowrap text-slate-700">{ entry.created_at }</td>
											<td className="px-2 py-2 whitespace-nowrap text-slate-700">{ entry.status }</td>
											<td className="px-2 py-2 font-mono text-xs text-slate-600">{ entry.uuid }</td>
											<td className="px-2 py-2 whitespace-nowrap text-slate-700">
												<div>{ formatPaymentAmount( payment ) }</div>
												{ payment?.receipt_url && (
													<a href={ payment.receipt_url } target="_blank" rel="noreferrer" className="text-xs font-medium text-slate-900 underline hover:no-underline">
														{ __( 'Receipt', 'enterprise-forms' ) }
													</a>
												) }
											</td>
											<td className="px-2 py-2 font-mono text-xs text-slate-600">{ formatPayload( entry.payload ) }</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
				) }
			</div>
		</section>
	);
};

export default Entries;
