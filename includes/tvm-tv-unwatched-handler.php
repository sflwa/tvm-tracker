<?php
/**
 * Surgical AJAX Handler for TV Unwatched View
 * Version 1.2.1 - 100% Standalone Logic
 * Author: South Florida Web Advisors
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_TV_Unwatched_Handler {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_tv_unwatched_surgical', array( $this, 'get_unwatched_data' ) );
		add_action( 'wp_ajax_tvm_get_unwatched_episodes_surgical', array( $this, 'get_unwatched_episodes' ) );
	}

	public function get_unwatched_data() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$user_id = get_current_user_id();
		$table_stats = $wpdb->prefix . 'tvm_series_stats';

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID as id, p.post_title as title, m.meta_value as poster_path, s.unwatched_count, s.last_updated
			 FROM {$wpdb->posts} p
			 INNER JOIN $table_stats s ON p.ID = s.item_id
			 LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_tvm_poster_path'
			 WHERE s.user_id = %d AND s.unwatched_count > 0 AND p.post_type = 'tvm_item'
			 ORDER BY p.post_title ASC",
			$user_id
		) );

		if ( empty( $results ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$watchlist = array();
		foreach ( $results as $row ) {
			$watchlist[] = array(
				'id'                    => (int) $row->id,
				'title'                 => $row->title,
				'poster_path'           => $row->poster_path,
				'aired_unwatched_count' => (int) $row->unwatched_count,
				'last_sync'             => $row->last_updated ? date( 'M j, g:i a', strtotime( $row->last_updated ) ) : 'Never'
			);
		}
		wp_send_json_success( array( 'items' => $watchlist ) );
	}

	public function get_unwatched_episodes() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$parent_id = absint( $_POST['series_id'] );
		$user_id   = get_current_user_id();
		global $wpdb;

		$progress_table = $wpdb->prefix . 'tvm_user_progress';
		$today = current_time('Y-m-d');

		$episodes = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_content, m_s.meta_value as season, m_n.meta_value as number, m_d.meta_value as air_date
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m_p ON p.ID = m_p.post_id AND m_p.meta_key = '_tvm_parent_id'
			 JOIN {$wpdb->postmeta} m_s ON p.ID = m_s.post_id AND m_s.meta_key = '_tvm_season'
			 JOIN {$wpdb->postmeta} m_n ON p.ID = m_n.post_id AND m_n.meta_key = '_tvm_number'
			 JOIN {$wpdb->postmeta} m_d ON p.ID = m_d.post_id AND m_d.meta_key = '_tvm_air_date'
			 LEFT JOIN $progress_table prog ON p.ID = prog.episode_id AND prog.user_id = %d
			 WHERE m_p.meta_value = %d AND p.post_type = 'tvm_episode' AND m_d.meta_value <= %s
			 AND (prog.watched_at IS NULL OR prog.watched_at = '')
			 ORDER BY CAST(m_s.meta_value AS UNSIGNED) ASC, CAST(m_n.meta_value AS UNSIGNED) ASC",
			$user_id, $parent_id, $today
		) );

		$data = array();
		foreach ( $episodes as $ep ) {
			$sources_raw = get_post_meta( $ep->ID, '_tvm_episode_sources', true ) ?: array();
			$data[] = array(
				'id'         => $ep->ID,
				'title'      => html_entity_decode( $ep->post_title, ENT_QUOTES, 'UTF-8' ),
				'overview'   => $ep->post_content,
				'season'     => (int) $ep->season,
				'number'     => (int) $ep->number,
				'air_date'   => $ep->air_date ? date( 'M j, Y', strtotime( $ep->air_date ) ) : 'TBA',
				'sources_html' => $this->render_surgical_sources( $sources_raw, $user_id )
			);
		}
		wp_send_json_success( $data );
	}

	private function render_surgical_sources( $sources, $user_id ) {
		if ( empty( $sources ) ) return '<span style="font-size:10px; color:#999; text-transform:uppercase; background:#f5f5f5; padding:4px 8px; border-radius:4px; font-weight:700;">No Sources</span>';
		
		$user_services  = get_user_meta( $user_id, 'tvm_user_services', true ) ?: array();
		$primary_region = strtoupper( get_user_meta( $user_id, 'tvm_primary_region', true ) ?: 'US' );
		$master_list    = get_transient( 'tvm_global_sources' ) ?: array();
		
		$html = '';
		foreach ( $sources as $s ) {
			$sid = (int) $s['source_id'];
			$type = strtolower( $s['type'] );
			$reg = strtoupper( $s['region'] );

			if ( in_array( $type, ['rent', 'buy', 'purchase'] ) ) continue;
			if ( ! in_array( $sid, $user_services ) ) continue;
			if ( $type === 'sub' && $reg !== $primary_region ) continue;

			foreach ( $master_list as $m ) {
				if ( (int) $m['id'] === $sid ) {
					$html .= '<img src="' . esc_url( $m['logo_100px'] ) . '" title="' . esc_attr( $s['name'] ) . '" style="width:40px; height:40px; border-radius:6px; border:1px solid #eee; object-fit:contain; background:#fff;">';
					break;
				}
			}
		}
		return ! empty( $html ) ? $html : '<span style="font-size:10px; color:#999; text-transform:uppercase; background:#f5f5f5; padding:4px 8px; border-radius:4px; font-weight:700;">No Sources</span>';
	}
}
new TVM_TV_Unwatched_Handler();
