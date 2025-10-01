<?php
/**
 * Plugin Name: TVM Tracker
 * Plugin URI:  [Your Plugin URI]
 * Description: A powerful WordPress plugin for tracking TV show and movie watch progress via the Watchmode API.
 * Version:     1.0.20
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

        // Register AJAX endpoints.
        add_action( 'wp_ajax_tvm_tracker_toggle_show', array( $this, 'tvm_tracker_toggle_show_callback' ) );
        add_action( 'wp_ajax_nopriv_tvm_tracker_toggle_show', array( $this, 'tvm_tracker_toggle_show_callback' ) );
        add_action( 'wp_ajax_tvm_tracker_toggle_episode', array( $this, 'tvm_tracker_toggle_episode_callback' ) );
        add_action( 'wp_ajax_nopriv_tvm_tracker_toggle_episode', array( $this, 'tvm_tracker_toggle_episode_callback' ) );
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

        // Instantiate modules, passing dependencies
        if ( $this->api_client ) {
            $this->settings_page = new Tvm_Tracker_Settings( $this->api_client );
        }
        if ( $this->api_client && $this->db_client ) {
            $this->shortcode_handler = new Tvm_Tracker_Shortcode( $this->api_client, $this->db_client );
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

        // Rule for: /page-slug/my-shows/unwatched
        add_rewrite_rule(
            '^([^/]+)/my-shows/unwatched/?$',
            'index.php?pagename=$matches[1]&tvm_action_view=unwatched',
            'top'
        );

        // Rule for: /page-slug/my-shows
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
            $db->tvm_tracker_add_show( $user_id, $title_id, $title_name, $total_episodes );
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
            $db->tvm_tracker_mark_episode_watched( $user_id, $title_id, $episode_id );
            $message = esc_html__( 'Episode marked watched.', 'tvm-tracker' );
            $action = 'watched';
        } else {
            // Mark episode as unwatched
            $db->tvm_tracker_mark_episode_unwatched( $user_id, $title_id, $episode_id );
            $message = esc_html__( 'Episode marked unwatched.', 'tvm-tracker' );
            $action = 'unwatched';
        }

        wp_send_json_success( array( 'message' => $message, 'action' => $action ) );
    }

    /**
     * Uninstall hook to remove options and database tables.
     */
    public static function tvm_tracker_uninstall() {
        if ( class_exists( 'Tvm_Tracker_Installer' ) ) {
            Tvm_Tracker_Installer::tvm_tracker_uninstall();
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
