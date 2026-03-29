<?php
/**
 * AJAX Search Handler for TMDb
 * Version 1.0.7 - Aligned with tvm-search.js Alpha Logic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Search_Handler {

	public function __construct() {
		// Matching the action in your tvm-search.js
		add_action( 'wp_ajax_tvm_search_tmdb_alpha', array( $this, 'handle_search' ) );
	}

	public function handle_search() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		
		$query = isset( $_POST['query'] ) ? sanitize_text_field( $_POST['query'] ) : '';
		$api   = TVM_Tracker::get_instance()->tmdb;
		$results = $api->search( $query );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( $results->get_error_message() );
		}

		global $wpdb;
		$user_id        = get_current_user_id();
		$progress_table = $wpdb->prefix . 'tvm_user_progress';

		// Identify TMDb IDs already in this user's vault
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
			
			// GOLDEN RULE: Standardize the media_type for the frontend loop
			if ( ! isset( $item['media_type'] ) ) {
				$item['media_type'] = isset( $item['title'] ) ? 'movie' : 'tv';
			}
		}

		// Sort Alphabetically per Golden Rule #5
		usort( $results, function( $a, $b ) {
			$titleA = $a['title'] ?? $a['name'] ?? '';
			$titleB = $b['title'] ?? $b['name'] ?? '';
			return strcasecmp( $titleA, $titleB );
		});

		wp_send_json_success( $results );
	}
}