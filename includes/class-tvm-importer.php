<?php
/**
 * Library Importer & Automation Logic
 * Version 2.1.2 - Added Weekly Movie Metadata Refresh
 *
 * @package TV_Movie_Tracker
 * @version 2.1.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Importer {

	public function __construct() {
		// AJAX Hooks
		add_action( 'wp_ajax_tvm_import_item', array( $this, 'handle_import' ) );
		add_action( 'wp_ajax_tvm_sync_series', array( $this, 'handle_manual_sync' ) );
		add_action( 'wp_ajax_tvm_sync_series_status', array( $this, 'handle_status_only_sync' ) );
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

	/**
	 * Recalculates stats for a specific series and updates the flat table.
	 * This is the core performance driver for large libraries.
	 */
	public function recalculate_series_stats( $post_id, $user_id = null ) {
		global $wpdb;
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		
		if ( ! $user_id || ! $post_id ) return;

		$today_str = current_time( 'Y-m-d' );
		$table_progress = $wpdb->prefix . 'tvm_user_progress';
		$table_stats    = $wpdb->prefix . 'tvm_series_stats';

		// 1. Count Total Aired Episodes
		$aired_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p 
			 JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_parent_id'
			 JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_air_date'
			 WHERE p.post_type = 'tvm_episode' AND m1.meta_value = %d AND m2.meta_value <= %s",
			$post_id, $today_str
		) );

		// 2. Count Watched Episodes
		$watched_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table_progress 
			 WHERE user_id = %d AND item_id = %d AND episode_id > 0 AND watched_at IS NOT NULL",
			$user_id, $post_id
		) );

		// 3. Count Upcoming (Future) Episodes
		$upcoming_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p 
			 JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_parent_id'
			 JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_air_date'
			 WHERE p.post_type = 'tvm_episode' AND m1.meta_value = %d AND m2.meta_value > %s",
			$post_id, $today_str
		) );

		$unwatched_count = max( 0, $aired_count - $watched_count );

		// 4. Upsert into Stats Table (Flat Summary)
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO $table_stats (user_id, item_id, watched_count, unwatched_count, upcoming_count, last_updated)
			 VALUES (%d, %d, %d, %d, %d, %s)
			 ON DUPLICATE KEY UPDATE 
			 watched_count = VALUES(watched_count), 
			 unwatched_count = VALUES(unwatched_count), 
			 upcoming_count = VALUES(upcoming_count), 
			 last_updated = VALUES(last_updated)",
			$user_id, $post_id, $watched_count, $unwatched_count, $upcoming_count, current_time('mysql')
		) );
	}

	public function handle_delete() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) { wp_send_json_error( 'Invalid ID' ); }

		global $wpdb;
		$user_id = get_current_user_id();
		
		$wpdb->delete( $wpdb->prefix . 'tvm_user_progress', array( 'user_id' => $user_id, 'item_id' => $post_id ) );
		$wpdb->delete( $wpdb->prefix . 'tvm_series_stats', array( 'user_id' => $user_id, 'item_id' => $post_id ) );
		
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

			$release_date = ( 'tv' === $type ) ? ($details['first_air_date'] ?? '') : ($details['release_date'] ?? '');
			update_post_meta( $post_id, '_tvm_release_date', $release_date );
			update_post_meta( $post_id, '_tvm_status', $details['status'] ?? 'Released' );
		}

		$this->ensure_user_progress( $post_id, $type );
		
		if ( 'tv' === $type ) {
			$this->sync_tvmaze_metadata( $post_id, get_post_meta( $post_id, '_tvm_tvdb_id', true ) );
			$this->recalculate_series_stats( $post_id ); 
		}
		
		$this->sync_watchmode_data( $post_id );

		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	/**
	 * Manual sync handler
	 * Supports both TV (TVmaze) and Movies (TMDb Refresh)
	 */
	public function handle_manual_sync() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$post_id = absint( $_POST['post_id'] );
		$type    = get_post_meta( $post_id, '_tvm_media_type', true );

		if ( 'movie' === $type ) {
			$this->refresh_movie_metadata( $post_id );
		} else {
			$this->sync_tvmaze_metadata( $post_id, get_post_meta( $post_id, '_tvm_tvdb_id', true ) );
		}

		$this->sync_watchmode_data( $post_id );
		
		if ( 'tv' === $type ) {
			$this->recalculate_series_stats( $post_id ); 
		}

		wp_send_json_success( "Manual Sync Complete." );
	}

	/**
	 * WEEKLY SYNC LOGIC (v2.1.2)
	 * Refreshes TV episode schedules and Movie release dates
	 */
	public function run_weekly_sync() {
		$items = get_posts( array( 
			'post_type'      => 'tvm_item', 
			'posts_per_page' => -1 
		) );

		$all_users = get_users( array( 'role__in' => array( 'administrator', 'editor', 'author', 'subscriber' ) ) );

		foreach ( $items as $item ) {
			$type   = get_post_meta( $item->ID, '_tvm_media_type', true );
			$status = strtolower( get_post_meta( $item->ID, '_tvm_status', true ) );

			if ( 'tv' === $type ) {
				$is_active = ! in_array( $status, array( 'ended', 'canceled' ) );
				if ( $is_active || ! $this->is_item_fully_watched( $item->ID, 'tv' ) ) {
					$this->sync_tvmaze_metadata( $item->ID, get_post_meta( $item->ID, '_tvm_tvdb_id', true ) );
					$this->sync_watchmode_data( $item->ID );

					foreach ( $all_users as $user ) {
						$this->recalculate_series_stats( $item->ID, $user->ID );
					}
				}
			} else {
				// MOVIE LOGIC: Check if movie is unwatched and either "Upcoming" or "TBA"
				$is_watched = $this->is_item_fully_watched( $item->ID, 'movie' );
				$release_date = get_post_meta( $item->ID, '_tvm_release_date', true );
				$is_upcoming = ( ! $release_date || strtotime( $release_date ) > time() );

				if ( ! $is_watched && $is_upcoming ) {
					$this->refresh_movie_metadata( $item->ID );
					$this->sync_watchmode_data( $item->ID );
				}
			}
		}
	}

	/**
	 * Refreshes basic Movie metadata from TMDb (Release Date, Status, Poster)
	 */
	private function refresh_movie_metadata( $post_id ) {
		$tmdb_id = get_post_meta( $post_id, '_tvm_tmdb_id', true );
		if ( ! $tmdb_id ) return;

		$api = TVM_Tracker::get_instance()->tmdb;
		$details = $api->get_details( $tmdb_id, 'movie' );

		if ( ! is_wp_error( $details ) ) {
			update_post_meta( $post_id, '_tvm_release_date', $details['release_date'] ?? '' );
			update_post_meta( $post_id, '_tvm_status', $details['status'] ?? 'Released' );
			update_post_meta( $post_id, '_tvm_poster_path', $details['poster_path'] ?? '' );
		}
	}

	public function run_monthly_sync() {
		$items = get_posts( array( 'post_type' => 'tvm_item', 'posts_per_page' => -1 ) );
		$all_users = get_users( array( 'role__in' => array( 'administrator', 'editor', 'author', 'subscriber' ) ) );

		foreach ( $items as $item ) {
			$type = get_post_meta( $item->ID, '_tvm_media_type', true );
			if ( ! $this->is_item_fully_watched( $item->ID, $type ) ) {
				$this->sync_watchmode_data( $item->ID );
			}
			if ( 'tv' === $type ) {
				foreach ( $all_users as $user ) {
					$this->recalculate_series_stats( $item->ID, $user->ID );
				}
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

	public function sync_tvmaze_metadata( $post_id, $tvdb_id ) {
		if ( ! $tvdb_id ) return;
		$tvmaze = new TVM_API_TVMAZE();
		$lookup = $tvmaze->get_id_by_external( $tvdb_id );
		if ( ! is_wp_error( $lookup ) && isset( $lookup['id'] ) ) {
			
			$episodes = $tvmaze->get_episodes( $lookup['id'] );

			$max_s = 0; $max_e = 0;
			foreach($episodes as $e) {
				if($e['season'] > $max_s) { $max_s = $e['season']; $max_e = $e['number']; }
				elseif($e['season'] == $max_s && $e['number'] > $max_e) { $max_e = $e['number']; }
			}

			$season_map = [];
			foreach($episodes as $e) {
				$s = $e['season'];
				if(!isset($season_map[$s])) $season_map[$s] = 0;
				if($e['number'] > $season_map[$s]) $season_map[$s] = $e['number'];
			}

			foreach ( $episodes as $ep ) { 
				$is_series_final = ($ep['season'] == $max_s && $ep['number'] == $max_e);
				$is_season_final = ($ep['number'] == $season_map[$ep['season']]);
				$this->upsert_episode( $post_id, $ep, $is_season_final, $is_series_final ); 
			}
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

	private function upsert_episode( $parent_id, $ep, $is_season_final = false, $is_series_final = false ) {
		global $wpdb;
		$s = absint($ep['season']); 
		$n = absint($ep['number']);

		$episode_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_parent_id'
			 INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_season'
			 INNER JOIN {$wpdb->postmeta} m3 ON p.ID = m3.post_id AND m3.meta_key = '_tvm_number'
			 WHERE p.post_type = 'tvm_episode' 
			 AND m1.meta_value = %d AND m2.meta_value = %d AND m3.meta_value = %d LIMIT 1",
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
			wp_update_post( array( 'ID' => $episode_id, 'post_title' => $title, 'post_content' => $ep['summary'] ?? '' ) );
		}

		update_post_meta( $episode_id, '_tvm_parent_id', $parent_id );
		update_post_meta( $episode_id, '_tvm_season', $s );
		update_post_meta( $episode_id, '_tvm_number', $n );
		update_post_meta( $episode_id, '_tvm_air_date', $ep['airdate'] );
		update_post_meta( $episode_id, '_tvm_is_season_premiere', ($n === 1) ? 'yes' : 'no' );
		update_post_meta( $episode_id, '_tvm_is_season_finale', ($is_season_final) ? 'yes' : 'no' );
		
		$show_status = strtolower(get_post_meta($parent_id, '_tvm_status', true));
		$is_ended = in_array($show_status, ['ended', 'canceled']);
		update_post_meta( $episode_id, '_tvm_is_series_finale', ($is_ended && $is_series_final) ? 'yes' : 'no' );
	}

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
			 AND m1.meta_value = %d AND m2.meta_value = %d AND m3.meta_value = %d LIMIT 1",
			$parent_id, $s, $n
		) );

		if ( $episode_id ) { 
			update_post_meta( $episode_id, '_tvm_episode_sources', $wm_ep['sources'] ?? array() ); 
		}
	}

	private function ensure_user_progress( $post_id, $type ) {
		global $wpdb;
		$user_id = get_current_user_id();
		$table = $wpdb->prefix . 'tvm_user_progress';
		
		$exists = $wpdb->get_var( $wpdb->prepare( 
			"SELECT id FROM $table WHERE user_id = %d AND item_id = %d AND season_number = 0", 
			$user_id, 
			$post_id 
		) );

		if ( ! $exists ) {
			$wpdb->insert( $table, array( 
				'user_id'        => $user_id, 
				'item_id'        => $post_id, 
				'media_type'     => $type, 
				'season_number'  => 0, 
				'episode_number' => 0,
				'watched_at'     => null
			) );
		}
	}

/**
	 * AJAX handler to manually update only the parent series status.
	 */
	public function handle_status_only_sync() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) { 
			wp_send_json_error( 'Invalid Post ID.' ); 
		}

		$tvdb_id = get_post_meta( $post_id, '_tvm_tvdb_id', true );
		if ( ! $tvdb_id ) {
			wp_send_json_error( 'TVDB ID missing for status lookup.' );
		}

		// Run isolated updater
		$updated_status = $this->sync_tvmaze_status_only( $post_id, $tvdb_id );

		if ( is_wp_error( $updated_status ) ) {
			wp_send_json_error( $updated_status->get_error_message() );
		}

		wp_send_json_success( "Series status updated successfully to: " . $updated_status );
	}

	/**
	 * Independent lookups targeting ONLY the main show data object.
	 * Bypasses episode queries and insertions completely.
	 */
	public function sync_tvmaze_status_only( $post_id, $tvdb_id ) {
		if ( ! $tvdb_id ) {
			return new WP_Error( 'missing_id', 'Missing tracking ID' );
		}

		$tvmaze = new TVM_API_TVMAZE();
		$lookup = $tvmaze->get_id_by_external( $tvdb_id );

		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		// Grab the core show metadata block from TVmaze without fetching any episode embeds
		if ( isset( $lookup['id'] ) ) {
			// Check if your TVmaze class has an explicit fallback to read basic profiles, 
			// otherwise we read the primary object returned right from the external lookup
			$status = $lookup['status'] ?? '';

			if ( empty( $status ) && method_exists( $tvmaze, 'get_show_by_id' ) ) {
				$show_profile = $tvmaze->get_show_by_id( $lookup['id'] );
				$status = $show_profile['status'] ?? '';
			}

			if ( ! empty( $status ) ) {
				update_post_meta( $post_id, '_tvm_status', $status );
				update_post_meta( $post_id, '_tvm_last_sync', current_time( 'mysql' ) );
				return $status;
			}
		}

		return new WP_Error( 'sync_failed', 'Could not locate status property inside the API payload.' );
	}



	
}
