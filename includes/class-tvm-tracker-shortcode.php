<?php
/**
 * TVM Tracker - Shortcode Handler Class
 * Core Shortcode Logic and View Delegation.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 1.1.39
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Tvm_Tracker_Shortcode' ) ) {

/**
 * Tvm_Tracker_Shortcode class.
 */
class Tvm_Tracker_Shortcode {

    const VERSION = '1.1.39';

    private $api_client;
    private $db_client;

    /**
     * Constructor.
     */
    public function __construct( $api_client, $db_client ) {
        $this->api_client = $api_client;
        $this->db_client  = $db_client;

        add_shortcode( 'tvm_tracker', array( $this, 'tvm_tracker_render' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'tvm_tracker_frontend_enqueue' ) );
        // AJAX handler for loading single episode details in the Unwatched view
        add_action( 'wp_ajax_tvm_tracker_load_unwatched_episode', array( $this, 'tvm_tracker_load_unwatched_episode_callback' ) );
    }

    /**
     * Enqueues frontend scripts and styles.
     */
    public function tvm_tracker_frontend_enqueue() {
        // Enqueue frontend styles
        wp_enqueue_style( 'tvm-tracker-frontend', TVM_TRACKER_URL . 'css/tvm-tracker-frontend.css', array(), self::VERSION );

        // Enqueue frontend script (for AJAX and interaction)
        wp_enqueue_script( 'tvm-tracker-frontend-js', TVM_TRACKER_URL . 'js/tvm-tracker-frontend.js', array( 'jquery' ), '1.3.2', true );

        // Localize script for AJAX calls
        wp_localize_script( 'tvm-tracker-frontend-js', 'tvmTrackerAjax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'tvm_tracker_ajax_nonce' ),
        ) );
    }

    /**
     * Main shortcode rendering function.
     *
     * @param array $atts Shortcode attributes.
     * @return string The HTML output.
     */
    public function tvm_tracker_render( $atts ) {
        if ( ! is_user_logged_in() ) {
            /* translators: 1: opening anchor tag to login page, 2: closing anchor tag */
            return '<p class="tvm-tracker-container">' . sprintf( esc_html__( 'Please %1$slog in%2$s to use the TVM Tracker.', 'tvm-tracker' ), '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">', '</a>' ) . '</p>';
        }

        ob_start();
        ?>
        <div class="tvm-tracker-container">
            <?php
            $title_id = get_query_var( 'tvm_title_id' );
            $action_view = get_query_var( 'tvm_action_view' );

            $search_query = '';
            if ( isset( $_GET['tvm_search'] ) && isset( $_GET['tvm_search_nonce'] ) ) {
                if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['tvm_search_nonce'] ) ), 'tvm_search_nonce_action' ) ) {
                    $search_query = sanitize_text_field( wp_unslash( $_GET['tvm_search'] ) );
                }
            }

            // Define variables for use in included templates
            $api_client = $this->api_client;
            $db_client = $this->db_client;
            $current_user_id = get_current_user_id();
            $permalink = get_permalink();
            $all_sources = $api_client->tvm_tracker_get_all_sources();
            $enabled_sources = get_option( 'tvm_tracker_enabled_sources', array() );
            $source_map = array();

            if ( ! is_wp_error( $all_sources ) && is_array( $all_sources ) ) {
                foreach ( $all_sources as $source ) {
                    $source_map[ absint( $source['id'] ) ] = $source;
                }
            }


            // Render logic delegation
            if ( ! empty( $title_id ) ) {
                // Details Page
                require TVM_TRACKER_PATH . 'includes/shortcode-views/view-details-page.php';
            } elseif ( ! empty( $action_view ) && $action_view === 'tracker' ) {
                // My Tracker Page (List/Poster)
                require TVM_TRACKER_PATH . 'includes/shortcode-views/view-my-tracker.php';
            } elseif ( ! empty( $action_view ) && $action_view === 'unwatched' ) {
                // Unwatched Episodes Page
                require TVM_TRACKER_PATH . 'includes/shortcode-views/view-unwatched-page.php';
            } elseif ( ! empty( $search_query ) ) {
                // Search Results Page
                require TVM_TRACKER_PATH . 'includes/shortcode-views/view-search-results.php';
            } else {
                // Search Form
                require TVM_TRACKER_PATH . 'includes/shortcode-views/view-search-form.php';
            }

            // Always show debug info if enabled for admin users
            $this->tvm_tracker_display_debug_info();
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX callback to load the next unwatched episode's HTML.
     */
    public function tvm_tracker_load_unwatched_episode_callback() {
        // Dependencies are available via $this->db_client and $this->api_client
        $db_client = $this->db_client;
        $api_client = $this->api_client;
        $all_sources = $api_client->tvm_tracker_get_all_sources();
        $enabled_sources = get_option( 'tvm_tracker_enabled_sources', array() );
        $source_map = array();

        if ( ! is_wp_error( $all_sources ) && is_array( $all_sources ) ) {
            foreach ( $all_sources as $source ) {
                $source_map[ absint( $source['id'] ) ] = $source;
            }
        }

        header( 'Content-Type: application/json' );

        $nonce = sanitize_text_field( $_POST['nonce'] ?? '' );
        $title_id = absint( $_POST['title_id'] ?? 0 );

        if ( ! wp_verify_nonce( $nonce, 'tvm_tracker_ajax_nonce' ) || ! $title_id || ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'tvm-tracker' ) ) );
        }

        $user_id = get_current_user_id();

        // 1. Get ALL unwatched episodes for all shows
        $unwatched_episodes_raw = $db_client->tvm_tracker_get_unwatched_episodes( $user_id );
        
        // 2. Filter list to only include episodes from the requested $title_id
        $show_unwatched = array_filter($unwatched_episodes_raw, function($ep) use ($title_id) {
            return absint($ep['title_id']) === $title_id;
        });

        // 3. Sort by release date (soonest first)
        usort( $show_unwatched, function( $a, $b ) {
            return strtotime( $a['release_date'] ) <=> strtotime( $b['release_date'] );
        } );
        
        // 4. Get the NEXT unwatched episode (the first in the sorted array)
        $next_episode = reset($show_unwatched);

        if ( empty( $next_episode ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'All episodes for this show are watched!', 'tvm-tracker' ) ) );
        }
        
        // 5. Render the HTML and return
        // Pass necessary variables to the template file
        ob_start();
        require TVM_TRACKER_PATH . 'includes/shortcode-views/view-single-unwatched-episode.php';
        $html = ob_get_clean();

        wp_send_json_success( array( 
            'html' => $html,
            'message' => esc_html__( 'Episode details loaded.', 'tvm-tracker' ),
        ) );
    }

    /**
     * Displays API URLs called if debug mode is enabled.
     */
    private function tvm_tracker_display_debug_info() {
        $is_debug_enabled = get_option( 'tvm_tracker_debug_mode', 0 );

        if ( $is_debug_enabled && current_user_can( 'manage_options' ) ) {
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

}
