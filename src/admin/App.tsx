import { Suspense } from '@wordpress/element';
import { HashRouter, useRoutes } from 'react-router-dom';
import SkeletonLoader from './components/SkeletonLoader';
import { APP_ROUTES } from './routing/routes';
import { ToastProvider } from './toast/ToastContext';

interface AppRouterProps {}

const AppRouter = ( {}: AppRouterProps ): JSX.Element => {
	const routedElement = useRoutes( APP_ROUTES );
	return routedElement ?? <SkeletonLoader rows={ 3 } />;
};

const App = (): JSX.Element => {
	return (
		<HashRouter>
			<ToastProvider>
				<Suspense fallback={ <SkeletonLoader rows={ 5 } /> }>
					<AppRouter />
				</Suspense>
			</ToastProvider>
		</HashRouter>
	);
};

export default App;
