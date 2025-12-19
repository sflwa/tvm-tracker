<?php
/**
 * TVM Tracker - Database Interaction Class (Schema V2.0)
 * Handles all CRUD operations for custom database tables.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 2.2.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tvm_Tracker_DB class.
 * Manages interaction with the custom V2.0 tables.
 */
class Tvm_Tracker_DB {

    // V2.0 Table names
    private $table_shows;
    private $table_episode_data;
    private $table_episode_links;
    private $table_sources;
    private $table_episodes; // Tracking table
    private $table_api_cache; // NEW: API Cache table
    private $api_client;


   /**
     * Constructor.
     * Sets up table names and triggers migration check.
     */
    public function __construct() {
        global $wpdb;
        $this->table_shows         = $wpdb->prefix . 'tvm_tracker_shows';
        $this->table_episode_data  = $wpdb->prefix . 'tvm_tracker_episode_data';
        $this->table_episode_links = $wpdb->prefix . 'tvm_tracker_episode_links';
        $this->table_sources       = $wpdb->prefix . 'tvm_tracker_sources';
        $this->table_episodes      = $wpdb->prefix . 'tvm_tracker_episodes';
        $this->table_api_cache     = $wpdb->prefix . 'tvm_tracker_api_cache';
        
        // One-time check to migrate old transients immediately upon class load
        $this->tvm_tracker_migrate_transients();
    }

    /**
     * Sets the API Client dependency after instantiation.
     *
     * @param Tvm_Tracker_API $api_client The instantiated API client.
     */
    public function tvm_tracker_set_api_client( $api_client ) {
        $this->api_client = $api_client;
    }

    // =======================================================================
    // API CACHE METHODS (NEW)
    // =======================================================================
    
    /**
     * Retrieves cached API data from the custom table.
     *
     * @param string $cache_key The MD5 hash key.
     * @return array|bool The cached data array (data, expires, type) or false if not found/expired.
     */
    public function tvm_tracker_get_api_cache( $cache_key ) {
        global $wpdb;
        $current_time = current_time( 'mysql' );
        
        $sql = $wpdb->prepare(
            "SELECT cached_data FROM {$this->table_api_cache} WHERE cache_key = %s AND cache_expires > %s",
            $cache_key,
            $current_time
        );
        $result = $wpdb->get_var( $sql );
        
        if ( ! $result ) {
            return false;
        }
        
        // Unserialize and return the data
        return unserialize( $result );
    }
    
    /**
     * Stores/Updates API data in the custom cache table.
     *
     * @param string $cache_key The MD5 hash key.
     * @param string $request_path The human-readable API path.
     * @param string $cache_type The API endpoint type.
     * @param array $data The API response data array.
     * @param int $duration The cache duration in seconds.
     * @return bool True on success, False on failure.
     */
    public function tvm_tracker_set_api_cache( $cache_key, $request_path, $cache_type, $data, $duration ) {
        global $wpdb;
        
        $current_time = current_time( 'mysql' );
        $cache_expires = date( 'Y-m-d H:i:s', strtotime( $current_time ) + $duration );
        $serialized_data = serialize( $data );
        
        $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table_api_cache} WHERE cache_key = %s", $cache_key ) );
        
        if ( $existing_id ) {
            // Update existing entry
            $result = $wpdb->update(
                $this->table_api_cache,
                array( 
                    'last_updated' => $current_time,
                    'cache_expires' => $cache_expires,
                    'cached_data' => $serialized_data,
                ),
                array( 'id' => $existing_id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            // Insert new entry
            $result = $wpdb->insert(
                $this->table_api_cache,
                array(
                    'cache_key' => $cache_key,
                    'request_path' => $request_path,
                    'cache_type' => $cache_type,
                    'first_call' => $current_time,
                    'last_updated' => $current_time,
                    'cache_expires' => $cache_expires,
                    'cached_data' => $serialized_data,
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
        }
        
        return $result !== false;
    }
    
    /**
     * Deletes a specific cache entry.
     *
     * @param string $cache_key The MD5 hash key.
     * @return bool True on success, False on failure.
     */
    public function tvm_tracker_delete_api_cache( $cache_key ) {
        global $wpdb;
        return $wpdb->delete( $this->table_api_cache, array( 'cache_key' => $cache_key ), array( '%s' ) ) !== false;
    }
    
    /**
     * One-time migration function to move old WordPress transients to the new DB table.
     * Only runs once based on a stored option.
     */
    public function tvm_tracker_migrate_transients() {
        global $wpdb;
        
        if ( get_option( 'tvm_tracker_cache_migrated' ) ) {
            return;
        }

        // Find all transients related to our plugin in the wp_options table
        $sql = "SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE '\_transient\_tvm\_tracker\_%'";
        $transients = $wpdb->get_results( $sql, ARRAY_A );
        
        if ( empty( $transients ) ) {
            // No transients found, just mark as migrated and exit
            update_option( 'tvm_tracker_cache_migrated', true );
            return;
        }
        
        // Default cache durations (in seconds)
        $duration_map = [
            'details'  => 2592000, // 1 month
            'sources'  => 2592000, // 1 month
            'episodes' => 604800,  // 1 week
            'search'   => 43200,   // 12 hours
            'default'  => 43200,   // 12 hours
        ];

        foreach ( $transients as $transient ) {
            $option_name = $transient['option_name'];
            $cache_key = substr( $option_name, 21 ); 
            $cached_data = unserialize( $transient['option_value'] );
            
            $cache_type = 'migrated';
            $request_path = 'Migrated Cache (Key: ' . $cache_key . ')';
            
            $timeout_option_name = '_transient_timeout_' . substr($option_name, 11);
            $timeout_timestamp = get_option( $timeout_option_name );
            
            if ( $timeout_timestamp ) {
                $cache_expires = date( 'Y-m-d H:i:s', $timeout_timestamp );
            } else {
                $cache_expires = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + $duration_map['default'] );
            }

            // Insert into the new table
            $wpdb->insert(
                $this->table_api_cache,
                array(
                    'cache_key'     => $cache_key,
                    'request_path'  => sanitize_text_field( $request_path ),
                    'cache_type'    => $cache_type,
                    'first_call'    => current_time( 'mysql' ),
                    'last_updated'  => current_time( 'mysql' ),
                    'cache_expires' => $cache_expires,
                    'cached_data'   => serialize( $cached_data ),
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
            
            // Clean up old transients
            delete_option( $option_name );
            delete_option( $timeout_option_name );
        }
        
        // Mark migration as complete
        update_option( 'tvm_tracker_cache_migrated', true );
    }

    // =======================================================================
    // ADMIN STATS GETTER METHODS
    // =======================================================================
    
    public function tvm_tracker_get_total_shows_count() {
        global $wpdb;
        $sql = "SELECT COUNT(DISTINCT title_id) FROM {$this->table_shows} WHERE item_type NOT IN ('movie', 'short_film', 'doc_film', 'tv_movie')";
        return absint( $wpdb->get_var( $sql ) );
    }
    
    public function tvm_tracker_get_total_movies_count() {
        global $wpdb;
        $sql = "SELECT COUNT(DISTINCT title_id) FROM {$this->table_shows} WHERE item_type IN ('movie', 'short_film', 'doc_film', 'tv_movie')";
        return absint( $wpdb->get_var( $sql ) );
    }
    
    public function tvm_tracker_get_total_episodes_count() {
        global $wpdb;
        $sql = "SELECT COUNT(id) FROM {$this->table_episode_data}";
        return absint( $wpdb->get_var( $sql ) );
    }
    
    public function tvm_tracker_get_cache_count() {
        global $wpdb;
        $sql = "SELECT COUNT(id) FROM {$this->table_api_cache}";
        return absint( $wpdb->get_var( $sql ) );
    }

    public function tvm_tracker_get_api_log_records( $cache_type = '', $title_id = 0 ) {
        global $wpdb;
        $where_clauses = array();
        
        if ( ! empty( $cache_type ) && $cache_type !== 'all' ) {
            $where_clauses[] = $wpdb->prepare( "cache_type = %s", sanitize_text_field( $cache_type ) );
        }
        
        if ( $title_id > 0 ) {
             $title_id_part = (string)absint($title_id);
             $where_clauses[] = $wpdb->prepare( "request_path LIKE %s", '%title/' . $wpdb->esc_like($title_id_part) . '/%' );
        }
        
        $where_clause_sql = !empty($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

        $sql = "
            SELECT cache_key, request_path, cache_type, first_call, last_updated, cache_expires
            FROM {$this->table_api_cache} 
            {$where_clause_sql}
            ORDER BY last_updated DESC
            LIMIT 200
        ";
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public function tvm_tracker_get_title_name_by_id( $title_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT title_name FROM {$this->table_shows} WHERE title_id = %d LIMIT 1",
            absint( $title_id )
        );
        return $wpdb->get_var( $sql );
    }

    // =======================================================================
    // CORE TRACKING METHODS (TVM_TRACKER_SHOWS TABLE)
    // =======================================================================

    public function tvm_tracker_add_show( $user_id, $title_id, $title_name, $total_episodes, $item_type = 'tv_series', $release_date = null, $is_watched = false ) {
        global $wpdb;
        $end_year = $this->tvm_tracker_populate_static_data( $title_id );
        $result = $wpdb->insert(
            $this->table_shows,
            array(
                'user_id'        => absint( $user_id ),
                'title_id'       => absint( $title_id ),
                'title_name'     => sanitize_text_field( $title_name ),
                'total_episodes' => absint( $total_episodes ),
                'tracked_date'   => current_time( 'mysql' ),
                'end_year'       => $end_year,
                'item_type'      => sanitize_text_field( $item_type ),
                'release_date'   => sanitize_text_field( $release_date ),
                'is_watched'     => (int) $is_watched,
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
        );
        return $result;
    }

    public function tvm_tracker_remove_show( $user_id, $title_id ) {
        global $wpdb;
        $wpdb->delete(
            $this->table_episodes,
            array( 'user_id' => absint( $user_id ), 'title_id' => absint( $title_id ) ),
            array( '%d', '%d' )
        );
        $result = $wpdb->delete(
            $this->table_shows,
            array( 'user_id' => absint( $user_id ), 'title_id' => absint( $title_id ) ),
            array( '%d', '%d' )
        );
        return $result !== false;
    }

    public function tvm_tracker_is_show_tracked( $user_id, $title_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(id) FROM {$this->table_shows} WHERE user_id = %d AND title_id = %d",
            absint( $user_id ),
            absint( $title_id )
        );
        return absint( $wpdb->get_var( $sql ) ) > 0;
    }
    
    public function tvm_tracker_get_tracked_shows( $user_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_shows} WHERE user_id = %d AND item_type NOT IN ('movie', 'short_film', 'doc_film', 'tv_movie') ",
            absint( $user_id )
        );
        return $wpdb->get_results( $sql );
    }

    public function tvm_tracker_get_tracked_movies( $user_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_shows} WHERE user_id = %d AND item_type IN ('movie', 'short_film', 'doc_film', 'tv_movie') ",
            absint( $user_id )
        );
        return $wpdb->get_results( $sql );
    }

    public function tvm_tracker_toggle_movie_watched( $user_id, $title_id, $is_watched ) {
        global $wpdb;
        $result = $wpdb->update(
            $this->table_shows,
            array( 'is_watched' => (int) $is_watched ),
            array( 'user_id' => absint( $user_id ), 'title_id' => absint( $title_id ) ),
            array( '%d' ),
            array( '%d', '%d' )
        );
        return $result !== false;
    }


    // =======================================================================
    // STATIC DATA POPULATION & UPDATE LOGIC (SYNCHRONIZED WITH CACHE)
    // =======================================================================

    public function tvm_tracker_check_for_new_episodes() {
        global $wpdb;
        $current_time = time();
        $last_checked = absint( get_option( 'tvm_tracker_last_update_check', 0 ) );
        
        if ( $current_time - $last_checked < 604800 ) {
            return;
        }

        $sql = "
            SELECT DISTINCT title_id
            FROM {$this->table_shows}
            WHERE (end_year IS NULL OR end_year = '0000') AND item_type NOT IN ('movie', 'short_film', 'doc_film', 'tv_movie')
        ";
        $ongoing_title_ids = $wpdb->get_col( $sql );
        
        if ( empty( $ongoing_title_ids ) ) {
            update_option( 'tvm_tracker_last_update_check', $current_time );
            return;
        }

        foreach ( $ongoing_title_ids as $title_id ) {
            $end_year = $this->tvm_tracker_populate_static_data( absint($title_id), true );
            if (!empty($end_year)) {
                 $wpdb->update(
                    $this->table_shows,
                    array('end_year' => $end_year),
                    array('title_id' => $title_id),
                    array('%s'),
                    array('%d')
                );
            }
        }
        update_option( 'tvm_tracker_last_update_check', $current_time );
    }

    /**
     * Populates all static episode and link data for a given title ID.
     * UPDATED: Now synchronizes database tables whenever fresh API data is available.
     */
    public function tvm_tracker_populate_static_data( $title_id, $is_update = false ) {
        global $wpdb;
        $title_id = absint( $title_id );
        
        // 1. ALWAYS Fetch Title Details to get the most recent end_year status
        $details = $this->api_client->tvm_tracker_get_title_details( $title_id );
        
        $end_year = null;
        if ( ! is_wp_error( $details ) && ! empty( $details['end_year'] ) ) {
            $end_year = absint( $details['end_year'] );
        }
        
        // 2. Fetch episodes and populate static data
        // Overwrites stale records by removing early return and using replace()
        $episodes = $this->api_client->tvm_tracker_get_episodes( $title_id );
        
        if ( is_wp_error( $episodes ) || empty( $episodes ) ) {
            return $end_year;
        }

        foreach ( $episodes as $episode ) {
            $episode_watchmode_id = absint( $episode['id'] );
            $release_date_raw = sanitize_text_field( $episode['release_date'] );
            $air_date = ( ! empty( $release_date_raw ) && strtotime( $release_date_raw ) ) 
                        ? date( 'Y-m-d', strtotime( $release_date_raw ) ) 
                        : '0000-00-00';

            // Use REPLACE to ensure existing episodes are updated with current info from API
            $wpdb->replace(
                $this->table_episode_data,
                array(
                    'title_id'         => $title_id,
                    'watchmode_id'     => $episode_watchmode_id,
                    'season_number'    => absint( $episode['season_number'] ),
                    'episode_number'   => absint( $episode['episode_number'] ),
                    'episode_name'     => sanitize_text_field( $episode['name'] ),
                    'air_date'         => $air_date,
                    'plot_overview'    => sanitize_textarea_field( $episode['overview'] ),
                    'thumbnail_url'    => esc_url_raw( $episode['thumbnail_url'] ),
                ),
                array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
            );

            if ( isset( $episode['sources'] ) && is_array( $episode['sources'] ) ) {
                // Clear existing links to ensure removed sources are correctly synced
                $wpdb->delete( 
                    $this->table_episode_links, 
                    array( 'title_id' => $title_id, 'episode_id' => $episode_watchmode_id ), 
                    array( '%d', '%d' ) 
                );

                foreach ( $episode['sources'] as $source ) {
                    $wpdb->insert(
                        $this->table_episode_links,
                        array(
                            'title_id'     => $title_id,
                            'episode_id'   => $episode_watchmode_id,
                            'source_id'    => absint( $source['source_id'] ),
                            'web_url'      => esc_url_raw( $source['web_url'] ),
                            'region'       => sanitize_text_field( $source['region'] ),
                        ),
                        array( '%d', '%d', '%d', '%s', '%s' )
                    );
                }
            }
        }
        $this->tvm_tracker_populate_global_sources();
        return $end_year;
    }
    
    /**
     * Forces relational database tables to update using current cache data.
     */
    public function tvm_tracker_sync_db_from_cache( $title_id ) {
        $title_id = absint( $title_id );
        if ( ! $title_id ) return false;

        // Populate static data with update flag to force synchronization
        $this->tvm_tracker_populate_static_data( $title_id, true );

        return true;
    }

    public function tvm_tracker_populate_global_sources() {
        global $wpdb;
        $sql_check = "SELECT COUNT(id) FROM {$this->table_sources}";
        if ( absint( $wpdb->get_var( $sql_check ) ) > 0 ) {
            return;
        }
        $sources = $this->api_client->tvm_tracker_get_all_sources();
        if ( is_wp_error( $sources ) || empty( $sources ) ) {
            return;
        }
        foreach ($sources as $source) {
            $wpdb->insert(
                $this->table_sources,
                array(
                    'source_id'  => absint( $source['id'] ),
                    'source_name' => sanitize_text_field( $source['name'] ),
                    'logo_url'   => esc_url_raw( $source['logo_100px'] ),
                ),
                array( '%d', '%s', '%s' )
            );
        }
    }

    public function tvm_tracker_force_update_ongoing_series() {
        global $wpdb;
        $sql = "
            SELECT DISTINCT title_id
            FROM {$this->table_shows}
            WHERE (end_year IS NULL OR end_year = '0000') AND item_type NOT IN ('movie', 'short_film', 'doc_film', 'tv_movie')
        ";
        $ongoing_title_ids = $wpdb->get_col( $sql );
        $processed_count = 0;
        if ( empty( $ongoing_title_ids ) ) {
            return $processed_count;
        }
        foreach ( $ongoing_title_ids as $title_id ) {
            $end_year = $this->tvm_tracker_populate_static_data( absint($title_id), true );
            if (!empty($end_year)) {
                 $wpdb->update(
                    $this->table_shows,
                    array('end_year' => $end_year),
                    array('title_id' => $title_id),
                    array('%s'),
                    array('%d')
                );
                $processed_count++;
            }
        }
        return $processed_count;
    }

    // =======================================================================
    // GETTER METHODS (USED BY VIEWS)
    // =======================================================================
    
    public function tvm_tracker_get_all_episode_data( $title_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_episode_data} WHERE title_id = %d ORDER BY season_number ASC, episode_number ASC",
            absint( $title_id )
        );
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public function tvm_tracker_get_watched_episodes( $user_id, $title_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT episode_id FROM {$this->table_episodes} WHERE user_id = %d AND title_id = %d",
            absint( $user_id ),
            absint( $title_id )
        );
        return array_map( 'absint', $wpdb->get_col( $sql ) );
    }
    
    public function tvm_tracker_get_watched_episodes_count( $user_id, $title_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(id) FROM {$this->table_episodes} WHERE user_id = %d AND title_id = %d",
            absint( $user_id ),
            absint( $title_id )
        );
        return absint( $wpdb->get_var( $sql ) );
    }
    
    public function tvm_tracker_get_unwatched_episodes( $user_id ) {
        global $wpdb;
        $tracked_titles_sql = $wpdb->prepare( "SELECT title_id FROM {$this->table_shows} WHERE user_id = %d", absint( $user_id ) );
        $tracked_title_ids = $wpdb->get_col( $tracked_titles_sql );
        if ( empty( $tracked_title_ids ) ) {
            return [];
        }
        $title_ids_in = implode( ',', array_map( 'absint', $tracked_title_ids ) );
        $sql = "
            SELECT ed.*, s.title_name
            FROM {$this->table_episode_data} ed
            LEFT JOIN {$this->table_shows} s ON ed.title_id = s.title_id AND s.user_id = %d
            WHERE ed.title_id IN ({$title_ids_in})
            AND ed.watchmode_id NOT IN (
                SELECT episode_id FROM {$this->table_episodes} 
                WHERE user_id = %d AND title_id = ed.title_id
            )
            ORDER BY ed.air_date ASC
        ";
        $sql = $wpdb->prepare( $sql, absint( $user_id ), absint( $user_id ) );
        return $wpdb->get_results( $sql, ARRAY_A );
    }
    
    public function tvm_tracker_get_episode_source_links( $title_id, $episode_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "
            SELECT el.web_url, src.source_id, src.source_name, src.logo_url, el.region
            FROM {$this->table_episode_links} el, {$this->table_sources} src
            WHERE el.source_id = src.source_id
            AND el.title_id = %d AND el.episode_id = %d
            ",
            absint( $title_id ),
            absint( $episode_id )
        );
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    // =======================================================================
    // EPISODE WATCH/UNWATCH TOGGLE (V2.0)
    // =======================================================================

    public function tvm_tracker_toggle_episode( $user_id, $title_id, $episode_id, $is_watched ) {
        global $wpdb;
        $user_id = absint( $user_id );
        $title_id = absint( $title_id );
        $episode_id = absint( $episode_id );
        if ( $is_watched ) {
            $result = $wpdb->query( $wpdb->prepare(
                "REPLACE INTO {$this->table_episodes} (user_id, title_id, episode_id) VALUES (%d, %d, %d)",
                $user_id, $title_id, $episode_id
            ) );
        } else {
            $result = $wpdb->delete(
                $this->table_episodes,
                array( 'user_id' => $user_id, 'title_id' => $title_id, 'episode_id' => $episode_id ),
                array( '%d', '%d', '%d' )
            );
        }
        return $result !== false;
    }

    public function tvm_tracker_toggle_bulk_episodes( $user_id, $title_id, $is_watched, $season_number = null ) {
        global $wpdb;
        $user_id = absint( $user_id );
        $title_id = absint( $title_id );
        $where_clause = $wpdb->prepare( "title_id = %d", $title_id );
        if ( ! is_null( $season_number ) ) {
            $season_number = absint( $season_number );
            $where_clause .= $wpdb->prepare( " AND season_number = %d", $season_number );
        }
        $today = date_i18n('Y-m-d');
        $airing_clause = $wpdb->prepare( "AND air_date <= %s AND air_date != %s", $today, '0000-00-00' );
        $sql_episodes = "SELECT watchmode_id FROM {$this->table_episode_data} WHERE {$where_clause} {$airing_clause}";
        $episode_ids = $wpdb->get_col( $sql_episodes );
        if ( empty( $episode_ids ) ) {
            return true;
        }
        $episode_ids_in = implode( ',', array_map( 'absint', $episode_ids ) );
        if ( $is_watched ) {
            $values_sql = [];
            foreach ( $episode_ids as $episode_id ) {
                $values_sql[] = $wpdb->prepare( '(%d, %d, %d)', $user_id, $title_id, absint( $episode_id ) );
            }
            $values_string = implode( ', ', $values_sql );
            $sql_insert = "INSERT IGNORE INTO {$this->table_episodes} (user_id, title_id, episode_id) VALUES {$values_string}";
            $result = $wpdb->query( $sql_insert );
        } else {
            $sql_delete = $wpdb->prepare(
                "DELETE FROM {$this->table_episodes} WHERE user_id = %d AND title_id = %d AND episode_id IN ({$episode_ids_in})",
                $user_id, $title_id
            );
            $result = $wpdb->query( $sql_delete );
        }
        return $result !== false;
    }
}
