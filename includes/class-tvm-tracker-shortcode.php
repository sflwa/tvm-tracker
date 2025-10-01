<?php
/**
 * TVM Tracker - Shortcode Handler Class
 * Handles the display logic for the [tvm_tracker] shortcode on the frontend.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 1.1.32
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tvm_Tracker_Shortcode class.
 */
class Tvm_Tracker_Shortcode {

    const VERSION = '1.1.32';

    private $api_client;
    private $db_client;

    /**
     * Constructor.
     * Registers the shortcode and instantiates dependencies.
     *
     * @param Tvm_Tracker_API $api_client The instantiated API client.
     * @param Tvm_Tracker_DB $db_client The instantiated DB client.
     */
    public function __construct( $api_client, $db_client ) {
        $this->api_client = $api_client;
        $this->db_client  = $db_client;

        add_shortcode( 'tvm_tracker', array( $this, 'tvm_tracker_render' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'tvm_tracker_frontend_enqueue' ) );
    }

    /**
     * Enqueues frontend scripts and styles.
     */
    public function tvm_tracker_frontend_enqueue() {
        // Enqueue frontend styles
        wp_enqueue_style( 'tvm-tracker-frontend', TVM_TRACKER_URL . 'css/tvm-tracker-frontend.css', array(), self::VERSION );

        // Enqueue frontend script (for AJAX and interaction)
        wp_enqueue_script( 'tvm-tracker-frontend-js', TVM_TRACKER_URL . 'js/tvm-tracker-frontend.js', array( 'jquery' ), '1.3.0', true );

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
            // Check for clean permalink variable set by the rewrite rule
            $title_id = get_query_var( 'tvm_title_id' );
            $action_view = get_query_var( 'tvm_action_view' );

            // Check for traditional search query
            $search_query = '';
            if ( isset( $_GET['tvm_search'] ) && isset( $_GET['tvm_search_nonce'] ) ) {
                if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['tvm_search_nonce'] ) ), 'tvm_search_nonce_action' ) ) {
                    $search_query = sanitize_text_field( wp_unslash( $_GET['tvm_search'] ) );
                }
            }

            if ( ! empty( $title_id ) ) {
                $this->tvm_tracker_render_details_page( absint( $title_id ) );
            } elseif ( ! empty( $action_view ) && $action_view === 'tracker' ) {
                $this->tvm_tracker_render_my_tracker();
            } elseif ( ! empty( $action_view ) && $action_view === 'unwatched' ) {
                $this->tvm_tracker_render_unwatched_page();
            } elseif ( ! empty( $search_query ) ) {
                $this->tvm_tracker_render_search_results( $search_query );
            } else {
                $this->tvm_tracker_render_search_form();
            }

            // Always show debug info if enabled for admin users
            $this->tvm_tracker_display_debug_info();
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the initial search form.
     */
    private function tvm_tracker_render_search_form() {
        $my_tracker_url = trailingslashit( get_permalink() ) . 'my-shows';
        ?>
        <div class="tvm-search-form-header">
            <h3><?php esc_html_e( 'Track Shows & Movies', 'tvm-tracker' ); ?></h3>
            <a href="<?php echo esc_url( $my_tracker_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'My Tracker', 'tvm-tracker' ); ?></a>
        </div>
        <form action="<?php echo esc_url( get_permalink() ); ?>" method="get" class="tvm-search-form">
            <div class="tvm-input-group">
                <input type="text" name="tvm_search" placeholder="<?php esc_attr_e( 'Enter title name...', 'tvm-tracker' ); ?>" required>
                <button type="submit" class="tvm-button tvm-button-search"><?php esc_html_e( 'Search', 'tvm-tracker' ); ?></button>
            </div>
            <?php wp_nonce_field( 'tvm_search_nonce_action', 'tvm_search_nonce' ); ?>
        </form>
        <?php
    }

    /**
     * Renders the user's tracked shows (List or Poster View).
     */
    private function tvm_tracker_render_my_tracker() {
        $current_view = isset( $_GET['tvm_view'] ) ? sanitize_key( $_GET['tvm_view'] ) : 'list';
        $tracked_shows_raw = $this->db_client->tvm_tracker_get_tracked_shows( get_current_user_id() );
        
        // Sort shows alphabetically by name
        if ( ! empty( $tracked_shows_raw ) ) {
            usort( $tracked_shows_raw, function( $a, $b ) {
                return strcmp( $a->title_name, $b->title_name );
            } );
        }

        $back_to_search_url = esc_url( get_permalink() );
        $unwatched_url = trailingslashit( get_permalink() ) . 'my-shows/unwatched';
        $list_view_url = trailingslashit( get_permalink() ) . 'my-shows?tvm_view=list';
        $poster_view_url = trailingslashit( get_permalink() ) . 'my-shows?tvm_view=poster';

        ?>
        <div class="tvm-tracker-list-header">
            <h3><?php esc_html_e( 'My Tracked Shows', 'tvm-tracker' ); ?></h3>
            <div class="tvm-details-actions">
                <a href="<?php echo esc_url( $unwatched_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'Unwatched Episodes', 'tvm-tracker' ); ?></a>
                <a href="<?php echo $back_to_search_url; ?>" class="tvm-button tvm-button-back"><?php esc_html_e( 'Back to Search', 'tvm-tracker' ); ?></a>
            </div>
        </div>

        <?php if ( empty( $tracked_shows_raw ) ) : ?>
            <p><?php esc_html_e( 'You are not currently tracking any shows. Use the search bar to find some!', 'tvm-tracker' ); ?></p>
        <?php else : ?>
            <div class="tvm-view-toggles">
                <a href="<?php echo esc_url( $list_view_url ); ?>" class="tvm-button <?php echo ( $current_view === 'list' ) ? 'tvm-button-active' : 'tvm-button-inactive'; ?>"><?php esc_html_e( 'List View', 'tvm-tracker' ); ?></a>
                <a href="<?php echo esc_url( $poster_view_url ); ?>" class="tvm-button <?php echo ( $current_view === 'poster' ) ? 'tvm-button-active' : 'tvm-button-inactive'; ?>"><?php esc_html_e( 'Poster View', 'tvm-tracker' ); ?></a>
            </div>
            
            <?php if ( $current_view === 'list' ) :
                $this->tvm_tracker_render_list_view( $tracked_shows_raw );
            elseif ( $current_view === 'poster' ) :
                $this->tvm_tracker_render_poster_view( $tracked_shows_raw );
            endif;
        endif;
    }

    /**
     * Renders the tracked shows in list format.
     *
     * @param array $tracked_shows Array of tracked show objects.
     */
    private function tvm_tracker_render_list_view( $tracked_shows ) {
        if ( empty( $tracked_shows ) ) {
            return;
        }
        ?>
        <ul class="tvm-results-list tvm-tracked-list">
            <?php foreach ( $tracked_shows as $show ) : 
                // Calculate progress
                $watched_count = $this->db_client->tvm_tracker_get_watched_episodes_count( get_current_user_id(), absint( $show->title_id ) );
                $total_count = absint( $show->total_episodes );
                $progress = ( $total_count > 0 ) ? round( ( $watched_count / $total_count ) * 100 ) : 0;
                $details_url = trailingslashit( get_permalink() ) . 'details/' . absint( $show->title_id );
            ?>
                <li class="tvm-result-item tvm-tracked-item">
                    <span class="tvm-result-title">
                        <?php echo esc_html( $show->title_name ); ?>
                    </span>
                    <span class="tvm-tracker-progress">
                        <?php
                        /* translators: 1: percentage watched, 2: episodes watched, 3: total episodes */
                        printf( esc_html__( 'Progress: %1$d%% (%2$d / %3$d episodes watched)', 'tvm-tracker' ), $progress, $watched_count, $total_count );
                        ?>
                    </span>
                    <a href="<?php echo esc_url( $details_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'View Details', 'tvm-tracker' ); ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * Renders the tracked shows in poster grid format.
     *
     * @param array $tracked_shows Array of tracked show objects.
     */
    private function tvm_tracker_render_poster_view( $tracked_shows ) {
        if ( empty( $tracked_shows ) ) {
            return;
        }
        
        echo '<div class="tvm-poster-grid">';
        
        foreach ( $tracked_shows as $show ) {
            $title_id = absint( $show->title_id );
            $details_data = $this->api_client->tvm_tracker_get_title_details( $title_id );

            if ( is_wp_error( $details_data ) || empty( $details_data['poster'] ) ) {
                continue; // Skip if we can't get poster data
            }
            
            $watched_count = $this->db_client->tvm_tracker_get_watched_episodes_count( get_current_user_id(), $title_id );
            $total_count = absint( $show->total_episodes );
            $details_url = trailingslashit( get_permalink() ) . 'details/' . $title_id;

            ?>
            <a href="<?php echo esc_url( $details_url ); ?>" class="tvm-poster-item">
                <img src="<?php echo esc_url( $details_data['poster'] ); ?>" alt="<?php echo esc_attr( $show->title_name ) . esc_attr__( ' Poster', 'tvm-tracker' ); ?>">
                
                <div class="tvm-poster-progress-overlay">
                    <span class="tvm-progress-count">
                        <?php echo absint( $watched_count ); ?> / <?php echo absint( $total_count ); ?>
                    </span>
                </div>
            </a>
            <?php
        }
        
        echo '</div>';
    }

    /**
     * Renders the search results.
     *
     * @param string $search_query The query term.
     */
    private function tvm_tracker_render_search_results( $search_query ) {
        $results = $this->api_client->tvm_tracker_search( $search_query );

        /* translators: %s: Search query term */
        echo '<h3>' . sprintf( esc_html__( 'Search Results for: %s', 'tvm-tracker' ), esc_html( $search_query ) ) . '</h3>';

        // Back link
        $my_tracker_url = trailingslashit( get_permalink() ) . 'my-shows';
        echo '<div class="tvm-details-actions" style="justify-content: flex-start; margin-bottom: 20px;">';
        echo '<a href="' . esc_url( $my_tracker_url ) . '" class="tvm-button tvm-button-details">' . esc_html__( 'My Tracker', 'tvm-tracker' ) . '</a>';
        echo '</div>';


        if ( is_wp_error( $results ) ) {
            echo '<p class="tvm-error-message">' . esc_html( $results->get_error_message() ) . '</p>';
            return;
        }

        if ( empty( $results ) ) {
            echo '<p>' . esc_html__( 'No results found. Please try a different search term.', 'tvm-tracker' ) . '</p>';
            return;
        }

        echo '<ul class="tvm-results-list">';
        foreach ( $results as $item ) {
            // Determine icon based on type
            $icon = ( in_array( $item['type'], array( 'tv_series', 'tv_miniseries' ), true ) ) ? '📺' : '🎬';
            $details_url = trailingslashit( get_permalink() ) . 'details/' . absint( $item['id'] );

            // Only display TV shows or movies
            if ( $item['type'] === 'movie' || strpos( $item['type'], 'tv_' ) !== false ) {
                ?>
                <li class="tvm-result-item">
                    <span class="tvm-result-icon"><?php echo $icon; // Safe emoji output ?></span>
                    <span class="tvm-result-title"><?php echo esc_html( $item['name'] ); ?> (<?php echo esc_html( absint( $item['year'] ) ); ?>)</span>
                    <a href="<?php echo esc_url( $details_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'View Details', 'tvm-tracker' ); ?></a>
                </li>
                <?php
            }
        }
        echo '</ul>';
    }

    /**
     * Renders the full details page for a given title.
     *
     * @param int $title_id The Watchmode title ID.
     */
    private function tvm_tracker_render_details_page( $title_id ) {
        // Fetch all required data (cached in API class)
        $details_data = $this->api_client->tvm_tracker_get_title_details( $title_id );
        $seasons_data = $this->api_client->tvm_tracker_get_seasons( $title_id );
        $episodes_data = $this->api_client->tvm_tracker_get_episodes( $title_id );
        $sources_data = $this->api_client->tvm_tracker_get_sources_for_title( $title_id );
        $all_sources = $this->api_client->tvm_tracker_get_all_sources();

        // 1. Handle API Errors or Missing Details
        if ( is_wp_error( $details_data ) ) {
            echo '<p class="tvm-error-message">' . esc_html( $details_data->get_error_message() ) . '</p>';
            return;
        }
        if ( empty( $details_data ) ) {
            echo '<p class="tvm-error-message">' . esc_html__( 'Could not load title details.', 'tvm-tracker' ) . '</p>';
            return;
        }

        // Check tracking status
        $is_tracked = $this->db_client->tvm_tracker_is_show_tracked( get_current_user_id(), $title_id );
        $button_class = $is_tracked ? 'tvm-button-remove' : 'tvm-button-add';
        $button_text = $is_tracked ? esc_html__( 'Tracking', 'tvm-tracker' ) : esc_html__( 'Add to Tracker', 'tvm-tracker' );
        $my_tracker_url = trailingslashit( get_permalink() ) . 'my-shows';
        $back_to_search_url = esc_url( get_permalink() );

        // 2. Render Header (Title, Year, and Actions)
        ?>
        <div class="tvm-details-header">
            <h2><?php echo esc_html( $details_data['title'] ); ?> (<?php echo esc_html( absint( $details_data['year'] ) ); ?>)</h2>
            <div class="tvm-details-actions">
                <a href="<?php echo esc_url( $my_tracker_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'My Tracker', 'tvm-tracker' ); ?></a>
                <a href="<?php echo $back_to_search_url; ?>" class="tvm-button tvm-button-back"><?php esc_html_e( 'Back to Search', 'tvm-tracker' ); ?></a>
                
                <!-- Add/Remove Tracker Button (AJAX Target) -->
                <button type="button" 
                    class="tvm-button <?php echo esc_attr( $button_class ); ?>" 
                    id="tvm-tracker-toggle"
                    data-title-id="<?php echo absint( $title_id ); ?>"
                    data-title-name="<?php echo esc_attr( $details_data['title'] ); ?>"
                    data-total-episodes="<?php echo absint( count( $episodes_data ) ); ?>"
                    data-is-tracked="<?php echo $is_tracked ? 'true' : 'false'; ?>"
                >
                    <?php echo $button_text; ?>
                </button>
            </div>
        </div>

        <div class="tvm-details-content-main">
            <!-- Poster Column (Left Float) -->
            <div class="tvm-poster-column">
                <img src="<?php echo esc_url( $details_data['poster'] ); ?>" alt="<?php echo esc_attr( $details_data['title'] ) . esc_attr__( ' Poster', 'tvm-tracker' ); ?>" class="tvm-poster" onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/300x450/eeeeee/333333?text=' . urlencode( $details_data['title'] ) ); ?>';">
            </div>

            <!-- Info Column (Right Content) -->
            <div class="tvm-info-column">
                <!-- Overview -->
                <p class="tvm-overview"><?php echo esc_html( $details_data['plot_overview'] ); ?></p>

                <!-- Season/Episode Count -->
                <?php
                // Use the count of the successfully loaded episode data for accuracy
                $season_count = is_array( $seasons_data ) ? count( $seasons_data ) : 0;
                $episode_count = is_array( $episodes_data ) ? count( $episodes_data ) : 0;
                ?>
                <h4><?php esc_html_e( 'Count:', 'tvm-tracker' ); ?></h4>
                <p><?php
                    /* translators: 1: number of seasons, 2: total number of episodes */
                    printf( esc_html__( 'This title has %1$d season(s) and %2$d total episode(s) listed.', 'tvm-tracker' ), absint( $season_count ), absint( $episode_count ) );
                ?></p>

                <!-- Streaming Sources -->
                <?php $this->tvm_tracker_render_sources( $sources_data, $all_sources ); ?>
            </div>
        </div>

        <!-- Seasons and Episodes List -->
        <?php $this->tvm_tracker_render_seasons_and_episodes( $title_id, $seasons_data, $episodes_data ); ?>
        <?php
    }

    /**
     * Renders the streaming sources list.
     *
     * @param array $title_sources Sources specific to the title.
     * @param array $all_sources Master list of all sources.
     */
    private function tvm_tracker_render_sources( $title_sources, $all_sources ) {
        if ( ! is_array( $title_sources ) || empty( $title_sources ) ) {
            return;
        }

        $enabled_sources = get_option( 'tvm_tracker_enabled_sources', array() );
        $source_map = array();

        if ( ! is_wp_error( $all_sources ) && is_array( $all_sources ) ) {
            foreach ( $all_sources as $source ) {
                $source_map[ absint( $source['id'] ) ] = $source;
            }
        }
        
        echo '<div class="tvm-source-logos">';
        echo '<h4>' . esc_html__( 'Available on:', 'tvm-tracker' ) . '</h4>';
        $displayed_source_ids = array();

        foreach ( $title_sources as $source ) {
            $source_id = absint( $source['source_id'] );
            
            // Filter by user-enabled sources and prevent duplicates
            if ( in_array( $source_id, $enabled_sources, true ) && ! in_array( $source_id, $displayed_source_ids, true ) ) {
                $logo_url = isset( $source_map[ $source_id ]['logo_100px'] ) ? $source_map[ $source_id ]['logo_100px'] : '';
                $web_url = esc_url( $source['web_url'] ?? '#' );

                if ( ! empty( $logo_url ) && $web_url !== '#' ) {
                    echo '<a href="' . $web_url . '" target="_blank">';
                    echo '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $source['name'] ) . esc_attr__( ' logo', 'tvm-tracker' ) . '" class="tvm-source-logo">';
                    echo '</a>';
                    $displayed_source_ids[] = $source_id;
                }
            }
        }
        echo '</div>';
    }

    /**
     * Renders the collapsible seasons and episodes list.
     *
     * @param int $title_id The Watchmode title ID.
     * @param array $seasons_data Season data.
     * @param array $episodes_data Episode data.
     */
    private function tvm_tracker_render_seasons_and_episodes( $title_id, $seasons_data, $episodes_data ) {
        if ( empty( $seasons_data ) || empty( $episodes_data ) ) {
            return;
        }

        // Map episodes to seasons for easy lookup
        $episodes_by_season = array();
        foreach ( $episodes_data as $episode ) {
            $season_num = absint( $episode['season_number'] ?? $episode['number'] ?? 0 ); // Fallback to 'number' if 'season_number' is missing
            if ( $season_num > 0 ) {
                $episodes_by_season[ $season_num ][] = $episode;
            }
        }

        $watched_episodes = $this->db_client->tvm_tracker_get_watched_episodes( get_current_user_id(), $title_id );
        $all_sources = $this->api_client->tvm_tracker_get_all_sources();
        $enabled_sources = get_option( 'tvm_tracker_enabled_sources', array() );
        $source_map = array();

        if ( ! is_wp_error( $all_sources ) && is_array( $all_sources ) ) {
            foreach ( $all_sources as $source ) {
                $source_map[ absint( $source['id'] ) ] = $source;
            }
        }

        echo '<div class="tvm-seasons-episodes">';
        echo '<h3>' . esc_html__( 'Seasons', 'tvm-tracker' ) . '</h3>';

        foreach ( $seasons_data as $season ) {
            $season_num = absint( $season['number'] ?? 0 );
            $episode_count = absint( $season['episode_count'] ?? 0 );
            $season_episodes = $episodes_by_season[ $season_num ] ?? array();
            
            if ( $season_num === 0 ) {
                continue;
            }
            
            ?>
            <div class="tvm-season" data-season-number="<?php echo absint( $season_num ); ?>">
                <div class="tvm-season-header">
                    <h4>
                        <?php echo esc_html( $season['name'] ); ?>
                        <?php
                        /* translators: %d: number of episodes in the season */
                        printf( esc_html__( ' (%d episodes)', 'tvm-tracker' ), absint( $episode_count ) );
                        ?>
                    </h4>
                </div>
                <div class="tvm-season-content">
                    <?php if ( ! empty( $season['overview'] ) ) : ?>
                        <p><strong><?php esc_html_e( 'Overview:', 'tvm-tracker' ); ?></strong> <?php echo esc_html( $season['overview'] ); ?></p>
                    <?php endif; ?>

                    <?php foreach ( $season_episodes as $episode ) :
                        $episode_id = absint( $episode['id'] );
                        // Check against the array of watched episode IDs (just the IDs, not the full row)
                        $is_watched = in_array( $episode_id, $watched_episodes, true );
                        $release_date = $episode['release_date'] ?? esc_html__( 'TBA', 'tvm-tracker' );
                        $episode_sources = $episode['sources'] ?? array();
                        
                        $button_class = $is_watched ? 'tvm-button-watched' : 'tvm-button-unwatched';
                        $button_text = $is_watched ? esc_html__( 'Watched', 'tvm-tracker' ) : esc_html__( 'Unwatched', 'tvm-tracker' );
                    ?>
                        <div class="tvm-episode" data-episode-id="<?php echo $episode_id; ?>">
                            <div class="tvm-episode-header">
                                <div class="tvm-episode-title-status">
                                    
                                    <button type="button" 
                                        class="tvm-episode-toggle tvm-button <?php echo esc_attr( $button_class ); ?>" 
                                        data-episode-id="<?php echo $episode_id; ?>" 
                                        data-title-id="<?php echo $title_id; ?>" 
                                        data-is-watched="<?php echo $is_watched ? 'true' : 'false'; ?>"
                                    >
                                        <?php echo $button_text; ?>
                                    </button>

                                    <div class="tvm-episode-info">
                                        <span class="tvm-episode-number-title">
                                            <?php echo esc_html( $episode['episode_number'] ); ?>. <?php echo esc_html( $episode['name'] ); ?>
                                            (<?php echo esc_html( date( get_option( 'date_format' ), strtotime( $release_date ) ) ); ?>)
                                        </span>
                                        <div class="tvm-episode-sources-small">
                                            <?php foreach ( $episode_sources as $source ) :
                                                $source_id = absint( $source['source_id'] );
                                                // Display logo only if the source is enabled in the admin settings
                                                if ( in_array( $source_id, $enabled_sources, true ) ) {
                                                    $logo_url = $source_map[ $source_id ]['logo_100px'] ?? '';
                                                    if ( ! empty( $logo_url ) ) : ?>
                                                        <a href="<?php echo esc_url( $source['web_url'] ?? '#' ); ?>" target="_blank">
                                                            <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $source['name'] ) . esc_attr__( ' logo', 'tvm-tracker' ); ?>" class="tvm-episode-source-logo">
                                                        </a>
                                                    <?php endif;
                                                }
                                            endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tvm-episode-content">
                                <div class="tvm-episode-details-inner">
                                    <p><?php echo esc_html( $episode['overview'] ); ?></p>
                                    <?php if ( ! empty( $episode['thumbnail_url'] ) ) : ?>
                                        <p>
                                            <img src="<?php echo esc_url( $episode['thumbnail_url'] ); ?>" alt="<?php echo esc_attr( $episode['name'] ) . esc_attr__( ' Thumbnail', 'tvm-tracker' ); ?>" style="max-width: 250px; border-radius: 4px;">
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
        }
        echo '</div>';
    }

    /**
     * Renders the unwatched episodes list.
     */
    private function tvm_tracker_render_unwatched_page() {
        // Fetch all required data
        $tracked_shows_raw = $this->db_client->tvm_tracker_get_tracked_shows( get_current_user_id() );
        
        $unwatched_episodes = array();
        $today = new DateTime( 'today' );
        
        // Loop through all tracked shows to compile the unwatched episode list
        foreach ( $tracked_shows_raw as $show ) {
            $title_id = absint( $show->title_id );
            $show_name = esc_html( $show->title_name );
            
            $episodes_data = $this->api_client->tvm_tracker_get_episodes( $title_id );
            $watched_episodes = $this->db_client->tvm_tracker_get_watched_episodes( get_current_user_id(), $title_id );

            if ( is_wp_error( $episodes_data ) || empty( $episodes_data ) ) {
                continue;
            }

            foreach ( $episodes_data as $episode ) {
                $episode_id = absint( $episode['id'] );
                if ( ! in_array( $episode_id, $watched_episodes, true ) ) {
                    // This episode is unwatched. Add show name for sorting.
                    $episode['show_name'] = $show_name;
                    $episode['title_id'] = $title_id;

                    $release_date = $episode['release_date'] ?? '';
                    if ( ! empty( $release_date ) ) {
                        $air_date = new DateTime( $release_date );
                        if ( $air_date >= $today ) {
                            $unwatched_episodes['upcoming'][] = $episode;
                        } else {
                            $unwatched_episodes['past'][] = $episode;
                        }
                    } else {
                        // Treat episodes with no date as past/unreleased if not trackable
                        $unwatched_episodes['past'][] = $episode; 
                    }
                }
            }
        }

        // Sorting Logic
        if ( ! empty( $unwatched_episodes['upcoming'] ) ) {
            // Sort upcoming by release date (soonest first)
            usort( $unwatched_episodes['upcoming'], function( $a, $b ) {
                return strtotime( $a['release_date'] ) <=> strtotime( $b['release_date'] );
            } );
        }

        if ( ! empty( $unwatched_episodes['past'] ) ) {
            // Sort past episodes alphabetically by show name
            usort( $unwatched_episodes['past'], function( $a, $b ) {
                return strcmp( $a['show_name'], $b['show_name'] );
            } );
        }

        $my_tracker_url = trailingslashit( get_permalink() ) . 'my-shows';
        $back_to_search_url = esc_url( get_permalink() );

        ?>
        <div class="tvm-tracker-list-header">
            <h3><?php esc_html_e( 'My Unwatched Episodes', 'tvm-tracker' ); ?></h3>
            <div class="tvm-details-actions">
                <a href="<?php echo esc_url( $my_tracker_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'My Tracker', 'tvm-tracker' ); ?></a>
                <a href="<?php echo $back_to_search_url; ?>" class="tvm-button tvm-button-back"><?php esc_html_e( 'Back to Search', 'tvm-tracker' ); ?></a>
            </div>
        </div>
        
        <?php if ( empty( $unwatched_episodes['upcoming'] ) && empty( $unwatched_episodes['past'] ) ) : ?>
            <p><?php esc_html_e( 'You have no unwatched episodes for your tracked shows!', 'tvm-tracker' ); ?></p>
        <?php else : ?>

            <!-- Upcoming Episodes -->
            <?php if ( ! empty( $unwatched_episodes['upcoming'] ) ) : ?>
                <h4><?php esc_html_e( 'Upcoming Episodes (Soonest First)', 'tvm-tracker' ); ?></h4>
                <ul class="tvm-unwatched-list">
                    <?php foreach ( $unwatched_episodes['upcoming'] as $episode ) : ?>
                        <?php $this->tvm_tracker_render_unwatched_item( $episode, false ); ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <!-- Past Episodes -->
            <?php if ( ! empty( $unwatched_episodes['past'] ) ) : ?>
                <h4><?php esc_html_e( 'Past Unwatched Episodes (Ordered by Show)', 'tvm-tracker' ); ?></h4>
                <ul class="tvm-unwatched-list">
                    <?php foreach ( $unwatched_episodes['past'] as $episode ) : ?>
                        <?php $this->tvm_tracker_render_unwatched_item( $episode, true ); ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        <?php endif;
    }

    /**
     * Renders a single unwatched episode item.
     *
     * @param array $episode The episode data array.
     * @param bool $show_button Whether to show the 'Mark Watched' button (only for past episodes).
     */
    private function tvm_tracker_render_unwatched_item( $episode, $show_button = false ) {
        $title_id = absint( $episode['title_id'] );
        $episode_id = absint( $episode['id'] );
        $all_sources = $this->api_client->tvm_tracker_get_all_sources();
        $enabled_sources = get_option( 'tvm_tracker_enabled_sources', array() );
        $source_map = array();

        if ( ! is_wp_error( $all_sources ) && is_array( $all_sources ) ) {
            foreach ( $all_sources as $source ) {
                $source_map[ absint( $source['id'] ) ] = $source;
            }
        }
        
        $release_date = $episode['release_date'] ?? esc_html__( 'TBA', 'tvm-tracker' );

        ?>
        <li class="tvm-unwatched-item" data-episode-id="<?php echo $episode_id; ?>">
            <div class="tvm-unwatched-info">
                <span class="tvm-unwatched-title">
                    <a href="<?php echo trailingslashit( get_permalink() ) . 'details/' . $title_id; ?>" title="<?php echo esc_attr( $episode['show_name'] ); ?>">
                        <?php echo esc_html( $episode['show_name'] ); ?>
                    </a>
                    — S<?php echo absint( $episode['season_number'] ?? $episode['number'] ); ?>E<?php echo absint( $episode['episode_number'] ); ?>: <?php echo esc_html( $episode['name'] ); ?>
                </span>
                
                <span class="tvm-unwatched-date">
                    (<?php echo esc_html( date( get_option( 'date_format' ), strtotime( $release_date ) ) ); ?>)
                </span>
            </div>

            <div class="tvm-unwatched-actions">
                <div class="tvm-episode-sources-small">
                    <?php foreach ( $episode['sources'] ?? array() as $source ) :
                        $source_id = absint( $source['source_id'] );
                        if ( in_array( $source_id, $enabled_sources, true ) ) {
                            $logo_url = $source_map[ $source_id ]['logo_100px'] ?? '';
                            if ( ! empty( $logo_url ) ) : ?>
                                <a href="<?php echo esc_url( $source['web_url'] ?? '#' ); ?>" target="_blank">
                                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $source['name'] ) . esc_attr__( ' logo', 'tvm-tracker' ); ?>" class="tvm-episode-source-logo">
                                </a>
                            <?php endif;
                        }
                    endforeach; ?>
                </div>

                <?php if ( $show_button ) : ?>
                    <button type="button" 
                        class="tvm-unwatched-toggle tvm-button tvm-button-add" 
                        data-episode-id="<?php echo $episode_id; ?>" 
                        data-title-id="<?php echo $title_id; ?>" 
                        data-is-watched="true"
                    >
                        <?php esc_html_e( 'Mark Watched', 'tvm-tracker' ); ?>
                    </button>
                <?php endif; ?>
            </div>
            <!-- Localized status message target -->
            <div class="tvm-unwatched-status"></div>
        </li>
        <?php
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
