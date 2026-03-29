<?php
/**
 * TVmaze API Client
 * * Used for accurate episode scheduling and air dates.
 *
 * @package TV_Movie_Tracker
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_API_TVMAZE {

	private const API_URL = 'https://api.tvmaze.com/';

	/**
	 * Log API call for stats
	 */
	private function log_api_call() {
		$current = (int) get_option( 'tvm_api_calls_tvmaze', 0 );
		update_option( 'tvm_api_calls_tvmaze', $current + 1 );
	}

	/**
	 * Remote GET wrapper
	 */
	private function remote_get( $endpoint, $args = array() ) {
		$url = self::API_URL . $endpoint;
		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->log_api_call();
		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Find TVmaze ID using external IDs (TVDB)
	 */
	public function get_id_by_external( $tvdb_id ) {
		return $this->remote_get( 'lookup/shows', array( 'thetvdb' => $tvdb_id ) );
	}

	/**
	 * Get full episode list
	 */
	public function get_episodes( $tvmaze_id ) {
		return $this->remote_get( "shows/{$tvmaze_id}/episodes", array( 'specials' => 1 ) );
	}
}
