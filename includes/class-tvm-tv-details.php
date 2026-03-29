<?php
/**
 * AJAX TV Details Handler
 * Version 1.1.1 - Added Episode Sources & Overview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_TV_Details {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_tv_episodes', array( $this, 'get_episodes' ) );
		add_action( 'wp_ajax_tvm_toggle_episode_watched', array( $this, 'toggle_watched' ) );
	}

	public function get_episodes() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$parent_id = absint( $_POST['post_id'] );
		$user_id   = get_current_user_id();
		global $wpdb;

		$episodes = get_posts( array(
			'post_type'      => 'tvm_episode',
			'posts_per_page' => -1,
			'meta_query'     => array( array( 'key' => '_tvm_parent_id', 'value' => $parent_id, 'compare' => '=' ) ),
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_tvm_season',
			'order'          => 'ASC',
		) );

		$progress_table = $wpdb->prefix . 'tvm_user_progress';
		$user_progress = $wpdb->get_results( $wpdb->prepare( "SELECT episode_id, watched_at FROM $progress_table WHERE user_id = %d AND item_id = %d AND episode_id > 0", $user_id, $parent_id ), OBJECT_K );

		$data = array();
		$today = current_time('Y-m-d');
		foreach ( $episodes as $ep ) {
			$ep_id = $ep->ID;
			$air_date = get_post_meta( $ep_id, '_tvm_air_date', true );
			
			// FETCH THE SYNCED DATA
			$sources = get_post_meta( $ep_id, '_tvm_episode_sources', true ) ?: array();

			$data[] = array(
				'id'         => $ep_id,
				'title'      => html_entity_decode( $ep->post_title, ENT_QUOTES, 'UTF-8' ),
				'overview'   => $ep->post_content, 
				'season'     => (int) get_post_meta( $ep_id, '_tvm_season', true ),
				'number'     => (int) get_post_meta( $ep_id, '_tvm_number', true ),
				'air_date'   => $air_date,
				'sources'    => $sources, // Passed to JS for Rule filtering
				'is_future'  => ( $air_date && $air_date > $today ),
				'is_watched' => isset( $user_progress[$ep_id] ) && ! empty( $user_progress[$ep_id]->watched_at )
			);
		}

		usort($data, function($a, $b) {
			if ($a['season'] === $b['season']) return $a['number'] - $b['number'];
			return $a['season'] - $b['season'];
		});

		wp_send_json_success( $data );
	}

	public function toggle_watched() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$ep_id   = absint( $_POST['episode_id'] );
		$watched = ( $_POST['watched'] === 'true' );
		$user_id = get_current_user_id();
		$item_id = get_post_meta( $ep_id, '_tvm_parent_id', true );
		$progress_table = $wpdb->prefix . 'tvm_user_progress';

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $progress_table WHERE user_id = %d AND episode_id = %d", $user_id, $ep_id ) );

		if ( $existing ) {
			$wpdb->update( $progress_table, array( 'watched_at' => $watched ? current_time( 'mysql' ) : null ), array( 'id' => $existing ) );
		} else {
			$wpdb->insert( $progress_table, array(
				'user_id'    => $user_id,
				'item_id'    => $item_id,
				'episode_id' => $ep_id,
				'media_type' => 'tv',
				'season_number'  => (int) get_post_meta( $ep_id, '_tvm_season', true ),
				'episode_number' => (int) get_post_meta( $ep_id, '_tvm_number', true ),
				'watched_at' => $watched ? current_time( 'mysql' ) : null
			));
		}
		wp_send_json_success();
	}
}
