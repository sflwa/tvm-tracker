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
$view = $wp_query->get( 'tvm_view' ) ?: 'poster'; // Default to poster view

$tracked_shows_raw = $db_client->tvm_tracker_get_tracked_shows( $current_user_id );

// Sort by title name alphabetically
usort( $tracked_shows_raw, function( $a, $b ) {
    return strcasecmp( $a->title_name, $b->title_name );
} );

// Inject poster URL and year via API Client (cached)
$tracked_shows = array_map( function( $show ) use ( $api_client ) {
    // Check cache for details before calling API
    $details = $api_client->tvm_tracker_get_title_details( absint( $show->title_id ) );

    $show->poster = is_array( $details ) ? esc_url( $details['poster'] ?? '' ) : '';
    $show->year = is_array( $details ) ? absint( $details['year'] ?? 0 ) : 0;
    return $show;
}, $tracked_shows_raw );

$current_url = trailingslashit( $permalink ) . 'my-shows';
$back_to_search_url = esc_url( $permalink );
$unwatched_url = trailingslashit( $current_url ) . 'unwatched';

?>
<div class="tvm-tracker-list-header">
    <h3><?php esc_html_e( 'My Tracked Shows', 'tvm-tracker' ); ?></h3>
    <div class="tvm-details-actions">
        <a href="<?php echo esc_url( $unwatched_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'Unwatched Episodes', 'tvm-tracker' ); ?></a>
        <a href="<?php echo $back_to_search_url; ?>" class="tvm-button tvm-button-back"><?php esc_html_e( 'Back to Search', 'tvm-tracker' ); ?></a>
    </div>
</div>

<div class="tvm-view-toggles">
    <a href="<?php echo esc_url( remove_query_arg( 'tvm_view', $current_url ) ); ?>" class="tvm-button <?php echo ( $view === 'list' ) ? 'tvm-button-view-active' : 'tvm-button-view'; ?>"><?php esc_html_e( 'List View', 'tvm-tracker' ); ?></a>
    <a href="<?php echo esc_url( add_query_arg( 'tvm_view', 'poster', $current_url ) ); ?>" class="tvm-button <?php echo ( $view === 'poster' ) ? 'tvm-button-view-active' : 'tvm-button-view'; ?>"><?php esc_html_e( 'Poster View', 'tvm-tracker' ); ?></a>
</div>

<?php if ( empty( $tracked_shows ) ) : ?>
    <p><?php esc_html_e( 'You are not currently tracking any shows. Use the search bar to find some!', 'tvm-tracker' ); ?></p>
<?php elseif ( $view === 'list' ) :
    require TVM_TRACKER_PATH . 'includes/shortcode-views/view-list-view.php';
elseif ( $view === 'poster' ) :
    require TVM_TRACKER_PATH . 'includes/shortcode-views/view-poster-view.php';
endif;


// Helper function for List View rendering
if ( ! function_exists( 'tvm_tracker_render_list_view_template' ) ) {
    function tvm_tracker_render_list_view_template( $tracked_shows, $db_client, $permalink ) {
        // Render List View HTML
        ?>
        <ul class="tvm-results-list tvm-tracked-list">
            <?php foreach ( $tracked_shows as $show ) : 
                // Calculate progress
                $watched_count = $db_client->tvm_tracker_get_watched_episodes_count( get_current_user_id(), absint( $show->title_id ) );
                $total_count = absint( $show->total_episodes );
                $progress = ( $total_count > 0 ) ? round( ( $watched_count / $total_count ) * 100 ) : 0;
                $details_url = trailingslashit( $permalink ) . 'details/' . absint( $show->title_id );
            ?>
                <li class="tvm-result-item tvm-tracked-item">
                    <span class="tvm-result-title">
                        <?php echo esc_html( $show->title_name ); ?>
                        <?php if ( $show->year > 0 ) : ?>
                            (<?php echo esc_html( $show->year ); ?>)
                        <?php endif; ?>
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
}

// Helper function for Poster View rendering
if ( ! function_exists( 'tvm_tracker_render_poster_view_template' ) ) {
    function tvm_tracker_render_poster_view_template( $tracked_shows, $db_client, $permalink ) {
        // Render Poster View HTML
        ?>
        <div class="tvm-poster-grid">
            <?php foreach ( $tracked_shows as $show ) :
                // Calculate progress
                $watched_count = $db_client->tvm_tracker_get_watched_episodes_count( get_current_user_id(), absint( $show->title_id ) );
                $total_count = absint( $show->total_episodes );
                $details_url = trailingslashit( $permalink ) . 'details/' . absint( $show->title_id );

                $poster_url = $show->poster;
                $show_title = esc_attr( $show->title_name );
            ?>
                <a href="<?php echo esc_url( $details_url ); ?>" class="tvm-poster-item">
                    <img src="<?php echo esc_url( $poster_url ); ?>" alt="<?php echo $show_title . esc_attr__( ' Poster', 'tvm-tracker' ); ?>" onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/200x300/eeeeee/333333?text=' . urlencode( $show_title ) ); ?>';">

                    <!-- Progress Overlay -->
                    <div class="tvm-poster-progress-overlay">
                        <span class="tvm-progress-count">
                            <?php echo absint( $watched_count ); ?> / <?php echo absint( $total_count ); ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
