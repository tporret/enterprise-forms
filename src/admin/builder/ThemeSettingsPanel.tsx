import { Card, CardBody, CardHeader, SelectControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useBuilderState } from './useBuilderState';

const THEME_PANEL_STORAGE_KEY = 'enterpriseForms.themePanel.isOpen';

declare global {
	interface Window {
		enterpriseFormsAdminConfig?: {
			themes?: Record< string, string >;
		};
	}
}

const getThemeOptions = (): Array<{ label: string; value: string }> => {
	const themes = window.enterpriseFormsAdminConfig?.themes ?? {};
	const entries = Object.entries( themes );

	if ( entries.length === 0 ) {
		return [
			{ label: __( 'FSE Default', 'enterprise-forms' ), value: 'chameleon' },
			{ label: __( 'ITSM Standard', 'enterprise-forms' ), value: 'itsm' },
		];
	}

	return entries.map( ( [ value, label ] ) => ( { value, label } ) );
};

const ThemeSettingsPanel = (): JSX.Element => {
	const [ isOpen, setIsOpen ] = useState( () => {
		if ( typeof window === 'undefined' ) {
			return false;
		}

		try {
			return window.localStorage.getItem( THEME_PANEL_STORAGE_KEY ) === '1';
		} catch {
			return false;
		}
	} );
	const formId = useBuilderState( ( state ) => state.formId );
	const theme = useBuilderState( ( state ) => state.schema.settings.theme );
	const setTheme = useBuilderState( ( state ) => state.setTheme );
	const options = getThemeOptions();

	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return;
		}

		try {
			window.localStorage.setItem( THEME_PANEL_STORAGE_KEY, isOpen ? '1' : '0' );
		} catch {
			// Ignore storage errors (private mode / blocked storage).
		}
	}, [ isOpen ] );

	return (
		<Card>
			<CardHeader
				className="cursor-pointer select-none"
				onClick={ () => setIsOpen( ( prev ) => ! prev ) }
			>
				<strong>{ __( 'Theme', 'enterprise-forms' ) }</strong>
				<span className="text-slate-400 text-xs">{ isOpen ? '▲' : '▼' }</span>
			</CardHeader>
			{ isOpen && (
				<CardBody>
					<SelectControl
						label={ __( 'Frontend theme', 'enterprise-forms' ) }
						value={ theme || 'chameleon' }
						options={ options }
						onChange={ ( value ) => setTheme( value ) }
						help={ __( 'Controls the frontend CSS variable theme applied to this form block.', 'enterprise-forms' ) }
						disabled={ formId <= 0 }
					/>
				</CardBody>
			) }
		</Card>
	);
};

export default ThemeSettingsPanel;