export interface ValidationRules {
	min_length?: number;
	max_length?: number;
	pattern?: string;
	min?: number;
	max?: number;
	min_date?: string;
	max_date?: string;
	step?: number;
	max_size_mb?: number;
	accept?: string;
}

export interface FormField {
	id: string;
	type: string;
	label: string;
	required: boolean;
	validation_rules: ValidationRules;
	name?: string;
	placeholder?: string;
	value?: string;
	multiple?: boolean;
	button_text?: string;
	options?: string[];
	gateway?: 'stripe';
	amount_source?: 'static' | 'field';
	amount?: string;
	amount_field?: string;
	currency?: string;
	description?: string;
	enable_wallets?: boolean;
}

export interface FormPage {
	id: string;
	title?: string;
	description?: string;
	fields: FormField[];
}

export interface ConditionalLogicRule {
	id: string;
	field_id: string;
	operator: 'equals' | 'not_equals' | 'contains' | 'is_empty' | 'is_not_empty';
	value: string;
	action: 'show' | 'hide' | 'require' | 'disable';
	target_field_id: string;
}

export interface NotificationSettings {
	enabled: boolean;
	recipients: string;
	included_field_ids: string[] | null; // null = all eligible fields
}

export interface FormSettings {
	theme: string;
	notification: NotificationSettings;
}

export interface FormSchema {
	schema_version: '1.0.0';
	requires_payment?: boolean;
	fields: FormField[];
	pages: FormPage[];
	logic: ConditionalLogicRule[];
	settings: FormSettings;
}

export const createEmptySchema = (): FormSchema => ( {
	schema_version: '1.0.0',
	fields: [],
	pages: [],
	logic: [],
	settings: {
		theme: 'chameleon',
		notification: {
			enabled: true,
			recipients: '',
			included_field_ids: null,
		},
	},
} );
