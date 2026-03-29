<?php
/**
 * Library Importer Logic
 * Version 1.8.4 - Idempotent Progress Linking
 *
 * @package TV_Movie_Tracker
 * @version 1.8.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Importer {

	public function __construct() {
		add_action( 'wp_ajax_tvm_import_item', array( $this, 'handle_import' ) );
		add_action( 'wp_ajax_tvm_sync_series', array( $this, 'handle_sync' ) );
	}

	public function handle_import() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$tmdb_id = isset( $_POST['tmdb_id'] ) ? absint( $_POST['tmdb_id'] ) : 0;
		$type    = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : 'movie';
		global $wpdb;

		$post_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_tvm_tmdb_id' AND meta_value = %s LIMIT 1",
			$tmdb_id
		) );

		if ( ! $post_id ) {
			$api = TVM_Tracker::get_instance()->tmdb;
			$details = $api->get_details( $tmdb_id, $type );
			if ( is_wp_error( $details ) ) { wp_send_json_error( $details->get_error_message() ); }

			$post_id = wp_insert_post( array(
				'post_title'   => ( 'tv' === $type ) ? $details['name'] : $details['title'],
				'post_content' => $details['overview'] ?? '',
				'post_status'  => 'publish',
				'post_type'    => 'tvm_item',
			) );

			update_post_meta( $post_id, '_tvm_tmdb_id', $tmdb_id );
			update_post_meta( $post_id, '_tvm_media_type', $type );
			update_post_meta( $post_id, '_tvm_poster_path', $details['poster_path'] );
			
			$tvdb_id = $details['external_ids']['tvdb_id'] ?? 0;
			$imdb_id = $details['external_ids']['imdb_id'] ?? '';
			update_post_meta( $post_id, '_tvm_tvdb_id', $tvdb_id );
			update_post_meta( $post_id, '_tvm_imdb_id', $imdb_id );
		}

		$this->handle_sync_logic( $post_id );
		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	public function handle_sync() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$post_id = absint( $_POST['post_id'] );
		
		$result = $this->handle_sync_logic( $post_id );
		wp_send_json_success( $result );
	}

	private function handle_sync_logic( $post_id ) {
		global $wpdb;
		$user_id = get_current_user_id();
		$progress_table = $wpdb->prefix . 'tvm_user_progress';
		
		$type    = get_post_meta( $post_id, '_tvm_media_type', true );
		$tmdb_id = get_post_meta( $post_id, '_tvm_tmdb_id', true );
		$tvdb_id = get_post_meta( $post_id, '_tvm_tvdb_id', true );

		// 1. ENSURE USER PROGRESS LINK EXISTS (The Fix for ID 402)
		$existing_link = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $progress_table WHERE user_id = %d AND item_id = %d AND season_number = 0",
			$user_id, $post_id
		) );

		if ( ! $existing_link ) {
			$wpdb->insert( $progress_table, array(
				'user_id'    => $user_id,
				'item_id'    => $post_id,
				'media_type' => $type,
				'season_number'  => 0,
				'episode_number' => 0,
				'watched_at'     => NULL
			) );
		}

		// 2. SELF-HEAL IDs
		if ( 'tv' === $type && ( ! $tvdb_id || $tvdb_id == 0 ) ) {
			$api = TVM_Tracker::get_instance()->tmdb;
			$details = $api->get_details( $tmdb_id, 'tv' );
			if ( ! is_wp_error( $details ) ) {
				$tvdb_id = $details['external_ids']['tvdb_id'] ?? 0;
				update_post_meta( $post_id, '_tvm_tvdb_id', $tvdb_id );
				update_post_meta( $post_id, '_tvm_imdb_id', $details['external_ids']['imdb_id'] ?? '' );
			}
		}

		// 3. SYNC EPISODES
		if ( 'tv' === $type && $tvdb_id ) {
			return $this->import_episodes( $post_id, $tvdb_id );
		}
		return "Sync complete.";
	}

	private function import_episodes( $parent_post_id, $tvdb_id ) {
		$tvmaze = new TVM_API_TVMAZE();
		$lookup = $tvmaze->get_id_by_external( $tvdb_id );
		
		if ( is_wp_error( $lookup ) || ! isset( $lookup['id'] ) ) {
			return "No TVMaze match found.";
		}

		$episodes = $tvmaze->get_episodes( $lookup['id'] );
		if ( ! is_array( $episodes ) || empty( $episodes ) ) {
			return "No episodes found.";
		}

		global $wpdb;
		foreach ( $episodes as $ep ) {
			$s = absint( $ep['season'] );
			$n = absint( $ep['number'] );

			$episode_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM $wpdb->posts p 
				 JOIN $wpdb->postmeta m1 ON p.ID = m1.post_id 
				 JOIN $wpdb->postmeta m2 ON p.ID = m2.post_id 
				 JOIN $wpdb->postmeta m3 ON p.ID = m3.post_id 
				 WHERE p.post_type = 'tvm_episode' 
				 AND m1.meta_key = '_tvm_parent_id' AND m1.meta_value = %d 
				 AND m2.meta_key = '_tvm_season' AND m2.meta_value = %d 
				 AND m3.meta_key = '_tvm_number' AND m3.meta_value = %d LIMIT 1",
				$parent_post_id, $s, $n
			));

			if ( ! $episode_id ) {
				$episode_id = wp_insert_post( array(
					'post_title'   => sprintf( 'S%02dE%02d - %s', $s, $n, $ep['name'] ),
					'post_content' => $ep['summary'] ?? '',
					'post_status'  => 'publish',
					'post_type'    => 'tvm_episode',
				) );
			}

			if ( $episode_id ) {
				update_post_meta( $episode_id, '_tvm_parent_id', $parent_post_id );
				update_post_meta( $episode_id, '_tvm_season', $s );
				update_post_meta( $episode_id, '_tvm_number', $n );
				update_post_meta( $episode_id, '_tvm_air_date', $ep['airdate'] );
			}
		}
		return "Success: Processed " . count($episodes) . " episodes.";
	}
}