import { lazy } from '@wordpress/element';
import { Navigate, type RouteObject } from 'react-router-dom';
import WorkstationLayout from '../layouts/WorkstationLayout';

const DashboardRoute = lazy( () => import( '../routes/Dashboard' ) );
const BuilderRoute = lazy( () => import( '../routes/Builder' ) );
const EntriesRoute = lazy( () => import( '../routes/Entries' ) );
const SettingsPaymentsRoute = lazy( () => import( '../routes/SettingsPayments' ) );

export const APP_ROUTES: RouteObject[] = [
	{
		path: '/',
		element: <WorkstationLayout />,
		children: [
			{ index: true, element: <Navigate to="/dashboard" replace /> },
			{ path: 'dashboard', element: <DashboardRoute /> },
			{ path: 'builder/:formId', element: <BuilderRoute /> },
			{ path: 'entries/:formId', element: <EntriesRoute /> },
			{ path: 'settings', element: <SettingsPaymentsRoute /> },
		],
	},
];
