<?php
/**
 * Shortcode View: Title Details Page
 *
 * @var int $title_id The Watchmode title ID.
 * @var string $permalink Base permalink for the page.
 * @var Tvm_Tracker_API $api_client
 * @var Tvm_Tracker_DB $db_client
 * @var int $current_user_id Current user ID.
 * @var array $source_map Array of all available sources.
 * @var array $enabled_sources Array of enabled source IDs.
 */

// CRITICAL V2.0 CHECK: Ensure static data is populated before proceeding.
// This handles the architectural change of decoupling data population from tracking.
$db_client->tvm_tracker_populate_static_data( $title_id );


// Fetch all required data (most of which is now cached or local DB data)
$details_data = $api_client->tvm_tracker_get_title_details( $title_id ); // API for poster, overview (cached)
$episodes_data = $db_client->tvm_tracker_get_all_episode_data( $title_id ); // DB for static episode data
$sources_data = $api_client->tvm_tracker_get_sources_for_title( $title_id ); // API for title sources (cached)


// 1. Handle API Errors or Missing Details
if ( is_wp_error( $details_data ) ) {
    echo '<p class="tvm-error-message">' . esc_html( $details_data->get_error_message() ) . '</p>';
    return;
}
if ( empty( $details_data ) ) {
    echo '<p class="tvm-error-message">' . esc_html__( 'Could not load title details.', 'tvm-tracker' ) . '</p>';
    return;
}

// Determine if the title is a series (not a movie)
$is_series = ! in_array( $details_data['type'], array( 'movie', 'short_film', 'doc_film' ) );
$item_type = sanitize_text_field( $details_data['type'] ?? ($is_series ? 'tv_series' : 'movie') );
$release_date = sanitize_text_field( $details_data['release_date'] ?? null );


// Check tracking status (V2.0 DB method)
$is_tracked = $db_client->tvm_tracker_is_show_tracked( $current_user_id, $title_id );
$button_class = $is_tracked ? 'tvm-button-remove' : 'tvm-button-add';
$button_text = $is_tracked ? esc_html__( 'Tracking', 'tvm-tracker' ) : esc_html__( 'Add to Tracker', 'tvm-tracker' );
$my_tracker_url = trailingslashit( $permalink ) . 'my-shows';
$back_to_search_url = esc_url( $permalink );

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
            data-item-type="<?php echo esc_attr( $item_type ); ?>" 
            data-release-date="<?php echo esc_attr( $release_date ); ?>"
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

        <!-- Season/Episode Count (ONLY FOR SERIES) -->
        <?php if ( $is_series ) : ?>
            <?php
            // Use the count of the successfully loaded episode data for accuracy
            $episode_count = is_array( $episodes_data ) ? count( $episodes_data ) : 0;
            
            // Calculate season count dynamically from episode data
            $season_count = count( array_unique( array_column( $episodes_data, 'season_number' ) ) );
            ?>
            <h4><?php esc_html_e( 'Count:', 'tvm-tracker' ); ?></h4>
            <p><?php
                /* translators: 1: number of seasons, 2: total number of episodes */
                printf( esc_html__( 'This title has %1$d season(s) and %2$d total episode(s) listed.', 'tvm-tracker' ), absint( $season_count ), absint( $episode_count ) );
            ?></p>
        <?php endif; ?>

        <!-- Streaming Sources -->
        <div class="tvm-source-logos">
            <h4><?php esc_html_e( 'Available on:', 'tvm-tracker' ); ?></h4>
            <?php 
            // The renderer needs global variables: $source_map and $enabled_sources
            require TVM_TRACKER_PATH . 'includes/shortcode-views/view-render-sources-detail.php'; 
            // Use the helper function, passing title-level sources
            if ( function_exists( 'tvm_tracker_render_sources_list' ) ) {
                tvm_tracker_render_sources_list( $sources_data, $source_map, $enabled_sources, false );
            }
            ?>
        </div>
    </div>
</div>

<!-- Seasons and Episodes List (ONLY FOR SERIES) -->
<?php if ( $is_series ) : ?>
    <?php 
    // Pass the tracking status to the renderer (FIX)
    require TVM_TRACKER_PATH . 'includes/shortcode-views/view-render-seasons-episodes.php'; 
    ?>
<?php endif; ?>
