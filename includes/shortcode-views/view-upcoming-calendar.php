<?php
/**
 * Shortcode View: Upcoming Episodes Calendar/Agenda View
 *
 * @var string $permalink Base permalink for the page.
 * @var Tvm_Tracker_DB $db_client
 * @var int $current_user_id Current user ID.
 */

// Get current view mode (default to 'calendar')
global $wp_query;
$view_mode = get_query_var( 'tvm_calendar_view' ) ?: 'calendar';

// Define dates for filtering
$today = date_i18n('Y-m-d');
$today_timestamp = strtotime($today);

// Fetch all unwatched episodes for the current user
$all_unwatched_episodes = $db_client->tvm_tracker_get_unwatched_episodes( $current_user_id );

// Filter episodes to only include upcoming (today or future)
$upcoming_episodes_raw = array_filter( $all_unwatched_episodes, function( $episode ) use ( $today ) {
    $air_date = $episode['air_date'] ?? '0000-00-00';
    return ( $air_date >= $today && $air_date !== '0000-00-00' );
});

// Sort episodes by air date (soonest first)
usort( $upcoming_episodes_raw, function( $a, $b ) {
    return strtotime( $a['air_date'] ) <=> strtotime( $b['air_date'] );
});


// Initialize the array before attempting to use it
$episodes_by_date = []; 

// Group episodes by date for the Agenda/Calendar view
foreach ($upcoming_episodes_raw as $episode) {
    $air_date = $episode['air_date'];
    $episodes_by_date[$air_date][] = $episode;
}


$base_upcoming_url = trailingslashit( $permalink ) . 'my-shows/upcoming'; 
$my_tracker_url = trailingslashit( $permalink ) . 'my-shows';
$unwatched_url = trailingslashit( $my_tracker_url ) . 'unwatched';
$back_to_search_url = esc_url( $permalink );

?>
<div class="tvm-upcoming-calendar-container">
    <div class="tvm-tracker-list-header">
        <h3><?php esc_html_e( 'Upcoming Episode Schedule', 'tvm-tracker' ); ?></h3>
        <div class="tvm-details-actions">
            
            <!-- Link back to My Tracker -->
            <a href="<?php echo esc_url( $my_tracker_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'My Tracker', 'tvm-tracker' ); ?></a>
            
            <!-- Link back to Unwatched Episodes -->
            <a href="<?php echo esc_url( $unwatched_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'Unwatched Episodes', 'tvm-tracker' ); ?></a>
            
            <a href="<?php echo $back_to_search_url; ?>" class="tvm-button tvm-button-back"><?php esc_html_e( 'Back to Search', 'tvm-tracker' ); ?></a>
        </div>
    </div>

    <!-- VIEW TOGGLES -->
    <div class="tvm-view-toggles">
        <!-- Calendar View (Base URL) -->
        <a href="<?php echo esc_url( $base_upcoming_url ); ?>" class="tvm-button <?php echo ( $view_mode === 'calendar' ) ? 'tvm-button-view-active' : 'tvm-button-view'; ?>"><?php esc_html_e( 'Calendar View', 'tvm-tracker' ); ?></a>
        
        <!-- Agenda View (Uses the clean permalink structure) -->
        <a href="<?php echo esc_url( trailingslashit( $base_upcoming_url ) . 'agenda' ); ?>" class="tvm-button <?php echo ( $view_mode === 'agenda' ) ? 'tvm-button-view-active' : 'tvm-button-view'; ?>"><?php esc_html_e( 'Agenda View', 'tvm-tracker' ); ?></a>
    </div>
    
    <?php if ( empty( $upcoming_episodes_raw ) ) : ?>
        <p><?php esc_html_e( 'No upcoming episodes found for your tracked shows.', 'tvm-tracker' ); ?></p>
    <?php elseif ( $view_mode === 'agenda' ) :
        // Agenda/List View (Headings for each day)
        require TVM_TRACKER_PATH . 'includes/shortcode-views/view-upcoming-agenda.php';
    else :
        // Default Calendar View
        require TVM_TRACKER_PATH . 'includes/shortcode-views/view-upcoming-calendar-display.php';
    endif; ?>
</div>
