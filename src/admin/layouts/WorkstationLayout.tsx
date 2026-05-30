import { NavLink, Outlet } from 'react-router-dom';
import { __ } from '@wordpress/i18n';

interface NavigationItem {
	label: string;
	path: string;
}

const NAV_ITEMS: NavigationItem[] = [
	{ label: __( 'Dashboard', 'enterprise-forms' ), path: '/dashboard' },
	{ label: __( 'Builder', 'enterprise-forms' ), path: '/builder/0' },
	{ label: __( 'Entries', 'enterprise-forms' ), path: '/entries/0' },
	{ label: __( 'Settings', 'enterprise-forms' ), path: '/settings' },
];

const WorkstationLayout = (): JSX.Element => {
	return (
		<div className="min-h-screen bg-slate-100 text-slate-900">
			<div className="grid min-h-screen grid-cols-1 lg:grid-cols-[240px_1fr]">
				<aside className="border-r border-slate-200 bg-white/95 p-6">
					<div className="mb-8">
						<h1 className="text-lg font-semibold tracking-tight">{ __( 'Enterprise Forms', 'enterprise-forms' ) }</h1>
						<p className="mt-1 text-xs text-slate-500">{ __( 'Workstation', 'enterprise-forms' ) }</p>
					</div>
					<nav className="space-y-2">
						{ NAV_ITEMS.map( ( item ) => (
							<NavLink
								key={ item.path }
								to={ item.path }
								className={ ( { isActive } ) =>
									`block rounded-lg px-3 py-2 text-sm font-medium transition ${
										isActive ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'
									}`
								}
							>
								{ item.label }
							</NavLink>
						) ) }
						<div className="h-4" aria-hidden="true" />
						<div className="h-4" aria-hidden="true" />
						<a
							href="index.php"
							className="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
						>
							{ __( 'Exit', 'enterprise-forms' ) }
						</a>
					</nav>
				</aside>
				<main className="overflow-auto">
					<Outlet />
				</main>
			</div>
		</div>
	);
};

export default WorkstationLayout;
