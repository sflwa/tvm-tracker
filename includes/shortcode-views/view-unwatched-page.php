<?php
/**
 * Shortcode View: Unwatched Episodes Page (Poster Selector Layout)
 *
 * @var string $permalink Base permalink for the page.
 * @var Tvm_Tracker_API $api_client
 * @var Tvm_Tracker_DB $db_client
 * @var int $current_user_id Current user ID.
 * @var array $source_map Array of all available sources.
 * @var array $enabled_sources Array of enabled source IDs.
 * @version 1.1.48
 */

// Define today's date for filtering (uses WordPress timezone)
$today = date_i18n( 'Y-m-d' ); 

// Fetch all required data: all unwatched episodes for the user
// NOTE: tvm_tracker_get_unwatched_episodes now fetches all episode metadata via a SQL JOIN.
$unwatched_episodes_raw = $db_client->tvm_tracker_get_unwatched_episodes( $current_user_id );
$tracked_shows_raw = $db_client->tvm_tracker_get_tracked_shows( $current_user_id );


// --- STEP 1: Pre-Process and Cache Show Details (Poster/Year) ---
// This prevents hitting the API repeatedly inside the episode grouping loop.
$show_details_cache = [];
foreach ($tracked_shows_raw as $show) {
    $title_id = absint($show->title_id);
    
    // Fetch details (poster/year) via cached API call
    $details = $api_client->tvm_tracker_get_title_details( $title_id ); 

    $show_details_cache[$title_id] = [
        'poster'    => is_array($details) ? esc_url($details['poster'] ?? '') : '',
        'show_name' => $show->title_name,
    ];
}


// --- STEP 2: Process Episodes and Group by Section ---
$shows_with_upcoming = [];
$shows_with_past = [];

foreach ( $unwatched_episodes_raw as $episode ) {
    $title_id = absint( $episode['title_id'] );
    $release_date = $episode['air_date'] ?? '9999-12-31'; // Use air_date from DB
    $show_details = $show_details_cache[$title_id] ?? ['poster' => '', 'show_name' => esc_html__('N/A', 'tvm-tracker')];

    $has_valid_date = ! empty( $release_date ) && $release_date !== '0000-00-00' && $release_date !== '9999-12-31';
    
    // Determine if the episode is upcoming (today or future)
    $is_upcoming = $has_valid_date && ( $release_date >= $today );

    // Determine target array based on date type
    $target_array_ref = $is_upcoming ? 'shows_with_upcoming' : 'shows_with_past';
    $target_array_name = $is_upcoming ? 'shows_with_upcoming' : 'shows_with_past';

    // Initialize the show entry in the specific sectional array
    if ( ! isset( $$target_array_name[ $title_id ] ) ) {
        // Use the pre-cached details
        $$target_array_name[ $title_id ] = [
            'title_id' => $title_id,
            'show_name' => $show_details['show_name'],
            'poster' => $show_details['poster'],
            'unwatched_count' => 0, // Sectional count
        ];
    }

    // Increment the count for the specific section
    $$target_array_name[ $title_id ]['unwatched_count']++;
}


// Filter out shows with a count of 0 (defensive)
$shows_with_upcoming = array_filter( $shows_with_upcoming, fn($show) => $show['unwatched_count'] > 0 );
$shows_with_past = array_filter( $shows_with_past, fn($show) => $show['unwatched_count'] > 0 );

$my_tracker_url = trailingslashit( $permalink ) . 'my-shows';
$back_to_search_url = esc_url( $permalink );
// CRITICAL FIX: Define the Upcoming URL here
$upcoming_url = trailingslashit( $my_tracker_url ) . 'upcoming'; 

?>
<div class="tvm-tracker-list-header">
    <h3><?php esc_html_e( 'My Unwatched Episodes', 'tvm-tracker' ); ?></h3>
    <div class="tvm-details-actions">
        <!-- NEW LINK -->
        <a href="<?php echo esc_url( $upcoming_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'Upcoming Schedule', 'tvm-tracker' ); ?></a>
        
        <!-- Existing Links -->
        <a href="<?php echo esc_url( $my_tracker_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'My Tracker', 'tvm-tracker' ); ?></a>
        <a href="<?php echo $back_to_search_url; ?>" class="tvm-button tvm-button-back"><?php esc_html_e( 'Back to Search', 'tvm-tracker' ); ?></a>
    </div>
</div>


<?php if ( empty( $shows_with_upcoming ) && empty( $shows_with_past ) ) : ?>
    <p><?php esc_html_e( 'You have no unwatched episodes for your tracked shows!', 'tvm-tracker' ); ?></p>
<?php endif; ?>

<!-- --- UPCOMING EPISODES SECTION --- -->
<div class="tvm-unwatched-section tvm-upcoming-section">
    <h3><?php esc_html_e( 'Upcoming Episodes (Today or Future)', 'tvm-tracker' ); ?></h3>
    <?php if ( empty( $shows_with_upcoming ) ) : ?>
        <p><?php esc_html_e( 'You are caught up on all upcoming episodes!', 'tvm-tracker' ); ?></p>
    <?php else : ?>
        <div class="tvm-unwatched-poster-grid tvm-upcoming-grid">
            <?php foreach ( $shows_with_upcoming as $show ) :
                $poster_url = esc_url( $show['poster'] );
                $show_name = esc_attr( $show['show_name'] );
                $title_id = absint( $show['title_id'] );
                $count = absint( $show['unwatched_count'] );
                ?>
                <div class="tvm-unwatched-poster-selector" data-title-id="<?php echo $title_id; ?>" data-unwatched-type="upcoming">
                    <img src="<?php echo $poster_url; ?>" alt="<?php echo $show_name . esc_attr__( ' Poster', 'tvm-tracker' ); ?>" class="tvm-unwatched-poster-img" onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/120x180/eeeeee/333333?text=' . urlencode( $show_name ) ); ?>';">
                    <!-- Name removed per user request -->
                    <span class="tvm-unwatched-poster-count"><?php echo $count; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- --- PAST EPISODES SECTION --- -->
<div class="tvm-unwatched-section tvm-past-section">
    <h3><?php esc_html_e( 'Past Unwatched Episodes', 'tvm-tracker' ); ?></h3>
    <?php if ( empty( $shows_with_past ) ) : ?>
        <p><?php esc_html_e( 'You are caught up on all past episodes!', 'tvm-tracker' ); ?></p>
    <?php else : ?>
        <div class="tvm-unwatched-poster-grid tvm-past-grid">
            <?php foreach ( $shows_with_past as $show ) :
                $poster_url = esc_url( $show['poster'] );
                $show_name = esc_attr( $show['show_name'] );
                $title_id = absint( $show['title_id'] );
                $count = absint( $show['unwatched_count'] );
                ?>
                <div class="tvm-unwatched-poster-selector" data-title-id="<?php echo $title_id; ?>" data-unwatched-type="past">
                    <img src="<?php echo $poster_url; ?>" alt="<?php echo $show_name . esc_attr__( ' Poster', 'tvm-tracker' ); ?>" class="tvm-unwatched-poster-img" onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/120x180/eeeeee/333333?text=' . urlencode( $show_name ) ); ?>';">
                    <!-- Name removed per user request -->
                    <span class="tvm-unwatched-poster-count"><?php echo $count; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- --- SINGLE RESULTS CONTAINER --- -->
<div id="tvm-episode-results">
    <p class="tvm-empty-list"><?php esc_html_e('Select a show poster above to view its unwatched episodes.', 'tvm-tracker'); ?></p>
</div>
