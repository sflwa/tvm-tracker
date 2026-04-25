<?php
/**
 * Surgical AJAX Handler for TV Unwatched View
 * Version 1.0.2 - Optimized Single-Pass SQL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_TV_Unwatched_Handler {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_tv_unwatched_surgical', array( $this, 'get_unwatched_data' ) );
	}

	public function get_unwatched_data() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		
		global $wpdb;
		$user_id = get_current_user_id();
		$table_stats = $wpdb->prefix . 'tvm_series_stats';

		/**
		 * STEP 1 & 2: Optimized Single-Pass Query
		 * We find series with unwatched episodes AND get their counts 
		 * by joining the stats table directly.
		 */
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT 
                p.ID as id, 
                p.post_title as title, 
                m.meta_value as poster_path, 
                s.unwatched_count, 
                s.last_updated
			 FROM {$wpdb->posts} p
			 INNER JOIN $table_stats s ON p.ID = s.item_id
			 LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_tvm_poster_path'
			 WHERE s.user_id = %d 
			 AND s.unwatched_count > 0
			 AND p.post_type = 'tvm_item'
			 ORDER BY p.post_title ASC",
			$user_id
		) );

		if ( empty( $results ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$watchlist = array();
		foreach ( $results as $row ) {
			$watchlist[] = array(
				'id'                    => (int) $row->id,
				'title'                 => $row->title,
				'poster_path'           => $row->poster_path,
				'aired_unwatched_count' => (int) $row->unwatched_count,
				'last_sync'             => $row->last_updated ? date( 'M j, g:i a', strtotime( $row->last_updated ) ) : 'Never'
			);
		}

		wp_send_json_success( array( 'items' => $watchlist ) );
	}
}

new TVM_TV_Unwatched_Handler();
