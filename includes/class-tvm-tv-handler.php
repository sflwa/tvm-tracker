<?php
/**
 * AJAX TV Watchlist Handler
 * Version 1.0.3 - Stream Only Filter Logic
 *
 * @package TV_Movie_Tracker
 * @version 1.0.3
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
		
		// Get user streaming preferences for Rule Check
		$user_services  = get_user_meta( $user_id, 'tvm_user_services', true ) ?: array();
		$primary_region = strtoupper( get_user_meta( $user_id, 'tvm_primary_region', true ) ?: 'US' );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();

				// Get all episodes and their metadata
				$ep_data = $wpdb->get_results( $wpdb->prepare( 
					"SELECT p.ID, m1.meta_value as air_date, m2.meta_value as sources 
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_air_date'
					 LEFT JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_episode_sources'
					 WHERE p.post_type = 'tvm_episode' 
					 AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tvm_parent_id' AND meta_value = %d)", 
					$id 
				) );

				$ep_ids = wp_list_pluck( $ep_data, 'ID' );
				$ep_count = count( $ep_ids );
				$ep_watched_count = 0;
				$has_upcoming = false;
				$has_aired_unwatched = false;
				$has_streaming = false;

				if ( $ep_count > 0 ) {
					// Count watched episodes
					$ep_watched_count = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM $progress_table 
						 WHERE user_id = %d AND item_id = %d 
						 AND episode_id IN (" . implode( ',', array_map( 'intval', $ep_ids ) ) . ") 
						 AND watched_at IS NOT NULL",
						$user_id, $id
					) );

					foreach ( $ep_data as $ep ) {
						$is_future = ( $ep->air_date && $ep->air_date > $today_str );
						
						// Watch status check
						$is_watched = $wpdb->get_var( $wpdb->prepare(
							"SELECT id FROM $progress_table WHERE user_id = %d AND episode_id = %d AND watched_at IS NOT NULL",
							$user_id, $ep->ID
						) );

						if ( $is_future ) {
							$has_upcoming = true;
						} elseif ( ! $is_watched ) {
							$has_aired_unwatched = true;
						}

						// Rule-based Streaming Check (Only if not already found)
						if ( ! $has_streaming && ! empty( $ep->sources ) ) {
							$sources = maybe_unserialize( $ep->sources );
							if ( is_array( $sources ) ) {
								foreach ( $sources as $s ) {
									$sid   = (int) $s['source_id'];
									$type  = $s['type'];
									$reg   = strtoupper( $s['region'] );

									if ( in_array( $type, array( 'rent', 'buy', 'purchase' ) ) ) continue;
									if ( ! in_array( $sid, $user_services ) ) continue;

									if ( ( $type === 'sub' && $reg === $primary_region ) || $type === 'free' ) {
										$has_streaming = true;
										break;
									}
								}
							}
						}
					}
				}

				$last_sync = get_post_meta( $id, '_tvm_last_sync', true );
				$formatted_sync = $last_sync ? date( 'M j, g:i a', strtotime( $last_sync ) ) : 'Never';

				$watchlist[] = array(
					'id'                  => $id,
					'title'               => get_the_title(),
					'poster_path'         => get_post_meta( $id, '_tvm_poster_path', true ),
					'ep_count'            => $ep_count,
					'ep_watched'          => $ep_watched_count,
					'has_aired_unwatched' => $has_aired_unwatched,
					'has_upcoming'        => $has_upcoming,
					'has_streaming'       => $has_streaming, // FOR THE STREAM ONLY TOGGLE
					'last_sync'           => $formatted_sync
				);
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array( 'items' => $watchlist ) );
	}
}
