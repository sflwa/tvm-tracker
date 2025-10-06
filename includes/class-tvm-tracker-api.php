<?php
/**
 * TVM Tracker - Watchmode API Client
 * Handles all communication and caching for the Watchmode API (Schema V2.0 compatibility).
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 2.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tvm_Tracker_API class.
 */
class Tvm_Tracker_API {

    const VERSION = '2.1.0';
    
    // New Cache Duration Constants (in seconds)
    const CACHE_DURATION_DETAILS = 2592000; // 1 month
    const CACHE_DURATION_EPISODES = 604800;  // 1 week
    const CACHE_DURATION_SOURCES = 2592000;  // 1 month
    const CACHE_DURATION_SEARCH = 43200;    // 12 hours (Default for search, should not be cached long)
    
    const API_URL = 'https://api.watchmode.com/v1/';

    /**
     * @var array Stores all API URLs called during a single request for debugging.
     */
    private static $api_urls_called = array();

    /**
     * Constructor.
     */
    public function __construct() {
        // Initialization can happen here if needed.
    }
    
    /**
     * Retrieves the database client instance via the main plugin class.
     * @return Tvm_Tracker_DB|null
     */
    private function tvm_tracker_get_db_client() {
        if ( class_exists( 'Tvm_Tracker_Plugin' ) ) {
            return Tvm_Tracker_Plugin::tvm_tracker_get_instance()->get_db_client();
        }
        return null;
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
     * Executes a request to the Watchmode API with built-in error handling and database caching.
     *
     * @param string $endpoint The API endpoint (e.g., 'sources/', 'search/').
     * @param array  $params Additional query parameters.
     * @param bool   $is_cached Whether to use caching for this request.
     * @param string $cache_type Identifier for the data type being cached ('details', 'episodes', etc.).
     * @param int    $duration Cache duration in seconds.
     * @return array|WP_Error The API response data array or a WP_Error object on failure.
     */
    private function tvm_tracker_api_request( $endpoint, $params = array(), $is_cached = false, $cache_type = 'default', $duration = self::CACHE_DURATION_SEARCH ) {
        
        $db_client = $this->tvm_tracker_get_db_client();

        // 1. Prepare Request Data
        $api_key = self::tvm_tracker_get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'tvm_tracker_no_key', esc_html__( 'Watchmode API Key is missing. Please configure it in the plugin settings.', 'tvm-tracker' ) );
        }

        $query_args = array_merge( $params, array( 'apiKey' => $api_key ) );
        $url = self::API_URL . $endpoint . '?' . http_build_query( $query_args );

        // Generate unique cache key (MD5 hash of the request URL without the API Key)
        $request_path_base = self::API_URL . $endpoint . '?' . http_build_query( $params );
        $cache_key = md5( $request_path_base );
        
        // Log URL for debug purposes (includes key for verification)
        self::$api_urls_called[] = esc_url_raw( $request_path_base . '&apiKey=[REDACTED]' );

        // 2. Check VALID Cache (Uses expiration check)
        if ( $is_cached && $db_client ) {
            $cached_data = $db_client->tvm_tracker_get_api_cache( $cache_key );
            if ( false !== $cached_data ) {
                return $cached_data;
            }
        }

        // 3. Execute Live API Call
        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ) );

        $data = null; // Initialize data variable
        $has_api_error = false;

        // Check for transport error
        if ( is_wp_error( $response ) ) {
            $error_to_return = $response;
            $has_api_error = true;
        } else {
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            // Check for JSON decode error
            if ( is_null( $data ) && json_last_error() !== JSON_ERROR_NONE ) {
                $error_to_return = new WP_Error( 'tvm_tracker_json_error', esc_html__( 'Failed to decode API response JSON.', 'tvm-tracker' ) );
                $has_api_error = true;
            }
            // Check for API-specific errors (like quota overrun)
            elseif ( isset( $data['error'] ) ) {
                /* translators: 1: Error Code 2: Error Message */
                $error_message = sprintf( esc_html__( 'API Error: %1$s - %2$s', 'tvm-tracker' ), $data['code'], $data['message'] );
                $error_to_return = new WP_Error( 'tvm_tracker_api_error', $error_message );
                $has_api_error = true;
            }
        }
        
        // 4. CRITICAL FALLBACK LOGIC (Use Expired Cache on Live Failure)
        if ( $has_api_error && $is_cached && $db_client ) {
            global $wpdb;
            $table_api_cache = $wpdb->prefix . 'tvm_tracker_api_cache';

            // Query the cache table directly, IGNORING the expiration time.
            $expired_data_sql = $wpdb->prepare(
                "SELECT cached_data FROM {$table_api_cache} WHERE cache_key = %s",
                $cache_key
            );
            $expired_result = $wpdb->get_var( $expired_data_sql );
            
            if ( $expired_result ) {
                // Found expired cache, use it as a fallback.
                return unserialize( $expired_result );
            }
            
            // If no cache is found, return the original error.
            return $error_to_return;
        }

        // 5. Store/Update Cache & Return Live Data
        // FIX for Issue #12: Only update the cache if no API error was found.
        if ( $is_cached && $db_client && !$has_api_error ) {
            $db_client->tvm_tracker_set_api_cache( 
                $cache_key, 
                esc_url_raw($request_path_base), 
                $cache_type, 
                $data, 
                $duration 
            );
        }

        // Return live data if successful, otherwise return the error object.
        return $has_api_error ? $error_to_return : $data;
    }

    // =======================================================================
    // PUBLIC API WRAPPERS
    // =======================================================================

    /**
     * Fetches all streaming sources from the Watchmode API.
     * This is used by the database class to populate the local source table.
     *
     * @return array|WP_Error
     */
    public function tvm_tracker_get_all_sources() {
        return $this->tvm_tracker_api_request( 
            'sources/', 
            array(), 
            true, 
            'sources', 
            self::CACHE_DURATION_SOURCES 
        );
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
        // Do not cache search results long
        $results = $this->tvm_tracker_api_request( 
            $endpoint, 
            $params, 
            true, // We cache search results, but for a short duration
            'search',
            self::CACHE_DURATION_SEARCH 
        );

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
        return $this->tvm_tracker_api_request( 
            $endpoint, 
            array(), 
            true, 
            'details', 
            self::CACHE_DURATION_DETAILS 
        );
    }
    
    /**
     * Gets the season information for a specific title ID.
     *
     * @param int $title_id The Watchmode title ID.
     * @return array|WP_Error
     */
    public function tvm_tracker_get_seasons( $title_id ) {
        $endpoint = "title/{$title_id}/seasons/";
        return $this->tvm_tracker_api_request( 
            $endpoint, 
            array(), 
            true, 
            'seasons', 
            self::CACHE_DURATION_DETAILS 
        );
    }

    /**
     * Gets the full episode information for a specific title ID.
     *
     * @param int $title_id The Watchmode title ID.
     * @return array|WP_Error
     */
    public function tvm_tracker_get_episodes( $title_id ) {
        $endpoint = "title/{$title_id}/episodes/";
        $response = $this->tvm_tracker_api_request( 
            $endpoint, 
            array(), 
            true, 
            'episodes', 
            self::CACHE_DURATION_EPISODES 
        );

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
        $response = $this->tvm_tracker_api_request( 
            $endpoint, 
            array(), 
            true, 
            'title_sources', 
            self::CACHE_DURATION_DETAILS 
        );

        // The API returns the sources array directly as the top level response
        if ( is_wp_error( $response ) || ! is_array( $response ) ) {
            return array();
        }

        return $response;
    }
}
