<?php
namespace EnterpriseForms;

/**
 * Connects the WordPress Interactivity API for Frontend performance.
 */
class Interactivity {
	public function init(): void {
		add_action( 'init', [ $this, 'register_block' ] );
	}

	public function register_block(): void {
		// Registering from build directory where block metadata is generated.
		$block_dir = EP_FORMS_PATH . 'build/blocks/form';
		$index_php = $block_dir . '/index.php';

		if ( file_exists( $index_php ) ) {
			require_once $index_php;
		}

		if ( file_exists( $block_dir . '/block.json' ) ) {
			register_block_type( $block_dir );
		}

		foreach ( [ 'src/blocks/page-break', 'src/blocks/file-upload' ] as $relative_dir ) {
			$metadata_dir = EP_FORMS_PATH . $relative_dir;

			if ( file_exists( $metadata_dir . '/block.json' ) ) {
				register_block_type_from_metadata( $metadata_dir );
			}
		}
	}
}
