import { getContext, store } from '@wordpress/interactivity';

type ValueMap = Record< string, string >;
type ErrorMap = Record< string, string >;
type VisibilityMap = Record< string, boolean >;

interface ConditionalRule {
	id: string;
	field_id: string;
	target_field_id: string;
	operator: 'equals' | 'not_equals' | 'contains' | 'is_empty' | 'is_not_empty';
	value: string;
	action: 'show' | 'hide' | 'require' | 'disable';
}

interface FormContext {
	values: ValueMap;
	errors: ErrorMap;
	visibility: VisibilityMap;
	isSubmitting: boolean;
	isSuccess: boolean;
	message: string;
	successMessage: string;
	submitButtonText: string;
	redirectUrl: string;
	hasTracked: boolean;
	requiresPayment?: boolean;
	paymentGateway?: 'stripe' | 'braintree' | 'paypal' | 'authorize_net' | 'adyen' | 'square';
	paymentClientConfig?: Record< string, string >;
	stripePublishableKey?: string;
	paymentReady?: boolean;
	rules?: ConditionalRule[];
	uploadProgress: {
		active: boolean;
		fileName: string;
		percentage: number;
	};
	uploadedFiles: Array< {
		id: string;
		name: string;
		url: string;
		size: number;
	} >;
	currentStep: number;
	totalSteps: number;
	dropzoneActive: boolean;
}

interface FormState {
	config: {
		restUrl: string;
		paymentIntentUrl: string;
		nonce: string;
	};
}

interface PaymentIntentResponse {
	id?: string;
	payment_record_id?: string;
	client_secret?: string;
	client_token?: string;
	amount?: number;
	currency?: string;
	gateway?: string;
	client_config?: Record< string, string >;
	publishable_key?: string;
	message?: string;
}

interface StripeElements {
	create: ( type: 'payment' ) => StripePaymentElement;
	submit: () => Promise< { error?: { message?: string } } >;
}

interface StripePaymentElement {
	mount: ( selector: string | HTMLElement ) => void;
	unmount?: () => void;
}

interface StripeInstance {
	elements: ( options: { clientSecret: string } ) => StripeElements;
	confirmPayment: ( options: { elements: StripeElements; redirect: 'if_required' } ) => Promise< {
		error?: { message?: string };
		paymentIntent?: { id?: string; status?: string };
	} >;
}

interface StripeWindow extends Window {
	Stripe?: ( publishableKey: string ) => StripeInstance;
}

interface BraintreeDropinInstance {
	requestPaymentMethod: () => Promise< { nonce?: string } >;
	teardown?: () => Promise< void >;
}

interface BraintreeWindow extends Window {
	braintree?: {
		dropin?: {
			create: ( options: { authorization: string; container: HTMLElement; paypal?: Record< string, unknown >; venmo?: Record< string, unknown > }, callback: ( error: Error | null, instance?: BraintreeDropinInstance ) => void ) => void;
		};
	};
}

interface PayPalButtonsActions {
	order: {
		capture: () => Promise< { id?: string } >;
	};
}

interface PayPalButtonsInstance {
	render: ( selector: string | HTMLElement ) => Promise< void >;
	close?: () => Promise< void >;
}

interface PayPalWindow extends Window {
	paypal?: {
		Buttons: ( config: {
			createOrder: () => string | Promise< string >;
			onApprove: ( data: { orderID?: string }, actions: PayPalButtonsActions ) => Promise< void >;
			onError: ( error: Error ) => void;
		} ) => PayPalButtonsInstance;
	};
}

interface SquareCard {
	attach: ( selector: string | HTMLElement ) => Promise< void >;
	tokenize: () => Promise< { status: string; token?: string; errors?: Array< { message?: string } > } >;
	destroy?: () => Promise< void >;
}

interface SquarePayments {
	card: () => Promise< SquareCard >;
}

interface SquareWindow extends Window {
	Square?: {
		payments: ( applicationId: string, locationId: string ) => SquarePayments;
	};
}

interface ValidationErrorResponse {
	message?: string;
	data?: {
		status?: number;
		errors?: Record< string, string >;
	};
}

const toStringValue = ( target: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement ): string => {
	if ( target instanceof HTMLInputElement ) {
		if ( target.type === 'checkbox' ) {
			return target.checked ? ( target.value || '1' ) : '';
		}

		if ( target.type === 'radio' ) {
			return target.checked ? target.value : '';
		}

		if ( target.type === 'file' ) {
			return target.value;
		}
	}

	return target.value ?? '';
};

let stripeScriptPromise: Promise< void > | null = null;
let braintreeScriptPromise: Promise< void > | null = null;
let paypalScriptPromise: Promise< void > | null = null;
let paypalScriptKey = '';
let squareScriptPromise: Promise< void > | null = null;
let squareScriptEnvironment = '';
let stripeInstance: StripeInstance | null = null;
let stripeElements: StripeElements | null = null;
let stripePaymentElement: StripePaymentElement | null = null;
let braintreeDropin: BraintreeDropinInstance | null = null;
let paypalButtons: PayPalButtonsInstance | null = null;
let squareCard: SquareCard | null = null;
let paypalOrderId = '';
let paypalApprovedOrderId = '';
let paymentClientSecret = '';
let paymentClientToken = '';
let paymentFingerprint = '';
let paymentGatewayFingerprint = '';

const getStripeWindow = (): StripeWindow => window as StripeWindow;
const getBraintreeWindow = (): BraintreeWindow => window as BraintreeWindow;
const getPayPalWindow = (): PayPalWindow => window as PayPalWindow;
const getSquareWindow = (): SquareWindow => window as SquareWindow;

const loadScript = ( src: string, label: string ): Promise< void > => new Promise( ( resolve, reject ) => {
	const existingScript = document.querySelector( `script[src="${ src }"]` );
	if ( existingScript ) {
		existingScript.addEventListener( 'load', () => resolve(), { once: true } );
		existingScript.addEventListener( 'error', () => reject( new Error( `${ label } failed to load.` ) ), { once: true } );
		return;
	}

	const script = document.createElement( 'script' );
	script.src = src;
	script.async = true;
	script.addEventListener( 'load', () => resolve(), { once: true } );
	script.addEventListener( 'error', () => reject( new Error( `${ label } failed to load.` ) ), { once: true } );
	document.head.appendChild( script );
} );

const loadStripeScript = (): Promise< void > => {
	if ( getStripeWindow().Stripe ) {
		return Promise.resolve();
	}

	if ( stripeScriptPromise ) {
		return stripeScriptPromise;
	}

	stripeScriptPromise = loadScript( 'https://js.stripe.com/v3/', 'Stripe' );

	return stripeScriptPromise;
};

const loadBraintreeScript = (): Promise< void > => {
	if ( getBraintreeWindow().braintree?.dropin ) {
		return Promise.resolve();
	}

	if ( braintreeScriptPromise ) {
		return braintreeScriptPromise;
	}

	braintreeScriptPromise = loadScript( 'https://js.braintreegateway.com/web/dropin/1.43.0/js/dropin.min.js', 'Braintree' );
	return braintreeScriptPromise;
};

const loadPayPalScript = ( clientId: string, currency: string ): Promise< void > => {
	const key = `${ clientId }:${ currency.toUpperCase() }`;

	if ( getPayPalWindow().paypal && paypalScriptKey === key ) {
		return Promise.resolve();
	}

	if ( paypalScriptPromise && paypalScriptKey === key ) {
		return paypalScriptPromise;
	}

	paypalScriptKey = key;
	paypalScriptPromise = loadScript(
		`https://www.paypal.com/sdk/js?client-id=${ encodeURIComponent( clientId ) }&currency=${ encodeURIComponent( currency.toUpperCase() ) }&intent=capture`,
		'PayPal'
	);

	return paypalScriptPromise;
};

const loadSquareScript = ( environment: string ): Promise< void > => {
	const normalizedEnvironment = environment === 'production' ? 'production' : 'sandbox';
	if ( getSquareWindow().Square && squareScriptEnvironment === normalizedEnvironment ) {
		return Promise.resolve();
	}

	if ( squareScriptPromise && squareScriptEnvironment === normalizedEnvironment ) {
		return squareScriptPromise;
	}

	squareScriptEnvironment = normalizedEnvironment;
	squareScriptPromise = loadScript(
		normalizedEnvironment === 'production' ? 'https://web.squarecdn.com/v1/square.js' : 'https://sandbox.web.squarecdn.com/v1/square.js',
		'Square'
	);

	return squareScriptPromise;
};

const paymentValuesFingerprint = ( context: FormContext ): string => {
	const values = { ...context.values };
	delete values.payment_intent_id;
	delete values.payment_token;
	return JSON.stringify( values );
};

const getFormNonce = ( formElement: HTMLFormElement ): string => {
	const nonceInput = formElement.querySelector( 'input[name="ep_forms_nonce"]' ) as HTMLInputElement | null;
	return nonceInput?.value || '';
};

const fetchPaymentIntent = async ( context: FormContext, formElement: HTMLFormElement ): Promise< PaymentIntentResponse > => {
	const formData = new FormData( formElement );
	const storeState = ( runtime as { state: FormState } ).state;
	const formId = String( formData.get( 'form_id' ) || '' );
	const response = await fetch( storeState.config.paymentIntentUrl || '/wp-json/enterprise-forms/v1/payment-intent', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
		},
		body: JSON.stringify( {
			form_id: formId,
			schema_version: String( formData.get( 'schema_version' ) || '' ),
			ep_forms_nonce: getFormNonce( formElement ),
			ep_submission_token: String( formData.get( 'ep_submission_token' ) || '' ),
			values: context.values,
		} ),
	} );

	const result = await response.json() as PaymentIntentResponse;
	if ( ! response.ok ) {
		throw new Error( result?.message || 'Unable to prepare payment.' );
	}

	if ( result?.payment_record_id ) {
		context.values.payment_record_id = result.payment_record_id;
	}

	return result;
};

const resetPaymentUI = (): void => {
	if ( stripePaymentElement?.unmount ) {
		stripePaymentElement.unmount();
	}

	if ( braintreeDropin?.teardown ) {
		void braintreeDropin.teardown();
	}

	if ( paypalButtons?.close ) {
		void paypalButtons.close();
	}

	if ( squareCard?.destroy ) {
		void squareCard.destroy();
	}

	stripeElements = null;
	stripePaymentElement = null;
	braintreeDropin = null;
	paypalButtons = null;
	squareCard = null;
	paypalOrderId = '';
	paypalApprovedOrderId = '';
	paymentClientSecret = '';
	paymentClientToken = '';
};

const getPaymentMountPoint = ( formElement: HTMLFormElement ): HTMLElement => {
	const mountPoint = formElement.querySelector( '[data-ep-payment-container]' ) as HTMLElement | null;
	if ( ! mountPoint ) {
		throw new Error( 'Payment element container was not found.' );
	}

	return mountPoint;
};

const mountPaymentUI = async ( context: FormContext, formElement: HTMLFormElement, intent: PaymentIntentResponse ): Promise< void > => {
	const gateway = context.paymentGateway || 'stripe';
	const mountPoint = getPaymentMountPoint( formElement );
	mountPoint.innerHTML = '';

	switch ( gateway ) {
		case 'stripe': {
			const publishableKey = context.paymentClientConfig?.publishable_key || context.stripePublishableKey || intent.publishable_key || '';
			const clientSecret = intent.client_secret || '';
			if ( ! publishableKey ) {
				throw new Error( 'Stripe publishable key is not configured.' );
			}

			if ( ! clientSecret ) {
				throw new Error( 'Stripe did not return a payment client secret.' );
			}

			await loadStripeScript();
			const stripeFactory = getStripeWindow().Stripe;
			if ( ! stripeFactory ) {
				throw new Error( 'Stripe could not be initialized.' );
			}

			if ( ! stripeInstance ) {
				stripeInstance = stripeFactory( publishableKey );
			}

			paymentClientSecret = clientSecret;
			stripeElements = stripeInstance.elements( { clientSecret: paymentClientSecret } );
			stripePaymentElement = stripeElements.create( 'payment' );
			stripePaymentElement.mount( mountPoint );
			break;
		}

		case 'braintree': {
			const clientToken = intent.client_token || '';
			if ( ! clientToken ) {
				throw new Error( 'Braintree did not return a client token.' );
			}

			await loadBraintreeScript();
			const braintreeDropinFactory = getBraintreeWindow().braintree?.dropin;
			if ( ! braintreeDropinFactory ) {
				throw new Error( 'Braintree could not be initialized.' );
			}

			paymentClientToken = clientToken;
			braintreeDropin = await new Promise< BraintreeDropinInstance >( ( resolve, reject ) => {
				braintreeDropinFactory.create(
					{
						authorization: paymentClientToken,
						container: mountPoint,
						paypal: {},
						venmo: {},
					},
					( error, instance ) => {
						if ( error || ! instance ) {
							reject( error || new Error( 'Braintree payment UI could not be created.' ) );
							return;
						}

						resolve( instance );
					}
				);
			} );
			break;
		}

		case 'paypal': {
				const clientId = context.paymentClientConfig?.client_id || intent.client_config?.client_id || '';
				const orderId = intent.id || '';
				const currency = String( intent.currency || 'usd' ).toUpperCase();
				if ( ! clientId ) {
					throw new Error( 'PayPal client ID is not configured.' );
				}

				if ( ! orderId ) {
					throw new Error( 'PayPal did not return an order ID.' );
				}

				await loadPayPalScript( clientId, currency );
				const paypal = getPayPalWindow().paypal;
				if ( ! paypal?.Buttons ) {
					throw new Error( 'PayPal could not be initialized.' );
				}

				paypalOrderId = orderId;
				paypalApprovedOrderId = '';

				paypalButtons = paypal.Buttons( {
					createOrder: () => paypalOrderId,
					onApprove: async ( data, actions ) => {
						const capture = await actions.order.capture();
						const approvedId = capture?.id || data?.orderID || paypalOrderId;
						paypalApprovedOrderId = approvedId;
						context.values.payment_intent_id = approvedId;
					},
					onError: ( error ) => {
						throw error;
					},
				} );

				const paypalMount = document.createElement( 'div' );
				mountPoint.appendChild( paypalMount );
				await paypalButtons.render( paypalMount );
				break;
			}

		case 'square': {
			const applicationId = context.paymentClientConfig?.application_id || intent.client_config?.application_id || '';
			const locationId = context.paymentClientConfig?.location_id || intent.client_config?.location_id || '';
			const environment = context.paymentClientConfig?.environment || intent.client_config?.environment || 'sandbox';

			if ( ! applicationId || ! locationId ) {
				throw new Error( 'Square application credentials are not configured.' );
			}

			await loadSquareScript( environment );
			const square = getSquareWindow().Square;
			if ( ! square?.payments ) {
				throw new Error( 'Square could not be initialized.' );
			}

			const payments = square.payments( applicationId, locationId );
			squareCard = await payments.card();
			await squareCard.attach( mountPoint );
			break;
		}

		default:
			throw new Error( 'This payment gateway is not supported on the frontend yet.' );
	}
};

const ensurePaymentElement = async ( context: FormContext, formElement: HTMLFormElement ): Promise< void > => {
	if ( ! context.requiresPayment ) {
		throw new Error( 'Payment is not required.' );
	}

	const nextFingerprint = paymentValuesFingerprint( context );
	const gateway = context.paymentGateway || 'stripe';
	if ( nextFingerprint !== paymentFingerprint || gateway !== paymentGatewayFingerprint || ( gateway === 'stripe' && ! stripeElements ) || ( gateway === 'braintree' && ! braintreeDropin ) || ( gateway === 'paypal' && ! paypalButtons ) || ( gateway === 'square' && ! squareCard ) ) {
		resetPaymentUI();
		const intent = await fetchPaymentIntent( context, formElement );
		await mountPaymentUI( context, formElement, intent );
		paymentFingerprint = nextFingerprint;
		paymentGatewayFingerprint = gateway;
	}

	context.paymentReady = true;
};

const processGatewayPayment = async ( context: FormContext, formElement: HTMLFormElement ): Promise< string > => {
	await ensurePaymentElement( context, formElement );

	switch ( context.paymentGateway || 'stripe' ) {
		case 'stripe': {
			if ( ! stripeInstance || ! stripeElements ) {
				throw new Error( 'Stripe payment UI is not ready.' );
			}

			const submitResult = await stripeElements.submit();
			if ( submitResult?.error ) {
				throw new Error( submitResult.error.message || 'Payment details are incomplete.' );
			}

			const paymentResult = await stripeInstance.confirmPayment( { elements: stripeElements, redirect: 'if_required' } );
			if ( paymentResult?.error ) {
				throw new Error( paymentResult.error.message || 'Payment could not be confirmed.' );
			}

			const paymentIntentId = paymentResult?.paymentIntent?.id || '';
			if ( ! paymentIntentId || paymentResult?.paymentIntent?.status !== 'succeeded' ) {
				throw new Error( 'Payment has not completed successfully.' );
			}

			context.values.payment_intent_id = paymentIntentId;
			return paymentIntentId;
		}

		case 'braintree': {
			if ( ! braintreeDropin ) {
				throw new Error( 'Braintree payment UI is not ready.' );
			}

			const paymentMethod = await braintreeDropin.requestPaymentMethod();
			const nonce = paymentMethod?.nonce || '';
			if ( ! nonce ) {
				throw new Error( 'Braintree did not return a payment token.' );
			}

			context.values.payment_token = nonce;
			context.values.payment_intent_id = nonce;
			return nonce;
		}

			case 'paypal': {
				const approvedOrderId = context.values.payment_intent_id || paypalApprovedOrderId;
				if ( ! approvedOrderId ) {
					throw new Error( 'Complete PayPal approval before submitting the form.' );
				}

				context.values.payment_intent_id = approvedOrderId;
				return approvedOrderId;
			}

			case 'square': {
				if ( ! squareCard ) {
					throw new Error( 'Square payment UI is not ready.' );
				}

				const tokenResult = await squareCard.tokenize();
				if ( tokenResult.status !== 'OK' || ! tokenResult.token ) {
					const firstError = tokenResult.errors?.[0]?.message;
					throw new Error( firstError || 'Square did not return a payment token.' );
				}

				context.values.payment_token = tokenResult.token;
				context.values.payment_intent_id = tokenResult.token;
				return tokenResult.token;
			}

		default:
			throw new Error( 'This payment gateway is not supported on the frontend yet.' );
	}
};

const clearErrors = ( context: FormContext ): void => {
	Object.keys( context.errors ).forEach( ( key ) => {
		context.errors[ key ] = '';
	} );
};

const clearValues = ( context: FormContext ): void => {
	Object.keys( context.values ).forEach( ( key ) => {
		context.values[ key ] = '';
	} );
};

const resetVisibility = ( context: FormContext ): void => {
	Object.keys( context.visibility ).forEach( ( key ) => {
		context.visibility[ key ] = true;
	} );
};

/**
 * Evaluate a conditional rule against the current form values.
 */
const evaluateRule = ( rule: ConditionalRule, context: FormContext ): boolean => {
	const fieldValue = context.values[ rule.field_id ] || '';

	switch ( rule.operator ) {
		case 'equals':
			return fieldValue === rule.value;
		case 'not_equals':
			return fieldValue !== rule.value;
		case 'contains':
			return fieldValue.toString().includes( rule.value );
		case 'is_empty':
			return ! fieldValue || fieldValue.trim() === '';
		case 'is_not_empty':
			return !! fieldValue && fieldValue.trim() !== '';
		default:
			return true;
	}
};

/**
 * Evaluate all conditional logic rules and update visibility state.
 */
const evaluateLogic = ( context: FormContext ): void => {
	if ( ! context.rules || context.rules.length === 0 ) {
		return;
	}

	// Reset all visibility to true initially
	Object.keys( context.visibility ).forEach( ( key ) => {
		context.visibility[ key ] = true;
	} );

	// Apply each rule
	context.rules.forEach( ( rule ) => {
		const ruleMatches = evaluateRule( rule, context );

		switch ( rule.action ) {
			case 'show':
				// Show if rule matches
				context.visibility[ rule.target_field_id ] = ruleMatches;
				break;
			case 'hide':
				// Hide if rule matches
				context.visibility[ rule.target_field_id ] = ! ruleMatches;
				break;
			case 'require':
				// Require is handled during validation, but we can toggle visibility
				// In this basic version, we'll use show/hide semantics
				context.visibility[ rule.target_field_id ] = ruleMatches;
				break;
			case 'disable':
				// Disable is also a separate concern, but visibility is the main control
				context.visibility[ rule.target_field_id ] = ruleMatches;
				break;
		}
	} );
};

const mapValidationErrors = ( context: FormContext, payload: ValidationErrorResponse ): void => {
	const errors = payload?.data?.errors;
	if ( ! errors ) {
		context.message = payload?.message || 'Validation failed. Please review highlighted fields.';
		return;
	}

	Object.entries( errors ).forEach( ( [ field, message ] ) => {
		context.errors[ field ] = String( message || '' );
	} );
	context.message = payload?.message || 'Validation failed. Please review highlighted fields.';
};

const isHiddenByStepOrLogic = ( control: Element, pageElement: Element ): boolean => {
	const hiddenAncestor = control.closest( '[hidden]' );
	return !! hiddenAncestor && hiddenAncestor !== pageElement;
};

const validateCurrentStep = ( context: FormContext, trigger: HTMLElement ): boolean => {
	const formElement = trigger.closest( 'form' );
	const pageElement = formElement?.querySelectorAll< HTMLElement >( '.ep-form-page' )[ context.currentStep ];

	if ( ! formElement || ! pageElement ) {
		return true;
	}

	let isValid = true;
	let firstInvalidControl: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | null = null;
	const controls = pageElement.querySelectorAll< HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement >( 'input, textarea, select' );

	for ( const control of controls ) {
		const field = control.dataset.epField || control.name;

		if ( ! field || control.disabled || control.type === 'hidden' || isHiddenByStepOrLogic( control, pageElement ) ) {
			continue;
		}

		if ( control instanceof HTMLInputElement ) {
			control.setCustomValidity( '' );

			if ( control.type === 'file' && control.required && ! context.values[ field ] ) {
				control.setCustomValidity( 'Please upload a file before continuing.' );
			}
		}

		context.errors[ field ] = '';

		if ( ! control.checkValidity() ) {
			isValid = false;
			context.errors[ field ] = control.validationMessage || 'Please complete this field.';

			if ( ! firstInvalidControl ) {
				firstInvalidControl = control;
			}
		}
	}

	if ( ! isValid ) {
		context.message = 'Please complete the required fields before continuing.';

		if ( firstInvalidControl ) {
			firstInvalidControl.reportValidity();
			firstInvalidControl.focus();
		}

		return false;
	}

	context.message = '';
	return true;
};

const runtime = store( 'enterpriseForms', {
	state: {
		config: {
			restUrl: '',
			paymentIntentUrl: '',
			nonce: '',
		},
	},
	actions: {
		updateValue( event: Event ) {
			const context = getContext() as FormContext;
			const target = event.currentTarget as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | null;

			if ( ! target ) {
				return;
			}

			const field = target.dataset.epField || target.name;
			if ( ! field ) {
				return;
			}

			if ( target instanceof HTMLInputElement && target.type === 'radio' && ! target.checked ) {
				return;
			}

			context.values[ field ] = toStringValue( target );
			context.errors[ field ] = '';
			context.message = '';

			if ( context.requiresPayment && field !== 'payment_intent_id' && field !== 'payment_token' ) {
				context.values.payment_intent_id = '';
				context.values.payment_token = '';
				context.paymentReady = false;
			}

			// Evaluate conditional logic to update field visibility
			evaluateLogic( context );
		},

		*submitForm( event: SubmitEvent ): Generator< unknown, void, any > {
			event.preventDefault();

			const context = getContext() as FormContext;
			const formElement = event.currentTarget as HTMLFormElement | null;

			if ( ! formElement ) {
				return;
			}

			context.isSubmitting = true;
			context.isSuccess = false;
			context.message = '';
			clearErrors( context );

			const formData = new FormData( formElement );
			const formId = String( formData.get( 'form_id' ) || '' );
			Object.entries( context.values ).forEach( ( [ key, value ] ) => {
				const normalizedValue = String( value ?? '' );

				if ( key === 'hp_field' ) {
					formData.set( key, normalizedValue );
					return;
				}

				if ( ! formData.has( key ) ) {
					formData.set( key, normalizedValue );
				}
			} );

			const storeState = ( runtime as { state: FormState } ).state;
			const fallbackUrl = formId ? `/wp-json/enterprise-forms/v1/entries/${ encodeURIComponent( formId ) }` : '/wp-json/enterprise-forms/v1/entries/';
			const submitUrl = storeState.config.restUrl || fallbackUrl;

			try {
				if ( context.requiresPayment ) {
					const paymentIntentId = yield processGatewayPayment( context, formElement );
					context.values.payment_intent_id = paymentIntentId;
					formData.set( 'payment_intent_id', paymentIntentId );
					if ( context.values.payment_token ) {
						formData.set( 'payment_token', context.values.payment_token );
					}
				}

				const response: Response = yield fetch( submitUrl, {
					method: 'POST',
					body: formData,
				} );

				const result: ValidationErrorResponse = yield response.json();

				if ( response.status === 200 || response.status === 201 ) {
					context.isSuccess = true;
					context.message = result?.message || context.successMessage || 'Form submitted successfully.';
					clearValues( context );
					clearErrors( context );
					resetVisibility( context );
					formElement.reset();
					return;
				}

				if ( response.status === 400 || response.status === 422 ) {
					mapValidationErrors( context, result );
					context.isSuccess = false;
					return;
				}

				context.isSuccess = false;
				context.message = result?.message || 'Unable to submit the form at this time.';
			} catch ( error ) {
				context.isSuccess = false;
				context.message = error instanceof Error ? error.message : 'A network error occurred while submitting the form.';
			} finally {
				context.isSubmitting = false;
			}
		},

		/**
		 * Handle file selection via file input.
		 */
		*handleFileSelect( event: Event ) {
			const target = event.currentTarget as HTMLInputElement;
			if ( target.type !== 'file' ) return;

			const files = target.files;
			if ( ! files || files.length === 0 ) return;

			const context = getContext() as FormContext;

			for ( let i = 0; i < files.length; i++ ) {
				const file = files[ i ];
				yield* ( runtime as any ).actions.uploadFile( file, target );
			}
		},

		/**
		 * Handle file drop on dropzone.
		 */
		*handleFileDrop( event: DragEvent ) {
			event.preventDefault();
			event.stopPropagation();

			const context = getContext() as FormContext;
			context.dropzoneActive = false;

			const dataTransfer = event.dataTransfer;
			if ( ! dataTransfer ) return;

			const files = dataTransfer.files;
			if ( ! files || files.length === 0 ) return;

			for ( let i = 0; i < files.length; i++ ) {
				const file = files[ i ];
				yield* ( runtime as any ).actions.uploadFile( file );
			}
		},

		/**
		 * Handle dragover to show active state.
		 */
		handleDragOver( event: DragEvent ) {
			event.preventDefault();
			event.stopPropagation();

			const context = getContext() as FormContext;
			context.dropzoneActive = true;
		},

		/**
		 * Handle dragleave to hide active state.
		 */
		handleDragLeave( event: DragEvent ) {
			event.preventDefault();
			event.stopPropagation();

			const context = getContext() as FormContext;
			context.dropzoneActive = false;
		},

		/**
		 * Upload a single file via pre-signed URL.
		 */
		*uploadFile( file: File, field?: HTMLInputElement ): Generator< unknown, void, any > {
			const context = getContext() as FormContext;

			// Get form context for field validation
			const uploadField = field || document.querySelector( '[data-ep-upload-field]' ) as HTMLInputElement | null;
			if ( ! uploadField ) return;

			const fieldName = uploadField.dataset.epField || uploadField.name || 'file_upload';
			const maxSize = parseInt( uploadField.dataset.maxSize || '10485760', 10 );

			// Validate file size
			if ( file.size > maxSize ) {
				context.errors[ fieldName ] = `File too large. Maximum size: ${ Math.round( maxSize / 1024 / 1024 ) }MB`;
				return;
			}

			// Request pre-signed URL
			context.uploadProgress.active = true;
			context.uploadProgress.fileName = file.name;
			context.uploadProgress.percentage = 0;

			const storeState = ( runtime as { state: FormState } ).state;

			try {
				const intentResponse: Response = yield fetch( '/wp-json/enterprise-forms/v1/upload-intent', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': storeState.config.nonce || '',
					},
					body: JSON.stringify( {
						file_name: file.name,
						mime_type: file.type,
						file_size: file.size,
						form_id: ( document.querySelector( '[name="form_id"]' ) as HTMLInputElement )?.value || '',
						field_name: fieldName,
					} ),
				} );

				if ( ! intentResponse.ok ) {
					const error = yield intentResponse.json();
					context.errors[ fieldName ] = error?.message || 'Failed to prepare file upload.';
					context.uploadProgress.active = false;
					return;
				}

				const intent = yield intentResponse.json();

				// Upload file to pre-signed URL
				const uploadResponse: Response = yield fetch( intent.url, {
					method: intent.method || 'PUT',
					headers: intent.headers || {},
					body: file,
				} );

				if ( ! uploadResponse.ok ) {
					context.errors[ fieldName ] = 'File upload failed. Please try again.';
					context.uploadProgress.active = false;
					return;
				}

				let uploadedFileUrl = String( intent.storage_path || '' );
				let uploadedFileName = file.name;

				try {
					const uploadResult = yield uploadResponse.clone().json();
					uploadedFileUrl = uploadResult?.file?.url || uploadedFileUrl;
					uploadedFileName = uploadResult?.file?.name || uploadedFileName;
				} catch ( error ) {
					// Cloud providers usually return an empty body for successful PUT uploads.
				}

				// Store file metadata
				const fileData = {
					id: `file-${ Date.now() }-${ Math.random() }`,
					name: uploadedFileName,
					url: uploadedFileUrl,
					size: file.size,
				};

				context.uploadedFiles.push( fileData );
				context.values[ fieldName ] = fileData.url;
				context.errors[ fieldName ] = '';
				context.uploadProgress.percentage = 100;
				uploadField.value = '';
				context.uploadProgress.active = false;
			} catch ( error ) {
				context.errors[ fieldName ] = 'Network error during upload. Please try again.';
				context.uploadProgress.active = false;
			}
		},

		/**
		 * Remove an uploaded file.
		 */
		removeUploadedFile( event: Event ) {
			const button = event.currentTarget as HTMLButtonElement;
			const fileId = button.dataset.fileId;

			if ( ! fileId ) return;

			const context = getContext() as FormContext;
			context.uploadedFiles = context.uploadedFiles.filter( ( f ) => f.id !== fileId );

			// Update field value
			const uploadField = document.querySelector( '[data-ep-upload-field]' ) as HTMLInputElement | null;
			if ( uploadField ) {
				const fieldName = uploadField.dataset.epField || uploadField.name || 'file_upload';
				context.values[ fieldName ] = '';
			}
		},

		/**
		 * Navigate to next form step.
		 */
		nextStep( event: Event ) {
			const context = getContext() as FormContext;
			const trigger = event.currentTarget as HTMLElement | null;

			if ( trigger && ! validateCurrentStep( context, trigger ) ) {
				return;
			}

			if ( context.currentStep < context.totalSteps - 1 ) {
				context.currentStep++;
			}
		},

		/**
		 * Navigate to previous form step.
		 */
		prevStep() {
			const context = getContext() as FormContext;
			if ( context.currentStep > 0 ) {
				context.currentStep--;
			}
		},
	},
	callbacks: {
		afterSubmit() {
			const context = getContext() as FormContext;
			const formElement = document.querySelector( '.ep-form' ) as HTMLFormElement | null;

			if ( context.requiresPayment && formElement && ! context.paymentReady && ! context.isSubmitting ) {
				void ensurePaymentElement( context, formElement ).catch( ( error: Error ) => {
					context.message = error.message || 'Unable to initialize payment.';
				} );
			}

			if ( ! context.isSuccess ) {
				return;
			}

			if ( ! context.hasTracked ) {
				if ( typeof window !== 'undefined' ) {
					const analyticsPayload = {
						event: 'enterprise_forms_submit_success',
						timestamp: Date.now(),
					};

					const unknownWindow = window as Window & {
						dataLayer?: Array< Record< string, unknown > >;
					};

					if ( Array.isArray( unknownWindow.dataLayer ) ) {
						unknownWindow.dataLayer.push( analyticsPayload );
					}
				}

				context.hasTracked = true;
			}

			if ( context.redirectUrl ) {
				window.location.assign( context.redirectUrl );
			}
		},
	},
} );
