<?php
/**
 * Page Break Block Render
 *
 * @package enterprise-forms
 */

// This block is processed by the schema parser and does not render directly on the frontend.
// It serves as a visual marker in the admin builder to divide form steps.
// The actual step navigation is handled by the form block's view.ts logic.

echo '<!-- wp:ep/page-break {"title":"' . esc_attr( $attributes['title'] ?? '' ) . '","description":"' . esc_attr( $attributes['description'] ?? '' ) . '"} /-->';
