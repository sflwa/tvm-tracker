<?php
/**
 * TMDb API Client
 *
 * Handles all communication with The Movie Database.
 * Uses Bearer Token authentication (v4).
 *
 * @package TV_Movie_Tracker
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_API_TMDB {

	/**
	 * TMDb API Base URL
	 */
	private const API_URL = 'https://api.themoviedb.org/3/';

	/**
	 * Request stats for the dashboard
	 *
	 * @var int
	 */
	private $request_count = 0;

	/**
	 * Get the Read Access Token from settings
	 *
	 * @return string|false
	 */
	private function get_token() {
		return get_option( 'tvm_tmdb_api_key' );
	}

	/**
	 * Core request handler
	 *
	 * @param string $endpoint The API endpoint.
	 * @param array  $args     Query arguments.
	 * @return array|WP_Error
	 */
	private function remote_get( $endpoint, $args = array() ) {
		$token = $this->get_token();

		if ( ! $token ) {
			return new WP_Error( 'missing_token', __( 'TMDb Read Access Token is missing.', 'tvm-tracker' ) );
		}

		$url = self::API_URL . $endpoint;
		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'json_error', __( 'Failed to parse TMDb response.', 'tvm-tracker' ) );
		}

		// Track API call for our stats
		$this->log_api_call();

		return $data;
	}

	/**
	 * Increments the API call counter in the database
	 */
	private function log_api_call() {
		$current_calls = (int) get_option( 'tvm_api_calls_tmdb', 0 );
		update_option( 'tvm_api_calls_tmdb', $current_calls + 1 );
	}

	/**
	 * Search for Movies and TV Shows (Multi-Search)
	 *
	 * @param string $query The search term.
	 * @return array|WP_Error
	 */
	public function search( $query ) {
		$results = $this->remote_get( 'search/multi', array(
			'query' => urlencode( $query ),
		) );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		if ( empty( $results['results'] ) ) {
			return array();
		}

		// Filter results: Keep only movies and tv, discard people/actors
		return array_filter( $results['results'], function( $item ) {
			return isset( $item['media_type'] ) && in_array( $item['media_type'], array( 'movie', 'tv' ) );
		} );
	}

	/**
	 * Get detailed information for a specific item
	 *
	 * @param int    $id   TMDb ID.
	 * @param string $type 'movie' or 'tv'.
	 * @return array|WP_Error
	 */
	public function get_details( $id, $type = 'movie' ) {
		$endpoint = ( $type === 'tv' ) ? "tv/{$id}" : "movie/{$id}";
		
		// We append external_ids to get IMDb and TVDB links for bridging
		return $this->remote_get( $endpoint, array(
			'append_to_response' => 'external_ids',
		) );
	}
}
