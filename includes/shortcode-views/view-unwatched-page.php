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
 */

// Fetch all required data
$unwatched_episodes_raw = $db_client->tvm_tracker_get_unwatched_episodes( $current_user_id );

// Group data by show for the poster grid
$shows_with_unwatched = [];
foreach ($unwatched_episodes_raw as $episode) {
    $title_id = absint($episode['title_id']);
    
    if (!isset($shows_with_unwatched[$title_id])) {
        // Fetch show details (cached)
        $details = $api_client->tvm_tracker_get_title_details($title_id);

        $shows_with_unwatched[$title_id] = [
            'title_id' => $title_id,
            'show_name' => $episode['show_name'],
            'poster' => $details['poster'] ?? '',
            'unwatched_count' => 0,
        ];
    }
    $shows_with_unwatched[$title_id]['unwatched_count']++;
}

$my_tracker_url = trailingslashit( $permalink ) . 'my-shows';
$back_to_search_url = esc_url( $permalink );

?>
<div class="tvm-tracker-list-header">
    <h3><?php esc_html_e( 'My Unwatched Episodes', 'tvm-tracker' ); ?></h3>
    <div class="tvm-details-actions">
        <a href="<?php echo esc_url( $my_tracker_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'My Tracker', 'tvm-tracker' ); ?></a>
        <a href="<?php echo $back_to_search_url; ?>" class="tvm-button tvm-button-back"><?php esc_html_e( 'Back to Search', 'tvm-tracker' ); ?></a>
    </div>
</div>

<div class="tvm-unwatched-grid-wrapper">
    <?php if ( empty( $shows_with_unwatched ) ) : ?>
        <p><?php esc_html_e( 'You have no unwatched episodes for your tracked shows!', 'tvm-tracker' ); ?></p>
    <?php else : ?>
        <h3><?php esc_html_e('Shows with Unwatched Episodes', 'tvm-tracker'); ?></h3>
        <div class="tvm-unwatched-poster-grid">
            <?php foreach ($shows_with_unwatched as $show) : 
                $poster_url = esc_url($show['poster']);
                $show_name = esc_attr($show['show_name']);
                $title_id = absint($show['title_id']);
                $count = absint($show['unwatched_count']);
                ?>
                <a href="#" class="tvm-unwatched-poster-selector" data-title-id="<?php echo $title_id; ?>">
                    <img src="<?php echo $poster_url; ?>" alt="<?php echo $show_name . esc_attr__(' Poster', 'tvm-tracker'); ?>" class="tvm-unwatched-poster-img" onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/120x180/eeeeee/333333?text=' . urlencode( $show_name ) ); ?>';">
                    <span class="tvm-unwatched-poster-name"><?php echo esc_html($show['show_name']); ?></span>
                    <span class="tvm-unwatched-poster-count"><?php echo $count; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Detail Episode Card (Loaded via AJAX) -->
        <div id="tvm-episode-detail-card">
            <p class="tvm-empty-list"><?php esc_html_e('Select a show poster above to view its next unwatched episode.', 'tvm-tracker'); ?></p>
        </div>
    <?php endif; ?>
</div>

<?php 
// Include required helper functions (e.g., render_sources_list) 
require TVM_TRACKER_PATH . 'includes/shortcode-views/view-details-page.php';
