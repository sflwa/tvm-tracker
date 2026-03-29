<?php
/**
 * AJAX TV Watchlist Handler
 * Version 1.0.4 - Aired Unwatched Logic Fix
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
		$user_services  = get_user_meta( $user_id, 'tvm_user_services', true ) ?: array();
		$primary_region = strtoupper( get_user_meta( $user_id, 'tvm_primary_region', true ) ?: 'US' );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();

				$ep_data = $wpdb->get_results( $wpdb->prepare( 
					"SELECT p.ID, m1.meta_value as air_date, m2.meta_value as sources 
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_air_date'
					 LEFT JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_episode_sources'
					 WHERE p.post_type = 'tvm_episode' 
					 AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tvm_parent_id' AND meta_value = %d)", 
					$id 
				) );

				$ep_count = count( $ep_data );
				$ep_watched_count = 0;
				$aired_unwatched_count = 0;
				$has_upcoming = false;
				$has_streaming = false;

				if ( $ep_count > 0 ) {
					foreach ( $ep_data as $ep ) {
						$is_future = ( $ep->air_date && $ep->air_date > $today_str );
						$is_watched = $wpdb->get_var( $wpdb->prepare(
							"SELECT id FROM $progress_table WHERE user_id = %d AND episode_id = %d AND watched_at IS NOT NULL",
							$user_id, $ep->ID
						) );

						if ( $is_watched ) {
							$ep_watched_count++;
						} elseif ( ! $is_future ) {
							// FIX: Only count episodes that have already aired
							$aired_unwatched_count++;
						}

						if ( $is_future ) $has_upcoming = true;

						if ( ! $has_streaming && ! empty( $ep->sources ) ) {
							$sources = maybe_unserialize( $ep->sources );
							if ( is_array( $sources ) ) {
								foreach ( $sources as $s ) {
									if ( in_array( $s['type'], array( 'rent', 'buy', 'purchase' ) ) ) continue;
									if ( ! in_array( (int)$s['source_id'], $user_services ) ) continue;
									if ( ( $s['type'] === 'sub' && strtoupper($s['region']) === $primary_region ) || $s['type'] === 'free' ) {
										$has_streaming = true;
										break;
									}
								}
							}
						}
					}
				}

				$last_sync = get_post_meta( $id, '_tvm_last_sync', true );
				$watchlist[] = array(
					'id'                    => $id,
					'title'                 => get_the_title(),
					'poster_path'           => get_post_meta( $id, '_tvm_poster_path', true ),
					'ep_count'              => $ep_count,
					'ep_watched'            => $ep_watched_count,
					'aired_unwatched_count' => $aired_unwatched_count,
					'has_aired_unwatched'   => ( $aired_unwatched_count > 0 ),
					'has_upcoming'          => $has_upcoming,
					'has_streaming'         => $has_streaming,
					'last_sync'             => $last_sync ? date( 'M j, g:i a', strtotime( $last_sync ) ) : 'Never'
				);
			}
			wp_reset_postdata();
		}
		wp_send_json_success( array( 'items' => $watchlist ) );
	}
}
