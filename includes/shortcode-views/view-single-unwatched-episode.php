<?php
/**
 * Shortcode View: Renders a single unwatched episode for the AJAX callback.
 *
 * This file is included via AJAX request in Tvm_Tracker_Shortcode::tvm_tracker_load_unwatched_episode_callback().
 *
 * @var array $next_episode The episode data array.
 * @var Tvm_Tracker_API $api_client
 * @var Tvm_Tracker_DB $db_client
 * @var array $source_map Array of all available sources.
 * @var array $enabled_sources Array of enabled source IDs.
 */

// NOTE: Dependencies ($api_client, $db_client, $source_map, etc.) are available in the scope
// because they were defined in the AJAX callback function which required this file.

// Ensure tvm_tracker_render_sources_list exists
require TVM_TRACKER_PATH . 'includes/shortcode-views/view-details-page.php';

$episode = $next_episode;
$title_id = absint( $episode['title_id'] );
$episode_id = absint( $episode['id'] );
$release_date = $episode['release_date'] ?? esc_html__( 'TBA', 'tvm-tracker' );
$poster_url = $episode['thumbnail_url'] ?? 'https://placehold.co/150x84/eeeeee/333333?text=No+Image'; // Use episode thumbnail
$show_name = esc_html( $episode['show_name'] );
$episode_sources = $episode['sources'] ?? [];

?>
<div class="tvm-episode-detail-inner">
    <img src="<?php echo esc_url($poster_url); ?>" alt="<?php echo $show_name . ' Thumbnail'; ?>" class="tvm-episode-detail-poster">
    
    <div class="tvm-episode-detail-info">
        <h4><?php echo $show_name; ?> - S<?php echo absint($episode['season_number']); ?>E<?php echo absint($episode['episode_number']); ?></h4>
        <div class="tvm-episode-meta">
            <span><?php echo esc_html($episode['name']); ?></span>
            <span><?php esc_html_e('Air date:', 'tvm-tracker'); ?> <?php echo esc_html( date( get_option( 'date_format' ), strtotime( $release_date ) ) ); ?></span>
        </div>
        
        <p><?php echo esc_html($episode['overview']); ?></p>

        <div class="tvm-episode-action-container">
            <div class="tvm-episode-sources-detail">
                <?php 
                // Render sources using the helper function
                tvm_tracker_render_sources_list( $episode_sources, $source_map, $enabled_sources, true ); 
                ?>
            </div>

            <!-- Mark Watched Button -->
            <button type="button" 
                class="tvm-unwatched-toggle tvm-button tvm-button-watched" 
                data-episode-id="<?php echo $episode_id; ?>" 
                data-title-id="<?php echo $title_id; ?>" 
                data-is-watched="true"
            >
                <?php esc_html_e( 'Mark Watched', 'tvm-tracker' ); ?>
            </button>
            <!-- Localized status message target -->
            <div class="tvm-unwatched-status"></div>
        </div>
    </div>
</div>
