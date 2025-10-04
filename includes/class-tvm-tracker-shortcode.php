<?php
/**
 * TVM Tracker - Shortcode Handler Class
 * Core Shortcode Logic and View Delegation.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 1.1.52
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

    const VERSION = '1.1.52';

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
        wp_enqueue_script( 'tvm-tracker-frontend-js', TVM_TRACKER_URL . 'js/tvm-tracker-frontend.js', array( 'jquery' ), '1.3.4', true );

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
            // CRITICAL: Fetch enabled sources and ensure they are INTEGERS for strict comparison
            $enabled_sources_raw = get_option( 'tvm_tracker_enabled_sources', array() ); 
            $enabled_sources = array_map('absint', (array)$enabled_sources_raw);
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
            } elseif ( ! empty( $action_view ) && $action_view === 'movies' ) { // NEW ROUTE
                // Movie Tracker Page
                require TVM_TRACKER_PATH . 'includes/shortcode-views/view-my-tracker.php';
            } elseif ( ! empty( $action_view ) && $action_view === 'tracker' ) {
                // My Tracker Page (Shows/Base View)
                require TVM_TRACKER_PATH . 'includes/shortcode-views/view-my-tracker.php';
            } elseif ( ! empty( $action_view ) && $action_view === 'unwatched' ) {
                // Unwatched Episodes Page
                require TVM_TRACKER_PATH . 'includes/shortcode-views/view-unwatched-page.php';
            } elseif ( ! empty( $action_view ) && $action_view === 'upcoming' ) {
                 // Upcoming Calendar Page
                require TVM_TRACKER_PATH . 'includes/shortcode-views/view-upcoming-calendar.php';
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
     * Helper function to filter episodes by date type.
     * * @param array $episodes Array of episode data.
     * @param string $type 'upcoming' (>= today) or 'past' (< today).
     * @return array Filtered array of episodes.
     */
    private function tvm_tracker_filter_episodes_by_type( $episodes, $type ) {
        $today = date_i18n('Y-m-d');
        
        $filtered = array_filter( $episodes, function( $episode ) use ( $today, $type ) {
            // Use 'air_date' from the V2.0 database structure
            $air_date = $episode['air_date'] ?? '0000-00-00'; 
            
            // Check for valid date (handles '0000-00-00' and missing dates)
            $has_valid_date = ! empty( $air_date ) && $air_date !== '0000-00-00';

            // Determine upcoming status based on valid date
            if ( $has_valid_date ) {
                $is_upcoming = $air_date >= $today;
            } else {
                // If date is invalid or missing, always treat it as 'past' for viewing logic
                $is_upcoming = false;
            }

            if ( 'upcoming' === $type ) {
                return $is_upcoming;
            } else { // 'past'
                return ! $is_upcoming;
            }
        });
        
        // Re-index the array after filtering
        return array_values($filtered);
    }


    /**
     * AJAX callback to load the next unwatched episode's HTML (for Upcoming)
     * or the full list of past episodes (for Past).
     */
    public function tvm_tracker_load_unwatched_episode_callback() {
        // Dependencies are locally available in this scope:
        $db_client = $this->db_client;
        $api_client = $this->api_client; 
        
        // CRITICAL FIX: Explicitly fetch enabled sources for AJAX scope and cast to integer
        $enabled_sources_raw = get_option( 'tvm_tracker_enabled_sources', array() );
        $enabled_sources = array_map('absint', (array)$enabled_sources_raw); 
        
        // Ensure sources and source map are available for the view file (though source map is mostly unused in V2.0)
        $all_sources = $api_client->tvm_tracker_get_all_sources();
        $source_map = array();
        
        // Include the sources rendering helper function definition
        require_once TVM_TRACKER_PATH . 'includes/shortcode-views/view-render-sources-detail.php'; 

        if ( ! is_wp_error( $all_sources ) && is_array( $all_sources ) ) {
            foreach ( $all_sources as $source ) {
                $source_map[ absint( $source['id'] ) ] = $source;
            }
        }

        header( 'Content-Type: application/json' );

        $nonce = sanitize_text_field( $_POST['nonce'] ?? '' );
        $title_id = absint( $_POST['title_id'] ?? 0 );
        $type = sanitize_text_field( $_POST['type'] ?? 'upcoming' ); // 'upcoming' or 'past'

        if ( ! wp_verify_nonce( $nonce, 'tvm_tracker_ajax_nonce' ) || ! $title_id || ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'tvm-tracker' ) ) );
        }

        $user_id = get_current_user_id();

        // 1. Get ALL unwatched episodes for the specific user
        // We call the method that gets ALL unwatched episodes and manually filter by title_id here.
        $all_unwatched_episodes = $db_client->tvm_tracker_get_unwatched_episodes( $user_id );
        
        // Filter the results down to just the requested title ID
        $title_unwatched_episodes = array_filter($all_unwatched_episodes, function($episode) use ($title_id) {
            return absint($episode['title_id']) === $title_id;
        });
        
        if ( empty( $title_unwatched_episodes ) ) {
            wp_send_json_error( array( 
                'html' => '<p class="tvm-empty-list">' . esc_html__( 'All episodes for this show are watched!', 'tvm-tracker' ) . '</p>',
                'message' => esc_html__( 'All episodes for this show are watched!', 'tvm-tracker' ),
            ) );
        }

        // 2. Filter by Upcoming or Past using the helper function
        $filtered_episodes = $this->tvm_tracker_filter_episodes_by_type($title_unwatched_episodes, $type);
        
        if ( empty( $filtered_episodes ) ) {
            $msg = ( 'upcoming' === $type ) 
                ? esc_html__( 'No upcoming unwatched episodes for this show.', 'tvm-tracker' )
                : esc_html__( 'No past unwatched episodes for this show.', 'tvm-tracker' );
            wp_send_json_success( array( 
                'html' => '<p class="tvm-empty-list">' . $msg . '</p>',
                'message' => $msg,
            ) );
        }

        ob_start();

        // CRITICAL FIX: Explicitly pass objects into view scope
        // This ensures the view files can reliably access $db_client and $api_client
        global $db_client, $api_client, $enabled_sources, $source_map;
        $db_client = $this->db_client;
        $api_client = $this->api_client;
        $enabled_sources = array_map('absint', (array)get_option( 'tvm_tracker_enabled_sources', array() ));
        // $source_map remains defined above

        if ( 'upcoming' === $type ) {
            // Upcoming: Sort by release date (soonest first - ASC)
            usort( $filtered_episodes, function( $a, $b ) {
                return strtotime( $a['air_date'] ) <=> strtotime( $b['air_date'] );
            } );
            
            $upcoming_episodes_list = $filtered_episodes; 
            $view_file = TVM_TRACKER_PATH . 'includes/shortcode-views/view-single-unwatched-episode.php';
            
            // RENDER: Pass all required variables into the view scope
            require $view_file;
            
        } else {
            // Past: Sort by release date (oldest first - ASC)
            usort( $filtered_episodes, function( $a, $b ) {
                return strtotime( $a['air_date'] ) <=> strtotime( $b['air_date'] );
            } );

            $past_episodes_list = $filtered_episodes;
            $view_file = TVM_TRACKER_PATH . 'includes/shortcode-views/view-list-unwatched-past.php';
            
            // RENDER: Pass all required variables into the view scope
            require $view_file;
        }
        
        $html = ob_get_clean();

        wp_send_json_success( array( 
            'html' => $html,
            'message' => esc_html__( 'Episode details loaded successfully.', 'tvm-tracker' ),
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
