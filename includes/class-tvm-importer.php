<?php
/**
 * Library Importer & Automation Logic
 * Version 2.0.0 - WP-Cron Automation & Logic Lock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Importer {

	public function __construct() {
		add_action( 'wp_ajax_tvm_sync_series', array( $this, 'handle_manual_sync' ) );
		
		// CRON Hooks
		add_action( 'tvm_weekly_sync_event', array( $this, 'run_weekly_sync' ) );
		add_action( 'tvm_monthly_sync_event', array( $this, 'run_monthly_sync' ) );
		
		if ( ! wp_next_scheduled( 'tvm_weekly_sync_event' ) ) {
			wp_schedule_event( time(), 'weekly', 'tvm_weekly_sync_event' );
		}
		if ( ! wp_next_scheduled( 'tvm_monthly_sync_event' ) ) {
			wp_schedule_event( time(), 'monthly', 'tvm_monthly_sync_event' );
		}
	}

	/**
	 * TV WEEKLY LOGIC:
	 * 1) Metadata from TVMaze for all shows
	 * 2) Watchmode sources for series with unwatched eps AND no sources
	 */
	public function run_weekly_sync() {
		global $wpdb;
		$shows = get_posts( array( 'post_type' => 'tvm_item', 'posts_per_page' => -1, 'meta_key' => '_tvm_media_type', 'meta_value' => 'tv' ) );
		
		foreach ( $shows as $show ) {
			$tvdb_id = get_post_meta( $show->ID, '_tvm_tvdb_id', true );
			$imdb_id = get_post_meta( $show->ID, '_tvm_imdb_id', true );
			
			// 1. Always Sync TVMaze Metadata (No Limit)
			$this->sync_tvmaze_metadata( $show->ID, $tvdb_id );

			// 2. Conditional Watchmode Sync (Only if Unwatched & Missing Sources)
			if ( $this->needs_sources_sync( $show->ID ) ) {
				$this->sync_watchmode_data( $show->ID, $imdb_id );
			}
		}
	}

	/**
	 * TV/MOVIE MONTHLY LOGIC:
	 * 1) TV: Watchmode for any series with unwatched episodes
	 * 2) Movie: Watchmode for unwatched movies
	 */
	public function run_monthly_sync() {
		$items = get_posts( array( 'post_type' => 'tvm_item', 'posts_per_page' => -1 ) );
		
		foreach ( $items as $item ) {
			$type      = get_post_meta( $item->ID, '_tvm_media_type', true );
			$imdb_id   = get_post_meta( $item->ID, '_tvm_imdb_id', true );
			$is_watched = $this->is_item_fully_watched( $item->ID, $type );

			if ( ! $is_watched ) {
				$this->sync_watchmode_data( $item->ID, $imdb_id );
			}
		}
	}

	/**
	 * Logic Helper: Check if item is unwatched
	 */
	private function is_item_fully_watched( $post_id, $type ) {
		global $wpdb;
		$table = $wpdb->prefix . 'tvm_user_progress';
		if ( 'movie' === $type ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE item_id = %d AND watched_at IS NOT NULL", $post_id ) );
		} else {
			$aired_unwatched = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p 
				 JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_parent_id'
				 JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_air_date'
				 LEFT JOIN $table prog ON p.ID = prog.episode_id
				 WHERE m1.meta_value = %d AND m2.meta_value <= %s AND prog.watched_at IS NULL",
				$post_id, current_time( 'Y-m-d' )
			) );
			return ( $aired_unwatched == 0 );
		}
	}

	/**
	 * Logic Helper: TV Needs sources if unwatched and no sources exist in metadata
	 */
	private function needs_sources_sync( $post_id ) {
		global $wpdb;
		$has_sources = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} 
			 WHERE meta_key = '_tvm_episode_sources' 
			 AND post_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tvm_parent_id' AND meta_value = %d)
			 AND meta_value != '' AND meta_value != 'a:0:{}'", $post_id
		) );
		return ( $has_sources == 0 && ! $this->is_item_fully_watched( $post_id, 'tv' ) );
	}

	public function handle_manual_sync() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$post_id = absint( $_POST['post_id'] );
		$imdb_id = get_post_meta( $post_id, '_tvm_imdb_id', true );
		$tvdb_id = get_post_meta( $post_id, '_tvm_tvdb_id', true );
		
		$this->sync_tvmaze_metadata( $post_id, $tvdb_id );
		$this->sync_watchmode_data( $post_id, $imdb_id );
		
		wp_send_json_success( "Manual Sync Complete." );
	}

	private function sync_tvmaze_metadata( $post_id, $tvdb_id ) {
		$tvmaze = new TVM_API_TVMAZE();
		$lookup = $tvmaze->get_id_by_external( $tvdb_id );
		if ( ! is_wp_error( $lookup ) && isset( $lookup['id'] ) ) {
			$episodes = $tvmaze->get_episodes( $lookup['id'] );
			foreach ( $episodes as $ep ) {
				$this->upsert_episode_post( $post_id, $ep );
			}
		}
	}

	private function sync_watchmode_data( $post_id, $imdb_id ) {
		if ( ! $imdb_id ) return;
		$type = get_post_meta( $post_id, '_tvm_media_type', true );
		$watchmode = new TVM_API_WATCHMODE();

		if ( 'tv' === $type ) {
			$wm_data = $watchmode->get_all_episodes_data( $imdb_id );
			if ( ! is_wp_error( $wm_data ) ) {
				foreach ( $wm_data as $wm_ep ) {
					$this->update_episode_sources( $post_id, $wm_ep );
				}
			}
		} else {
			$sources = $watchmode->get_sources( $imdb_id );
			update_post_meta( $post_id, '_tvm_streaming_sources', $sources );
		}
		update_post_meta( $post_id, '_tvm_last_sync', current_time( 'mysql' ) );
	}

	private function upsert_episode_post( $parent_id, $ep ) {
		global $wpdb;
		$s = absint( $ep['season'] ); $n = absint( $ep['number'] );
		$episode_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} m1 JOIN {$wpdb->postmeta} m2 ON m1.post_id = m2.post_id WHERE m1.meta_key = '_tvm_parent_id' AND m1.meta_value = %d AND m2.meta_key = '_tvm_season' AND m2.meta_value = %d AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m3 WHERE m3.post_id = m1.post_id AND m3.meta_key = '_tvm_number' AND m3.meta_value = %d)", $parent_id, $s, $n ) );

		if ( ! $episode_id ) {
			$episode_id = wp_insert_post( array( 'post_title' => sprintf( 'S%02dE%02d - %s', $s, $n, $ep['name'] ), 'post_content' => $ep['summary'] ?? '', 'post_status' => 'publish', 'post_type' => 'tvm_episode' ) );
		}
		update_post_meta( $episode_id, '_tvm_parent_id', $parent_id );
		update_post_meta( $episode_id, '_tvm_season', $s );
		update_post_meta( $episode_id, '_tvm_number', $n );
		update_post_meta( $episode_id, '_tvm_air_date', $ep['airdate'] );
	}

	private function update_episode_sources( $parent_id, $wm_ep ) {
		global $wpdb;
		$s = absint( $wm_ep['season_number'] ); $n = absint( $wm_ep['episode_number'] );
		$episode_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} m1 JOIN {$wpdb->postmeta} m2 ON m1.post_id = m2.post_id WHERE m1.meta_key = '_tvm_parent_id' AND m1.meta_value = %d AND m2.meta_key = '_tvm_season' AND m2.meta_value = %d AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m3 WHERE m3.post_id = m1.post_id AND m3.meta_key = '_tvm_number' AND m3.meta_value = %d)", $parent_id, $s, $n ) );
		if ( $episode_id ) {
			update_post_meta( $episode_id, '_tvm_episode_sources', $wm_ep['sources'] ?? array() );
		}
	}
}
