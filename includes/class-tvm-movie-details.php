<?php
/**
 * AJAX Movie Details Handler
 * Version 1.0.3 - Fix Undefined Method Error
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Movie_Details {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_movie_details', array( $this, 'get_details' ) );
	}

	public function get_details() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) { wp_send_json_error( 'Invalid ID' ); }

		$user_id = get_current_user_id();

		// LOGIC: If watched & synced > 30 days ago, trigger on-demand sync
		$last_sync = get_post_meta( $post_id, '_tvm_last_sync', true );
		$is_watched = $this->is_watched( $user_id, $post_id );

		if ( $is_watched && ( ! $last_sync || strtotime( $last_sync ) < strtotime( '-30 days' ) ) ) {
			if ( class_exists( 'TVM_Importer' ) ) {
				$importer = new TVM_Importer();
				$imdb_id = get_post_meta( $post_id, '_tvm_imdb_id', true );
				// FIX: Call correct public method
				$importer->sync_watchmode_data( $post_id, $imdb_id );
			}
		}

		$all_sources = get_post_meta( $post_id, '_tvm_streaming_sources', true ) ?: array();
		$overview    = get_post_field( 'post_content', $post_id );
		$title       = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		
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

			if ( in_array($source_type, array('rent', 'buy', 'purchase')) ) continue;

			if ( $source_type === 'sub' ) {
				if ( ! in_array( $provider_id, $user_services ) ) continue;
				if ( $region !== strtoupper($primary_region) ) continue;
				$streaming[$provider_id . '_' . $region] = $source;
			} 
			
			if ( $source_type === 'free' ) {
				if ( ! in_array( $provider_id, $user_services ) ) continue;
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

	private function is_watched( $user_id, $post_id ) {
		global $wpdb;
		$progress_table = $wpdb->prefix . 'tvm_user_progress';
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $progress_table WHERE user_id = %d AND item_id = %d AND watched_at IS NOT NULL", $user_id, $post_id ) );
	}
}
