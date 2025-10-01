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

// Fetch all required data (cached in API class)
$details_data = $api_client->tvm_tracker_get_title_details( $title_id );
$seasons_data = $api_client->tvm_tracker_get_seasons( $title_id );
$episodes_data = $api_client->tvm_tracker_get_episodes( $title_id );
$sources_data = $api_client->tvm_tracker_get_sources_for_title( $title_id );


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
        <?php require TVM_TRACKER_PATH . 'includes/shortcode-views/view-render-sources-detail.php'; ?>
    </div>
</div>

<!-- Seasons and Episodes List -->
<?php require TVM_TRACKER_PATH . 'includes/shortcode-views/view-render-seasons-episodes.php'; ?>
<?php
// Helper function for rendering sources (used by detail and unwatched pages)
if ( ! function_exists( 'tvm_tracker_render_sources_list' ) ) {
    function tvm_tracker_render_sources_list( $title_sources, $source_map, $enabled_sources, $is_small_icons = false ) {
        if ( ! is_array( $title_sources ) || empty( $title_sources ) ) {
            return;
        }

        $unique_source_ids = [];
        $class = $is_small_icons ? 'tvm-episode-source-logo' : 'tvm-source-logo';

        // First pass: Collect unique sources, prioritizing US
        foreach ($title_sources as $source) {
            $source_id = absint($source['source_id']);
            $region = sanitize_text_field($source['region'] ?? '');

            if (!in_array($source_id, $enabled_sources, true)) continue;

            if (!isset($unique_source_ids[$source_id]) || $region === 'US') {
                $unique_source_ids[$source_id] = $source;
            }
        }

        // Second pass: Render the unique list
        foreach ($unique_source_ids as $source) {
            $source_id = absint($source['source_id']);
            $logo_url = $source_map[$source_id]['logo_100px'] ?? '';
            $web_url = esc_url($source['web_url'] ?? '#');
            $source_name = sanitize_text_field($source['name']);

            if (!empty($logo_url) && $web_url !== '#') {
                echo '<a href="' . $web_url . '" target="_blank">';
                echo '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($source_name) . esc_attr__(' logo', 'tvm-tracker') . '" class="' . esc_attr($class) . '">';
                echo '</a>';
            }
        }
    }
}
