<?php
/**
 * Plugin Name: TVM Tracker
 * Plugin URI:  [Your Plugin URI]
 * Description: A powerful WordPress plugin for tracking TV show and movie watch progress via the Watchmode API.
 * Version:     2.0.1
 * Author:      [Your Name]
 * Author URI:  [Your Website]
 * Text Domain: tvm-tracker
 * Domain Path: /languages
 *
 * @package Tvm_Tracker
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants.
if ( ! defined( 'TVM_TRACKER_PATH' ) ) {
    define( 'TVM_TRACKER_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'TVM_TRACKER_URL' ) ) {
    define( 'TVM_TRACKER_URL', plugin_dir_url( __FILE__ ) );
}

// Include dependencies globally to ensure activation hooks and class instantiation works correctly.
require_once TVM_TRACKER_PATH . 'includes/class-tvm-tracker-installer.php';
require_once TVM_TRACKER_PATH . 'includes/class-tvm-tracker-api.php';
require_once TVM_TRACKER_PATH . 'includes/class-tvm-tracker-db.php';
require_once TVM_TRACKER_PATH . 'includes/class-tvm-tracker-settings.php';
require_once TVM_TRACKER_PATH . 'includes/class-tvm-tracker-shortcode.php';


/**
 * The core plugin class.
 */
class Tvm_Tracker_Plugin {

    /**
     * The unique instance of the plugin.
     *
     * @var Tvm_Tracker_Plugin
     */
    protected static $instance = null;

    /**
     * @var Tvm_Tracker_API
     */
    private $api_client;

    /**
     * @var Tvm_Tracker_DB
     */
    private $db_client;

    /**
     * @var Tvm_Tracker_Settings
     */
    private $settings_page;

    /**
     * @var Tvm_Tracker_Shortcode
     */
    private $shortcode_handler;

    /**
     * Ensures only one instance of the plugin exists.
     *
     * @return Tvm_Tracker_Plugin
     */
    public static function tvm_tracker_get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Returns the database client instance.
     *
     * @return Tvm_Tracker_DB
     */
    public function get_db_client() {
        return $this->db_client;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        $this->tvm_tracker_define_hooks();
    }

    /**
     * Defines all core hooks and actions.
     */
    private function tvm_tracker_define_hooks() {
        // Register activation/deactivation hooks.
        register_deactivation_hook( __FILE__, array( 'Tvm_Tracker_Installer', 'tvm_tracker_uninstall' ) );

        // Action to instantiate all main classes after plugins are loaded.
        add_action( 'plugins_loaded', array( $this, 'tvm_tracker_instantiate_classes' ) );

        // Add rewrite rules on initialization (fixes Fatal Error: add_rule() on null).
        add_action( 'init', array( $this, 'tvm_tracker_add_rewrite_rules' ) );

        // Add query vars for clean permalinks.
        add_filter( 'query_vars', array( $this, 'tvm_tracker_add_query_vars' ) );
        
        // CRITICAL FIX: Trigger background episode update check on page load.
        add_action( 'wp', array( $this, 'tvm_tracker_schedule_update_check' ) );

        // Register AJAX endpoints.
        add_action( 'wp_ajax_tvm_tracker_toggle_show', array( $this, 'tvm_tracker_toggle_show_callback' ) );
        add_action( 'wp_ajax_nopriv_tvm_tracker_toggle_show', array( $this, 'tvm_tracker_toggle_show_callback' ) );
        add_action( 'wp_ajax_tvm_tracker_toggle_episode', array( $this, 'tvm_tracker_toggle_episode_callback' ) );
        add_action( 'wp_ajax_nopriv_tvm_tracker_toggle_episode', array( $this, 'tvm_tracker_toggle_episode_callback' ) );
        add_action( 'wp_ajax_tvm_tracker_toggle_movie_watched', array( $this, 'tvm_tracker_toggle_movie_watched_callback' ) ); // NEW
        add_action( 'wp_ajax_nopriv_tvm_tracker_toggle_movie_watched', array( $this, 'tvm_tracker_toggle_movie_watched_callback' ) ); // NEW
        add_action( 'wp_ajax_tvm_tracker_toggle_bulk_episodes', array( $this, 'tvm_tracker_toggle_bulk_episodes_callback' ) ); // NEW
        add_action( 'wp_ajax_nopriv_tvm_tracker_toggle_bulk_episodes', array( $this, 'tvm_tracker_toggle_bulk_episodes_callback' ) ); // NEW
    }

    /**
     * Instantiates the core plugin classes (API, DB, Settings, Shortcode).
     */
    public function tvm_tracker_instantiate_classes() {
        // Instantiating clients first for dependency injection
        if ( class_exists( 'Tvm_Tracker_API' ) ) {
            $this->api_client = new Tvm_Tracker_API();
        }
        if ( class_exists( 'Tvm_Tracker_DB' ) ) {
            $this->db_client = new Tvm_Tracker_DB();
        }
        
        // CRITICAL: Set the API client on the DB client for V2.0 data population
        if ( $this->db_client && $this->api_client ) {
            $this->db_client->tvm_tracker_set_api_client( $this->api_client );
        }

        // Instantiate modules, passing dependencies
        if ( $this->api_client ) {
            $this->settings_page = new Tvm_Tracker_Settings( $this->api_client );
        }
        if ( $this->api_client && $this->db_client ) {
            $this->shortcode_handler = new Tvm_Tracker_Shortcode( $this->api_client, $this->db_client );
        }
    }
    
    /**
     * Executes the weekly update check if needed.
     * Hooked to 'wp' action to ensure everything is loaded but only runs once per user session.
     */
    public function tvm_tracker_schedule_update_check() {
        if ( $this->db_client ) {
            // The DB method handles the 7-day frequency check internally.
            $this->db_client->tvm_tracker_check_for_new_episodes();
        }
    }

    /**
     * Adds custom rewrite rules for clean permalinks.
     */
    public function tvm_tracker_add_rewrite_rules() {
        global $wp_rewrite;
        if ( empty( $wp_rewrite->permalink_structure ) ) {
            return;
        }

        // Rule for: /page-slug/details/{title_id}
        add_rewrite_rule(
            '^([^/]+)/details/([0-9]+)/?$',
            'index.php?pagename=$matches[1]&tvm_title_id=$matches[2]',
            'top'
        );
        
        // CRITICAL FIX: Rule for: /page-slug/my-shows/movies (Movie Tracker)
        add_rewrite_rule(
            '^([^/]+)/my-shows/movies/?$',
            'index.php?pagename=$matches[1]&tvm_action_view=movies',
            'top'
        );

        // Rule for: /page-slug/my-shows/upcoming/agenda (Agenda View)
        // MUST BE FIRST for highest priority
        add_rewrite_rule(
            '^([^/]+)/my-shows/upcoming/agenda/?$',
            'index.php?pagename=$matches[1]&tvm_action_view=upcoming&tvm_calendar_view=agenda',
            'top'
        );
        
        // Rule for: /page-slug/my-shows/upcoming (Calendar View - Base URL)
        add_rewrite_rule(
            '^([^/]+)/my-shows/upcoming/?$',
            'index.php?pagename=$matches[1]&tvm_action_view=upcoming',
            'top'
        );

        // Rule for: /page-slug/my-shows/unwatched
        add_rewrite_rule(
            '^([^/]+)/my-shows/unwatched/?$',
            'index.php?pagename=$matches[1]&tvm_action_view=unwatched',
            'top'
        );

        // Rule for: /page-slug/my-shows (Base Tracker View)
        add_rewrite_rule(
            '^([^/]+)/my-shows/?$',
            'index.php?pagename=$matches[1]&tvm_action_view=tracker',
            'top'
        );
    }

    /**
     * Adds custom query variables to WordPress.
     *
     * @param array $vars Existing query variables.
     * @return array
     */
    public function tvm_tracker_add_query_vars( $vars ) {
        $vars[] = 'tvm_title_id';
        $vars[] = 'tvm_action_view';
        $vars[] = 'tvm_view'; // Required for list/poster view toggle
        $vars[] = 'tvm_calendar_view'; // Required for calendar/agenda view toggle
        $vars[] = 'tvm_movie_tab'; // Required for movie tab view
        return $vars;
    }

    /**
     * AJAX callback to toggle a show as tracked/untracked.
     * wp_ajax_tvm_tracker_toggle_show
     * wp_ajax_nopriv_tvm_tracker_toggle_show
     */
    public function tvm_tracker_toggle_show_callback() {
        // Must be logged in to track a show
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Authentication required.', 'tvm-tracker' ) ) );
        }

        $user_id = get_current_user_id();

        // 1. Nonce Verification
        if ( ! check_ajax_referer( 'tvm_tracker_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'tvm-tracker' ) ) );
        }

        // 2. Parameter Validation
        $title_id = isset( $_POST['title_id'] ) ? absint( $_POST['title_id'] ) : 0;
        $is_tracking = isset( $_POST['is_tracking'] ) ? sanitize_text_field( wp_unslash( $_POST['is_tracking'] ) ) : 'false';
        $title_name = isset( $_POST['title_name'] ) ? sanitize_text_field( wp_unslash( $_POST['title_name'] ) ) : '';
        $total_episodes = isset( $_POST['total_episodes'] ) ? absint( $_POST['total_episodes'] ) : 0;
        // Movie Tracking Fields
        $item_type = isset( $_POST['item_type'] ) ? sanitize_text_field( wp_unslash( $_POST['item_type'] ) ) : 'tv_series';
        $release_date = isset( $_POST['release_date'] ) ? sanitize_text_field( wp_unslash( $_POST['release_date'] ) ) : null;
        
        // Determine the boolean status for movie watched (true if string 'true' is received)
        // This resolves the issue where 'Seen It' button didn't set is_watched=1 on add.
        $is_movie_watched = isset( $_POST['is_movie_watched'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['is_movie_watched'] ) );


        if ( empty( $title_id ) || empty( $title_name ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid request parameters.', 'tvm-tracker' ) ) );
        }

        $db = $this->db_client;
        $action = '';

        if ( 'true' === $is_tracking ) {
            // User is currently tracking the show, so we remove it (toggle off)
            $db->tvm_tracker_remove_show( $user_id, $title_id );
            $message = esc_html__( 'Show removed from tracker.', 'tvm-tracker' );
            $action = 'removed';
        } else {
            // User is not tracking the show, so we add it (toggle on)
            // CRITICAL FIX: Pass the movie watched status ($is_movie_watched) to the add function.
            $db->tvm_tracker_add_show( $user_id, $title_id, $title_name, $total_episodes, $item_type, $release_date, $is_movie_watched );
            $message = esc_html__( 'Show added to tracker!', 'tvm-tracker' );
            $action = 'added';
        }

        wp_send_json_success( array( 'message' => $message, 'action' => $action ) );
    }

    /**
     * AJAX callback to toggle an episode watched/unwatched.
     * wp_ajax_tvm_tracker_toggle_episode
     * wp_ajax_nopriv_tvm_tracker_toggle_episode
     */
    public function tvm_tracker_toggle_episode_callback() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Authentication required.', 'tvm-tracker' ) ) );
        }

        $user_id = get_current_user_id();

        // 1. Nonce Verification
        if ( ! check_ajax_referer( 'tvm_tracker_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'tvm-tracker' ) ) );
        }

        // 2. Parameter Validation
        $title_id = isset( $_POST['title_id'] ) ? absint( $_POST['title_id'] ) : 0;
        $episode_id = isset( $_POST['episode_id'] ) ? absint( $_POST['episode_id'] ) : 0;
        // is_watched is sent as 'true' or 'false' string from JS
        $is_watched = isset( $_POST['is_watched'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['is_watched'] ) );

        // CRITICAL: Ensure both IDs are non-zero
        if ( empty( $title_id ) || empty( $episode_id ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid episode or title ID.', 'tvm-tracker' ) ) );
        }
        
        $db = $this->db_client;
        $action = '';

        // Check if the show is tracked first (essential for database integrity)
        if ( ! $db->tvm_tracker_is_show_tracked( $user_id, $title_id ) ) {
            // If the show isn't tracked, we can't mark an episode.
            wp_send_json_error( array( 'message' => esc_html__( 'Show must be added to tracker first.', 'tvm-tracker' ) ) );
        }

        if ( $is_watched ) {
            // Mark episode as watched
            $db->tvm_tracker_toggle_episode( $user_id, $title_id, $episode_id, true );
            $message = esc_html__( 'Episode marked watched.', 'tvm-tracker' );
            $action = 'watched';
        } else {
            // Mark episode as unwatched
            $db->tvm_tracker_toggle_episode( $user_id, $title_id, $episode_id, false );
            $message = esc_html__( 'Episode marked unwatched.', 'tvm-tracker' );
            $action = 'unwatched';
        }

        wp_send_json_success( array( 'message' => $message, 'action' => $action ) );
    }

    /**
     * AJAX callback to toggle a movie's watched/unwatched status.
     * wp_ajax_tvm_tracker_toggle_movie_watched
     */
    public function tvm_tracker_toggle_movie_watched_callback() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Authentication required.', 'tvm-tracker' ) ) );
        }

        $user_id = get_current_user_id();

        // 1. Nonce Verification
        if ( ! check_ajax_referer( 'tvm_tracker_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'tvm-tracker' ) ) );
        }

        // 2. Parameter Validation
        $title_id = isset( $_POST['title_id'] ) ? absint( $_POST['title_id'] ) : 0;
        // is_watched is sent as 'true' or 'false' string from JS
        $is_watched = isset( $_POST['is_watched'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['is_watched'] ) );

        if ( empty( $title_id ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid title ID.', 'tvm-tracker' ) ) );
        }

        $db = $this->db_client;
        $action = $is_watched ? 'watched' : 'unwatched';
        $message = $is_watched ? esc_html__( 'Movie status updated to: Seen It.', 'tvm-tracker' ) : esc_html__( 'Movie status updated to: Want to See.', 'tvm-tracker' );

        // 3. Perform the DB update (method defined in class-tvm-tracker-db.php)
        $success = $db->tvm_tracker_toggle_movie_watched( $user_id, $title_id, $is_watched );

        if ( $success ) {
            wp_send_json_success( array( 'message' => $message, 'action' => $action ) );
        } else {
            wp_send_json_error( array( 'message' => esc_html__( 'Failed to update movie status in database.', 'tvm-tracker' ) ) );
        }
    }
    
    /**
     * AJAX callback to toggle all episodes in a season or a series watched/unwatched.
     * wp_ajax_tvm_tracker_toggle_bulk_episodes
     */
    public function tvm_tracker_toggle_bulk_episodes_callback() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Authentication required.', 'tvm-tracker' ) ) );
        }

        $user_id = get_current_user_id();

        // 1. Nonce Verification
        if ( ! check_ajax_referer( 'tvm_tracker_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'tvm-tracker' ) ) );
        }

        // 2. Parameter Validation
        $title_id = isset( $_POST['title_id'] ) ? absint( $_POST['title_id'] ) : 0;
        $target_type = isset( $_POST['target_type'] ) ? sanitize_text_field( wp_unslash( $_POST['target_type'] ) ) : ''; // 'series' or 'season'
        $season_number = isset( $_POST['season_number'] ) ? absint( $_POST['season_number'] ) : 0;
        $is_watched = isset( $_POST['is_watched'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['is_watched'] ) );

        if ( empty( $title_id ) || ( $target_type === 'season' && empty( $season_number ) ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid request parameters for bulk update.', 'tvm-tracker' ) ) );
        }

        $db = $this->db_client;
        $success = false;

        if ($target_type === 'series') {
            $success = $db->tvm_tracker_toggle_series_bulk( $user_id, $title_id, $is_watched );
            
            // FIX: Added translator comment before sprintf for watched state
            /* translators: 1: 'watched' or 'unwatched' status verb */
            $message = $is_watched 
                ? esc_html__('All airing episodes marked watched.', 'tvm-tracker') 
                : esc_html__('All episodes marked unwatched.', 'tvm-tracker');
        } elseif ($target_type === 'season') {
            $success = $db->tvm_tracker_toggle_season_bulk( $user_id, $title_id, $season_number, $is_watched );
            
            // FIX: Added translator comment before sprintf for watched state
            /* translators: 1: Season number, 2: 'watched' or 'unwatched' status verb */
            $message = $is_watched 
                ? sprintf(
                    /* Translators: %d is the season number. */
                    esc_html__("All airing episodes in Season %d marked watched.", 'tvm-tracker'), 
                    $season_number
                )
                : sprintf(
                    /* Translators: %d is the season number. */
                    esc_html__("All episodes in Season %d marked unwatched.", 'tvm-tracker'), 
                    $season_number
                );
        } else {
             wp_send_json_error( array( 'message' => esc_html__( 'Invalid bulk target type.', 'tvm-tracker' ) ) );
        }


        if ( $success ) {
            wp_send_json_success( array( 'message' => $message, 'action' => $is_watched ? 'watched' : 'unwatched' ) );
        } else {
             wp_send_json_error( array( 'message' => esc_html__( 'Bulk update failed.', 'tvm-tracker' ) ) );
        }
    }


    /**
     * Displays API URLs called if debug mode is enabled.
     */
    private function tvm_tracker_display_debug_info() {
        $is_debug_enabled = get_option( 'tvm_tracker_debug_mode', 0 );

        if ( current_user_can( 'manage_options' ) ) {
            $urls = $this->api_client->tvm_tracker_get_api_urls_called();

            echo '<div class="tvm-debug-box">';
            echo '<h4>' . esc_html__( 'DEBUG MODE (API Calls):', 'tvm-tracker' ) . '</h4>';

            if ( ! empty( $urls ) ) {
                echo '<ol>';
                foreach ( $urls as $url ) {
                    echo '<li>' . esc_html( $url ) . '</li>';
                }
                echo '</ol>';
            } else {
                echo '<p>' . esc_html__( 'No API calls logged on this page.', 'tvm-tracker' ) . '</p>';
            }
            echo '</div>';
        }
    }
}


/**
 * Registers the installer hook (must be outside class for activation).
 */
if ( class_exists( 'Tvm_Tracker_Installer' ) ) {
    register_activation_hook( __FILE__, array( 'Tvm_Tracker_Installer', 'tvm_tracker_install' ) );
}


/**
 * Begins execution of the plugin.
 */
function tvm_tracker_run() {
    Tvm_Tracker_Plugin::tvm_tracker_get_instance();
}
tvm_tracker_run();
