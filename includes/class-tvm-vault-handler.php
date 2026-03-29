<?php
/**
 * AJAX Vault Management (Movies & TV Shows)
 * Version 1.2.0 - Delegated to Importer for Modularity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Vault_Handler {

	public function __construct() {
		// Ensure the importer is available
		if ( ! class_exists( 'TVM_Importer' ) ) {
			require_once TVM_PATH . 'includes/class-tvm-importer.php';
		}

		add_action( 'wp_ajax_tvm_import_item', array( $this, 'import_item' ) );
		add_action( 'wp_ajax_tvm_untrack_item', array( $this, 'untrack_item' ) );
		add_action( 'wp_ajax_tvm_toggle_watched', array( $this, 'toggle_watched' ) );
	}

	public function import_item() {
		$importer = new TVM_Importer();
		$importer->handle_import();
	}

	public function untrack_item() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$tmdb_id = absint( $_POST['tmdb_id'] );
		
		$post_id = $wpdb->get_var( $wpdb->prepare( 
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_tvm_tmdb_id' AND meta_value = %d", 
			$tmdb_id 
		) );

		if ( $post_id ) {
			$user_id = get_current_user_id();
			$progress_table = $wpdb->prefix . 'tvm_user_progress';
			
			// Remove ALL progress records for this item (Main record + all episodes)
			$wpdb->delete( $progress_table, array( 
				'user_id' => $user_id, 
				'item_id' => $post_id 
			), array( '%d', '%d' ) );
			
			wp_send_json_success();
		}
		wp_send_json_error( 'Item not found in vault.' );
	}

	public function toggle_watched() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$tmdb_id = absint( $_POST['tmdb_id'] );
		$is_watched = ( $_POST['watched'] === 'true' );
		
		$post_id = $wpdb->get_var( $wpdb->prepare( 
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_tvm_tmdb_id' AND meta_value = %d", 
			$tmdb_id 
		) );

		if ( $post_id ) {
			$wpdb->update( 
				$wpdb->prefix . 'tvm_user_progress', 
				array( 'watched_at' => $is_watched ? current_time( 'mysql' ) : null ),
				array( 
					'user_id' => get_current_user_id(), 
					'item_id' => $post_id, 
					'season_number' => 0 
				),
				array( '%s' ),
				array( '%d', '%d', '%d' )
			);
			wp_send_json_success();
		}
		wp_send_json_error( 'Failed to update watch status.' );
	}
}