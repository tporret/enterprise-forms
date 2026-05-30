import { createRoot, useEffect } from '@wordpress/element';
import App from './App';
import './style.css';

interface AdminBootstrapProps {
	children: JSX.Element;
}

const FULLSCREEN_STYLE_ID = 'enterprise-forms-fullscreen-hijack';

const AdminBootstrap = ( { children }: AdminBootstrapProps ): JSX.Element => {
	useEffect( () => {
		const styleElement = document.createElement( 'style' );
		styleElement.id = FULLSCREEN_STYLE_ID;
		styleElement.textContent = `
			body.toplevel_page_enterprise-forms #wpadminbar,
			body.toplevel_page_enterprise-forms #adminmenumain,
			body.toplevel_page_enterprise-forms #adminmenuback,
			body.toplevel_page_enterprise-forms #adminmenuwrap,
			body.toplevel_page_enterprise-forms #wpfooter {
				display: none !important;
			}
			body.toplevel_page_enterprise-forms #wpcontent,
			body.toplevel_page_enterprise-forms #wpbody,
			body.toplevel_page_enterprise-forms #wpwrap,
			body.toplevel_page_enterprise-forms #wpbody-content {
				margin-left: 0 !important;
				padding-left: 0 !important;
			}
			body.toplevel_page_enterprise-forms #wpbody-content {
				padding-bottom: 0 !important;
			}
			body.toplevel_page_enterprise-forms #enterprise-forms-root {
				width: 100% !important;
				min-height: 100vh !important;
			}
		`;
		document.head.appendChild( styleElement );

		return () => {
			document.getElementById( FULLSCREEN_STYLE_ID )?.remove();
		};
	}, [] );

	return children;
};

const mountNode = document.getElementById( 'enterprise-forms-root' );

if ( mountNode ) {
	const root = createRoot( mountNode );
	root.render(
		<AdminBootstrap>
			<App />
		</AdminBootstrap>
	);
}
