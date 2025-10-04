<?php
/**
 * Shortcode View: Tracked Movies - Future/Past View
 *
 * @var string $permalink Base permalink for the page.
 * @var Tvm_Tracker_API $api_client
 * @var Tvm_Tracker_DB $db_client
 * @var int $current_user_id Current user ID.
 * @var array $source_map Array of all available sources.
 */

global $wp_query;
$view = $wp_query->get( 'tvm_view' ) ?: 'poster'; // Default to poster view
$today = date_i18n('Y-m-d');

// 1. Fetch all tracked movies
$tracked_movies_raw = $db_client->tvm_tracker_get_tracked_movies( $current_user_id );

// 2. Inject poster URL via API Client (cached) and categorize
$movies_with_details = array_map( function( $movie ) use ( $api_client, $today ) {
    $details = $api_client->tvm_tracker_get_title_details( absint( $movie->title_id ) );

    $movie->poster = is_array( $details ) ? esc_url( $details['poster'] ?? '' ) : '';
    $movie->year = is_array( $details ) ? absint( $details['year'] ?? 0 ) : 0;
    
    // Calculate Days Until/Past
    if (!empty($movie->release_date) && $movie->release_date !== '0000-00-00') {
        $release_ts = strtotime($movie->release_date);
        $today_ts = strtotime($today);
        $diff_seconds = $release_ts - $today_ts;
        $diff_days = round($diff_seconds / (60 * 60 * 24));
        
        $movie->days_until = $diff_days;
        $movie->is_future = $diff_days >= 0;
    } else {
        $movie->days_until = null;
        $movie->is_future = false; // Treat movies with no date as past for default display
    }

    return $movie;
}, $tracked_movies_raw );


// 3. Split into Future and Past Movies
$future_movies = array_filter($movies_with_details, fn($m) => $m->is_future);
$past_movies = array_filter($movies_with_details, fn($m) => !$m->is_future);


// Sort Future Movies: Soonest release date first
usort($future_movies, fn($a, $b) => $a->days_until <=> $b->days_until);

$current_url = trailingslashit( $permalink ) . 'my-shows/movies';
$back_to_tracker_url = trailingslashit( $permalink ) . 'my-shows';
$back_to_search_url = esc_url( $permalink );

?>
<div class="tvm-tracker-list-header">
    <h3><?php esc_html_e( 'My Tracked Movies', 'tvm-tracker' ); ?></h3>
    <div class="tvm-details-actions">
        <a href="<?php echo esc_url( $back_to_tracker_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'Back to Tracker', 'tvm-tracker' ); ?></a>
        <a href="<?php echo $back_to_search_url; ?>" class="tvm-button tvm-button-back"><?php esc_html_e( 'Back to Search', 'tvm-tracker' ); ?></a>
    </div>
</div>

<?php if ( empty( $tracked_movies_raw ) ) : ?>
    <p><?php esc_html_e( 'You are not currently tracking any movies.', 'tvm-tracker' ); ?></p>
<?php endif; ?>

<!-- --- FUTURE MOVIES SECTION --- -->
<div class="tvm-movie-section tvm-future-movies">
    <h3><?php esc_html_e( 'Upcoming Movies', 'tvm-tracker' ); ?></h3>
    <?php if ( empty( $future_movies ) ) : ?>
        <p><?php esc_html_e( 'No future movies currently tracked.', 'tvm-tracker' ); ?></p>
    <?php else : ?>
        <div class="tvm-poster-grid">
            <?php foreach ( $future_movies as $movie ) :
                $release_ts = strtotime($movie->release_date);
                $release_date_display = date( get_option( 'date_format' ), $release_ts );
                
                // Overlay Text: Days until release
                if ($movie->days_until === 0) {
                    $overlay_text = esc_html__('TODAY', 'tvm-tracker');
                } elseif ($movie->days_until === 1) {
                    $overlay_text = esc_html__('1 DAY', 'tvm-tracker');
                } else {
                    $overlay_text = sprintf(esc_html__('%d DAYS', 'tvm-tracker'), $movie->days_until);
                }
                
                // Fallback to year for details page link (Movies don't use episode tracker)
                $details_url = trailingslashit( $permalink ) . 'details/' . absint( $movie->title_id );

                ?>
                <a href="<?php echo esc_url( $details_url ); ?>" class="tvm-poster-item">
                    <img src="<?php echo esc_url( $movie->poster ); ?>" alt="<?php echo esc_attr($movie->title_name) . esc_attr__( ' Poster', 'tvm-tracker' ); ?>" onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/200x300/eeeeee/333333?text=' . urlencode( $movie->title_name ) ); ?>';">

                    <!-- Countdown Overlay -->
                    <div class="tvm-poster-progress-overlay movie-countdown" title="<?php printf(esc_attr__('Releases on %s', 'tvm-tracker'), $release_date_display); ?>">
                        <?php echo $overlay_text; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- --- PAST MOVIES SECTION --- -->
<div class="tvm-movie-section tvm-past-movies">
    <h3><?php esc_html_e( 'Past Movies', 'tvm-tracker' ); ?></h3>
    <?php if ( empty( $past_movies ) ) : ?>
        <p><?php esc_html_e( 'No previously released movies tracked.', 'tvm-tracker' ); ?></p>
    <?php else : ?>
        <div class="tvm-poster-grid">
            <?php foreach ( $past_movies as $movie ) :
                $details_url = trailingslashit( $permalink ) . 'details/' . absint( $movie->title_id );
                ?>
                <a href="<?php echo esc_url( $details_url ); ?>" class="tvm-poster-item">
                    <img src="<?php echo esc_url( $movie->poster ); ?>" alt="<?php echo esc_attr($movie->title_name) . esc_attr__( ' Poster', 'tvm-tracker' ); ?>" onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/200x300/eeeeee/333333?text=' . urlencode( $movie->title_name ) ); ?>';">
                    
                    <!-- Progress Overlay shows release year -->
                    <div class="tvm-poster-progress-overlay movie-year">
                        <?php echo $movie->year; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
