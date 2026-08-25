<?php
/**
 * AJAX TV Watchlist Handler
 * Version 1.1.8 - Direct Streaming Verification Support in Watchlist Response
 * Author: South Florida Web Advisors
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_TV_Handler {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_tv_watchlist', array( $this, 'get_watchlist' ) );
		add_action( 'wp_ajax_tvm_get_calendar_month', array( $this, 'get_calendar_month' ) );
		// Action retained for on-demand streaming verification
		add_action( 'wp_ajax_tvm_verify_streaming', array( $this, 'verify_streaming_availability' ) );
	}

	/**
	 * Optimized Initial Load
	 * Uses a single SQL JOIN to retrieve all necessary data in one pass and calculates streaming availability.
	 */
	public function get_watchlist() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$user_id     = get_current_user_id();
		$stats_table = $wpdb->prefix . 'tvm_series_stats';

		// Single pass query joining posts and pre-calculated stats summary
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT 
				p.ID as id, 
				p.post_title as title, 
				pm1.meta_value as poster_path,
				pm2.meta_value as status,
				pm3.meta_value as last_sync,
				s.watched_count, 
				s.unwatched_count, 
				s.upcoming_count 
			 FROM {$wpdb->posts} p
			 INNER JOIN $stats_table s ON p.ID = s.item_id
			 LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_tvm_poster_path'
			 LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_tvm_status'
			 LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_tvm_last_sync'
			 WHERE s.user_id = %d 
			 AND p.post_type = 'tvm_item'
			 ORDER BY p.post_title ASC",
			$user_id
		) );

		$watchlist = array();
		if ( ! empty( $results ) ) {
			$series_ids = wp_list_pluck( $results, 'id' );
			$streaming_map = $this->batch_check_streaming_availability( $series_ids, $user_id );

			foreach ( $results as $row ) {
				$unwatched = (int) $row->unwatched_count;
				$upcoming  = (int) $row->upcoming_count;
				$item_id   = (int) $row->id;

				$watchlist[] = array(
					'id'                    => $item_id,
					'title'                 => $row->title,
					'poster_path'           => $row->poster_path,
					'status'                => $row->status ?: 'Unknown',
					'watched_count'         => (int) $row->watched_count,
					'aired_unwatched_count' => $unwatched,
					'upcoming_count'        => $upcoming,
					'has_aired_unwatched'   => ( $unwatched > 0 ),
					'has_upcoming'          => ( $upcoming > 0 ),
					'has_streaming'         => ! empty( $streaming_map[ $item_id ] ),
					'last_sync'             => $row->last_sync ? date( 'M j, g:i a', strtotime( $row->last_sync ) ) : 'Never'
				);
			}
		}
		
		wp_send_json_success( array( 'items' => $watchlist, 'stats' => null ) );
	}

	/**
	 * Helper function to batch verify streaming for a collection of series IDs
	 *
	 * @param array $series_ids Array of TV series post IDs.
	 * @param int   $user_id    User ID.
	 * @return array Keyed by series_id => bool.
	 */
	private function batch_check_streaming_availability( $series_ids, $user_id ) {
		if ( empty( $series_ids ) ) {
			return array();
		}

		global $wpdb;
		$user_services  = get_user_meta( $user_id, 'tvm_user_services', true ) ?: array();
		$primary_region = strtoupper( get_user_meta( $user_id, 'tvm_primary_region', true ) ?: 'US' );
		$progress_table = $wpdb->prefix . 'tvm_user_progress';

		if ( empty( $user_services ) ) {
			return array();
		}

		$ids_in_clause = implode( ',', array_map( 'absint', $series_ids ) );

		// Query unwatched episode sources for all requested series in one query
		$ep_sources = $wpdb->get_results( $wpdb->prepare( 
			"SELECT pm_parent.meta_value as series_id, m.meta_value as sources_raw
			 FROM {$wpdb->postmeta} m 
			 JOIN {$wpdb->posts} p ON m.post_id = p.ID 
			 JOIN {$wpdb->postmeta} pm_parent ON p.ID = pm_parent.post_id AND pm_parent.meta_key = '_tvm_parent_id'
			 WHERE p.post_type = 'tvm_episode' 
			 AND m.meta_key = '_tvm_episode_sources' 
			 AND pm_parent.meta_value IN ($ids_in_clause)
			 AND p.ID NOT IN (
				 SELECT episode_id 
				 FROM $progress_table 
				 WHERE user_id = %d 
				 AND watched_at IS NOT NULL 
				 AND episode_id > 0
			 )",
			$user_id 
		) );

		$streaming_status = array();

		foreach ( $ep_sources as $es ) {
			$sid = (int) $es->series_id;
			if ( ! empty( $streaming_status[ $sid ] ) ) {
				continue; // Streaming already confirmed for this show
			}

			$sources = maybe_unserialize( $es->sources_raw );
			if ( is_array( $sources ) ) {
				foreach ( $sources as $s ) {
					$type   = strtolower( $s['type'] ?? '' );
					$region = strtoupper( $s['region'] ?? '' );
					$src_id = (int) ( $s['source_id'] ?? 0 );

					if ( in_array( $type, array( 'rent', 'buy', 'purchase' ), true ) ) {
						continue;
					}
					if ( ! in_array( $src_id, $user_services, true ) ) {
						continue;
					}
					if ( ( $type === 'sub' && $region === $primary_region ) || $type === 'free' ) {
						$streaming_status[ $sid ] = true;
						break;
					}
				}
			}
		}

		return $streaming_status;
	}

	/**
	 * On-Demand Streaming Verification
	 * Performs deep metadata scans for a specific list of Series IDs.
	 */
	public function verify_streaming_availability() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		
		$series_ids = isset( $_POST['series_ids'] ) ? array_map( 'absint', $_POST['series_ids'] ) : array();
		if ( empty( $series_ids ) ) {
			wp_send_json_error( 'No series IDs provided' );
		}

		$user_id          = get_current_user_id();
		$streaming_status = $this->batch_check_streaming_availability( $series_ids, $user_id );

		wp_send_json_success( $streaming_status );
	}

	public function get_calendar_month() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$user_id        = get_current_user_id();
		$month          = sanitize_text_field( $_POST['month'] ); 
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
}
