<?php
/**
 * Watchmode API Client
 * Version 1.3.2 - Now with Debug Logging
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TVM_API_WATCHMODE {

	private const API_URL = 'https://api.watchmode.com/v1/';

	private function remote_get( $endpoint, $args = array() ) {
		$api_key = get_option( 'tvm_watchmode_api_key' );
		if ( ! $api_key ) return new WP_Error( 'no_api_key', 'Key missing.' );

		$url = self::API_URL . $endpoint;
		$args['apiKey'] = $api_key;
		$url = add_query_arg( $args, $url );

		// --- DEBUG LOG START ---
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "TVM Watchmode Request: " . $url );
		}
		// --- DEBUG LOG END ---

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			error_log( "TVM Watchmode Error: " . $response->get_error_message() );
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// --- DEBUG LOG START ---
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && empty($data) ) {
			error_log( "TVM Watchmode Empty Response Body: " . $body );
		}
		// --- DEBUG LOG END ---

		update_option( 'tvm_api_calls_watchmode', (int)get_option( 'tvm_api_calls_watchmode', 0 ) + 1 );
		return $data;
	}

	public function get_sources( $imdb_id ) {
		$data = $this->remote_get( "title/{$imdb_id}/details/", array( 'append_to_response' => 'sources' ) );
		return $data['sources'] ?? array();
	}

	public function get_all_episodes_data( $id ) {
		$watchmode_id = $id;

		if ( strpos( $id, 'tt' ) === 0 ) {
			$search = $this->remote_get( "search/", array( 
				'search_field' => 'imdb_id', 
				'search_value' => $id 
			) );

			if ( ! is_wp_error( $search ) && ! empty( $search['title_results'] ) ) {
				$watchmode_id = $search['title_results'][0]['id'];
				if ( WP_DEBUG ) error_log( "TVM Bridged IMDb {$id} to Watchmode ID {$watchmode_id}" );
			} else {
				return new WP_Error( 'id_not_found', 'Could not map IMDb ID.' );
			}
		}

		return $this->remote_get( "title/{$watchmode_id}/episodes/" );
	}
}
