<?php
/**
 * AJAX Item Details Handler (Modal & TV Drill-down)
 * Version 1.1.4 - Title Encoding Fix & Source Retrieval
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Details_Handler {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_item_details', array( $this, 'get_item_details' ) );
		add_action( 'wp_ajax_tvm_get_tv_episodes', array( $this, 'handle_get_episodes' ) );
		add_action( 'wp_ajax_tvm_toggle_episode_watched', array( $this, 'toggle_episode_watched' ) );
	}

	public function get_item_details() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) { wp_send_json_error( 'Invalid ID' ); }

		$user_id = get_current_user_id();
		$all_sources = get_post_meta( $post_id, '_tvm_streaming_sources', true ) ?: array();
		$overview    = get_post_field( 'post_content', $post_id );
        
        // Requirement #2: Decode entities for titles like Rogue Nation
        $title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		
		$user_services  = get_user_meta( $user_id, 'tvm_user_services', true ) ?: array();
		$primary_region = get_user_meta( $user_id, 'tvm_primary_region', true ) ?: 'US';
		
		$master_list = get_transient( 'tvm_global_sources' ) ?: array();
		$source_map  = array();
		foreach ( $master_list as $m ) {
			$source_map[ $m['id'] ] = array( 'logo' => $m['logo_100px'], 'type' => $m['type'] );
		}

		$streaming = array();
		foreach ( $all_sources as $source ) {
			$provider_id = (int) $source['source_id'];
			$source_type = isset( $source_map[$provider_id] ) ? $source_map[$provider_id]['type'] : 'sub';
			$region      = isset( $source['region'] ) ? strtoupper( $source['region'] ) : '';

			if ( in_array( $source_type, array('sub', 'free') ) ) {
				if ( ! in_array( $provider_id, $user_services ) ) continue;
				if ( 'sub' === $source_type && $region !== $primary_region ) continue;
				$streaming[$provider_id . '_' . $region] = $source;
			}
		}

		ob_start();
		if ( ! empty( $streaming ) ) {
			foreach ( $streaming as $s ) {
				$logo = isset( $source_map[$s['source_id']] ) ? $source_map[$s['source_id']]['logo'] : '';
				$reg  = strtoupper($s['region']);
				?>
				<div style="padding:14px; background:#fff; border:1px solid #eee; border-radius:12px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 4px 6px rgba(0,0,0,0.03);">
					<div style="display:flex; align-items:center; gap:15px;">
						<div style="position:relative; width:42px; height:42px;">
							<img src="<?php echo esc_url($logo); ?>" style="width:100%; height:100%; border-radius:8px; object-fit:contain;">
							<img src="https://flagcdn.com/w80/<?php echo strtolower($reg); ?>.png" style="position:absolute; bottom:-2px; right:-6px; width:22px; height:auto; border:2px solid #fff; border-radius:3px; box-shadow:0 2px 4px rgba(0,0,0,0.3);">
						</div>
						<div>
							<strong style="color:#1d2327; font-size:15px; display:block;"><?php echo esc_html($s['name']); ?></strong>
							<span style="font-size:10px; color:#999; text-transform:uppercase; font-weight:700;"><?php echo esc_html($reg); ?> Library</span>
						</div>
					</div>
					<a href="<?php echo esc_url($s['web_url']); ?>" target="_blank" style="text-decoration:none; background:#2271b1; color:#fff; padding:8px 18px; border-radius:8px; font-size:12px; font-weight:700;">WATCH</a>
				</div>
				<?php
			}
		} else {
			echo '<p style="font-size:13px; color:#666; text-align:center; padding:30px; background:#f9f9f9; border-radius:12px;">No streaming sources found for your enabled services.</p>';
		}
		
		$sources_html = ob_get_clean();
		wp_send_json_success( array( 'title' => $title, 'overview' => $overview, 'sources' => $sources_html ) );
	}

	public function handle_get_episodes() {
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
			$data[] = array(
				'id'         => $ep_id,
				'title'      => html_entity_decode( $ep->post_title, ENT_QUOTES, 'UTF-8' ),
				'season'     => (int) get_post_meta( $ep_id, '_tvm_season', true ),
				'number'     => (int) get_post_meta( $ep_id, '_tvm_number', true ),
				'air_date'   => $air_date,
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

	public function toggle_episode_watched() {
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