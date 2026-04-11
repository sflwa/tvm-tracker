<?php
/**
 * AJAX Handler for Library Statistics
 * Version 1.1.0 - Added TV Status and Movie Year breakdowns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Stats_Handler {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_library_stats', array( $this, 'get_stats' ) );
	}

	public function get_stats() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$user_id = get_current_user_id();
		$progress_table = $wpdb->prefix . 'tvm_user_progress';

		// 1. Movie Stats - Detailed Year Breakdown
		$movie_years = $wpdb->get_results( $wpdb->prepare(
			"SELECT 
				YEAR(m1.meta_value) as release_year,
				COUNT(p.ID) as total,
				SUM(CASE WHEN prog.watched_at IS NOT NULL THEN 1 ELSE 0 END) as watched
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_release_date'
			 JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_media_type' AND m2.meta_value = 'movie'
			 LEFT JOIN $progress_table prog ON p.ID = prog.item_id AND prog.user_id = %d AND prog.season_number = 0
			 WHERE p.post_type = 'tvm_item'
			 GROUP BY release_year
			 ORDER BY release_year DESC",
			$user_id
		) );

		$movie_total = 0;
		$movie_watched = 0;
		foreach ( $movie_years as $year ) {
			$movie_total += (int) $year->total;
			$movie_watched += (int) $year->watched;
		}

		// 2. TV Stats - Detailed Status Breakdown
		$tv_statuses = $wpdb->get_results( $wpdb->prepare(
			"SELECT 
				m1.meta_value as status,
				COUNT(p.ID) as total
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_status'
			 JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_media_type' AND m2.meta_value = 'tv'
			 JOIN $progress_table prog ON p.ID = prog.item_id AND prog.user_id = %d AND prog.season_number = 0
			 WHERE p.post_type = 'tvm_item'
			 GROUP BY status",
			$user_id
		) );

		// General TV Counts
		$tv_episodes = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p 
			 JOIN {$wpdb->postmeta} m ON p.ID = m.post_id 
			 WHERE p.post_type = 'tvm_episode' AND m.meta_key = '_tvm_parent_id' 
			 AND m.meta_value IN (SELECT item_id FROM $progress_table WHERE user_id = %d AND media_type = 'tv' AND season_number = 0)",
			$user_id
		) );

		$tv_watched = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $progress_table WHERE user_id = %d AND media_type = 'tv' AND episode_id > 0 AND watched_at IS NOT NULL",
			$user_id
		) );

		wp_send_json_success( array(
			'movies' => array(
				'total'   => $movie_total,
				'watched' => $movie_watched,
				'percent' => $movie_total > 0 ? round( ($movie_watched / $movie_total) * 100 ) : 0,
				'years'   => $movie_years
			),
			'tv' => array(
				'series'   => array_sum( array_column( $tv_statuses, 'total' ) ),
				'episodes' => $tv_episodes,
				'watched'  => $tv_watched,
				'percent'  => $tv_episodes > 0 ? round( ($tv_watched / $tv_episodes) * 100 ) : 0,
				'statuses' => $tv_statuses
			)
		) );
	}
}
