<?php
/**
 * AJAX Movie Watchlist Handler
 * Version 1.0.4 - Strict TBA/Released Separation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Movie_Handler {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_movie_watchlist', array( $this, 'get_watchlist' ) );
        add_action( 'wp_ajax_tvm_toggle_watched', array( $this, 'toggle_watched' ) );
        add_action( 'wp_ajax_tvm_untrack_item', array( $this, 'untrack_item' ) );
	}

	public function get_watchlist() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		global $wpdb;
		$user_id = get_current_user_id();
		$progress_table = $wpdb->prefix . 'tvm_user_progress';

		$user_movies = $wpdb->get_results(
			$wpdb->prepare( 
                "SELECT item_id, watched_at FROM $progress_table 
                 WHERE user_id = %d AND media_type = 'movie' AND season_number = 0", 
                $user_id 
            ),
			OBJECT_K
		);

		if ( empty( $user_movies ) ) {
			wp_send_json_success( array( 'items' => array(), 'stats' => array() ) );
		}

		$query = new WP_Query( array(
			'post_type'      => 'tvm_item',
			'post__in'       => array_keys( $user_movies ),
			'posts_per_page' => -1,
		) );

		$watchlist = array();
		$today     = new DateTime( current_time( 'Y-m-d' ) );
		
        $user_services  = get_user_meta( $user_id, 'tvm_user_services', true ) ?: array();
        $primary_region = strtoupper( get_user_meta( $user_id, 'tvm_primary_region', true ) ?: 'US' );
        $master_list    = get_transient( 'tvm_global_sources' ) ?: array();
        $source_map     = array();
        foreach ( $master_list as $m ) {
            $source_map[ $m['id'] ] = $m['type'];
        }

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();
				$release_date = get_post_meta( $id, '_tvm_release_date', true );
				$is_watched   = ! empty( $user_movies[$id]->watched_at );
                $raw_sources  = get_post_meta( $id, '_tvm_streaming_sources', true ) ?: array();

				$item = array(
					'id'            => $id,
					'title'         => get_the_title(),
					'type'          => 'movie',
					'poster_path'   => get_post_meta( $id, '_tvm_poster_path', true ),
					'tmdb_id'       => get_post_meta( $id, '_tvm_tmdb_id', true ),
					'is_watched'    => $is_watched,
					'status'        => 'released', // Default
					'days_to_go'    => null,
                    'has_streaming' => false
				);

                // Determine Status strictly
				if ( ! $release_date ) {
                    $item['status'] = 'upcoming';
                    $item['days_to_go'] = 'TBA';
                } else {
					$rd = new DateTime( $release_date );
					if ( $rd > $today ) {
						$item['status'] = 'upcoming';
						$item['days_to_go'] = $today->diff( $rd )->days;
					}
				}

                // Streaming Check
                foreach ( $raw_sources as $s ) {
                    $sid   = (int) $s['source_id'];
                    $stype = $source_map[$sid] ?? $s['type'];
                    $sreg  = strtoupper($s['region']);

                    if ( in_array($stype, array('rent', 'buy', 'purchase')) ) continue;
                    if ( ! in_array( $sid, $user_services ) ) continue;

                    if ( ($stype === 'sub' && $sreg === $primary_region) || $stype === 'free' ) {
                        $item['has_streaming'] = true;
                        break;
                    }
                }

				$watchlist[] = $item;
			}
			wp_reset_postdata();
		}

        // Calculate stats for REAL available (Released) movies only
        $count_released = 0; 
        $count_watched = 0;
        foreach($watchlist as $w) {
            if($w['status'] === 'released') {
                $count_released++;
                if($w['is_watched']) $count_watched++;
            }
        }

		wp_send_json_success( array(
			'items' => $watchlist,
			'stats' => array(
				'total'     => count( $watchlist ),
				'available' => $count_released,
				'watched'   => $count_watched,
				'percent'   => ( $count_released > 0 ) ? round( ( $count_watched / $count_released ) * 100 ) : 0
			)
		) );
	}

    public function toggle_watched() {
        check_ajax_referer( 'tvm_import_nonce', 'nonce' );
        global $wpdb;
        $user_id = get_current_user_id();
        $tmdb_id = sanitize_text_field( $_POST['tmdb_id'] );
        $watched = ( $_POST['watched'] === 'true' );
        $table   = $wpdb->prefix . 'tvm_user_progress';

        $post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tvm_tmdb_id' AND meta_value = %s LIMIT 1", $tmdb_id ) );

        if ( $watched ) {
            $wpdb->update( $table, array( 'watched_at' => current_time( 'mysql' ) ), array( 'user_id' => $user_id, 'item_id' => $post_id, 'media_type' => 'movie' ) );
        } else {
            $wpdb->update( $table, array( 'watched_at' => null ), array( 'user_id' => $user_id, 'item_id' => $post_id, 'media_type' => 'movie' ) );
        }
        wp_send_json_success();
    }

    public function untrack_item() {
        check_ajax_referer( 'tvm_import_nonce', 'nonce' );
        global $wpdb;
        $user_id = get_current_user_id();
        $tmdb_id = sanitize_text_field( $_POST['tmdb_id'] );
        $table   = $wpdb->prefix . 'tvm_user_progress';

        $post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tvm_tmdb_id' AND meta_value = %s LIMIT 1", $tmdb_id ) );

        $wpdb->delete( $table, array( 'user_id' => $user_id, 'item_id' => $post_id ) );
        wp_send_json_success();
    }
}