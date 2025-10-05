<?php
/**
 * Shortcode View: Tracked Movies - Future/Past View
 *
 * @var string $permalink Base permalink for the page.
 * @var Tvm_Tracker_API $api_client
 * @var Tvm_Tracker_DB $db_client
 * @var int $current_user_id Current user ID.
 * @var array $source_map Array of all available sources.
 * @var array $enabled_sources Array of enabled source IDs.
 */

// CRITICAL FIX: Ensure source map and enabled sources are available in this view's scope AND are arrays.
// This prevents the Fatal Error in view-render-sources-detail.php.
global $source_map, $enabled_sources;
global $wpdb; // Required for manual DB interaction if map is empty

// Defensive assignment: Initialize to empty array if not set or not an array.
$source_map = is_array( $source_map ) ? $source_map : [];
$enabled_sources = is_array( $enabled_sources ) ? $enabled_sources : [];

// --- CRITICAL FIX: Populate Source Map if it's empty in this scope ---
// This ensures that even if the main controller (Tvm_Tracker_Shortcode) didn't populate $source_map yet,
// we pull the necessary logo URLs from the local DB to render sources in the movie list.
if (empty($source_map) && class_exists('Tvm_Tracker_DB')) {
    $table_name_sources = $wpdb->prefix . 'tvm_tracker_sources';
    
    // We explicitly use $wpdb here since Tvm_Tracker_DB doesn't have a public getter for all sources in the necessary format.
    $sources_results = $wpdb->get_results( "SELECT source_id, source_name, logo_url FROM $table_name_sources", ARRAY_A );
    
    if (is_array($sources_results)) {
        foreach ($sources_results as $source) {
            // Rebuild $source_map in the expected format (ID => details)
            $source_map[absint($source['source_id'])] = [
                'name' => $source['source_name'],
                'logo_100px' => $source['logo_url'], // Using key expected by view-render-sources-detail
                // Add back source_id to prevent PHP warnings later
                'id' => absint($source['source_id'])
            ];
        }
    }
}
// --------------------------------------------------------------------

// CRITICAL FIX: Include source rendering helper for the Want To See list
require_once TVM_TRACKER_PATH . 'includes/shortcode-views/view-render-sources-detail.php';

global $wp_query;
$today = date_i18n('Y-m-d');

// --- 1. Determine Active Tab & Base URL ---
$base_url = trailingslashit( $permalink ) . 'my-shows/movies';
$current_tab = get_query_var( 'tvm_movie_tab' ) ?: 'want_to_see'; // Default to "Want to See"

// --- 2. Fetch all tracked movies ---
$tracked_movies_raw = $db_client->tvm_tracker_get_tracked_movies( $current_user_id );

// --- 3. Inject details, categorize, and calculate stats ---
$future_movies = [];
$want_to_see_movies = [];
$watched_movies = [];

// Initialize stats counters
$upcoming_count = 0;
$want_to_see_count = 0;
$watched_count = 0;

// Note: Using array_map to process all movies and populate the categorized lists simultaneously
array_map( function( $movie ) use ( $api_client, $today, &$future_movies, &$want_to_see_movies, &$watched_movies, &$upcoming_count, &$want_to_see_count, &$watched_count ) {
    $title_id = absint( $movie->title_id );
    $details = $api_client->tvm_tracker_get_title_details( $title_id );
    $sources = $api_client->tvm_tracker_get_sources_for_title( $title_id ); // Fetch title sources

    $movie->poster = is_array( $details ) ? esc_url( $details['poster'] ?? '' ) : '';
    $movie->year = is_array( $details ) ? absint( $details['year'] ?? 0 ) : 0;
    $movie->sources = is_array( $sources ) ? $sources : [];
    
    $is_watched = (bool)($movie->is_watched ?? false);

    // Calculate Days Until/Past
    $movie->days_until = null;
    $movie->is_future = false;
    
    if (!empty($movie->release_date) && $movie->release_date !== '0000-00-00') {
        $release_ts = strtotime($movie->release_date);
        $today_ts = strtotime($today);
        $diff_seconds = $release_ts - $today_ts;
        $diff_days = round($diff_seconds / (60 * 60 * 24));
        
        $movie->days_until = $diff_days;
        $movie->is_future = $diff_days >= 0;
    } 
    
    // Categorize and count
    if ($movie->is_future) {
        $future_movies[] = $movie;
        $upcoming_count++;
    } elseif ($is_watched) {
        $watched_movies[] = $movie;
        $watched_count++;
    } else {
        $want_to_see_movies[] = $movie;
        $want_to_see_count++;
    }

    return $movie;
}, $tracked_movies_raw );


// Sort Future Movies: Soonest release date first
usort($future_movies, fn($a, $b) => $a->days_until <=> $b->days_until);
// Sort Watched Movies/Want To See: By title name
usort($watched_movies, fn($a, $b) => strcasecmp($a->title_name, $b->title_name));
usort($want_to_see_movies, fn($a, $b) => strcasecmp($a->title_name, $b->title_name));


// Check if any movies are tracked at all
if ( empty( $tracked_movies_raw ) ) : ?>
    <p><?php esc_html_e( 'You are not currently tracking any movies.', 'tvm-tracker' ); ?></p>
    <?php return;
endif; ?>

<!-- --- MOVIE STATS DASHBOARD --- -->
<div class="tvm-global-stats-dashboard">
    <div class="tvm-stat-item">
        <h4 class="tvm-stat-label"><?php esc_html_e('Upcoming Movies', 'tvm-tracker'); ?></h4>
        <div class="tvm-stat-number"><?php echo absint($upcoming_count); ?></div>
    </div>
    <div class="tvm-stat-item">
        <h4 class="tvm-stat-label"><?php esc_html_e('Movies to Watch', 'tvm-tracker'); ?></h4>
        <div class="tvm-stat-number"><?php echo absint($want_to_see_count); ?></div>
    </div>
    <div class="tvm-stat-item">
        <h4 class="tvm-stat-label"><?php esc_html_e('Movies I\'ve Seen', 'tvm-tracker'); ?></h4>
        <div class="tvm-stat-number"><?php echo absint($watched_count); ?></div>
    </div>
</div>


<!-- --- MOVIE TAB NAVIGATION --- -->
<div class="tvm-movie-tabs" id="tvm-movie-tabs">
    <button class="tvm-movie-tab-btn <?php echo ( $current_tab === 'upcoming' ) ? 'is-active' : ''; ?>" data-tab="upcoming">
        <?php /* Translators: %d is the number of upcoming movies */ printf(esc_html__('Upcoming (%d)', 'tvm-tracker'), absint($upcoming_count)); ?>
    </button>
    <button class="tvm-movie-tab-btn <?php echo ( $current_tab === 'want_to_see' ) ? 'is-active' : ''; ?>" data-tab="want_to_see">
        <?php /* Translators: %d is the number of movies marked "Want to See" */ printf(esc_html__('Movies to Watch (%d)', 'tvm-tracker'), absint($want_to_see_count)); ?>
    </button>
    <button class="tvm-movie-tab-btn <?php echo ( $current_tab === 'watched' ) ? 'is-active' : ''; ?>" data-tab="watched">
        <?php /* Translators: %d is the number of movies marked "Watched" */ printf(esc_html__('Watched Movies (%d)', 'tvm-tracker'), absint($watched_count)); ?>
    </button>
</div>

<div class="tvm-movie-content-wrapper">

    <!-- --- 1. UPCOMING MOVIES TAB --- -->
    <div class="tvm-tab-content <?php echo ( $current_tab === 'upcoming' ) ? 'is-active' : ''; ?>" id="tvm-tab-upcoming">
        <?php if ( empty( $future_movies ) ) : ?>
            <p><?php esc_html_e( 'No upcoming movies currently tracked.', 'tvm-tracker' ); ?></p>
        <?php else : ?>
            <div class="tvm-poster-grid">
                <?php foreach ( $future_movies as $movie ) :
                    $release_ts = strtotime($movie->release_date);
                    // CRITICAL FIX: Replaced date() with date_i18n() for WordPress compatibility.
                    $release_date_display = date_i18n( get_option( 'date_format' ), $release_ts );
                    
                    if ($movie->days_until === 0) {
                        $overlay_text = esc_html__('TODAY', 'tvm-tracker');
                    } elseif ($movie->days_until === 1) {
                        $overlay_text = esc_html__('1 DAY', 'tvm-tracker');
                    } else {
                        /* Translators: %d is the number of days until the movie releases */
                        $overlay_text = sprintf(esc_html__('%d DAYS', 'tvm-tracker'), $movie->days_until);
                    }
                    
                    $details_url = trailingslashit( $permalink ) . 'details/' . absint( $movie->title_id );
                    ?>
                    <a href="<?php echo esc_url( $details_url ); ?>" class="tvm-poster-item">
                        <img src="<?php echo esc_url( $movie->poster ); ?>" alt="<?php echo esc_attr($movie->title_name) . esc_attr__( ' Poster', 'tvm-tracker' ); ?>" onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/200x300/eeeeee/333333?text=' . urlencode( $movie->title_name ) ); ?>';">

                        <!-- Countdown Overlay -->
                        <div class="tvm-poster-progress-overlay movie-countdown" title="<?php /* Translators: %s is the release date */ printf(esc_attr__('Releases on %s', 'tvm-tracker'), $release_date_display); ?>">
                            <?php echo $overlay_text; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- --- 2. MOVIES TO WATCH TAB (New List Layout with Sources) --- -->
    <div class="tvm-tab-content <?php echo ( $current_tab === 'want_to_see' ) ? 'is-active' : ''; ?>" id="tvm-tab-want_to_see">
        <?php if ( empty( $want_to_see_movies ) ) : ?>
            <p><?php esc_html_e( 'You have no movies marked "Want to See".', 'tvm-tracker' ); ?></p>
        <?php else : ?>
            <div class="tvm-movie-list-grid">
                <?php foreach ( $want_to_see_movies as $movie ) :
                    $details_url = trailingslashit( $permalink ) . 'details/' . absint( $movie->title_id );
                    
                    ?>
                    <div class="tvm-movie-list-item">
                        <a href="<?php echo esc_url( $details_url ); ?>">
                            <img src="<?php echo esc_url( $movie->poster ); ?>" 
                                alt="<?php echo esc_attr($movie->title_name) . esc_attr__( ' Poster', 'tvm-tracker' ); ?>" 
                                class="tvm-movie-list-poster" 
                                onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/100x150/eeeeee/333333?text=' . urlencode( $movie->title_name ) ); ?>';">
                        </a>

                        <div class="tvm-movie-list-info">
                            <h4>
                                <a href="<?php echo esc_url( $details_url ); ?>"><?php echo esc_html($movie->title_name); ?></a> 
                                (<?php echo esc_html($movie->year); ?>)
                            </h4>
                            
                            <!-- Tracking Status Toggle Button -->
                            <button type="button" 
                                class="tvm-button tvm-button-watched" 
                                data-title-id="<?php echo absint( $movie->title_id ); ?>"
                                data-is-watched="false"
                                id="tvm-movie-toggle-watched"
                            >
                                <?php esc_html_e('Mark Watched', 'tvm-tracker'); ?>
                            </button>

                            <!-- Streaming Sources -->
                            <div class="tvm-movie-list-sources">
                                <h5><?php esc_html_e('Available on:', 'tvm-tracker'); ?></h5>
                                <?php 
                                // Render sources using the helper function (defined in view-render-sources-detail.php)
                                // We pass the title-level sources here.
                                if ( function_exists( 'tvm_tracker_render_sources_list' ) ) {
                                    tvm_tracker_render_sources_list( $movie->sources, $source_map, $enabled_sources, true );
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>


    <!-- --- 3. WATCHED MOVIES TAB --- -->
    <div class="tvm-tab-content <?php echo ( $current_tab === 'watched' ) ? 'is-active' : ''; ?>" id="tvm-tab-watched">
        <?php if ( empty( $watched_movies ) ) : ?>
            <p><?php esc_html_e( 'No movies marked as watched.', 'tvm-tracker' ); ?></p>
        <?php else : ?>
            <div class="tvm-poster-grid">
                <?php foreach ( $watched_movies as $movie ) :
                    $details_url = trailingslashit( $permalink ) . 'details/' . absint( $movie->title_id );
                    ?>
                    <a href="<?php echo esc_url( $details_url ); ?>" class="tvm-poster-item">
                        <img src="<?php echo esc_url( $movie->poster ); ?>" alt="<?php echo esc_attr($movie->title_name) . esc_attr__( ' Poster', 'tvm-tracker' ); ?>" onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/200x300/eeeeee/333333?text=' . urlencode( $movie->title_name ) ); ?>';">
                        
                        <!-- Status Overlay -->
                        <div class="tvm-poster-progress-overlay movie-status">
                            <?php esc_html_e('SEEN IT', 'tvm-tracker'); ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
