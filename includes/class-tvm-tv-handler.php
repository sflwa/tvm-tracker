<?php
/**
 * AJAX TV Watchlist Handler
 * Version 1.0.8 - Calendar Overview Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_TV_Handler {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_tv_watchlist', array( $this, 'get_watchlist' ) );
		add_action( 'wp_ajax_tvm_get_calendar_month', array( $this, 'get_calendar_month' ) );
	}

	public function get_calendar_month() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$user_id = get_current_user_id();
		$month = sanitize_text_field( $_POST['month'] ); 
		$progress_table = $wpdb->prefix . 'tvm_user_progress';

		$tracked_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT item_id FROM $progress_table WHERE user_id = %d AND media_type = 'tv' AND season_number = 0",
			$user_id
		) );

		if ( empty( $tracked_ids ) ) {
			wp_send_json_success( array() );
		}

		$start_date = $month . '-01';
		$end_date   = date( 'Y-m-t', strtotime( $start_date ) );

		$episodes = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_content, m1.meta_value as air_date, m2.meta_value as parent_id, m3.meta_value as ep_num, m4.meta_value as season_num
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_air_date'
			 JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_parent_id'
			 JOIN {$wpdb->postmeta} m3 ON p.ID = m3.post_id AND m3.meta_key = '_tvm_number'
			 JOIN {$wpdb->postmeta} m4 ON p.ID = m4.post_id AND m4.meta_key = '_tvm_season'
			 WHERE p.post_type = 'tvm_episode'
			 AND m1.meta_value BETWEEN %s AND %s
			 AND m2.meta_value IN (" . implode( ',', array_map( 'intval', $tracked_ids ) ) . ")
			 ORDER BY m1.meta_value ASC",
			$start_date, $end_date
		) );

		$calendar_data = array();
		foreach ( $episodes as $ep ) {
			$is_watched = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $progress_table WHERE user_id = %d AND episode_id = %d AND watched_at IS NOT NULL",
				$user_id, $ep->ID
			) );

			$calendar_data[] = array(
				'id'         => $ep->ID,
				'series'     => get_the_title( $ep->parent_id ),
				'title'      => $ep->post_title,
				'overview'   => $ep->post_content,
				'air_date'   => $ep->air_date,
				'display'    => $ep->season_num . 'x' . $ep->ep_num,
				'is_watched' => (bool) $is_watched
			);
		}

		wp_send_json_success( $calendar_data );
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
			wp_send_json_success( array( 'items' => array(), 'stats' => array('series'=>0, 'episodes'=>0, 'watched'=>0, 'percent'=>0) ) );
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

        $tv_ep_total = 0;
        $tv_ep_watched = 0;

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
				$has_unwatched_streaming = false;

				if ( $ep_count > 0 ) {
                    $tv_ep_total += $ep_count;
					foreach ( $ep_data as $ep ) {
						$is_future = ( $ep->air_date && $ep->air_date > $today_str );
						$is_watched = $wpdb->get_var( $wpdb->prepare(
							"SELECT id FROM $progress_table WHERE user_id = %d AND episode_id = %d AND watched_at IS NOT NULL",
							$user_id, $ep->ID
						) );

						if ( $is_watched ) {
							$ep_watched_count++;
                            $tv_ep_watched++;
						} elseif ( ! $is_future ) {
							$aired_unwatched_count++;
						}

						if ( $is_future ) $has_upcoming = true;

						if ( ! $has_unwatched_streaming && ! $is_watched && ! $is_future && ! empty( $ep->sources ) ) {
							$sources = maybe_unserialize( $ep->sources );
							if ( is_array( $sources ) ) {
								foreach ( $sources as $s ) {
									if ( in_array( $s['type'], array( 'rent', 'buy', 'purchase' ) ) ) continue;
									if ( ! in_array( (int)$s['source_id'], $user_services ) ) continue;
									if ( ( $s['type'] === 'sub' && strtoupper($s['region']) === $primary_region ) || $s['type'] === 'free' ) {
										$has_unwatched_streaming = true;
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
					'has_streaming'         => $has_unwatched_streaming,
					'last_sync'             => $last_sync ? date( 'M j, g:i a', strtotime( $last_sync ) ) : 'Never'
				);
			}
			wp_reset_postdata();
		}
		wp_send_json_success( array( 
            'items' => $watchlist,
            'stats' => array(
                'series'   => count($watchlist),
                'episodes' => $tv_ep_total,
                'watched'  => $tv_ep_watched,
                'percent'  => ($tv_ep_total > 0) ? round(($tv_ep_watched / $tv_ep_total) * 100) : 0
            )
        ) );
	}
}
