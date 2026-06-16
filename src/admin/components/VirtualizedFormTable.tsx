import { useVirtualizer } from '@tanstack/react-virtual';
import { Button, Spinner } from '@wordpress/components';
import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useNavigate } from 'react-router-dom';
import type { EnterpriseFormRow } from '../hooks/useEnterpriseForms';

interface VirtualizedFormTableProps {
	rows: EnterpriseFormRow[];
	isBusy: boolean;
	onDuplicate: ( formId: number ) => void;
	onDelete: ( formId: number ) => void;
}

const formatDate = ( dateInput: string ): string => {
	if ( ! dateInput ) {
		return '—';
	}
	return new Date( dateInput ).toLocaleDateString();
};

const formatStatus = ( status: string ): string => {
	if ( status === 'publish' ) {
		return 'Published';
	}

	if ( status === 'inactive' ) {
		return 'Inactive';
	}

	return status === 'draft' ? 'Draft' : status;
};

const VirtualizedFormTable = ( { rows, isBusy, onDuplicate, onDelete }: VirtualizedFormTableProps ): JSX.Element => {
	const parentRef = useRef< HTMLDivElement | null >( null );
	const navigate = useNavigate();

	const rowVirtualizer = useVirtualizer( {
		count: rows.length,
		getScrollElement: () => parentRef.current,
		estimateSize: () => 66,
		overscan: 10,
	} );

	const virtualRows = rowVirtualizer.getVirtualItems();
	const totalSize = rowVirtualizer.getTotalSize();

	return (
		<div className="rounded-2xl border border-slate-200 bg-white">
			<div className="grid grid-cols-[2fr_1fr_1fr_2fr_1fr_1.3fr] gap-4 border-b border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
				<span>{ __( 'Form Name', 'enterprise-forms' ) }</span>
				<span>{ __( 'Status', 'enterprise-forms' ) }</span>
				<span>{ __( 'Submission Count', 'enterprise-forms' ) }</span>
				<span>{ __( 'Notifications', 'enterprise-forms' ) }</span>
				<span>{ __( 'Last Modified', 'enterprise-forms' ) }</span>
				<span>{ __( 'Actions', 'enterprise-forms' ) }</span>
			</div>

			<div ref={ parentRef } className="h-[560px] overflow-auto">
				{ isBusy && rows.length === 0 ? (
					<div className="flex h-full items-center justify-center">
						<Spinner />
					</div>
				) : (
					<div className="relative" style={ { height: `${ totalSize }px` } }>
						{ virtualRows.map( ( virtualRow ) => {
							const row = rows[ virtualRow.index ];
							const badgeClass = row.notificationEnabled
								? 'border-green-200 bg-green-50 text-green-700'
								: 'border-slate-200 bg-slate-50 text-slate-600';
							return (
								<div
									key={ row.id }
									className="absolute left-0 top-0 grid w-full grid-cols-[2fr_1fr_1fr_2fr_1fr_1.3fr] gap-4 border-b border-slate-100 px-4 py-3 text-sm"
									style={ { transform: `translateY(${ virtualRow.start }px)` } }
								>
									<span className="truncate font-medium text-slate-900" title={ row.title }>{ row.title }</span>
									<span>{ formatStatus( row.status ) }</span>
									<span>{ row.submissionCount }</span>
									<div className="min-w-0">
										<span className={ `inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${ badgeClass }` }>{ row.notificationLabel }</span>
										<p className="mt-1 truncate text-xs text-slate-500" title={ row.notificationTransport }>{ row.notificationTransport }</p>
									</div>
									<span>{ formatDate( row.lastModified ) }</span>
									<div className="flex flex-wrap items-center gap-2">
										<Button size="small" variant="secondary" onClick={ () => void navigate( `/builder/${ row.id }` ) }>
											{ __( 'Edit', 'enterprise-forms' ) }
										</Button>
										<Button size="small" variant="secondary" onClick={ () => onDuplicate( row.id ) }>
											{ __( 'Duplicate', 'enterprise-forms' ) }
										</Button>
										<Button size="small" variant="secondary" isDestructive onClick={ () => onDelete( row.id ) }>
											{ __( 'Delete', 'enterprise-forms' ) }
										</Button>
									</div>
								</div>
							);
						} ) }
					</div>
				) }
			</div>
		</div>
	);
};

export default VirtualizedFormTable;
