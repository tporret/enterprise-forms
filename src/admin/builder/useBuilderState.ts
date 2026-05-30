import { create } from 'zustand';
import type { FormSchema } from './schemaTypes';
import { createEmptySchema } from './schemaTypes';

export type BuilderSaveState = 'idle' | 'saving' | 'saved' | 'error';

interface BuilderState {
	formId: number;
	formTitle: string;
	schema: FormSchema;
	saveState: BuilderSaveState;
	error: string | null;
	setFormId: ( formId: number ) => void;
	setFormTitle: ( title: string ) => void;
	setSchema: ( schema: FormSchema ) => void;
	setTheme: ( theme: string ) => void;
	setNotificationEnabled: ( enabled: boolean ) => void;
	setNotificationRecipients: ( recipients: string ) => void;
	setNotificationIncludedFieldIds: ( ids: string[] | null ) => void;
	setSaveState: ( state: BuilderSaveState ) => void;
	setError: ( message: string | null ) => void;
}

export const useBuilderState = create< BuilderState >( ( set ) => ( {
	formId: 0,
	formTitle: '',
	schema: createEmptySchema(),
	saveState: 'idle',
	error: null,
	setFormId: ( formId ) => set( { formId } ),
	setFormTitle: ( formTitle ) => set( { formTitle } ),
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
	setSaveState: ( saveState ) => set( { saveState } ),
	setError: ( error ) => set( { error } ),
} ) );
