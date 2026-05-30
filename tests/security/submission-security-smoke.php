<?php
declare(strict_types=1);

use EnterpriseForms\EP_REST_Entries;
use EnterpriseForms\RestApi;

$wp_load = '';
$cursor = __DIR__;
for ( $depth = 0; $depth < 8; $depth++ ) {
	$candidate = $cursor . '/wp-load.php';
	if ( file_exists( $candidate ) ) {
		$wp_load = $candidate;
		break;
	}

	$cursor = dirname( $cursor );
}

if ( '' === $wp_load ) {
	fwrite( STDERR, "Unable to locate wp-load.php. Run this from inside a WordPress checkout.\n" );
	exit( 1 );
}

require_once $wp_load;

if ( ! class_exists( EP_REST_Entries::class ) ) {
	require_once dirname( __DIR__, 2 ) . '/enterprise-forms.php';
}

$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$token_key = static function ( string $token ): string {
	return 'ep_submit_token_' . hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
};

$make_request = static function ( int $form_id, array $params = [] ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST', '/enterprise-forms/v1/entries/' . $form_id );
	$request->set_param( 'form_id', $form_id );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return $request;
};

$entries = new EP_REST_Entries();
$form_id = 999999;

$no_nonce = $entries->create_item_permissions_check( $make_request( $form_id, [ 'hp_field' => '' ] ) );
$assert( is_wp_error( $no_nonce ) && 403 === (int) $no_nonce->get_error_data()['status'], 'Empty honeypot without nonce should be rejected.' );

$nonce = wp_create_nonce( 'ep_forms_public_submit' );
$submission_token = wp_generate_uuid4();
set_transient( $token_key( $submission_token ), $form_id, HOUR_IN_SECONDS );

$first = $entries->create_item_permissions_check(
	$make_request(
		$form_id,
		[
			'hp_field'            => '',
			'ep_forms_nonce'      => $nonce,
			'ep_submission_token' => $submission_token,
		]
	)
);
$assert( true === $first, 'Valid nonce and fresh submission token should pass.' );

$replay = $entries->create_item_permissions_check(
	$make_request(
		$form_id,
		[
			'hp_field'            => '',
			'ep_forms_nonce'      => $nonce,
			'ep_submission_token' => $submission_token,
		]
	)
);
$assert( is_wp_error( $replay ) && 'ep_forms_replay_detected' === $replay->get_error_code(), 'Reused submission token should be rejected.' );

add_filter( 'ep_forms_submission_rate_limit', static fn (): int => 1 );
add_filter( 'ep_forms_submission_rate_window', static fn (): int => HOUR_IN_SECONDS );

$rate_form_id = 1000000;
$rate_token_one = wp_generate_uuid4();
$rate_token_two = wp_generate_uuid4();
set_transient( $token_key( $rate_token_one ), $rate_form_id, HOUR_IN_SECONDS );
set_transient( $token_key( $rate_token_two ), $rate_form_id, HOUR_IN_SECONDS );

$entries->create_item_permissions_check(
	$make_request(
		$rate_form_id,
		[
			'hp_field'            => '',
			'ep_forms_nonce'      => $nonce,
			'ep_submission_token' => $rate_token_one,
		]
	)
);

$limited = $entries->create_item_permissions_check(
	$make_request(
		$rate_form_id,
		[
			'hp_field'            => '',
			'ep_forms_nonce'      => $nonce,
			'ep_submission_token' => $rate_token_two,
		]
	)
);
$assert( is_wp_error( $limited ) && 'ep_forms_rate_limited' === $limited->get_error_code(), 'Rate limit should block burst submissions per form.' );

$rest_api = new RestApi();
$method = new ReflectionMethod( RestApi::class, 'validate_local_upload_content' );
$method->setAccessible( true );
$spoofed_svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
$upload_check = $method->invoke( $rest_api, $spoofed_svg, 'avatar.jpg', [ 'jpg', 'jpeg', 'png' ] );
$assert( is_wp_error( $upload_check ), 'Spoofed image upload content should be rejected.' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "Submission security smoke checks failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Submission security smoke checks passed.\n";