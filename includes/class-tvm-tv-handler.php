<?php
/**
 * AJAX TV Watchlist Handler
 * Version 1.0.2 - Refined Filter Logic & Human Dates
 *
 * @package TV_Movie_Tracker
 * @version 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_TV_Handler {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_tv_watchlist', array( $this, 'get_watchlist' ) );
	}

	public function get_watchlist() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$user_id = get_current_user_id();
		$progress_table = $wpdb->prefix . 'tvm_user_progress';

		$user_shows = $wpdb->get_results(
			$wpdb->prepare( 
                "SELECT item_id FROM $progress_table 
                 WHERE user_id = %d AND media_type = 'tv' AND season_number = 0", 
                $user_id 
            ),
			OBJECT_K
		);

		if ( empty( $user_shows ) ) {
			wp_send_json_success( array( 'items' => array(), 'stats' => array() ) );
		}

		$query = new WP_Query( array(
			'post_type'      => 'tvm_item',
			'post__in'       => array_keys( $user_shows ),
			'posts_per_page' => -1,
		) );

		$watchlist = array();
		$today_str = current_time( 'Y-m-d' );
		$total_eps = 0;
		$watched_eps = 0;

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();

				// 1. Get all episode IDs and their air dates
				$ep_data = $wpdb->get_results( $wpdb->prepare( 
					"SELECT post_id, meta_value as air_date 
					 FROM $wpdb->postmeta 
					 WHERE meta_key = '_tvm_air_date' 
					 AND post_id IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_tvm_parent_id' AND meta_value = %d)", 
					$id 
				) );

				$ep_ids = wp_list_pluck( $ep_data, 'post_id' );
				$ep_count = count( $ep_ids );
				$ep_watched_count = 0;
				$has_upcoming = false;
				$has_aired_unwatched = false;

				if ( $ep_count > 0 ) {
					// 2. Count watched episodes
					$ep_watched_count = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM $progress_table 
						 WHERE user_id = %d AND item_id = %d 
						 AND episode_id IN (" . implode( ',', array_map( 'intval', $ep_ids ) ) . ") 
						 AND watched_at IS NOT NULL",
						$user_id, $id
					) );

					// 3. Identify Filter States
					foreach ( $ep_data as $ep ) {
						$is_future = ( $ep->air_date && $ep->air_date > $today_str );
						if ( $is_future ) {
							$has_upcoming = true;
						} else {
							// It has aired - check if user has NOT watched it
							$is_watched = $wpdb->get_var( $wpdb->prepare(
								"SELECT id FROM $progress_table WHERE user_id = %d AND episode_id = %d AND watched_at IS NOT NULL",
								$user_id, $ep->post_id
							) );
							if ( ! $is_watched ) {
								$has_aired_unwatched = true;
							}
						}
					}
				}

				$last_sync = get_post_meta( $id, '_tvm_last_sync', true );
				$formatted_sync = $last_sync ? date( 'M j, g:i a', strtotime( $last_sync ) ) : 'Never';

				$watchlist[] = array(
					'id'                  => $id,
					'title'               => get_the_title(),
					'type'                => 'tv',
					'poster_path'         => get_post_meta( $id, '_tvm_poster_path', true ),
					'tmdb_id'             => get_post_meta( $id, '_tvm_tmdb_id', true ),
					'ep_count'            => $ep_count,
					'ep_watched'          => $ep_watched_count,
					'is_watched_any'      => ( $ep_watched_count > 0 ), // For "Watched" filter
					'has_aired_unwatched' => $has_aired_unwatched,     // For "Unwatched" filter
					'has_upcoming'        => $has_upcoming,            // For "Upcoming" filter
					'status'              => $has_upcoming ? 'upcoming' : 'released',
					'last_sync'           => $formatted_sync
				);

				$total_eps += $ep_count;
				$watched_eps += $ep_watched_count;
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array(
			'items' => $watchlist,
			'stats' => array(
				'series'   => count( $watchlist ),
				'episodes' => $total_eps,
				'watched'  => $watched_eps,
				'percent'  => ( $total_eps > 0 ) ? round( ( $watched_eps / $total_eps ) * 100 ) : 0
			)
		) );
	}
}
