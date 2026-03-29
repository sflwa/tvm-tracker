<?php
/**
 * AJAX Watchlist & Stats Handler
 * Version 1.1.0 - Restored Movie Upcoming Logic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Watchlist_Handler {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_watchlist', array( $this, 'get_watchlist_data' ) );
	}

	public function get_watchlist_data() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$user_id = get_current_user_id();
		$progress_table = $wpdb->prefix . 'tvm_user_progress';

		$user_items = $wpdb->get_results(
			$wpdb->prepare( "SELECT item_id, watched_at FROM $progress_table WHERE user_id = %d AND season_number = 0", $user_id ),
			OBJECT_K
		);

		if ( empty( $user_items ) ) {
			wp_send_json_success( array('items' => array(), 'stats' => array('movie' => array(), 'tv' => array())) );
		}

		$query = new WP_Query( array(
			'post_type'      => 'tvm_item',
			'post__in'       => array_keys( $user_items ),
			'posts_per_page' => -1,
		) );

		$watchlist = array();
		$today     = new DateTime(current_time('Y-m-d'));
		
		$movie_total = 0;
		$movie_released = 0;
		$movie_watched = 0;
		$tv_series_count = 0;
		$tv_ep_total = 0;
		$tv_ep_watched = 0;

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id   = get_the_ID();
				$type = get_post_meta( $id, '_tvm_media_type', true ) ?: 'movie';
				$release_date = get_post_meta( $id, '_tvm_release_date', true );
				
				$item_data = array(
					'id'            => $id,
					'title'         => get_the_title(),
					'type'          => $type,
					'poster_path'   => get_post_meta( $id, '_tvm_poster_path', true ),
					'tmdb_id'       => get_post_meta( $id, '_tvm_tmdb_id', true ),
					'status'        => 'released',
					'days_to_go'    => null
				);

				if ( $release_date ) {
					$rd = new DateTime($release_date);
					if ( $rd > $today ) {
						$item_data['status'] = 'upcoming';
						$item_data['days_to_go'] = $today->diff($rd)->days;
					}
				}

				if ( 'movie' === $type ) {
					$movie_total++;
					$item_data['is_watched'] = ! empty( $user_items[$id]->watched_at );
					if ( $item_data['status'] === 'released' ) {
						$movie_released++;
						if ( $item_data['is_watched'] ) $movie_watched++;
					}
				} else {
					$tv_series_count++;
					$ep_posts = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_tvm_parent_id' AND meta_value = %d", $id ) );
					$item_data['ep_count'] = count( $ep_posts );
					
					if ( ! empty( $ep_posts ) ) {
						$w_count = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM $progress_table WHERE user_id = %d AND item_id = %d AND episode_id IN (" . implode(',', array_map('intval', $ep_posts)) . ") AND watched_at IS NOT NULL",
							$user_id, $id
						) );
						$item_data['ep_watched'] = $w_count;
						$item_data['is_watched'] = ( $w_count >= $item_data['ep_count'] && $item_data['ep_count'] > 0 );
						
						$tv_ep_total += $item_data['ep_count'];
						$tv_ep_watched += $w_count;

						$has_future = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE post_id IN (" . implode(',', array_map('intval', $ep_posts)) . ") AND meta_key = '_tvm_air_date' AND meta_value > %s LIMIT 1", current_time('Y-m-d') ) );
						$item_data['has_upcoming'] = ! empty( $has_future );
						if ( $item_data['has_upcoming'] ) $item_data['status'] = 'upcoming';
					}
				}
				$watchlist[] = $item_data;
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array( 
			'items' => $watchlist, 
			'stats' => array( 
				'movie' => array(
					'total' => $movie_total, 'available' => $movie_released, 'watched' => $movie_watched,
					'percent' => ($movie_released > 0) ? round(($movie_watched / $movie_released) * 100) : 0
				),
				'tv' => array(
					'series' => $tv_series_count, 'episodes' => $tv_ep_total, 'watched' => $tv_ep_watched,
					'percent' => ($tv_ep_total > 0) ? round(($tv_ep_watched / $tv_ep_total) * 100) : 0
				)
			) 
		) );
	}
}