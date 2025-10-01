<?php
/**
 * TVM Tracker - Watchmode API Client
 * Handles all communication and caching for the Watchmode API.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 1.0.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tvm_Tracker_API class.
 */
class Tvm_Tracker_API {

    const VERSION = '1.0.6';
    const CACHE_DURATION = 43200; // 12 hours in seconds
    const API_URL = 'https://api.watchmode.com/v1/';

    /**
     * @var array Stores all API URLs called during a single request for debugging.
     */
    private static $api_urls_called = array();

    /**
     * Constructor.
     */
    public function __construct() {
        // Initialization can happen here if needed, currently handled by main plugin class.
    }

    /**
     * Retrieves the API key from WordPress options.
     *
     * @return string The API key or an empty string.
     */
    private static function tvm_tracker_get_api_key() {
        return get_option( 'tvm_tracker_api_key', '' );
    }

    /**
     * Gets the list of API URLs called for debugging purposes.
     *
     * @return array
     */
    public static function tvm_tracker_get_api_urls_called() {
        return self::$api_urls_called;
    }

    /**
     * Executes a request to the Watchmode API with built-in error handling and logging.
     *
     * @param string $endpoint The API endpoint (e.g., 'sources/', 'search/').
     * @param array  $params Additional query parameters.
     * @param bool   $is_cached Whether to use caching for this request.
     * @return array|WP_Error The API response data array or a WP_Error object on failure.
     */
    private function tvm_tracker_api_request( $endpoint, $params = array(), $is_cached = false ) {
        $api_key = self::tvm_tracker_get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'tvm_tracker_no_key', esc_html__( 'Watchmode API Key is missing. Please configure it in the plugin settings.', 'tvm-tracker' ) );
        }

        $cache_key = 'tvm_tracker_' . md5( $endpoint . serialize( $params ) );

        if ( $is_cached ) {
            $cached_data = get_transient( $cache_key );
            if ( false !== $cached_data ) {
                return $cached_data;
            }
        }

        $query_args = array_merge( $params, array( 'apiKey' => $api_key ) );
        $url = self::API_URL . $endpoint . '?' . http_build_query( $query_args );

        // Log URL for debug purposes
        self::$api_urls_called[] = esc_url_raw( $url );

        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( is_null( $data ) && json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'tvm_tracker_json_error', esc_html__( 'Failed to decode API response JSON.', 'tvm-tracker' ) );
        }

        // Check for API-specific errors (Watchmode often returns error messages within the JSON body)
        if ( isset( $data['error'] ) ) {
            /* translators: 1: Error Code 2: Error Message */
            $error_message = sprintf( esc_html__( 'API Error: %1$s - %2$s', 'tvm-tracker' ), $data['code'], $data['message'] );
            return new WP_Error( 'tvm_tracker_api_error', $error_message );
        }

        if ( $is_cached ) {
            set_transient( $cache_key, $data, self::CACHE_DURATION );
        }

        return $data;
    }

    /**
     * Fetches all streaming sources from the Watchmode API.
     *
     * @return array|WP_Error
     */
    public function tvm_tracker_get_all_sources() {
        return $this->tvm_tracker_api_request( 'sources/', array(), true );
    }

    /**
     * Searches for titles based on a search term.
     *
     * @param string $search_value The term to search for.
     * @return array|WP_Error
     */
    public function tvm_tracker_search( $search_value ) {
        $endpoint = 'search/';
        $params = array(
            'search_field' => 'name',
            'search_value' => $search_value,
        );
        // Do not cache search results
        $results = $this->tvm_tracker_api_request( $endpoint, $params, false );

        if ( is_wp_error( $results ) || empty( $results['title_results'] ) ) {
            return array();
        }

        // Only return title results for the frontend shortcode
        return $results['title_results'];
    }

    /**
     * Gets detailed information for a specific title ID.
     *
     * @param int $title_id The Watchmode title ID.
     * @return array|WP_Error
     */
    public function tvm_tracker_get_title_details( $title_id ) {
        $endpoint = "title/{$title_id}/details/";
        return $this->tvm_tracker_api_request( $endpoint, array(), true );
    }

    /**
     * Gets the season information for a specific title ID.
     *
     * @param int $title_id The Watchmode title ID.
     * @return array|WP_Error
     */
    public function tvm_tracker_get_seasons( $title_id ) {
        $endpoint = "title/{$title_id}/seasons/";
        // The API response is the array of seasons directly, so we rely on the request method's return.
        return $this->tvm_tracker_api_request( $endpoint, array(), true );
    }

    /**
     * Gets the episode information for a specific title ID.
     *
     * @param int $title_id The Watchmode title ID.
     * @return array|WP_Error
     */
    public function tvm_tracker_get_episodes( $title_id ) {
        $endpoint = "title/{$title_id}/episodes/";
        $response = $this->tvm_tracker_api_request( $endpoint, array(), true );

        // The API returns the episodes array directly as the top level response
        if ( is_wp_error( $response ) || ! is_array( $response ) ) {
            return array();
        }

        return $response;
    }

    /**
     * Gets streaming source data for a specific title ID.
     *
     * @param int $title_id The Watchmode title ID.
     * @return array|WP_Error
     */
    public function tvm_tracker_get_sources_for_title( $title_id ) {
        $endpoint = "title/{$title_id}/sources/";
        $response = $this->tvm_tracker_api_request( $endpoint, array(), true );

        // The API returns the sources array directly as the top level response
        if ( is_wp_error( $response ) || ! is_array( $response ) ) {
            return array();
        }

        return $response;
    }
}
