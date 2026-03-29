<?php
/**
 * AJAX Search Handler for TMDb
 * Version 1.0.8 - Explicit Handshake Verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Search_Handler {

	public function __construct() {
		// Matches assets/js/tvm-search.js exactly
		add_action( 'wp_ajax_tvm_search_tmdb_alpha', array( $this, 'handle_search' ) );
	}

	public function handle_search() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		
		$query = isset( $_POST['query'] ) ? sanitize_text_field( $_POST['query'] ) : '';
		
		if ( empty( $query ) ) {
			wp_send_json_error( 'Search query is empty.' );
		}

		$api = TVM_Tracker::get_instance()->tmdb;
		$results = $api->search( $query );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( $results->get_error_message() );
		}

		global $wpdb;
		$user_id        = get_current_user_id();
		$progress_table = $wpdb->prefix . 'tvm_user_progress';

		// Track current items to disable buttons for things already in vault
		$tracked_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.meta_value FROM $wpdb->postmeta m 
				 JOIN $progress_table p ON m.post_id = p.item_id 
				 WHERE p.user_id = %d AND m.meta_key = '_tvm_tmdb_id'",
				$user_id
			)
		);

		foreach ( $results as &$item ) {
			$item['is_tracked'] = in_array( (string) $item['id'], $tracked_ids, true );
			
			if ( ! isset( $item['media_type'] ) ) {
				$item['media_type'] = isset( $item['title'] ) ? 'movie' : 'tv';
			}
		}

		// Alphabetical Sort
		usort( $results, function( $a, $b ) {
			$titleA = $a['title'] ?? $a['name'] ?? '';
			$titleB = $b['title'] ?? $b['name'] ?? '';
			return strcasecmp( $titleA, $titleB );
		});

		wp_send_json_success( $results );
	}
}
