import { Card, CardBody, CardHeader, TextControl, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useBuilderState } from './useBuilderState';

const PANEL_STORAGE_KEY = 'enterpriseForms.spamPreventionPanel.isOpen';

const clampInt = ( value: number, min: number, max: number ): number => {
	if ( Number.isNaN( value ) ) {
		return min;
	}

	return Math.min( max, Math.max( min, Math.round( value ) ) );
};

const parseNumber = ( value: string, fallback: number, min: number, max: number ): number => {
	const parsed = Number( value );
	if ( Number.isNaN( parsed ) ) {
		return fallback;
	}

	return clampInt( parsed, min, max );
};

const SpamPreventionSettingsPanel = (): JSX.Element => {
	const [ isOpen, setIsOpen ] = useState( () => {
		if ( typeof window === 'undefined' ) {
			return false;
		}

		try {
			return window.localStorage.getItem( PANEL_STORAGE_KEY ) === '1';
		} catch {
			return false;
		}
	} );

	const formId = useBuilderState( ( state ) => state.formId );
	const spamPrevention = useBuilderState( ( state ) => state.schema.settings.spam_prevention );
	const setSpamHoneypotEnabled = useBuilderState( ( state ) => state.setSpamHoneypotEnabled );
	const setSpamSubmissionRateLimit = useBuilderState( ( state ) => state.setSpamSubmissionRateLimit );
	const setSpamSubmissionRateWindow = useBuilderState( ( state ) => state.setSpamSubmissionRateWindow );
	const setSpamDuplicateSubmissionWindow = useBuilderState( ( state ) => state.setSpamDuplicateSubmissionWindow );

	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return;
		}

		try {
			window.localStorage.setItem( PANEL_STORAGE_KEY, isOpen ? '1' : '0' );
		} catch {
			// Ignore storage errors.
		}
	}, [ isOpen ] );

	const handleHoneypotToggle = ( enabled: boolean ): void => {
		if ( ! enabled && spamPrevention.enable_honeypot ) {
			const proceed = window.confirm(
				__(
					'Disabling the honeypot may increase bot submissions. Keep other protections enabled and only disable this if you know why.',
					'enterprise-forms'
				)
			);
			if ( ! proceed ) {
				return;
			}
		}

		setSpamHoneypotEnabled( enabled );
	};

	return (
		<Card>
			<CardHeader
				className="cursor-pointer select-none"
				onClick={ () => setIsOpen( ( prev ) => ! prev ) }
			>
				<strong className="flex-1">{ __( 'Spam Prevention', 'enterprise-forms' ) }</strong>
				<span className="text-slate-400 text-xs">{ isOpen ? '▲' : '▼' }</span>
			</CardHeader>
			{ isOpen && (
				<CardBody>
					<ToggleControl
						label={ __( 'Enable honeypot trap field', 'enterprise-forms' ) }
						checked={ Boolean( spamPrevention.enable_honeypot ) }
						onChange={ handleHoneypotToggle }
						disabled={ formId <= 0 }
						help={ __( 'Recommended: keep enabled unless this conflicts with custom frontend behavior.', 'enterprise-forms' ) }
					/>

					<TextControl
						type="number"
						label={ __( 'Rate limit: submissions per requester', 'enterprise-forms' ) }
						value={ String( spamPrevention.submission_rate_limit ?? 10 ) }
						onChange={ ( value ) => {
							setSpamSubmissionRateLimit( parseNumber( value, 10, 1, 1000 ) );
						} }
						help={ __( 'Default is 10. Higher values allow more burst traffic.', 'enterprise-forms' ) }
						disabled={ formId <= 0 }
					/>

					<TextControl
						type="number"
						label={ __( 'Rate limit window (seconds)', 'enterprise-forms' ) }
						value={ String( spamPrevention.submission_rate_window ?? 60 ) }
						onChange={ ( value ) => {
							setSpamSubmissionRateWindow( parseNumber( value, 60, 1, 86400 ) );
						} }
						help={ __( 'Default is 60 seconds.', 'enterprise-forms' ) }
						disabled={ formId <= 0 }
					/>

					<TextControl
						type="number"
						label={ __( 'Duplicate lock window (seconds)', 'enterprise-forms' ) }
						value={ String( spamPrevention.duplicate_submission_window ?? 300 ) }
						onChange={ ( value ) => {
							setSpamDuplicateSubmissionWindow( parseNumber( value, 300, 1, 86400 ) );
						} }
						help={ __( 'Prevents immediate re-submission of identical payloads. Default is 300 seconds.', 'enterprise-forms' ) }
						disabled={ formId <= 0 }
					/>
				</CardBody>
			) }
		</Card>
	);
};

export default SpamPreventionSettingsPanel;
