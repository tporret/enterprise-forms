import { create } from 'zustand';
import type { FormSchema } from './schemaTypes';
import { createEmptySchema } from './schemaTypes';

export type BuilderSaveState = 'idle' | 'saving' | 'saved' | 'error';
export type FormPostStatus = 'draft' | 'publish';

interface BuilderState {
	formId: number;
	formTitle: string;
	formStatus: FormPostStatus;
	schema: FormSchema;
	saveState: BuilderSaveState;
	error: string | null;
	setFormId: ( formId: number ) => void;
	setFormTitle: ( title: string ) => void;
	setFormStatus: ( status: FormPostStatus ) => void;
	setSchema: ( schema: FormSchema ) => void;
	setTheme: ( theme: string ) => void;
	setNotificationEnabled: ( enabled: boolean ) => void;
	setNotificationRecipients: ( recipients: string ) => void;
	setNotificationIncludedFieldIds: ( ids: string[] | null ) => void;
	setSpamHoneypotEnabled: ( enabled: boolean ) => void;
	setSpamSubmissionRateLimit: ( limit: number ) => void;
	setSpamSubmissionRateWindow: ( seconds: number ) => void;
	setSpamDuplicateSubmissionWindow: ( seconds: number ) => void;
	setSaveState: ( state: BuilderSaveState ) => void;
	setError: ( message: string | null ) => void;
}

export const useBuilderState = create< BuilderState >( ( set ) => ( {
	formId: 0,
	formTitle: '',
	formStatus: 'draft',
	schema: createEmptySchema(),
	saveState: 'idle',
	error: null,
	setFormId: ( formId ) => set( { formId } ),
	setFormTitle: ( formTitle ) => set( { formTitle } ),
	setFormStatus: ( formStatus ) => set( { formStatus } ),
	setSchema: ( schema ) => set( { schema } ),
	setTheme: ( theme ) => set( ( state ) => ( {
		schema: {
			...state.schema,
			settings: {
				...state.schema.settings,
				theme,
			},
		},
	} ) ),
	setNotificationEnabled: ( enabled ) => set( ( state ) => ( {
		schema: {
			...state.schema,
			settings: {
				...state.schema.settings,
				notification: {
					...state.schema.settings.notification,
					enabled,
				},
			},
		},
	} ) ),
	setNotificationRecipients: ( recipients ) => set( ( state ) => ( {
		schema: {
			...state.schema,
			settings: {
				...state.schema.settings,
				notification: {
					...state.schema.settings.notification,
					recipients,
				},
			},
		},
	} ) ),
	setNotificationIncludedFieldIds: ( included_field_ids ) => set( ( state ) => ( {
		schema: {
			...state.schema,
			settings: {
				...state.schema.settings,
				notification: {
					...state.schema.settings.notification,
					included_field_ids,
				},
			},
		},
	} ) ),
	setSpamHoneypotEnabled: ( enable_honeypot ) => set( ( state ) => ( {
		schema: {
			...state.schema,
			settings: {
				...state.schema.settings,
				spam_prevention: {
					...state.schema.settings.spam_prevention,
					enable_honeypot,
				},
			},
		},
	} ) ),
	setSpamSubmissionRateLimit: ( submission_rate_limit ) => set( ( state ) => ( {
		schema: {
			...state.schema,
			settings: {
				...state.schema.settings,
				spam_prevention: {
					...state.schema.settings.spam_prevention,
					submission_rate_limit,
				},
			},
		},
	} ) ),
	setSpamSubmissionRateWindow: ( submission_rate_window ) => set( ( state ) => ( {
		schema: {
			...state.schema,
			settings: {
				...state.schema.settings,
				spam_prevention: {
					...state.schema.settings.spam_prevention,
					submission_rate_window,
				},
			},
		},
	} ) ),
	setSpamDuplicateSubmissionWindow: ( duplicate_submission_window ) => set( ( state ) => ( {
		schema: {
			...state.schema,
			settings: {
				...state.schema.settings,
				spam_prevention: {
					...state.schema.settings.spam_prevention,
					duplicate_submission_window,
				},
			},
		},
	} ) ),
	setSaveState: ( saveState ) => set( { saveState } ),
	setError: ( error ) => set( { error } ),
} ) );
