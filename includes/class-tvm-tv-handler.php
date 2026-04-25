<?php
/**
 * AJAX TV Watchlist Handler
 * Version 1.1.6 - Enhanced Data for Detail View
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
		$stats_table    = $wpdb->prefix . 'tvm_series_stats';

		$query = new WP_Query( array(
			'post_type'      => 'tvm_item',
			'post__in'       => $wpdb->get_col( $wpdb->prepare( "SELECT item_id FROM $progress_table WHERE user_id = %d AND media_type = 'tv' AND season_number = 0", $user_id ) ) ?: array(0),
			'posts_per_page' => -1,
		) );

		$watchlist = array();
		$user_services  = get_user_meta( $user_id, 'tvm_user_services', true ) ?: array();
		$primary_region = strtoupper( get_user_meta( $user_id, 'tvm_primary_region', true ) ?: 'US' );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();

				$stats = $wpdb->get_row( $wpdb->prepare(
					"SELECT watched_count, unwatched_count, upcoming_count FROM $stats_table WHERE user_id = %d AND item_id = %d",
					$user_id, $id
				) );

				$watched   = (int) ( $stats->watched_count ?? 0 );
				$unwatched = (int) ( $stats->unwatched_count ?? 0 );
				$upcoming  = (int) ( $stats->upcoming_count ?? 0 );

				// Stream Only Logic
				$has_streaming = false;
				if ( $unwatched > 0 ) {
					$ep_sources = $wpdb->get_results( $wpdb->prepare( 
						"SELECT m.meta_value FROM {$wpdb->postmeta} m 
						 JOIN {$wpdb->posts} p ON m.post_id = p.ID 
						 WHERE p.post_type = 'tvm_episode' 
						 AND m.meta_key = '_tvm_episode_sources' 
						 AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tvm_parent_id' AND meta_value = %d)
						 AND p.ID NOT IN (SELECT episode_id FROM $progress_table WHERE user_id = %d AND item_id = %d AND watched_at IS NOT NULL)",
						$id, $user_id, $id 
					) );

					foreach ( $ep_sources as $es ) {
						$sources = maybe_unserialize( $es->meta_value );
						if ( is_array( $sources ) ) {
							foreach ( $sources as $s ) {
								if ( in_array( $s['type'], array( 'rent', 'buy', 'purchase' ) ) ) continue;
								if ( ! in_array( (int)$s['source_id'], $user_services ) ) continue;
								if ( ( $s['type'] === 'sub' && strtoupper($s['region']) === $primary_region ) || $s['type'] === 'free' ) {
									$has_streaming = true;
									break 2;
								}
							}
						}
					}
				}

				$watchlist[] = array(
					'id'                    => $id,
					'title'                 => get_the_title(),
					'poster_path'           => get_post_meta( $id, '_tvm_poster_path', true ),
					'status'                => get_post_meta( $id, '_tvm_status', true ) ?: 'Unknown',
					'watched_count'         => $watched, // NEW
					'aired_unwatched_count' => $unwatched,
					'upcoming_count'        => $upcoming, // NEW
					'has_aired_unwatched'   => ( $unwatched > 0 ),
					'has_upcoming'          => ( $upcoming > 0 ),
					'has_streaming'         => $has_streaming,
					'last_sync'             => get_post_meta( $id, '_tvm_last_sync', true ) ? date( 'M j, g:i a', strtotime( get_post_meta( $id, '_tvm_last_sync', true ) ) ) : 'Never'
				);
			}
			wp_reset_postdata();
		}
		
		wp_send_json_success( array( 'items' => $watchlist, 'stats' => null ) );
	}
}
