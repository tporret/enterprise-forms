import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { Tooltip } from '@wordpress/components';

type GatewaySlug = 'stripe' | 'braintree' | 'paypal' | 'square';
type StorageProviderSlug = 'local' | 's3' | 'r2' | 'gcs';

interface GatewayState {
	label: string;
	configured: boolean;
	fields: Record< string, string | boolean >;
}

interface StorageProviderState {
	label: string;
	configured: boolean;
	fields: Record< string, string | boolean >;
}

interface PaymentsSettingsResponse {
	default_gateway: GatewaySlug;
	gateways: Record< GatewaySlug, GatewayState >;
}

interface StorageSettingsResponse {
	active_provider: StorageProviderSlug;
	default_provider: StorageProviderSlug;
	providers: Record< StorageProviderSlug, StorageProviderState >;
}

interface EncryptionNotice {
	tone: 'success' | 'info' | 'warning';
	message: string;
}

const GATEWAY_FIELDS: Record< GatewaySlug, Array< { key: string; label: string; required?: boolean; secret?: boolean; placeholder?: string } > > = {
	stripe: [
		{ key: 'publishable_key', label: 'Publishable Key', required: true },
		{ key: 'secret_key', label: 'Secret Key', required: true, secret: true },
	],
	braintree: [
		{ key: 'environment', label: 'Environment', required: true },
		{ key: 'merchant_id', label: 'Merchant ID', required: true },
		{ key: 'public_key', label: 'Public Key', required: true },
		{ key: 'private_key', label: 'Private Key', required: true, secret: true },
	],
	paypal: [
		{ key: 'environment', label: 'Environment', required: true },
		{ key: 'client_id', label: 'Client ID', required: true },
		{ key: 'client_secret', label: 'Client Secret', required: true, secret: true },
	],
	square: [
		{ key: 'environment', label: 'Environment', required: true },
		{ key: 'application_id', label: 'Application ID', required: true },
		{ key: 'location_id', label: 'Location ID', required: true },
		{ key: 'access_token', label: 'Access Token', required: true, secret: true },
	],
};

const BRAINTREE_ENVIRONMENTS = [ 'sandbox', 'production' ] as const;
const PAYPAL_ENVIRONMENTS = [ 'sandbox', 'live' ] as const;
const SQUARE_ENVIRONMENTS = [ 'sandbox', 'production' ] as const;

const EMPTY_GATEWAYS = Object.keys( GATEWAY_FIELDS ).reduce( ( carry, gateway ) => {
	carry[ gateway as GatewaySlug ] = { label: gateway, configured: false, fields: {} };
	return carry;
}, {} as Record< GatewaySlug, GatewayState > );

const STORAGE_PROVIDER_FIELDS: Record< StorageProviderSlug, Array< { key: string; label: string; secret?: boolean; placeholder?: string } > > = {
	local: [],
	s3: [
		{ key: 'bucket', label: 'Bucket' },
		{ key: 'region', label: 'Region', placeholder: 'us-east-1' },
		{ key: 'endpoint', label: 'Endpoint', placeholder: 'Optional for AWS S3' },
		{ key: 'access_key_id', label: 'Access Key ID' },
		{ key: 'secret_access_key', label: 'Secret Access Key', secret: true },
		{ key: 'path_style', label: 'Path Style', placeholder: '1 for S3-compatible endpoints' },
		{ key: 'public_base_url', label: 'Public Base URL' },
		{ key: 'key_prefix', label: 'Key Prefix', placeholder: 'enterprise-forms' },
	],
	r2: [
		{ key: 'bucket', label: 'Bucket' },
		{ key: 'region', label: 'Region', placeholder: 'auto' },
		{ key: 'endpoint', label: 'Endpoint', placeholder: 'https://<account>.r2.cloudflarestorage.com' },
		{ key: 'access_key_id', label: 'Access Key ID' },
		{ key: 'secret_access_key', label: 'Secret Access Key', secret: true },
		{ key: 'path_style', label: 'Path Style', placeholder: '1' },
		{ key: 'public_base_url', label: 'Public Base URL' },
		{ key: 'key_prefix', label: 'Key Prefix', placeholder: 'enterprise-forms' },
	],
	gcs: [
		{ key: 'bucket', label: 'Bucket' },
		{ key: 'region', label: 'Region', placeholder: 'auto' },
		{ key: 'endpoint', label: 'Endpoint', placeholder: 'https://storage.googleapis.com' },
		{ key: 'access_key_id', label: 'Access Key ID' },
		{ key: 'secret_access_key', label: 'Secret Access Key', secret: true },
		{ key: 'path_style', label: 'Path Style', placeholder: '1' },
		{ key: 'public_base_url', label: 'Public Base URL' },
		{ key: 'key_prefix', label: 'Key Prefix', placeholder: 'enterprise-forms' },
	],
};

const EMPTY_STORAGE_PROVIDERS = Object.keys( STORAGE_PROVIDER_FIELDS ).reduce( ( carry, provider ) => {
	carry[ provider as StorageProviderSlug ] = { label: provider, configured: provider === 'local', fields: {} };
	return carry;
}, {} as Record< StorageProviderSlug, StorageProviderState > );

const buildDraftFromResponse = ( response: PaymentsSettingsResponse ): Record< GatewaySlug, Record< string, string > > => {
	const nextDraft = {} as Record< GatewaySlug, Record< string, string > >;

	( Object.keys( GATEWAY_FIELDS ) as GatewaySlug[] ).forEach( ( gateway ) => {
		nextDraft[ gateway ] = {};
		GATEWAY_FIELDS[ gateway ].forEach( ( field ) => {
			const value = response.gateways?.[ gateway ]?.fields?.[ field.key ];

			if ( gateway === 'braintree' && field.key === 'environment' ) {
				const envValue = typeof value === 'string' ? value.toLowerCase() : '';
				nextDraft[ gateway ][ field.key ] = BRAINTREE_ENVIRONMENTS.includes( envValue as ( typeof BRAINTREE_ENVIRONMENTS )[ number ] ) ? envValue : '';
				return;
			}

			if ( gateway === 'paypal' && field.key === 'environment' ) {
				const envValue = typeof value === 'string' ? value.toLowerCase() : '';
				nextDraft[ gateway ][ field.key ] = PAYPAL_ENVIRONMENTS.includes( envValue as ( typeof PAYPAL_ENVIRONMENTS )[ number ] ) ? envValue : '';
				return;
			}

			if ( gateway === 'square' && field.key === 'environment' ) {
				const envValue = typeof value === 'string' ? value.toLowerCase() : '';
				nextDraft[ gateway ][ field.key ] = SQUARE_ENVIRONMENTS.includes( envValue as ( typeof SQUARE_ENVIRONMENTS )[ number ] ) ? envValue : '';
				return;
			}

			nextDraft[ gateway ][ field.key ] = typeof value === 'string' ? value : '';
		} );
	} );

	return nextDraft;
};

const buildStorageDraftFromResponse = ( response: StorageSettingsResponse ): Record< StorageProviderSlug, Record< string, string > > => {
	const nextDraft = {} as Record< StorageProviderSlug, Record< string, string > >;

	( Object.keys( STORAGE_PROVIDER_FIELDS ) as StorageProviderSlug[] ).forEach( ( provider ) => {
		nextDraft[ provider ] = {};
		STORAGE_PROVIDER_FIELDS[ provider ].forEach( ( field ) => {
			const value = response.providers?.[ provider ]?.fields?.[ field.key ];
			nextDraft[ provider ][ field.key ] = typeof value === 'string' ? value : '';
		} );
	} );

	return nextDraft;
};

const getEncryptionNoticeFromQuery = (): EncryptionNotice | null => {
	if ( typeof window === 'undefined' ) {
		return null;
	}

	const searchParams = new URLSearchParams( window.location.search );
	if ( searchParams.get( 'ep_forms_key_check' ) !== 'done' ) {
		return null;
	}

	const status = searchParams.get( 'ep_forms_key_status' );
	if ( status === 'primary' ) {
		return {
			tone: 'success',
			message: __( 'Encryption key check complete. Enterprise Forms is configured using a wp-config or environment key.', 'enterprise-forms' ),
		};
	}

	if ( status === 'fallback' ) {
		return {
			tone: 'info',
			message: __( 'Encryption key check complete. Enterprise Forms is currently using the database fallback key.', 'enterprise-forms' ),
		};
	}

	return {
		tone: 'warning',
		message: __( 'Encryption key check complete. A key is still missing, so submissions remain unavailable.', 'enterprise-forms' ),
	};
};

const SettingsPayments = (): JSX.Element => {
	const [ gateways, setGateways ] = useState< Record< GatewaySlug, GatewayState > >( EMPTY_GATEWAYS );
	const [ draft, setDraft ] = useState< Record< GatewaySlug, Record< string, string > > >( {} as Record< GatewaySlug, Record< string, string > > );
	const [ storageProviders, setStorageProviders ] = useState< Record< StorageProviderSlug, StorageProviderState > >( EMPTY_STORAGE_PROVIDERS );
	const [ activeStorageProvider, setActiveStorageProvider ] = useState< StorageProviderSlug >( 'local' );
	const [ storageDraft, setStorageDraft ] = useState< Record< StorageProviderSlug, Record< string, string > > >( {} as Record< StorageProviderSlug, Record< string, string > > );
	const [ isOverviewOpen, setIsOverviewOpen ] = useState( true );
	const [ isEncryptionOpen, setIsEncryptionOpen ] = useState( true );
	const [ isPaymentsOpen, setIsPaymentsOpen ] = useState( true );
	const [ isStorageOpen, setIsStorageOpen ] = useState( true );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isSavingStorage, setIsSavingStorage ] = useState( false );
	const [ message, setMessage ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ validationErrors, setValidationErrors ] = useState< Record< string, string > >( {} );
	const [ storageMessage, setStorageMessage ] = useState< string | null >( null );
	const [ storageError, setStorageError ] = useState< string | null >( null );
	const [ encryptionNotice ] = useState< EncryptionNotice | null >( () => getEncryptionNoticeFromQuery() );

	const adminConfig = ( window as Window & {
		enterpriseFormsAdminConfig?: {
			encryption?: {
				isConfigured?: boolean;
				recheckUrl?: string;
			};
		};
	} ).enterpriseFormsAdminConfig;
	const isEncryptionConfigured = Boolean( adminConfig?.encryption?.isConfigured );
	const encryptionRecheckUrl = adminConfig?.encryption?.recheckUrl || '#';

	useEffect( () => {
		let isCancelled = false;
		setIsLoading( true );
		setError( null );

		Promise.all( [
			apiFetch< PaymentsSettingsResponse >( { path: '/enterprise-forms/v1/payments/settings' } ),
			apiFetch< StorageSettingsResponse >( { path: '/enterprise-forms/v1/storage/settings' } ),
		] )
			.then( ( [ paymentsResponse, storageResponse ] ) => {
				if ( isCancelled ) {
					return;
				}

				setGateways( { ...EMPTY_GATEWAYS, ...paymentsResponse.gateways } );
				setDraft( buildDraftFromResponse( paymentsResponse ) );
				setStorageProviders( { ...EMPTY_STORAGE_PROVIDERS, ...storageResponse.providers } );
				setActiveStorageProvider( storageResponse.active_provider || 'local' );
				setStorageDraft( buildStorageDraftFromResponse( storageResponse ) );
			} )
			.catch( () => {
				if ( ! isCancelled ) {
					setError( __( 'Unable to load settings.', 'enterprise-forms' ) );
				}
			} )
			.finally( () => {
				if ( ! isCancelled ) {
					setIsLoading( false );
				}
			} );

		return () => {
			isCancelled = true;
		};
	}, [] );

	const updateDraft = ( gateway: GatewaySlug, field: string, value: string ): void => {
		const fieldPath = `${ gateway }.${ field }`;

		setDraft( ( current ) => ( {
			...current,
			[ gateway ]: {
				...( current[ gateway ] || {} ),
				[ field ]: value,
			},
		} ) );

		setValidationErrors( ( current ) => {
			if ( ! current[ fieldPath ] ) {
				return current;
			}

			const nextErrors = { ...current };
			delete nextErrors[ fieldPath ];
			return nextErrors;
		} );
	};

	const validatePaymentDraft = (): Record< string, string > => {
		const nextErrors: Record< string, string > = {};

		( Object.keys( GATEWAY_FIELDS ) as GatewaySlug[] ).forEach( ( gateway ) => {
			const gatewayFields = GATEWAY_FIELDS[ gateway ];
			const isGatewayInUse = Boolean( gateways[ gateway ]?.configured ) || gatewayFields.some( ( field ) => {
				const value = draft[ gateway ]?.[ field.key ];
				return typeof value === 'string' && value.trim() !== '';
			} );

			if ( ! isGatewayInUse ) {
				return;
			}

			gatewayFields.forEach( ( field ) => {
				if ( ! field.required ) {
					return;
				}

				const value = draft[ gateway ]?.[ field.key ] || '';
				const hasSavedSecret = Boolean( gateways[ gateway ]?.fields?.[ `has_${ field.key }` ] );
				const hasValue = value.trim() !== '';

				if ( field.secret && hasSavedSecret && ! hasValue ) {
					return;
				}

				if ( ! hasValue ) {
					nextErrors[ `${ gateway }.${ field.key }` ] = __( 'This field is required.', 'enterprise-forms' );
					return;
				}

				if ( gateway === 'braintree' && field.key === 'environment' ) {
					const normalized = value.toLowerCase();
					if ( ! BRAINTREE_ENVIRONMENTS.includes( normalized as ( typeof BRAINTREE_ENVIRONMENTS )[ number ] ) ) {
						nextErrors[ `${ gateway }.${ field.key }` ] = __( 'Choose a valid environment.', 'enterprise-forms' );
					}
				}

				if ( gateway === 'paypal' && field.key === 'environment' ) {
					const normalized = value.toLowerCase();
					if ( ! PAYPAL_ENVIRONMENTS.includes( normalized as ( typeof PAYPAL_ENVIRONMENTS )[ number ] ) ) {
						nextErrors[ `${ gateway }.${ field.key }` ] = __( 'Choose a valid environment.', 'enterprise-forms' );
					}
				}

				if ( gateway === 'square' && field.key === 'environment' ) {
					const normalized = value.toLowerCase();
					if ( ! SQUARE_ENVIRONMENTS.includes( normalized as ( typeof SQUARE_ENVIRONMENTS )[ number ] ) ) {
						nextErrors[ `${ gateway }.${ field.key }` ] = __( 'Choose a valid environment.', 'enterprise-forms' );
					}
				}
			} );
		} );

		return nextErrors;
	};

	const updateStorageDraft = ( provider: StorageProviderSlug, field: string, value: string ): void => {
		setStorageDraft( ( current ) => ( {
			...current,
			[ provider ]: {
				...( current[ provider ] || {} ),
				[ field ]: value,
			},
		} ) );
	};

	const saveSettings = async ( event: { preventDefault: () => void } ): Promise< void > => {
		event.preventDefault();

		const nextValidationErrors = validatePaymentDraft();
		setValidationErrors( nextValidationErrors );
		if ( Object.keys( nextValidationErrors ).length > 0 ) {
			setMessage( null );
			setError( __( 'Complete all required payment fields before saving.', 'enterprise-forms' ) );
			return;
		}

		setIsSaving( true );
		setMessage( null );
		setError( null );

		try {
			const response = await apiFetch< PaymentsSettingsResponse >( {
				path: '/enterprise-forms/v1/payments/settings',
				method: 'POST',
				data: { gateways: draft },
			} );

			setGateways( { ...EMPTY_GATEWAYS, ...response.gateways } );
			setValidationErrors( {} );
			setDraft( ( current ) => {
				const nextDraft = { ...current };
				( Object.keys( GATEWAY_FIELDS ) as GatewaySlug[] ).forEach( ( gateway ) => {
					nextDraft[ gateway ] = { ...( nextDraft[ gateway ] || {} ) };
					GATEWAY_FIELDS[ gateway ].forEach( ( field ) => {
						if ( field.secret ) {
							nextDraft[ gateway ][ field.key ] = '';
						}

						if ( ( gateway === 'braintree' || gateway === 'paypal' || gateway === 'square' ) && field.key === 'environment' ) {
							const responseValue = response.gateways?.[ gateway ]?.fields?.[ field.key ];
							nextDraft[ gateway ][ field.key ] = typeof responseValue === 'string' ? responseValue : '';
						}
					} );
				} );

				return nextDraft;
			} );
			setMessage( __( 'Payment gateway settings saved.', 'enterprise-forms' ) );
		} catch {
			setError( __( 'Unable to save payment settings.', 'enterprise-forms' ) );
		} finally {
			setIsSaving( false );
		}
	};

	const saveStorageSettings = async ( event: { preventDefault: () => void } ): Promise< void > => {
		event.preventDefault();
		setIsSavingStorage( true );
		setStorageMessage( null );
		setStorageError( null );

		try {
			const response = await apiFetch< StorageSettingsResponse >( {
				path: '/enterprise-forms/v1/storage/settings',
				method: 'POST',
				data: { active_provider: activeStorageProvider, providers: storageDraft },
			} );

			setStorageProviders( { ...EMPTY_STORAGE_PROVIDERS, ...response.providers } );
			setActiveStorageProvider( response.active_provider || 'local' );
			setStorageDraft( ( current ) => {
				const nextDraft = { ...current };
				( Object.keys( STORAGE_PROVIDER_FIELDS ) as StorageProviderSlug[] ).forEach( ( provider ) => {
					nextDraft[ provider ] = { ...( nextDraft[ provider ] || {} ) };
					STORAGE_PROVIDER_FIELDS[ provider ].forEach( ( field ) => {
						if ( field.secret ) {
							nextDraft[ provider ][ field.key ] = '';
						}
					} );
				} );

				return nextDraft;
			} );
			setStorageMessage( __( 'File storage settings saved.', 'enterprise-forms' ) );
		} catch {
			setStorageError( __( 'Unable to save file storage settings.', 'enterprise-forms' ) );
		} finally {
			setIsSavingStorage( false );
		}
	};

	return (
		<section className="space-y-8 p-6 lg:p-10">
			<section className="max-w-5xl rounded-lg border border-slate-200 bg-white">
				<button
					type="button"
					onClick={ () => setIsOverviewOpen( ( current ) => ! current ) }
					className="flex w-full items-center justify-between gap-4 px-6 py-5 text-left"
					aria-expanded={ isOverviewOpen }
				>
					<div>
						<h2 className="text-2xl font-semibold tracking-tight text-slate-900">{ __( 'Enterprise Forms Settings', 'enterprise-forms' ) }</h2>
						<p className="mt-2 text-sm text-slate-600">{ __( 'Configure payments, file storage, and core plugin behavior.', 'enterprise-forms' ) }</p>
					</div>
					<span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
						{ isOverviewOpen ? __( 'Collapse', 'enterprise-forms' ) : __( 'Expand', 'enterprise-forms' ) }
					</span>
				</button>

				{ isOverviewOpen && (
					<div className="border-t border-slate-200 px-6 py-5">
						<p className="text-sm text-slate-600">{ __( 'Use the sections below to connect payment gateways and configure how uploaded files are stored.', 'enterprise-forms' ) }</p>
					</div>
				) }
			</section>

			<section className="max-w-5xl rounded-lg border border-slate-200 bg-white">
				<button
					type="button"
					onClick={ () => setIsEncryptionOpen( ( current ) => ! current ) }
					className="flex w-full items-center justify-between gap-4 px-6 py-5 text-left"
					aria-expanded={ isEncryptionOpen }
				>
					<div>
						<h3 className="text-lg font-semibold text-slate-900">{ __( 'Encryption Key', 'enterprise-forms' ) }</h3>
						<p className="mt-1 text-sm text-slate-600">{ __( 'Verify that submissions can be encrypted before forms go live.', 'enterprise-forms' ) }</p>
					</div>
					<span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
						{ isEncryptionOpen ? __( 'Collapse', 'enterprise-forms' ) : __( 'Expand', 'enterprise-forms' ) }
					</span>
				</button>

				{ isEncryptionOpen && (
					<div className="border-t border-slate-200 px-6 py-6">
						{ encryptionNotice && (
							<p
								className={ `mb-4 rounded-md px-3 py-2 text-sm ${
									encryptionNotice.tone === 'success'
										? 'bg-green-50 text-green-800'
										: encryptionNotice.tone === 'info'
										? 'bg-sky-50 text-sky-800'
										: 'bg-amber-50 text-amber-800'
								}` }
							>
								{ encryptionNotice.message }
							</p>
						) }

						<div className="rounded-lg border border-slate-200 p-4">
							<div className="mb-4 flex items-center justify-between gap-3">
								<h4 className="text-base font-semibold text-slate-900">{ __( 'Encryption Status', 'enterprise-forms' ) }</h4>
								<span className={ isEncryptionConfigured ? 'text-xs font-medium text-green-700' : 'text-xs font-medium text-amber-700' }>
									{ isEncryptionConfigured ? __( 'Configured', 'enterprise-forms' ) : __( 'Not configured', 'enterprise-forms' ) }
								</span>
							</div>

							<p className="text-sm text-slate-700">
								{ isEncryptionConfigured
									? __( 'Encryption is configured. Submissions can be accepted.', 'enterprise-forms' )
									: __( 'Encryption is not configured. Submissions are currently unavailable.', 'enterprise-forms' ) }
							</p>
							<p className="mt-2 text-sm text-slate-600">
								{ __( 'After updating wp-config.php or environment variables, run a re-check to refresh Enterprise Forms key status.', 'enterprise-forms' ) }
							</p>

							<a
								href={ encryptionRecheckUrl }
								className="mt-4 inline-flex rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700"
							>
								{ __( 'Re-check encryption key configuration', 'enterprise-forms' ) }
							</a>
						</div>
					</div>
				) }
			</section>

			<section className="max-w-5xl rounded-lg border border-slate-200 bg-white">
				<button
					type="button"
					onClick={ () => setIsPaymentsOpen( ( current ) => ! current ) }
					className="flex w-full items-center justify-between gap-4 px-6 py-5 text-left"
					aria-expanded={ isPaymentsOpen }
				>
					<div>
						<h3 className="text-lg font-semibold text-slate-900">{ __( 'Payments', 'enterprise-forms' ) }</h3>
						<p className="mt-1 text-sm text-slate-600">{ __( 'Connect Stripe, Braintree, PayPal, and Square for native checkout blocks.', 'enterprise-forms' ) }</p>
					</div>
					<span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
						{ isPaymentsOpen ? __( 'Collapse', 'enterprise-forms' ) : __( 'Expand', 'enterprise-forms' ) }
					</span>
				</button>

				{ isPaymentsOpen && (
					<form onSubmit={ ( event ) => void saveSettings( event ) } className="border-t border-slate-200 px-6 py-6">
						{ isLoading ? (
							<p className="text-sm text-slate-700">{ __( 'Loading settings...', 'enterprise-forms' ) }</p>
						) : (
							<>
								<div className="grid gap-5 lg:grid-cols-2">
									{ ( Object.keys( GATEWAY_FIELDS ) as GatewaySlug[] ).map( ( gateway ) => (
										<section key={ gateway } className="rounded-lg border border-slate-200 p-4">
											<div className="mb-4 flex items-center justify-between gap-3">
												<h3 className="text-base font-semibold text-slate-900">{ gateways[ gateway ]?.label || gateway }</h3>
												<span className={ gateways[ gateway ]?.configured ? 'text-xs font-medium text-green-700' : 'text-xs font-medium text-slate-500' }>
													{ gateways[ gateway ]?.configured ? __( 'Configured', 'enterprise-forms' ) : __( 'Not configured', 'enterprise-forms' ) }
												</span>
											</div>

											{ gateway === 'square' && (
												<p className="mb-4 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
													{ __( 'Square setup: find Application ID and Access Token in Square Developer Dashboard -> Applications -> Credentials, and Location ID in Square Dashboard -> Account & Settings -> Business -> Locations.', 'enterprise-forms' ) }
												</p>
											) }

											<div className="space-y-4">
												{ GATEWAY_FIELDS[ gateway ].map( ( field ) => {
													const hasSavedSecret = Boolean( gateways[ gateway ]?.fields?.[ `has_${ field.key }` ] );
													const fieldPath = `${ gateway }.${ field.key }`;
													const fieldError = validationErrors[ fieldPath ];
													const environmentOptions = field.key === 'environment'
														? ( gateway === 'braintree' ? [ 'sandbox', 'production' ] : gateway === 'paypal' ? [ 'sandbox', 'live' ] : gateway === 'square' ? [ 'sandbox', 'production' ] : [] )
														: [];

													return (
														<label key={ field.key } className="block text-sm font-medium text-slate-700">
															<span>{ field.label }{ field.required ? ' *' : '' }</span>
															{ environmentOptions.length > 0 ? (
																<select
																	value={ draft[ gateway ]?.[ field.key ] || '' }
																	onChange={ ( event ) => updateDraft( gateway, field.key, event.target.value ) }
																	className={ `mt-1 w-full rounded-md px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 ${ fieldError ? 'border border-red-300 focus:border-red-500 focus:ring-red-500' : 'border border-slate-300 focus:border-slate-500 focus:ring-slate-500' }` }
																>
																	<option value="">{ __( 'Select environment', 'enterprise-forms' ) }</option>
																	{ environmentOptions.map( ( option ) => (
																		<option key={ option } value={ option }>{ option.charAt( 0 ).toUpperCase() + option.slice( 1 ) }</option>
																	) ) }
																</select>
															) : (
																<input
																	type={ field.secret ? 'password' : 'text' }
																	value={ draft[ gateway ]?.[ field.key ] || '' }
																	onChange={ ( event ) => updateDraft( gateway, field.key, event.target.value ) }
																	placeholder={ field.secret && hasSavedSecret ? __( 'Saved. Leave blank to keep existing value.', 'enterprise-forms' ) : field.placeholder || '' }
																	className={ `mt-1 w-full rounded-md px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 ${ fieldError ? 'border border-red-300 focus:border-red-500 focus:ring-red-500' : 'border border-slate-300 focus:border-slate-500 focus:ring-slate-500' }` }
																	autoComplete={ field.secret ? 'new-password' : 'off' }
																/>
															) }
															{ fieldError && <span className="mt-1 block text-xs text-red-700">{ fieldError }</span> }
														</label>
													);
												} ) }
											</div>
										</section>
									) ) }
								</div>

								{ message && <p className="mt-4 text-sm text-green-700">{ message }</p> }
								{ error && <p className="mt-4 text-sm text-red-700">{ error }</p> }

								<button
									type="submit"
									disabled={ isSaving }
									className="mt-6 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
								>
									{ isSaving ? __( 'Saving...', 'enterprise-forms' ) : __( 'Save Payment Settings', 'enterprise-forms' ) }
								</button>
							</>
						) }
					</form>
				) }
			</section>

			<section className="max-w-5xl rounded-lg border border-slate-200 bg-white">
				<button
					type="button"
					onClick={ () => setIsStorageOpen( ( current ) => ! current ) }
					className="flex w-full items-center justify-between gap-4 px-6 py-5 text-left"
					aria-expanded={ isStorageOpen }
				>
					<div className="flex items-center gap-2">
						<h3 className="text-lg font-semibold text-slate-900">{ __( 'File Storage', 'enterprise-forms' ) }</h3>
						<Tooltip text={ __( 'S3-compatible uploads require bucket CORS for PUT requests from this site. Set Public Base URL when files should resolve through a CDN or public bucket URL.', 'enterprise-forms' ) }>
							<span
								className="inline-flex h-5 w-5 items-center justify-center rounded-full border border-slate-300 text-xs font-semibold text-slate-600"
								aria-label={ __( 'File storage requirements', 'enterprise-forms' ) }
							>
								?
							</span>
						</Tooltip>
					</div>
					<span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
						{ isStorageOpen ? __( 'Collapse', 'enterprise-forms' ) : __( 'Expand', 'enterprise-forms' ) }
					</span>
				</button>

				{ isStorageOpen && (
					<form onSubmit={ ( event ) => void saveStorageSettings( event ) } className="border-t border-slate-200 px-6 py-6">
						<div className="mb-5 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
							<div>
								<p className="mt-1 text-sm text-slate-600">{ __( 'Use local uploads or S3-compatible direct uploads for file fields.', 'enterprise-forms' ) }</p>
							</div>
							<label className="block min-w-56 text-sm font-medium text-slate-700">
								<span>{ __( 'Active Provider', 'enterprise-forms' ) }</span>
								<select
									value={ activeStorageProvider }
									onChange={ ( event ) => setActiveStorageProvider( event.target.value as StorageProviderSlug ) }
									className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
								>
									{ ( Object.keys( STORAGE_PROVIDER_FIELDS ) as StorageProviderSlug[] ).map( ( provider ) => (
										<option key={ provider } value={ provider }>{ storageProviders[ provider ]?.label || provider }</option>
									) ) }
								</select>
							</label>
						</div>

						{ isLoading ? (
							<p className="text-sm text-slate-700">{ __( 'Loading settings...', 'enterprise-forms' ) }</p>
						) : (
							<>
								<div className="grid gap-5 lg:grid-cols-2">
									{ ( Object.keys( STORAGE_PROVIDER_FIELDS ) as StorageProviderSlug[] ).map( ( provider ) => (
										<section key={ provider } className="rounded-lg border border-slate-200 p-4">
											<div className="mb-4 flex items-center justify-between gap-3">
												<h4 className="text-base font-semibold text-slate-900">{ storageProviders[ provider ]?.label || provider }</h4>
												<span className={ storageProviders[ provider ]?.configured ? 'text-xs font-medium text-green-700' : 'text-xs font-medium text-slate-500' }>
													{ storageProviders[ provider ]?.configured ? __( 'Configured', 'enterprise-forms' ) : __( 'Not configured', 'enterprise-forms' ) }
												</span>
											</div>

											{ STORAGE_PROVIDER_FIELDS[ provider ].length === 0 ? (
												<p className="text-sm text-slate-600">{ __( 'Stores files in the WordPress uploads directory.', 'enterprise-forms' ) }</p>
											) : (
												<div className="space-y-4">
													{ STORAGE_PROVIDER_FIELDS[ provider ].map( ( field ) => {
														const hasSavedSecret = Boolean( storageProviders[ provider ]?.fields?.[ `has_${ field.key }` ] );

														return (
															<label key={ field.key } className="block text-sm font-medium text-slate-700">
																<span>{ field.label }</span>
																<input
																	type={ field.secret ? 'password' : 'text' }
																	value={ storageDraft[ provider ]?.[ field.key ] || '' }
																	onChange={ ( event ) => updateStorageDraft( provider, field.key, event.target.value ) }
																	placeholder={ field.secret && hasSavedSecret ? __( 'Saved. Leave blank to keep existing value.', 'enterprise-forms' ) : field.placeholder || '' }
																	className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
																	autoComplete={ field.secret ? 'new-password' : 'off' }
																/>
															</label>
														);
													} ) }
												</div>
											) }
										</section>
									) ) }
								</div>

								{ storageMessage && <p className="mt-4 text-sm text-green-700">{ storageMessage }</p> }
								{ storageError && <p className="mt-4 text-sm text-red-700">{ storageError }</p> }

								<button
									type="submit"
									disabled={ isSavingStorage }
									className="mt-6 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
								>
									{ isSavingStorage ? __( 'Saving...', 'enterprise-forms' ) : __( 'Save File Storage', 'enterprise-forms' ) }
								</button>
							</>
						) }
					</form>
				) }
			</section>
		</section>
	);
};

export default SettingsPayments;