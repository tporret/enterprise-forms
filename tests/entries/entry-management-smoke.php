<?php
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require_once dirname( __DIR__, 5 ) . '/wp-load.php';

use EnterpriseForms\EP_Crypto;
use EnterpriseForms\Permissions;

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$request = static function ( string $method, string $path, array $params = [] ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/enterprise-forms/v1/' . $path );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
if ( ! $admins || ! EP_Crypto::is_configured() ) {
	fwrite( STDERR, "Tests require a local WordPress administrator and configured entry encryption.\n" );
	exit( 1 );
}

$original_user = get_current_user_id();
$grant_test_capabilities = static function ( array $caps, array $required, array $args, WP_User $user ) use ( $admins ): array {
	if ( (int) $admins[0] === $user->ID ) {
		foreach ( [ Permissions::EDIT_FORMS, Permissions::VIEW_ENTRIES, Permissions::EXPORT_ENTRIES ] as $cap ) {
			$caps[ $cap ] = true;
		}
	}
	return $caps;
};
add_filter( 'user_has_cap', $grant_test_capabilities, 10, 4 );
wp_set_current_user( (int) $admins[0] );
if ( ! current_user_can( Permissions::EDIT_FORMS ) ) {
	fwrite( STDERR, "The local administrator needs Enterprise Forms capabilities.\n" );
	exit( 1 );
}

$form_ids = [];
$entry_ids = [];
$table = $wpdb->prefix . 'ep_entries';
$search_table = $wpdb->prefix . 'ep_entry_search';
$audit_table = $wpdb->prefix . 'ep_audit_log';

try {
	for ( $index = 0; $index < 2; $index++ ) {
		$form_id = wp_insert_post( [ 'post_type' => 'ep_form', 'post_status' => 'draft', 'post_title' => 'Entry management test ' . wp_generate_uuid4() ], true );
		if ( is_wp_error( $form_id ) ) {
			throw new RuntimeException( $form_id->get_error_message() );
		}
		$form_ids[] = $form_id;
	}
	$crypto = new EP_Crypto();
	$payload = wp_json_encode( [ 'name' => 'Entry Fixture', 'message' => 'reviewworkflowfixture' ] );
	$stored_payload = wp_json_encode( [ 'ciphertext' => $crypto->encrypt( $payload ) ] );
	foreach ( [ 'unread', 'read', 'spam', 'trash', 'redacted', 'unread' ] as $index => $status ) {
		$inserted = $wpdb->insert( $table, [
			'uuid' => wp_generate_uuid4(),
			'form_id' => $form_ids[ 5 === $index ? 1 : 0 ],
			'status' => $status,
			'payload' => $stored_payload,
			'created_at' => '2026-10-16 10:00:00',
		] );
		if ( false === $inserted ) {
			throw new RuntimeException( 'Unable to create fixture entry: ' . $wpdb->last_error );
		}
		$entry_ids[] = (int) $wpdb->insert_id;
	}
	[ $unread, $read, $spam, $trash, $redacted, $other_form ] = $entry_ids;
	$form_id = $form_ids[0];
	$bulk = static fn ( array $ids, string $action, bool $confirm = false ) => $request( 'POST', "entries/{$form_id}/bulk", [ 'ids' => $ids, 'action' => $action, 'confirm_delete' => $confirm ] );
	$status = static fn ( int $id ) => $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );

	wp_set_current_user( 0 );
	$assert( 403 === $bulk( [ $unread ], 'read' )->get_status(), 'Anonymous users must not mutate entries.' );
	$assert( 403 === $request( 'GET', "entries/{$form_id}" )->get_status(), 'Anonymous users must not view entries.' );
	wp_set_current_user( (int) $admins[0] );

	$list = $request( 'GET', "entries/{$form_id}", [ 'status' => 'inbox', 'limit' => 25 ] )->get_data();
	$ids = array_column( $list['items'], 'id' );
	sort( $ids );
	$assert( [ $unread, $read, $redacted ] === $ids, 'Inbox must exclude Spam and Trash but retain other existing statuses.' );
	$assert( 3 === $list['counts']['inbox'] && 1 === $list['counts']['trash'], 'Tab counts must cover all entries for this form.' );
	$assert( 3 === EnterpriseForms\Database::count_entries( $form_id ), 'Dashboard counts must exclude Spam and Trash.' );
	$assert( 3 === EnterpriseForms\Database::count_entries_by_form_ids( [ $form_id ] )[ $form_id ], 'Per-form dashboard counts must exclude Spam and Trash.' );
	$assert( 'Entry Fixture' === $list['items'][0]['payload']['name'], 'Encrypted entry details must still be readable.' );
	$page = $request( 'GET', "entries/{$form_id}", [ 'status' => 'inbox', 'limit' => 1, 'offset' => 1 ] )->get_data();
	$assert( 3 === $page['total'] && 3 === $page['total_pages'] && 1 === count( $page['items'] ), 'Filtered pagination must have matching totals.' );
	$empty = $request( 'GET', "entries/{$form_id}", [ 'status' => 'inbox', 'date_from' => '2026-10-17' ] )->get_data();
	$assert( 0 === $empty['total'] && 3 === $empty['counts']['inbox'], 'Date filtering must not alter global tab counts.' );
	$search = $request( 'GET', "entries/{$form_id}", [ 'status' => 'inbox', 'search' => 'reviewworkflowfixture' ] )->get_data();
	$assert( 3 === $search['total'], 'Secure payload search must respect the Inbox filter.' );
	$export = $request( 'GET', "entries/{$form_id}/export", [ 'status' => 'inbox', 'columns' => 'entry_id,status' ] );
	$csv_rows = array_map( 'str_getcsv', explode( "\n", trim( substr( $export->get_data(), 3 ) ) ) );
	$export_ids = array_map( 'intval', array_column( array_slice( $csv_rows, 1 ), 0 ) );
	sort( $export_ids );
	$assert( [ $unread, $read, $redacted ] === $export_ids, 'CSV export must use the same Inbox filter as the list.' );
	$view_only = static function ( array $caps ): array {
		$caps[ Permissions::EDIT_FORMS ] = false;
		$caps[ Permissions::EXPORT_ENTRIES ] = false;
		return $caps;
	};
	add_filter( 'user_has_cap', $view_only, 20 );
	$readonly_list = $request( 'GET', "entries/{$form_id}", [ 'status' => 'inbox' ] )->get_data();
	$assert( false === $readonly_list['can_edit'] && false === $readonly_list['can_export'], 'View-only responses must disable management and export controls.' );
	$assert( 403 === $bulk( [ $unread ], 'read' )->get_status(), 'View-only users cannot bulk edit.' );
	$assert( 403 === $request( 'POST', "entries/{$unread}/status", [ 'status' => 'read' ] )->get_status(), 'View-only users cannot change single-entry status.' );
	$assert( 403 === $request( 'GET', "entries/{$form_id}/export" )->get_status(), 'View-only users cannot export.' );
	remove_filter( 'user_has_cap', $view_only, 20 );

	foreach ( [ [ [], 'read' ], [ range( 1, 101 ), 'read' ], [ [ $read, $read ], 'read' ], [ [ -1 ], 'read' ], [ [ $read ], 'invalid' ] ] as [ $ids, $action ] ) {
		$assert( 400 === $bulk( $ids, $action )->get_status(), 'Invalid bulk parameters must be rejected by REST schema validation.' );
	}
	$assert( 400 === $bulk( [ $trash ], 'delete' )->get_status(), 'Permanent deletion must require confirmation.' );
	$boundary = $bulk( array_merge( [ $read ], range( PHP_INT_MAX - 98, PHP_INT_MAX ) ), 'read' );
	$assert( 200 === $boundary->get_status() && [ $read ] === $boundary->get_data()['updated'] && 99 === count( $boundary->get_data()['errors'] ), 'Exactly 100 selected IDs must be supported with per-ID results.' );
	$assert( 1 === count( $bulk( [ $spam ], 'delete', true )->get_data()['errors'] ), 'Only trashed entries may be permanently deleted.' );
	$result = $bulk( [ $unread, $read, $other_form, PHP_INT_MAX ], 'spam' )->get_data();
	$assert( [ $unread, $read ] === $result['updated'] && 2 === count( $result['errors'] ), 'Bulk actions must report mixed results and reject wrong-form or missing IDs.' );
	$assert( 'unread' === $status( $other_form ), 'Entries from other forms must remain untouched.' );
	$assert( 'spam' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$search_table} WHERE entry_id = %d", $unread ) ), 'Status updates must synchronize search indexing.' );
	$bulk( [ $unread ], 'trash' );
	$assert( 'trash' === $status( $unread ), 'Trash must preserve the entry.' );
	$assert( 1 === count( $bulk( [ $unread ], 'read' )->get_data()['errors'] ), 'Trash must require explicit restore before status changes.' );
	$bulk( [ $unread ], 'restore' );
	$assert( 'unread' === $status( $unread ), 'Restore must return the entry to the unread Inbox.' );
	$bulk( [ $unread ], 'read' );
	$assert( 'read' === $status( $unread ), 'Read action must persist.' );
	$bulk( [ $unread ], 'unread' );
	$assert( 'unread' === $status( $unread ), 'Unread action must persist.' );

	$single = $request( 'POST', "entries/{$read}/status", [ 'status' => 'read' ] );
	$assert( 200 === $single->get_status() && $read === $single->get_data()['id'], 'The single-entry status route must retain its canonical response shape.' );
	$original_suppression = $wpdb->suppress_errors( true );
	$fail_update = static fn ( string $sql ): string => str_replace( "UPDATE `{$table}`", "UPDATE `{$table}_missing_fixture`", $sql );
	add_filter( 'query', $fail_update );
	$failed_update = $bulk( [ $unread ], 'read' )->get_data();
	remove_filter( 'query', $fail_update );
	$assert( [] === $failed_update['updated'] && 1 === count( $failed_update['errors'] ) && 'unread' === $status( $unread ), 'Database update failures must not report success.' );
	$fail_index = static fn ( string $sql ): string => str_replace( "REPLACE INTO `{$search_table}`", "REPLACE INTO `{$search_table}_missing_fixture`", $sql );
	add_filter( 'query', $fail_index );
	$failed_index = $bulk( [ $unread ], 'read' )->get_data();
	remove_filter( 'query', $fail_index );
	$assert( [] === $failed_index['updated'] && str_contains( $failed_index['errors'][0]['message'] ?? '', 'status was saved' ), 'Search index failures must explicitly report partial persistence.' );
	$fail_delete = static fn ( string $sql ): string => str_replace( "DELETE FROM `{$search_table}`", "DELETE FROM `{$search_table}_missing_fixture`", $sql );
	add_filter( 'query', $fail_delete );
	$failed_delete = $bulk( [ $trash ], 'delete', true )->get_data();
	remove_filter( 'query', $fail_delete );
	$wpdb->suppress_errors( $original_suppression );
	$assert( [] === $failed_delete['updated'] && 1 === count( $failed_delete['errors'] ) && 'trash' === $status( $trash ), 'Failed index cleanup must preserve the trashed entry and report failure.' );
	$delete = $bulk( [ $trash ], 'delete', true )->get_data();
	$assert( [ $trash ] === $delete['updated'] && null === $status( $trash ), 'Confirmed deletion must remove the trashed entry.' );
	$assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM {$search_table} WHERE entry_id = %d", $trash ) ), 'Deleted entries must not remain in the search index.' );
	$audit_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE object_type = 'entry' AND object_id = %d", $unread ) );
	$assert( $audit_count >= 5, 'Entry mutations must be audited.' );
} catch ( Throwable $error ) {
	$failures[] = $error->getMessage();
} finally {
	foreach ( $entry_ids as $id ) {
		$wpdb->delete( $search_table, [ 'entry_id' => $id ], [ '%d' ] );
		$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
		$wpdb->delete( $audit_table, [ 'object_type' => 'entry', 'object_id' => $id ], [ '%s', '%d' ] );
	}
	foreach ( $form_ids as $id ) {
		wp_delete_post( $id, true );
		$wpdb->delete( $audit_table, [ 'object_type' => 'form', 'object_id' => $id ], [ '%s', '%d' ] );
	}
	wp_set_current_user( $original_user );
	remove_filter( 'user_has_cap', $grant_test_capabilities, 10 );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "Entry management REST/database smoke tests passed.\n";
