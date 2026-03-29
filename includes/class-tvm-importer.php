<?php
/**
 * Library Importer Logic
 * Version 1.9.0 - Added Watchmode Episode & Source Sync
 *
 * @package TV_Movie_Tracker
 * @version 1.9.0
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
		$imdb_id = get_post_meta( $post_id, '_tvm_imdb_id', true );

		// 1. ENSURE USER PROGRESS LINK EXISTS
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
				$imdb_id = $details['external_ids']['imdb_id'] ?? '';
				update_post_meta( $post_id, '_tvm_tvdb_id', $tvdb_id );
				update_post_meta( $post_id, '_tvm_imdb_id', $imdb_id );
			}
		}

		// 3. SYNC EPISODES & STREAMING
		if ( 'tv' === $type ) {
			return $this->import_episodes( $post_id, $tvdb_id, $imdb_id );
		}
		return "Sync complete.";
	}

	private function import_episodes( $parent_post_id, $tvdb_id, $imdb_id ) {
		// A. Fetch Metadata from TVMaze
		$tvmaze = new TVM_API_TVMAZE();
		$lookup = $tvmaze->get_id_by_external( $tvdb_id );
		$episodes = array();

		if ( ! is_wp_error( $lookup ) && isset( $lookup['id'] ) ) {
			$episodes = $tvmaze->get_episodes( $lookup['id'] );
		}

		if ( empty( $episodes ) ) {
			return "No episode metadata found.";
		}

		// B. Fetch Streaming Sources from Watchmode (One Call for All Episodes)
		$watchmode = new TVM_API_WATCHMODE();
		$wm_data = $watchmode->get_all_episodes_data( $imdb_id );
		$streaming_map = array();

		if ( ! is_wp_error( $wm_data ) && is_array( $wm_data ) ) {
			foreach ( $wm_data as $wm_ep ) {
				$key = "S" . $wm_ep['season_number'] . "E" . $wm_ep['episode_number'];
				$streaming_map[$key] = array(
					'sources'  => $wm_ep['sources'] ?? array(),
					'overview' => $wm_ep['overview'] ?? ''
				);
			}
		}

		global $wpdb;
		$count = 0;
		foreach ( $episodes as $ep ) {
			$s = absint( $ep['season'] );
			$n = absint( $ep['number'] );
			$ep_key = "S{$s}E{$n}";

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

				// C. Attach Streaming Data if available
				if ( isset( $streaming_map[$ep_key] ) ) {
					update_post_meta( $episode_id, '_tvm_episode_sources', $streaming_map[$ep_key]['sources'] );
					// Update description if Watchmode has a better one
					if ( ! empty( $streaming_map[$ep_key]['overview'] ) ) {
						$wpdb->update( $wpdb->posts, array( 'post_content' => $streaming_map[$ep_key]['overview'] ), array( 'ID' => $episode_id ) );
					}
				}
				$count++;
			}
		}

		// Update Last Sync Timestamp
		update_post_meta( $parent_post_id, '_tvm_last_sync', current_time( 'mysql' ) );

		return "Success: Processed {$count} episodes with streaming data.";
	}
}
