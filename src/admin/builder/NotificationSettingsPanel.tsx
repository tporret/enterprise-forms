import apiFetch from '@wordpress/api-fetch';
import { Card, CardBody, CardHeader, CheckboxControl, TextControl, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useBuilderState } from './useBuilderState';

interface NotificationFormStatus {
	enabled?: boolean;
	has_custom_recipients?: boolean;
	using_admin_fallback?: boolean;
	resolved_recipients?: string[];
}

interface NotificationTransportStatus {
	mode?: string;
	provider?: string;
	configured?: boolean;
	from_email?: string;
	from_name?: string;
	host?: string;
}

interface NotificationStatusesResponse {
	transport?: NotificationTransportStatus;
	forms?: Record< string, NotificationFormStatus >;
}

const NotificationSettingsPanel = (): JSX.Element => {
	const [ isOpen, setIsOpen ] = useState( false );
	const formId = useBuilderState( ( state ) => state.formId );
	const notification = useBuilderState( ( state ) => state.schema.settings.notification );
	const fields = useBuilderState( ( state ) => state.schema.fields );
	const setNotificationEnabled = useBuilderState( ( state ) => state.setNotificationEnabled );
	const setNotificationRecipients = useBuilderState( ( state ) => state.setNotificationRecipients );
	const setNotificationIncludedFieldIds = useBuilderState( ( state ) => state.setNotificationIncludedFieldIds );

	const [ isLoadingStatus, setIsLoadingStatus ] = useState( false );
	const [ transport, setTransport ] = useState< NotificationTransportStatus | null >( null );
	const [ formStatus, setFormStatus ] = useState< NotificationFormStatus | null >( null );

	// Eligible fields are all non-submit, non-hidden types
	const eligibleFields = fields.filter( ( f ) => f.type !== 'submit' && f.type !== 'hidden' );

	// null means "all included"; expand to full id list for checkbox rendering
	const effectiveIds: string[] = notification.included_field_ids
		?? eligibleFields.map( ( f ) => f.id );
	const includedSet = new Set( effectiveIds );

	const toggleFieldId = ( fieldId: string, checked: boolean ): void => {
		const next = checked
			? [ ...effectiveIds.filter( ( id ) => id !== fieldId ), fieldId ]
			: effectiveIds.filter( ( id ) => id !== fieldId );

		// If all eligible fields are now included, store null (clean default)
		const allEligibleIds = eligibleFields.map( ( f ) => f.id );
		const isAll = allEligibleIds.every( ( id ) => next.includes( id ) ) && next.length === allEligibleIds.length;
		setNotificationIncludedFieldIds( isAll ? null : next );
	};

	const selectAll = (): void => setNotificationIncludedFieldIds( null );
	const deselectAll = (): void => setNotificationIncludedFieldIds( [] );

	useEffect( () => {
		if ( formId <= 0 ) {
			setTransport( null );
			setFormStatus( null );
			return;
		}

		let cancelled = false;
		setIsLoadingStatus( true );

		apiFetch< NotificationStatusesResponse >( {
			path: `/enterprise-forms/v1/notifications/statuses?form_ids=${ formId }`,
		} )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				setTransport( response.transport ?? null );
				setFormStatus( response.forms?.[ String( formId ) ] ?? null );
			} )
			.catch( () => {
				if ( cancelled ) {
					return;
				}
				setTransport( null );
				setFormStatus( null );
			} )
			.finally( () => {
				if ( cancelled ) {
					return;
				}
				setIsLoadingStatus( false );
			} );

		return () => {
			cancelled = true;
		};
	}, [ formId ] );

	const resolvedRecipients = formStatus?.resolved_recipients ?? [];
	const recipientHint = resolvedRecipients.length > 0
		? resolvedRecipients.join( ', ' )
		: __( 'No valid recipients resolved yet.', 'enterprise-forms' );

	return (
		<Card>
			<CardHeader
				className="cursor-pointer select-none"
				onClick={ () => setIsOpen( ( prev ) => ! prev ) }
			>
				<strong className="flex-1">{ __( 'Notification Settings', 'enterprise-forms' ) }</strong>
				<span className="text-slate-400 text-xs">{ isOpen ? '▲' : '▼' }</span>
			</CardHeader>
			{ isOpen && <CardBody>
				<ToggleControl
					label={ __( 'Enable submission email notifications', 'enterprise-forms' ) }
					checked={ Boolean( notification.enabled ) }
					onChange={ ( enabled ) => setNotificationEnabled( enabled ) }
					disabled={ formId <= 0 }
				/>
				{ notification.enabled && (
					<>
						<TextControl
							label={ __( 'Recipients (comma-separated user emails)', 'enterprise-forms' ) }
							value={ notification.recipients }
							onChange={ ( value ) => setNotificationRecipients( value ) }
							help={ __( 'Only existing site user emails are used. Leave empty to fallback to the site admin email.', 'enterprise-forms' ) }
							disabled={ formId <= 0 }
						/>

						<div className="mb-3">
							<div className="mb-1 flex items-center justify-between">
								<p className="text-xs font-semibold uppercase tracking-wide text-slate-700">
									{ __( 'Fields to include in email', 'enterprise-forms' ) }
								</p>
								{ eligibleFields.length > 0 && (
									<div className="flex gap-2 text-xs text-slate-500">
										<button type="button" className="hover:text-slate-800 hover:underline" onClick={ selectAll }>
											{ __( 'All', 'enterprise-forms' ) }
										</button>
										<span>/</span>
										<button type="button" className="hover:text-slate-800 hover:underline" onClick={ deselectAll }>
											{ __( 'None', 'enterprise-forms' ) }
										</button>
									</div>
								) }
							</div>
							{ eligibleFields.length === 0 ? (
								<p className="text-xs text-slate-400 italic">
									{ __( 'Add fields to the form to configure email content.', 'enterprise-forms' ) }
								</p>
							) : (
								<div className="max-h-52 overflow-y-auto rounded border border-slate-200 bg-slate-50 px-2 py-1">
									{ eligibleFields.map( ( field ) => (
										<CheckboxControl
											key={ field.id }
											label={ field.label || field.id }
											checked={ includedSet.has( field.id ) }
											onChange={ ( checked ) => toggleFieldId( field.id, checked ) }
											className="!mb-0 py-0.5 text-sm"
										/>
									) ) }
								</div>
							) }
						</div>

						<div className="mt-1 rounded border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
							<p className="font-semibold text-slate-800">{ __( 'Mail Transport Status', 'enterprise-forms' ) }</p>
							<p>
								{ isLoadingStatus
									? __( 'Loading transport status...', 'enterprise-forms' )
									: `${ transport?.provider || __( 'Unknown provider', 'enterprise-forms' ) } (${ transport?.mode || 'wp_mail' })` }
							</p>
							{ transport?.host && <p>{ __( 'Host:', 'enterprise-forms' ) } { transport.host }</p> }
							{ transport?.from_email && <p>{ __( 'From Email:', 'enterprise-forms' ) } { transport.from_email }</p> }
							{ transport?.from_name && <p>{ __( 'From Name:', 'enterprise-forms' ) } { transport.from_name }</p> }
						</div>
						<div className="mt-2 rounded border border-slate-200 bg-white p-3 text-xs text-slate-700">
							<p className="font-semibold text-slate-800">{ __( 'Resolved Recipients', 'enterprise-forms' ) }</p>
							<p>{ recipientHint }</p>
							{ formStatus?.using_admin_fallback && (
								<p className="mt-1 text-slate-500">{ __( 'Using WordPress admin email fallback.', 'enterprise-forms' ) }</p>
							) }
						</div>
					</>
				) }
			</CardBody> }
		</Card>
	);
};

export default NotificationSettingsPanel;
