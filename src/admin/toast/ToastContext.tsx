import { SnackbarList } from '@wordpress/components';
import { createContext, useCallback, useContext, useMemo, useState } from '@wordpress/element';

export type ToastStatus = 'info' | 'success' | 'warning' | 'error';

export interface ToastNotice {
	id: string;
	content: string;
	type: ToastStatus;
}

interface ToastContextValue {
	addToast: ( content: string, type?: ToastStatus ) => void;
	removeToast: ( id: string ) => void;
}

interface ToastProviderProps {
	children: JSX.Element;
}

const ToastContext = createContext< ToastContextValue | null >( null );

const createToastId = (): string => `toast_${ Date.now() }_${ Math.floor( Math.random() * 10000 ) }`;

export const ToastProvider = ( { children }: ToastProviderProps ): JSX.Element => {
	const [ notices, setNotices ] = useState< ToastNotice[] >( [] );

	const removeToast = useCallback( ( id: string ) => {
		setNotices( ( current ) => current.filter( ( notice ) => notice.id !== id ) );
	}, [] );

	const addToast = useCallback( ( content: string, type: ToastStatus = 'info' ) => {
		setNotices( ( current ) => [ ...current, { id: createToastId(), content, type } ] );
	}, [] );

	const contextValue = useMemo< ToastContextValue >( () => ( { addToast, removeToast } ), [ addToast, removeToast ] );

	return (
		<ToastContext.Provider value={ contextValue }>
			<>
				{ children }
				<SnackbarList notices={ notices } className="ef-snackbar-list" onRemove={ removeToast } />
			</>
		</ToastContext.Provider>
	);
};

export const useToast = (): ToastContextValue => {
	const context = useContext( ToastContext );
	if ( ! context ) {
		throw new Error( 'useToast must be used within ToastProvider.' );
	}

	return context;
};
