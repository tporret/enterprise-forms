import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import VirtualizedFormTable from '../components/VirtualizedFormTable';
import { useEnterpriseForms } from '../hooks/useEnterpriseForms';
import { useToast } from '../toast/ToastContext';

const Dashboard = (): JSX.Element => {
	const { forms, isLoading, isRefreshing, error, createForm, duplicateForm, deleteForm, refresh } = useEnterpriseForms();
	const { addToast } = useToast();
	const navigate = useNavigate();
	const [ isMutating, setIsMutating ] = useState( false );

	const handleCreate = async (): Promise<void> => {
		setIsMutating( true );
		try {
			const newId = await createForm();
			void navigate( `/builder/${ newId }` );
		} catch {
			addToast( __( 'Could not create form.', 'enterprise-forms' ), 'error' );
		} finally {
			setIsMutating( false );
		}
	};

	const stats = useMemo( () => {
		const published = forms.filter( ( form ) => form.status === 'publish' ).length;
		const drafts = forms.filter( ( form ) => form.status === 'draft' ).length;
		const totalSubmissions = forms.reduce( ( sum, form ) => sum + form.submissionCount, 0 );
		return { published, drafts, totalSubmissions };
	}, [ forms ] );

	const handleDuplicate = async ( formId: number ): Promise<void> => {
		setIsMutating( true );
		try {
			await duplicateForm( formId );
			addToast( __( 'Form duplicated.', 'enterprise-forms' ), 'success' );
		} catch {
			addToast( __( 'Duplicate failed and was reverted.', 'enterprise-forms' ), 'error' );
		} finally {
			setIsMutating( false );
		}
	};

	const handleDelete = async ( formId: number ): Promise<void> => {
		setIsMutating( true );
		try {
			await deleteForm( formId );
			addToast( __( 'Form deleted.', 'enterprise-forms' ), 'success' );
		} catch {
			addToast( __( 'Delete failed and was reverted.', 'enterprise-forms' ), 'error' );
		} finally {
			setIsMutating( false );
		}
	};

	return (
		<section className="p-6 lg:p-10">
			<div className="mb-8 flex flex-wrap items-start justify-between gap-3">
				<div>
					<h2 className="text-2xl font-semibold tracking-tight">{ __( 'Dashboard', 'enterprise-forms' ) }</h2>
					<p className="mt-2 text-sm text-slate-600">{ __( 'High-performance forms overview with instant cached return.', 'enterprise-forms' ) }</p>
				</div>
				<div className="flex items-center gap-2">
					{ isRefreshing && <Spinner /> }
					<Button variant="secondary" onClick={ refresh } disabled={ isRefreshing || isMutating }>
						{ __( 'Refresh', 'enterprise-forms' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ () => { void handleCreate(); } }
						disabled={ isRefreshing || isMutating }
					>
						{ __( 'New Form', 'enterprise-forms' ) }
					</Button>
				</div>
			</div>

			<div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
				<div className="rounded-2xl border border-slate-200 bg-white p-5">
					<p className="text-xs uppercase tracking-wide text-slate-500">{ __( 'Total Forms', 'enterprise-forms' ) }</p>
					<p className="mt-2 text-3xl font-semibold text-slate-900">{ forms.length }</p>
				</div>
				<div className="rounded-2xl border border-slate-200 bg-white p-5">
					<p className="text-xs uppercase tracking-wide text-slate-500">{ __( 'Published / Draft', 'enterprise-forms' ) }</p>
					<p className="mt-2 text-3xl font-semibold text-slate-900">{ stats.published } / { stats.drafts }</p>
				</div>
				<div className="rounded-2xl border border-slate-200 bg-white p-5">
					<p className="text-xs uppercase tracking-wide text-slate-500">{ __( 'Total Submissions', 'enterprise-forms' ) }</p>
					<p className="mt-2 text-3xl font-semibold text-slate-900">{ stats.totalSubmissions }</p>
				</div>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false } className="mb-4">
					{ __( 'Could not load dashboard data.', 'enterprise-forms' ) }
				</Notice>
			) }

			<div className="rounded-2xl border border-slate-200 bg-white p-1">
				<VirtualizedFormTable
					rows={ forms }
					isBusy={ isLoading || isRefreshing || isMutating }
					onDuplicate={ ( formId ) => {
						void handleDuplicate( formId );
					} }
					onDelete={ ( formId ) => {
						void handleDelete( formId );
					} }
				/>
			</div>
		</section>
	);
};

export default Dashboard;
