<?php
/**
 * TVM Tracker - Database Interaction Class (Schema V2.0)
 * Handles all CRUD operations for custom database tables.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 2.1.2
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
    private $api_client;


    /**
     * Constructor.
     * Sets up table names.
     */
    public function __construct() {
        global $wpdb;
        $this->table_shows         = $wpdb->prefix . 'tvm_tracker_shows';
        $this->table_episode_data  = $wpdb->prefix . 'tvm_tracker_episode_data';
        $this->table_episode_links = $wpdb->prefix . 'tvm_tracker_episode_links';
        $this->table_sources       = $wpdb->prefix . 'tvm_tracker_sources';
        $this->table_episodes      = $wpdb->prefix . 'tvm_tracker_episodes';
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
    // CORE TRACKING METHODS (TVM_TRACKER_SHOWS TABLE)
    // =======================================================================

    /**
     * Adds a new show or movie to the tracker and populates static data tables.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @param string $title_name The title name.
     * @param int $total_episodes The total number of episodes (0 for movies).
     * @param string $item_type The Watchmode item type ('movie', 'tv_series', etc.).
     * @param string $release_date Movie release date (YYYY-MM-DD), empty for series.
     * @param bool $is_watched Whether the movie has been watched (1) or is 'want to see' (0).
     * @return bool|int True on success, False on failure, or row ID on insert.
     */
    public function tvm_tracker_add_show( $user_id, $title_id, $title_name, $total_episodes, $item_type = 'tv_series', $release_date = null, $is_watched = false ) {
        global $wpdb;

        // 1. CRITICAL: Populate static data first to get the end_year (for series)
        $end_year = $this->tvm_tracker_populate_static_data( $title_id );

        // 2. Add show to tracking table
        $result = $wpdb->insert(
            $this->table_shows,
            array(
                'user_id'        => absint( $user_id ),
                'title_id'       => absint( $title_id ),
                'title_name'     => sanitize_text_field( $title_name ),
                'total_episodes' => absint( $total_episodes ),
                'tracked_date'   => current_time( 'mysql' ),
                'end_year'       => $end_year, // Store end year status
                'item_type'      => sanitize_text_field( $item_type ), // Store item type
                'release_date'   => sanitize_text_field( $release_date ), // Store movie release date
                'is_watched'     => (int) $is_watched, // Store watched status (0 or 1)
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
        );

        return $result;
    }

    /**
     * Removes a show and all its related tracking data.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @return bool True on success, False on failure.
     */
    public function tvm_tracker_remove_show( $user_id, $title_id ) {
        global $wpdb;

        // 1. Delete all user's tracking entries for the show
        $wpdb->delete(
            $this->table_episodes,
            array( 'user_id' => absint( $user_id ), 'title_id' => absint( $title_id ) ),
            array( '%d', '%d' )
        );

        // 2. Delete the show entry itself
        $result = $wpdb->delete(
            $this->table_shows,
            array( 'user_id' => absint( $user_id ), 'title_id' => absint( $title_id ) ),
            array( '%d', '%d' )
        );

        return $result !== false;
    }

    /**
     * Checks if a specific show is tracked by the user.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @return bool True if tracked, false otherwise.
     */
    public function tvm_tracker_is_show_tracked( $user_id, $title_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(id) FROM {$this->table_shows} WHERE user_id = %d AND title_id = %d",
            absint( $user_id ),
            absint( $title_id )
        );
        $count = $wpdb->get_var( $sql );
        return absint( $count ) > 0;
    }
    
    /**
     * Retrieves the list of all tracked shows (TV Series) for the user.
     *
     * @param int $user_id The current user ID.
     * @return array|object|null Array of tracked show objects, or empty array.
     */
    public function tvm_tracker_get_tracked_shows( $user_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_shows} WHERE user_id = %d AND item_type NOT IN ('movie', 'short_film', 'doc_film') ",
            absint( $user_id )
        );
        return $wpdb->get_results( $sql );
    }

    /**
     * Retrieves the list of all tracked movies for the user.
     *
     * @param int $user_id The current user ID.
     * @return array|object|null Array of tracked movie objects, or empty array.
     */
    public function tvm_tracker_get_tracked_movies( $user_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_shows} WHERE user_id = %d AND item_type IN ('movie', 'short_film', 'doc_film') ",
            absint( $user_id )
        );
        return $wpdb->get_results( $sql );
    }

    /**
     * Updates the watched status of a tracked movie.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @param bool $is_watched True for watched (1), false for want to see (0).
     * @return bool True on success, False on failure.
     */
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
    // STATIC DATA POPULATION & UPDATE LOGIC (CRITICAL V2.0 LOGIC)
    // =======================================================================

    /**
     * Checks the API for new episodes for all ongoing (not ended) shows.
     * This is designed to be called by the shortcode handler once per week.
     */
    public function tvm_tracker_check_for_new_episodes() {
        global $wpdb;
        $current_time = time();
        $last_checked = absint( get_option( 'tvm_tracker_last_update_check', 0 ) );
        
        // Only run the full check once every 7 days (604800 seconds)
        if ( $current_time - $last_checked < 604800 ) {
            return;
        }

        // Get all unique titles that are NOT marked as ended (end_year IS NULL or 0)
        // and are not movies.
        $sql = "
            SELECT DISTINCT title_id
            FROM {$this->table_shows}
            WHERE (end_year IS NULL OR end_year = '0000') AND item_type NOT IN ('movie', 'short_film', 'doc_film')
        ";
        $ongoing_title_ids = $wpdb->get_col( $sql );
        
        if ( empty( $ongoing_title_ids ) ) {
            // Nothing to update
            update_option( 'tvm_tracker_last_update_check', $current_time );
            return;
        }

        foreach ( $ongoing_title_ids as $title_id ) {
            // Attempt to populate/update the static data for the show
            // The populate method handles the API call and insert logic internally.
            $end_year = $this->tvm_tracker_populate_static_data( absint($title_id), true ); 
            
            // If the series has ended, update the tracking table to reflect the end_year for all users tracking it.
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

        // Update the timestamp after all checks are complete
        update_option( 'tvm_tracker_last_update_check', $current_time );
    }


    /**
     * Populates all static episode and link data for a given title ID.
     *
     * @param int $title_id The Watchmode title ID.
     * @param bool $is_update True if this is an update check (inserts new episodes only).
     * @return string|null The end year of the show (YYYY), or null if not applicable/found.
     */
    public function tvm_tracker_populate_static_data( $title_id, $is_update = false ) {
        global $wpdb;
        $title_id = absint( $title_id );
        
        // 1. Check if data already exists and if this is NOT an update check
        if ( ! $is_update ) {
            $sql_check = $wpdb->prepare( "SELECT COUNT(id) FROM {$this->table_episode_data} WHERE title_id = %d", $title_id );
            if ( absint( $wpdb->get_var( $sql_check ) ) > 0 ) {
                // If data exists and we are not updating, return the existing end year if available
                $end_year_sql = $wpdb->prepare("SELECT end_year FROM {$this->table_shows} WHERE title_id = %d LIMIT 1", $title_id);
                return $wpdb->get_var($end_year_sql);
            }
        }

        // --- 2. Fetch Title Details to get end_year and episodes ---
        $details = $this->api_client->tvm_tracker_get_title_details( $title_id );
        $episodes = $this->api_client->tvm_tracker_get_episodes( $title_id );
        
        if ( is_wp_error( $episodes ) || empty( $episodes ) ) {
            return null;
        }

        $end_year = null;
        if ( ! is_wp_error( $details ) && ! empty( $details['end_year'] ) ) {
            // Store end year as a 4-digit string
            $end_year = absint( $details['end_year'] );
        }
        
        // --- 3. Populate Episode Data Table & Links Table ---
        foreach ( $episodes as $episode ) {
            $episode_watchmode_id = absint( $episode['id'] );

            // Skip existing episodes during update check
            $episode_exists_sql = $wpdb->prepare( 
                "SELECT COUNT(id) FROM {$this->table_episode_data} WHERE watchmode_id = %d", 
                $episode_watchmode_id 
            );
            if ( $is_update && absint( $wpdb->get_var( $episode_exists_sql ) ) > 0 ) {
                continue; 
            }
            // CRITICAL: If not an update, check if episode exists to prevent duplication on re-tracking
            if ( !$is_update ) {
                 if ( absint( $wpdb->get_var( $episode_exists_sql ) ) > 0 ) {
                    continue; 
                }
            }


            // CRITICAL FIX: Ensure date is correctly handled.
            $release_date_raw = sanitize_text_field( $episode['release_date'] );
            $air_date = ( ! empty( $release_date_raw ) && strtotime( $release_date_raw ) ) 
                        ? date( 'Y-m-d', strtotime( $release_date_raw ) ) 
                        : '0000-00-00';


            $result = $wpdb->insert(
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

            // --- 4. Populate Episode Links Table ---
            if ( isset( $episode['sources'] ) && is_array( $episode['sources'] ) ) {
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
        
        // Ensure static sources table is populated (Installer only runs once, this handles updates)
        $this->tvm_tracker_populate_global_sources();
        
        return $end_year;
    }
    
    /**
     * Populates the global wp_tvm_tracker_sources table.
     */
    public function tvm_tracker_populate_global_sources() {
        global $wpdb;
        
        // Check if sources table is empty
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


    // =======================================================================
    // GETTER METHODS (USED BY VIEWS)
    // =======================================================================
    
    /**
     * Retrieves all episode data (static) for a given title ID, ordered by season/episode number.
     * Used by the Detail Page view-render-seasons-episodes.php.
     *
     * @param int $title_id The Watchmode title ID.
     * @return array Array of episode data.
     */
    public function tvm_tracker_get_all_episode_data( $title_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_episode_data} WHERE title_id = %d ORDER BY season_number ASC, episode_number ASC",
            absint( $title_id )
        );
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Retrieves the watch status (tracked episodes) for a specific title.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @return array Array of episode Watchmode IDs (integers).
     */
    public function tvm_tracker_get_watched_episodes( $user_id, $title_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT episode_id FROM {$this->table_episodes} WHERE user_id = %d AND title_id = %d",
            absint( $user_id ),
            absint( $title_id )
        );
        return array_map( 'absint', $wpdb->get_col( $sql ) );
    }
    
    /**
     * Retrieves the count of episodes watched for a specific show.
     * Required by view-poster-view.php and view-list-view.php.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @return int The count of watched episodes.
     */
    public function tvm_tracker_get_watched_episodes_count( $user_id, $title_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(id) FROM {$this->table_episodes} WHERE user_id = %d AND title_id = %d",
            absint( $user_id ),
            absint( $title_id )
        );
        $count = $wpdb->get_var( $sql );
        return absint( $count );
    }
    
    /**
     * Retrieves all episode data (static) and joins it with the user's watch status.
     * Used by the Unwatched Page.
     *
     * @param int $user_id The current user ID.
     * @return array Array of unwatched episode data.
     */
    public function tvm_tracker_get_unwatched_episodes( $user_id ) {
        global $wpdb;
        
        // Step 1: Get all titles tracked by the user
        $tracked_titles_sql = $wpdb->prepare( "SELECT title_id FROM {$this->table_shows} WHERE user_id = %d", absint( $user_id ) );
        $tracked_title_ids = $wpdb->get_col( $tracked_titles_sql );
        
        if ( empty( $tracked_title_ids ) ) {
            return [];
        }

        $title_ids_in = implode( ',', array_map( 'absint', $tracked_title_ids ) );
        
        // Step 2: Query for all episodes for those titles that are NOT in the tracking table
        $sql = "
            SELECT 
                ed.*, 
                s.title_name
            FROM {$this->table_episode_data} ed
            LEFT JOIN {$this->table_shows} s 
                ON ed.title_id = s.title_id AND s.user_id = %d
            WHERE 
                ed.title_id IN ({$title_ids_in})
            AND 
                ed.watchmode_id NOT IN (
                    SELECT episode_id FROM {$this->table_episodes} 
                    WHERE user_id = %d AND title_id = ed.title_id
                )
            ORDER BY ed.air_date ASC
        ";

        $sql = $wpdb->prepare( $sql, absint( $user_id ), absint( $user_id ) );
        return $wpdb->get_results( $sql, ARRAY_A );
    }
    
    /**
     * Retrieves streaming source links for a specific episode.
     *
     * @param int $title_id The Watchmode title ID.
     * @param int $episode_id The Watchmode episode ID.
     * @return array Array of source link data.
     */
    public function tvm_tracker_get_episode_source_links( $title_id, $episode_id ) {
        global $wpdb;
        
        // CRITICAL FIX: Using safer implicit JOIN based on user confirmation
        $sql = $wpdb->prepare(
            "
            SELECT el.web_url, src.source_id, src.source_name, src.logo_url, el.region
            FROM {$this->table_episode_links} el, {$this->table_sources} src
            WHERE 
                el.source_id = src.source_id
            AND
                el.title_id = %d AND el.episode_id = %d
            ",
            absint( $title_id ),
            absint( $episode_id )
        );
        
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    // =======================================================================
    // EPISODE WATCH/UNWATCH TOGGLE (V2.0)
    // =======================================================================

    /**
     * Marks an episode as watched or unwatched.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @param int $episode_id The Watchmode episode ID.
     * @param bool $is_watched True to mark as watched, False to mark as unwatched.
     * @return bool True on success, False on failure.
     */
    public function tvm_tracker_toggle_episode( $user_id, $title_id, $episode_id, $is_watched ) {
        global $wpdb;

        $user_id = absint( $user_id );
        $title_id = absint( $title_id );
        $episode_id = absint( $episode_id );

        if ( $is_watched ) {
            // INSERT: Mark as watched (add row to tracking table)
            // Use REPLACE INTO to ensure idempotency (prevent duplicate inserts if called twice)
            $result = $wpdb->query( $wpdb->prepare(
                "REPLACE INTO {$this->table_episodes} (user_id, title_id, episode_id) VALUES (%d, %d, %d)",
                $user_id,
                $title_id,
                $episode_id
            ) );

        } else {
            // DELETE: Mark as unwatched (remove row from tracking table)
            $result = $wpdb->delete(
                $this->table_episodes,
                array(
                    'user_id'    => $user_id,
                    'title_id'   => $title_id,
                    'episode_id' => $episode_id,
                ),
                array( '%d', '%d', '%d' )
            );
        }
        return $result !== false;
    }

    /**
     * Bulk marks all episodes in a season or series as watched/unwatched.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @param bool $is_watched True to mark as watched, False to mark as unwatched.
     * @param int|null $season_number Optional. If provided, only affects this season.
     * @return bool True on success, False on failure.
     */
    public function tvm_tracker_toggle_bulk_episodes( $user_id, $title_id, $is_watched, $season_number = null ) {
        global $wpdb;

        $user_id = absint( $user_id );
        $title_id = absint( $title_id );

        // 1. Get all episode IDs (watchmode_id) for the target scope (title or season)
        $where_clause = $wpdb->prepare( "title_id = %d", $title_id );
        if ( ! is_null( $season_number ) ) {
            $season_number = absint( $season_number );
            $where_clause .= $wpdb->prepare( " AND season_number = %d", $season_number );
        }
        
        // Fetch episode IDs that have already aired (air_date <= today and not '0000-00-00')
        $today = date_i18n('Y-m-d');
        $airing_clause = $wpdb->prepare( "AND air_date <= %s AND air_date != %s", $today, '0000-00-00' );


        $sql_episodes = "SELECT watchmode_id FROM {$this->table_episode_data} WHERE {$where_clause} {$airing_clause}";
        $episode_ids = $wpdb->get_col( $sql_episodes );

        if ( empty( $episode_ids ) ) {
            // No episodes found or none have aired yet, return success
            return true;
        }

        $episode_ids_in = implode( ',', array_map( 'absint', $episode_ids ) );
        
        if ( $is_watched ) {
            // BULK INSERT (Mark Watched)
            // Use INSERT IGNORE to prevent conflicts with UNIQUE KEY (user_episode)
            $values_sql = [];
            foreach ( $episode_ids as $episode_id ) {
                $values_sql[] = $wpdb->prepare( '(%d, %d, %d)', $user_id, $title_id, absint( $episode_id ) );
            }
            $values_string = implode( ', ', $values_sql );

            $sql_insert = "INSERT IGNORE INTO {$this->table_episodes} (user_id, title_id, episode_id) VALUES {$values_string}";
            $result = $wpdb->query( $sql_insert );

        } else {
            // BULK DELETE (Mark Unwatched)
            $sql_delete = $wpdb->prepare(
                "DELETE FROM {$this->table_episodes} WHERE user_id = %d AND title_id = %d AND episode_id IN ({$episode_ids_in})",
                $user_id,
                $title_id
            );
            $result = $wpdb->query( $sql_delete );
        }

        return $result !== false;
    }
}
