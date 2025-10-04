<?php
/**
 * Shortcode View: My Tracker List/Poster View
 *
 * @var string $permalink Base permalink for the page.
 * @var Tvm_Tracker_API $api_client
 * @var Tvm_Tracker_DB $db_client
 * @var int $current_user_id Current user ID.
 * @var array $source_map Array of all available sources.
 */

global $wp_query;
// Check if the primary action view is 'movies' or 'shows'
$action_view = get_query_var( 'tvm_action_view' ) === 'movies' ? 'movies' : 'shows';
$view = $wp_query->get( 'tvm_view' ) ?: 'poster'; // Default view for shows/movies

$current_user_id = get_current_user_id();

// --- 1. Fetch Tracked Items based on type ---
if ($action_view === 'movies') {
    $tracked_items_raw = $db_client->tvm_tracker_get_tracked_movies( $current_user_id );
} else {
    $tracked_items_raw = $db_client->tvm_tracker_get_tracked_shows( $current_user_id );
}

// Sort by title name alphabetically
usort( $tracked_items_raw, function( $a, $b ) {
    return strcasecmp( $a->title_name, $b->title_name );
} );

// --- 2. Inject external details (Poster/Year) ---
$tracked_items = array_map( function( $item ) use ( $api_client ) {
    // Fetch details via cached API call
    $details = $api_client->tvm_tracker_get_title_details( absint( $item->title_id ) );

    $item->poster = is_array( $details ) ? esc_url( $details['poster'] ?? '' ) : '';
    $item->year = is_array( $details ) ? absint( $details['year'] ?? 0 ) : 0;
    return $item;
}, $tracked_items_raw );


$base_url = trailingslashit( $permalink ) . 'my-shows';
$back_to_search_url = esc_url( $permalink );
$unwatched_url = trailingslashit( $base_url ) . 'unwatched';
$upcoming_url = trailingslashit( $base_url ) . 'upcoming';

// Determine URLs for view toggles
$current_view_url = trailingslashit( $base_url ) . ($action_view === 'movies' ? 'movies' : '');
?>

<div class="tvm-tracker-list-header">
    <h3>
        <?php echo ( $action_view === 'movies' ) 
            ? esc_html__( 'My Tracked Movies', 'tvm-tracker' ) 
            : esc_html__( 'My Tracked Series', 'tvm-tracker' ); 
        ?>
    </h3>
    <div class="tvm-details-actions">
        
        <!-- Navigation to other pages -->
        <a href="<?php echo esc_url( $upcoming_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'Upcoming Schedule', 'tvm-tracker' ); ?></a>
        <a href="<?php echo esc_url( $unwatched_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'Unwatched Episodes', 'tvm-tracker' ); ?></a>
        <a href="<?php echo $back_to_search_url; ?>" class="tvm-button tvm-button-back"><?php esc_html_e( 'Back to Search', 'tvm-tracker' ); ?></a>
    </div>
</div>

<!-- SERIES/MOVIE TOGGLE -->
<div class="tvm-type-toggles">
    <a href="<?php echo esc_url( $base_url ); ?>" class="tvm-button <?php echo ( $action_view === 'shows' ) ? 'tvm-button-view-active' : 'tvm-button-view'; ?>"><?php esc_html_e( 'Series Tracker', 'tvm-tracker' ); ?></a>
    <a href="<?php echo esc_url( trailingslashit( $base_url ) . 'movies' ); ?>" class="tvm-button <?php echo ( $action_view === 'movies' ) ? 'tvm-button-view-active' : 'tvm-button-view'; ?>"><?php esc_html_e( 'Movie Tracker', 'tvm-tracker' ); ?></a>
</div>

<?php if ( $action_view === 'movies' ) : 
    // Movie View (Future/Past Split is handled internally by view-tracked-movies)
    require TVM_TRACKER_PATH . 'includes/shortcode-views/view-tracked-movies.php';

// --- TV SERIES VIEW ---
else : 
    // Ensure variables for sub-views are named correctly
    $tracked_shows = $tracked_items;
    ?>
    <div class="tvm-view-toggles">
        <a href="<?php echo esc_url( remove_query_arg( 'tvm_view', $base_url ) ); ?>" class="tvm-button <?php echo ( $view === 'list' ) ? 'tvm-button-view-active' : 'tvm-button-view'; ?>"><?php esc_html_e( 'List View', 'tvm-tracker' ); ?></a>
        <a href="<?php echo esc_url( add_query_arg( 'tvm_view', 'poster', $base_url ) ); ?>" class="tvm-button <?php echo ( $view === 'poster' ) ? 'tvm-button-view-active' : 'tvm-button-view'; ?>"><?php esc_html_e( 'Poster View', 'tvm-tracker' ); ?></a>
    </div>
    
    <?php if ( empty( $tracked_items ) ) : ?>
        <p><?php esc_html_e( 'You are not currently tracking any series. Use the search bar to find some!', 'tvm-tracker' ); ?></p>
    <?php elseif ( $view === 'list' ) :
        // List View: Variables $tracked_shows, $db_client, $permalink are available in scope.
        require TVM_TRACKER_PATH . 'includes/shortcode-views/view-list-view.php';
    elseif ( $view === 'poster' ) :
        // Poster View: Variables $tracked_shows, $db_client, $permalink are available in scope.
        require TVM_TRACKER_PATH . 'includes/shortcode-views/view-poster-view.php';
    endif;
    
endif;

// Removed helper function definitions to fix parse error.
