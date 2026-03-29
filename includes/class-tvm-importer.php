<?php
/**
 * Library Importer & Automation Logic
 * Version 2.0.7 - Smart Upsert (Prevents Duplication)
 *
 * @package TV_Movie_Tracker
 * @version 2.0.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Importer {

	public function __construct() {
		// AJAX Hooks
		add_action( 'wp_ajax_tvm_import_item', array( $this, 'handle_import' ) );
		add_action( 'wp_ajax_tvm_sync_series', array( $this, 'handle_manual_sync' ) );
		add_action( 'wp_ajax_tvm_delete_item', array( $this, 'handle_delete' ) );
		
		// Automation Hooks (WP-Cron)
		add_action( 'tvm_weekly_sync_event', array( $this, 'run_weekly_sync' ) );
		add_action( 'tvm_monthly_sync_event', array( $this, 'run_monthly_sync' ) );
		
		if ( ! wp_next_scheduled( 'tvm_weekly_sync_event' ) ) {
			wp_schedule_event( time(), 'weekly', 'tvm_weekly_sync_event' );
		}
		if ( ! wp_next_scheduled( 'tvm_monthly_sync_event' ) ) {
			wp_schedule_event( time(), 'monthly', 'tvm_monthly_sync_event' );
		}
	}

	public function handle_delete() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) { wp_send_json_error( 'Invalid ID' ); }

		global $wpdb;
		$user_id = get_current_user_id();
		$table = $wpdb->prefix . 'tvm_user_progress';
		$wpdb->delete( $table, array( 'user_id' => $user_id, 'item_id' => $post_id ) );
		
		wp_send_json_success( 'Item removed from your vault.' );
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
			update_post_meta( $post_id, '_tvm_tvdb_id', $details['external_ids']['tvdb_id'] ?? 0 );
			update_post_meta( $post_id, '_tvm_imdb_id', $details['external_ids']['imdb_id'] ?? '' );
		}

		$this->ensure_user_progress( $post_id, $type );
		$this->sync_tvmaze_metadata( $post_id, get_post_meta( $post_id, '_tvm_tvdb_id', true ) );
		$this->sync_watchmode_data( $post_id );

		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	public function handle_manual_sync() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$post_id = absint( $_POST['post_id'] );
		$this->sync_tvmaze_metadata( $post_id, get_post_meta( $post_id, '_tvm_tvdb_id', true ) );
		$this->sync_watchmode_data( $post_id );
		wp_send_json_success( "Manual Sync Complete." );
	}

	public function run_weekly_sync() {
		$shows = get_posts( array( 'post_type' => 'tvm_item', 'posts_per_page' => -1, 'meta_key' => '_tvm_media_type', 'meta_value' => 'tv' ) );
		foreach ( $shows as $show ) {
			$this->sync_tvmaze_metadata( $show->ID, get_post_meta( $show->ID, '_tvm_tvdb_id', true ) );
			if ( $this->needs_watchmode_sync( $show->ID ) ) {
				$this->sync_watchmode_data( $show->ID );
			}
		}
	}

	public function run_monthly_sync() {
		$items = get_posts( array( 'post_type' => 'tvm_item', 'posts_per_page' => -1 ) );
		foreach ( $items as $item ) {
			$type = get_post_meta( $item->ID, '_tvm_media_type', true );
			if ( ! $this->is_item_fully_watched( $item->ID, $type ) ) {
				$this->sync_watchmode_data( $item->ID );
			}
		}
	}

	public function is_item_fully_watched( $post_id, $type ) {
		global $wpdb;
		$table = $wpdb->prefix . 'tvm_user_progress';
		if ( 'movie' === $type ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE item_id = %d AND watched_at IS NOT NULL", $post_id ) );
		} else {
			$unwatched = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_parent_id' JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_air_date' LEFT JOIN $table prog ON p.ID = prog.episode_id WHERE m1.meta_value = %d AND m2.meta_value <= %s AND (prog.watched_at IS NULL OR prog.watched_at = '')", $post_id, current_time('Y-m-d') ) );
			return ( $unwatched == 0 );
		}
	}

	private function needs_watchmode_sync( $post_id ) {
		global $wpdb;
		$has_sources = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tvm_episode_sources' AND post_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tvm_parent_id' AND meta_value = %d) AND meta_value != '' AND meta_value != 'a:0:{}'", $post_id ) );
		return ( $has_sources == 0 && ! $this->is_item_fully_watched( $post_id, 'tv' ) );
	}

	public function sync_tvmaze_metadata( $post_id, $tvdb_id ) {
		if ( ! $tvdb_id ) return;
		$tvmaze = new TVM_API_TVMAZE();
		$lookup = $tvmaze->get_id_by_external( $tvdb_id );
		if ( ! is_wp_error( $lookup ) && isset( $lookup['id'] ) ) {
			$episodes = $tvmaze->get_episodes( $lookup['id'] );
			foreach ( $episodes as $ep ) { $this->upsert_episode( $post_id, $ep ); }
		}
	}

	public function sync_watchmode_data( $post_id ) {
		$imdb_id = get_post_meta( $post_id, '_tvm_imdb_id', true );
		if ( ! $imdb_id ) return;
		$type = get_post_meta( $post_id, '_tvm_media_type', true );
		$watchmode = new TVM_API_WATCHMODE();
		if ( 'tv' === $type ) {
			$wm_data = $watchmode->get_all_episodes_data( $imdb_id );
			if ( ! is_wp_error( $wm_data ) ) {
				foreach ( $wm_data as $wm_ep ) { $this->update_ep_sources( $post_id, $wm_ep ); }
			}
		} else {
			$sources = $watchmode->get_sources( $imdb_id );
			update_post_meta( $post_id, '_tvm_streaming_sources', $sources );
		}
		update_post_meta( $post_id, '_tvm_last_sync', current_time( 'mysql' ) );
	}

	/**
	 * SMART UPSERT: Efficiently finds existing episode by parent/season/number 
	 * to prevent duplication while updating metadata.
	 */
	private function upsert_episode( $parent_id, $ep ) {
		global $wpdb;
		$s = absint($ep['season']); 
		$n = absint($ep['number']);

		// Optimized lookup: Find episode ID where all 3 keys match in one go
		$episode_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_parent_id'
			 INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_season'
			 INNER JOIN {$wpdb->postmeta} m3 ON p.ID = m3.post_id AND m3.meta_key = '_tvm_number'
			 WHERE p.post_type = 'tvm_episode' 
			 AND m1.meta_value = %d 
			 AND m2.meta_value = %d 
			 AND m3.meta_value = %d 
			 LIMIT 1",
			$parent_id, $s, $n
		) );

		$title = sprintf('S%02dE%02d - %s', $s, $n, $ep['name']);

		if ( ! $episode_id ) {
			$episode_id = wp_insert_post( array( 
				'post_title'   => $title, 
				'post_content' => $ep['summary'] ?? '', 
				'post_status'  => 'publish', 
				'post_type'    => 'tvm_episode' 
			) );
		} else {
			// Update existing to ensure title/summary are fresh
			wp_update_post( array(
				'ID'           => $episode_id,
				'post_title'   => $title,
				'post_content' => $ep['summary'] ?? ''
			) );
		}

		update_post_meta( $episode_id, '_tvm_parent_id', $parent_id );
		update_post_meta( $episode_id, '_tvm_season', $s );
		update_post_meta( $episode_id, '_tvm_number', $n );
		update_post_meta( $episode_id, '_tvm_air_date', $ep['airdate'] );
	}

	/**
	 * Updates sources using the same smart lookup logic
	 */
	private function update_ep_sources( $parent_id, $wm_ep ) {
		global $wpdb;
		$s = absint($wm_ep['season_number']); 
		$n = absint($wm_ep['episode_number']);

		$episode_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_parent_id'
			 INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_season'
			 INNER JOIN {$wpdb->postmeta} m3 ON p.ID = m3.post_id AND m3.meta_key = '_tvm_number'
			 WHERE p.post_type = 'tvm_episode' 
			 AND m1.meta_value = %d 
			 AND m2.meta_value = %d 
			 AND m3.meta_value = %d 
			 LIMIT 1",
			$parent_id, $s, $n
		) );

		if ( $episode_id ) { 
			update_post_meta( $episode_id, '_tvm_episode_sources', $wm_ep['sources'] ?? array() ); 
		}
	}

	private function ensure_user_progress( $post_id, $type ) {
		global $wpdb;
		$table = $wpdb->prefix . 'tvm_user_progress';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id = %d AND item_id = %d AND season_number = 0", get_current_user_id(), $post_id ) );
		if ( ! $exists ) {
			$wpdb->insert( $table, array( 'user_id' => get_current_user_id(), 'item_id' => $post_id, 'media_type' => $type, 'season_number' => 0, 'episode_number' => 0 ) );
		}
	}
}
