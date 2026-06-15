import { Button, PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { ConditionalLogicRule, FormField } from './schemaTypes';

interface LogicBuilderProps {
	fields: FormField[];
	logic: ConditionalLogicRule[];
	onChange: ( logic: ConditionalLogicRule[] ) => void;
}

const OPERATORS = [
	{ label: __( 'equals', 'enterprise-forms' ), value: 'equals' },
	{ label: __( 'not equals', 'enterprise-forms' ), value: 'not_equals' },
	{ label: __( 'contains', 'enterprise-forms' ), value: 'contains' },
	{ label: __( 'is empty', 'enterprise-forms' ), value: 'is_empty' },
	{ label: __( 'is not empty', 'enterprise-forms' ), value: 'is_not_empty' },
];

const ACTIONS = [
	{ label: __( 'Show', 'enterprise-forms' ), value: 'show' },
	{ label: __( 'Hide', 'enterprise-forms' ), value: 'hide' },
	{ label: __( 'Require', 'enterprise-forms' ), value: 'require' },
	{ label: __( 'Disable', 'enterprise-forms' ), value: 'disable' },
];

export const LogicBuilder = ( { fields, logic, onChange }: LogicBuilderProps ): JSX.Element => {
	const [ newRule, setNewRule ] = useState< Partial< ConditionalLogicRule > >( {
		action: 'show',
		operator: 'equals',
	} );

	const fieldOptions = fields.map( ( f ) => ( { label: f.label || f.id, value: f.id } ) );

	const addRule = (): void => {
		if (
			! newRule.field_id ||
			! newRule.target_field_id ||
			! newRule.action ||
			! newRule.operator
		) {
			return;
		}

		const rule: ConditionalLogicRule = {
			id: `rule-${ Date.now() }`,
			field_id: newRule.field_id,
			target_field_id: newRule.target_field_id,
			action: newRule.action as 'show' | 'hide' | 'require' | 'disable',
			operator: newRule.operator as 'equals' | 'not_equals' | 'contains' | 'is_empty' | 'is_not_empty',
			value: newRule.value || '',
		};

		onChange( [ ...logic, rule ] );
		setNewRule( { action: 'show', operator: 'equals' } );
	};

	const removeRule = ( id: string ): void => {
		onChange( logic.filter( ( r ) => r.id !== id ) );
	};

	const updateRule = ( id: string, key: keyof ConditionalLogicRule, value: unknown ): void => {
		onChange(
			logic.map( ( r ) =>
				r.id === id ? { ...r, [ key ]: value } : r
			)
		);
	};

	const requiresValue = ( operator: string ): boolean => {
		return operator !== 'is_empty' && operator !== 'is_not_empty';
	};

	return (
		<PanelBody title={ __( 'Conditional Logic Rules', 'enterprise-forms' ) } initialOpen className="ep-logic-builder">
			{ logic.length === 0 ? (
				<p className="mb-3 text-sm text-slate-500">
					{ __( 'No conditional logic rules yet. Create one below.', 'enterprise-forms' ) }
				</p>
			) : (
				<div className="mb-4 space-y-2 border-b border-slate-200 pb-4">
					{ logic.map( ( rule ) => {
						const triggerField = fields.find( ( f ) => f.id === rule.field_id );
						const targetField = fields.find( ( f ) => f.id === rule.target_field_id );

						return (
							<div
								key={ rule.id }
								className="flex items-start justify-between rounded bg-slate-50 p-2 text-xs"
							>
								<div className="flex-1">
									<p className="font-semibold text-slate-700">
										{ triggerField?.label || rule.field_id } { rule.operator }{ ' ' }
										{ requiresValue( rule.operator ) ? `"${ rule.value }"` : '(empty)' }
									</p>
									<p className="text-slate-600">
										→ { rule.action } { targetField?.label || rule.target_field_id }
									</p>
								</div>
								<button
									type="button"
									onClick={ () => removeRule( rule.id ) }
									className="ml-2 rounded p-1 text-red-500 hover:bg-red-100"
									title={ __( 'Remove rule', 'enterprise-forms' ) }
								>
									✕
								</button>
							</div>
						);
					} ) }
				</div>
			) }

			<div className="space-y-3 rounded border border-dashed border-slate-300 p-3">
				<h4 className="text-xs font-semibold uppercase text-slate-700">
					{ __( 'Add New Rule', 'enterprise-forms' ) }
				</h4>

				<SelectControl
					label={ __( 'When this field', 'enterprise-forms' ) }
					value={ newRule.field_id || '' }
					options={ [ { label: __( '— Select —', 'enterprise-forms' ), value: '' }, ...fieldOptions ] }
					onChange={ ( field_id ) => setNewRule( { ...newRule, field_id } ) }
				/>

				<SelectControl
					label={ __( 'Operator', 'enterprise-forms' ) }
					value={ newRule.operator || 'equals' }
					options={ OPERATORS }
					onChange={ ( operator ) => setNewRule( { ...newRule, operator: operator as ConditionalLogicRule['operator'] } ) }
				/>

				{ requiresValue( newRule.operator || 'equals' ) && (
					<TextControl
						label={ __( 'Value', 'enterprise-forms' ) }
						value={ newRule.value || '' }
						onChange={ ( value ) => setNewRule( { ...newRule, value } ) }
						placeholder={ __( 'e.g., "yes", "selected value"', 'enterprise-forms' ) }
					/>
				) }

				<SelectControl
					label={ __( 'Then', 'enterprise-forms' ) }
					value={ newRule.action || 'show' }
					options={ ACTIONS }
					onChange={ ( action ) => setNewRule( { ...newRule, action: action as ConditionalLogicRule['action'] } ) }
				/>

				<SelectControl
					label={ __( 'This field', 'enterprise-forms' ) }
					value={ newRule.target_field_id || '' }
					options={ [ { label: __( '— Select —', 'enterprise-forms' ), value: '' }, ...fieldOptions ] }
					onChange={ ( target_field_id ) => setNewRule( { ...newRule, target_field_id } ) }
				/>

				<Button variant="primary" onClick={ addRule }>
					{ __( '+ Add Rule', 'enterprise-forms' ) }
				</Button>
			</div>
		</PanelBody>
	);
};
