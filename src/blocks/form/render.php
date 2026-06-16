<?php
/**
 * Back-compat shim. Block metadata now points at view.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/view.php';
