<?php
/**
 * TVM Tracker - Database Interaction Class
 * Handles all CRUD operations for custom database tables.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 1.1.9
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tvm_Tracker_DB class.
 * Manages interaction with the wp_tvm_tracker_shows and wp_tvm_tracker_episodes tables.
 */
class Tvm_Tracker_DB {

    private $table_shows;
    private $table_episodes;
    private $api_client;


    /**
     * Constructor.
     * Sets up table names.
     */
    public function __construct() {
        global $wpdb;
        // Explicitly define properties to avoid PHP 8.2+ Deprecated warnings
        $this->table_shows = $wpdb->prefix . 'tvm_tracker_shows';
        $this->table_episodes = $wpdb->prefix . 'tvm_tracker_episodes';
    }

    /**
     * Sets the API Client dependency after instantiation.
     *
     * @param Tvm_Tracker_API $api_client The instantiated API client.
     */
    public function tvm_tracker_set_api_client( $api_client ) {
        $this->api_client = $api_client;
    }

    /**
     * Adds a new show to the tracker.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @param string $title_name The show name.
     * @param int $total_episodes The total number of episodes.
     * @return bool|int True on success, False on failure, or row ID on insert.
     */
    public function tvm_tracker_add_show( $user_id, $title_id, $title_name, $total_episodes ) {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table_shows,
            array(
                'user_id' => absint( $user_id ),
                'title_id' => absint( $title_id ),
                'title_name' => sanitize_text_field( $title_name ),
                'total_episodes' => absint( $total_episodes ),
                'tracked_date' => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%d', '%s' )
        );
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Removes a show and all its episodes from the tracker.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @return bool True on success, False on failure.
     */
    public function tvm_tracker_remove_show( $user_id, $title_id ) {
        global $wpdb;

        // 1. Delete all episodes related to the show
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
            // INSERT/REPLACE: Mark as watched
            $result = $wpdb->replace(
                $this->table_episodes,
                array(
                    'user_id' => $user_id,
                    'title_id' => $title_id,
                    'episode_id' => $episode_id,
                    'is_watched' => 1,
                ),
                array( '%d', '%d', '%d', '%d' )
            );
        } else {
            // DELETE: Mark as unwatched (remove row)
            $result = $wpdb->delete(
                $this->table_episodes,
                array(
                    'user_id' => $user_id,
                    'title_id' => $title_id,
                    'episode_id' => $episode_id,
                ),
                array( '%d', '%d', '%d' )
            );
        }
        return $result !== false;
    }

    /**
     * CRITICAL FIX: Alias method required by older version of tvm-tracker.php.
     * This method is an alias for the correct toggle method, assuming the old code
     * was designed to only mark an episode as watched.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @param int $episode_id The Watchmode episode ID.
     * @return bool True on success, False on failure.
     */
    public function tvm_tracker_mark_episode_watched( $user_id, $title_id, $episode_id ) {
        // Assume if the old function name is called, the intent is always to mark as watched (true)
        return $this->tvm_tracker_toggle_episode( $user_id, $title_id, $episode_id, true );
    }

    /**
     * Retrieves the list of all tracked shows for the user.
     *
     * @param int $user_id The current user ID.
     * @return array|object|null Array of tracked show objects, or empty array.
     */
    public function tvm_tracker_get_tracked_shows( $user_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_shows} WHERE user_id = %d",
            absint( $user_id )
        );
        return $wpdb->get_results( $sql );
    }

    /**
     * Retrieves the count of episodes watched for a specific show.
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
     * Retrieves an array of all episode IDs watched for a specific show.
     * This is used for checking the initial state of episode buttons.
     *
     * @param int $user_id The current user ID.
     * @param int $title_id The Watchmode title ID.
     * @return array Array of episode IDs (integers).
     */
    public function tvm_tracker_get_watched_episodes( $user_id, $title_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT episode_id FROM {$this->table_episodes} WHERE user_id = %d AND title_id = %d",
            absint( $user_id ),
            absint( $title_id )
        );
        // Get all episode_id values as a flat array
        $results = $wpdb->get_col( $sql );
        return array_map( 'absint', $results );
    }

    /**
     * Retrieves a list of unwatched episodes for the user.
     *
     * @param int $user_id The current user ID.
     * @return array Array of unwatched episode data.
     */
    public function tvm_tracker_get_unwatched_episodes( $user_id ) {
        global $wpdb;
        $tracked_shows = $this->tvm_tracker_get_tracked_shows( $user_id );
        if ( empty( $tracked_shows ) ) {
            return [];
        }

        // CRITICAL FIX: Ensure $this->api_client is available
        if ( ! $this->api_client ) {
            return [];
        }

        $unwatched_list = [];
        // Fetch all watched episode IDs just once for efficiency
        $watched_episodes_sql = $wpdb->prepare(
            "SELECT episode_id FROM {$this->table_episodes} WHERE user_id = %d",
            absint( $user_id )
        );
        $watched_episode_ids = $wpdb->get_col( $watched_episodes_sql );

        foreach ( $tracked_shows as $show ) {
            // 1. Fetch episodes for the show (cached API call)
            $episodes_data = $this->api_client->tvm_tracker_get_episodes( absint( $show->title_id ) );

            if ( is_wp_error( $episodes_data ) || empty( $episodes_data ) ) {
                continue;
            }

            $title_name = $show->title_name;

            // 2. Filter out episodes that are in the watched list
            foreach ( $episodes_data as $episode ) {
                $episode_id = absint( $episode['id'] );

                // CRITICAL FIX: The shortcode handles Upcoming vs Past rendering.
                // The DB class should only filter out episodes the user has watched.
                if ( ! in_array( $episode_id, $watched_episode_ids, true ) ) {
                     // This is an unwatched episode. Add metadata needed for display.
                    $episode['title_name'] = $title_name;
                    $episode['title_id'] = absint( $show->title_id );
                    $unwatched_list[] = $episode;
                }
            }
        }

        return $unwatched_list;
    }
}
