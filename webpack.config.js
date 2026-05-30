const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// When WP_EXPERIMENTAL_MODULES=true, @wordpress/scripts exports [scriptConfig, moduleConfig].
const configs = Array.isArray( defaultConfig ) ? defaultConfig : [ defaultConfig ];

const scriptConfig = configs[ 0 ];
const moduleConfig = configs[ 1 ]; // undefined when modules flag is off

const scriptConfigWithAdmin = {
	...scriptConfig,
	entry: {
		...( ( typeof scriptConfig.entry === 'function' ) ? scriptConfig.entry() : scriptConfig.entry ),
		'admin/index': './src/admin/index.tsx',
	},
};

module.exports = moduleConfig
	? [ scriptConfigWithAdmin, moduleConfig ]
	: scriptConfigWithAdmin;
