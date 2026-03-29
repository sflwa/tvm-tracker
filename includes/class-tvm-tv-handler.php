<?php
/**
 * AJAX TV Watchlist Handler
 * Version 1.0.1 - Added Sync Metadata
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
		$today     = current_time( 'Y-m-d' );
		$total_eps = 0;
		$watched_eps = 0;

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();

				// Aggregation Engine
				$ep_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_tvm_parent_id' AND meta_value = %d", $id ) );
				$ep_count = count( $ep_ids );
				$ep_watched = 0;
				$has_upcoming = false;

				if ( $ep_count > 0 ) {
					$ep_watched = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM $progress_table WHERE user_id = %d AND item_id = %d AND episode_id IN (" . implode( ',', array_map( 'intval', $ep_ids ) ) . ") AND watched_at IS NOT NULL",
						$user_id, $id
					) );

					$upcoming_check = $wpdb->get_var( $wpdb->prepare(
						"SELECT post_id FROM $wpdb->postmeta WHERE post_id IN (" . implode( ',', array_map( 'intval', $ep_ids ) ) . ") AND meta_key = '_tvm_air_date' AND meta_value > %s LIMIT 1",
						$today
					) );
					$has_upcoming = ! empty( $upcoming_check );
				}

				// Retrieve Last Sync Metadata
				$last_sync = get_post_meta( $id, '_tvm_last_sync', true );
				$formatted_sync = $last_sync ? date( 'M j, g:i a', strtotime( $last_sync ) ) : 'Never';

				$watchlist[] = array(
					'id'           => $id,
					'title'        => get_the_title(),
					'type'         => 'tv',
					'poster_path'  => get_post_meta( $id, '_tvm_poster_path', true ),
					'tmdb_id'      => get_post_meta( $id, '_tvm_tmdb_id', true ),
					'ep_count'     => $ep_count,
					'ep_watched'   => $ep_watched,
					'is_watched'   => ( $ep_count > 0 && $ep_watched >= $ep_count ),
					'status'       => $has_upcoming ? 'upcoming' : 'released',
					'has_upcoming' => $has_upcoming,
					'last_sync'    => $formatted_sync
				);

				$total_eps += $ep_count;
				$watched_eps += $ep_watched;
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
