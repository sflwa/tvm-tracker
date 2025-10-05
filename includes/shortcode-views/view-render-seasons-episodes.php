<?php
/**
 * Shortcode View: Renders the collapsible seasons and episodes list.
 *
 * This file is required by view-details-page.php.
 *
 * @var int $title_id The Watchmode title ID.
 * @var array $episodes_data Episode data (from Tvm_Tracker_DB::tvm_tracker_get_all_episode_data()).
 * @var Tvm_Tracker_DB $db_client
 * @var array $source_map Master map of all sources (only used for source rendering helper).
 * @var array $enabled_sources Array of enabled source IDs.
 * @var bool $is_tracked Whether the show is currently tracked by the user.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( empty( $episodes_data ) ) {
    return;
}

// Get today's date for comparison (in WordPress time)
$today = date_i18n('Y-m-d'); 

// Convert data to associative array format if it contains objects (for robust key access)
$processed_episodes_data = array_map(function($e) {
    return (array) $e;
}, $episodes_data);


// Map episodes to seasons and calculate counts based on local DB data
$episodes_by_season = array();
$season_map = array();

foreach ( $processed_episodes_data as $episode ) {
    $season_num = absint( $episode['season_number'] );
    
    if ( $season_num > 0 ) {
        // Map episodes under their season number
        $episodes_by_season[ $season_num ][] = $episode;

        // Ensure season is represented in the map for rendering order
        if ( ! isset( $season_map[ $season_num ] ) ) {
            $season_map[ $season_num ] = [
                'number' => $season_num,
                /* Translators: %d is the season number */
                'name' => sprintf( esc_html__( 'Season %d', 'tvm-tracker' ), $season_num ),
                'overview' => $episode['plot_overview'] ?? '', // Using first episode overview as a proxy if season overview is unavailable
                'episode_count' => 0,
            ];
        }
        $season_map[ $season_num ]['episode_count']++;
    }
}

// Sort seasons numerically
ksort($season_map);

// Fetch the list of episode Watchmode IDs watched by the user
$watched_episodes = $db_client->tvm_tracker_get_watched_episodes( get_current_user_id(), $title_id );

// --- SERIES-LEVEL BULK ACTION LOGIC ---
// Count episodes that have already aired (air_date <= today)
$airing_episodes = array_filter( $processed_episodes_data, function( $e ) use ( $today ) {
    $air_date = $e['air_date'] ?? '0000-00-00';
    return ( $air_date <= $today && $air_date !== '0000-00-00' );
});
$series_airing_count = count($airing_episodes);
$watched_count = count( $watched_episodes );

// Determine if the entire series (that has aired) is considered watched
$is_series_fully_watched = ($series_airing_count > 0 && $watched_count >= $series_airing_count); 

echo '<div class="tvm-seasons-episodes">';
echo '<h3>' . esc_html__( 'Seasons', 'tvm-tracker' ) . '</h3>';

// NEW: Series-level bulk actions (Toggle only shows the next logical action)
if ($is_tracked && $series_airing_count > 0) :
    if ($is_series_fully_watched) :
        // Series is fully watched: show UNWATCHED button
        $bulk_btn_text = esc_html__('Mark All Series Unwatched', 'tvm-tracker');
        $bulk_btn_class = 'tvm-button-remove';
        $bulk_is_watched = 'false';
    else :
        // Series is NOT fully watched: show WATCHED button
        $bulk_btn_text = esc_html__('Mark All Series Watched', 'tvm-tracker');
        $bulk_btn_class = 'tvm-button-watched';
        $bulk_is_watched = 'true';
    endif;
?>
    <div class="tvm-series-bulk-actions">
        <button type="button" 
            class="tvm-button <?php echo esc_attr($bulk_btn_class); ?> tvm-bulk-toggle" 
            data-title-id="<?php echo absint($title_id); ?>" 
            data-target="series" 
            data-is-watched="<?php echo $bulk_is_watched; ?>">
            <?php echo esc_html($bulk_btn_text); ?>
        </button>
    </div>
<?php endif; ?>


<?php foreach ( $season_map as $season_num => $season ) :
    $episode_count = absint( $season['episode_count'] );
    $season_episodes = $episodes_by_season[ $season_num ] ?? array();
    
    // --- SEASON-LEVEL BULK ACTION LOGIC ---
    $season_watched_count = 0;
    $season_airing_count = 0;

    foreach ($season_episodes as $episode) {
        $air_date = $episode['air_date'] ?? '0000-00-00';
        $episode_watchmode_id = absint( $episode['watchmode_id'] );
        
        // Only count episodes that have aired
        if ( $air_date <= $today && $air_date !== '0000-00-00' ) {
            $season_airing_count++;

            // Check if this specific episode is in the watched list
            if ( in_array( $episode_watchmode_id, $watched_episodes, true ) ) {
                $season_watched_count++;
            }
        }
    }
    
    // Determine if the season is fully watched (only considering aired episodes)
    $is_season_fully_watched = ($season_airing_count > 0 && $season_watched_count >= $season_airing_count);
    
    // Determine the next bulk action for the season
    $season_bulk_action_data = [];
    if ($is_tracked && $season_airing_count > 0) {
        if ($is_season_fully_watched) {
            $season_bulk_action_data['text'] = esc_html__('Mark Season Unwatched', 'tvm-tracker');
            $season_bulk_action_data['class'] = 'tvm-button-remove';
            $season_bulk_action_data['is_watched'] = 'false';
        } else {
            $season_bulk_action_data['text'] = esc_html__('Mark Season Watched', 'tvm-tracker');
            $season_bulk_action_data['class'] = 'tvm-button-watched';
            $season_bulk_action_data['is_watched'] = 'true';
        }
    }
    // --- END SEASON-LEVEL BULK ACTION LOGIC ---
    ?>
    <div class="tvm-season" data-season-number="<?php echo absint( $season_num ); ?>">
        <div class="tvm-season-header">
            <h4>
                <?php echo esc_html( $season['name'] ); ?>
                <?php
                /* translators: %d: number of episodes in the season */
                printf( esc_html__( ' (%d episodes)', 'tvm-tracker' ), absint( $episode_count ) );
                ?>
            </h4>
            
            <!-- Season-level bulk action button (conditional display) -->
            <?php if (!empty($season_bulk_action_data)) : ?>
            <div class="tvm-season-bulk-actions">
                <button type="button"
                    class="tvm-button <?php echo esc_attr($season_bulk_action_data['class']); ?> tvm-bulk-toggle"
                    data-title-id="<?php echo absint($title_id); ?>"
                    data-target="season"
                    data-season-number="<?php echo absint($season_num); ?>"
                    data-is-watched="<?php echo $season_bulk_action_data['is_watched']; ?>"
                >
                    <?php echo esc_html($season_bulk_action_data['text']); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="tvm-season-content">
            <?php if ( ! empty( $season['overview'] ) ) : ?>
                <p><strong><?php esc_html_e( 'Overview:', 'tvm-tracker' ); ?></strong> <?php echo esc_html( $season['overview'] ); ?></p>
            <?php endif; ?>

            <?php foreach ( $season_episodes as $episode ) :
                $episode_watchmode_id = absint( $episode['watchmode_id'] );
                // Check against the array of watched episode IDs for initial state
                $is_watched = in_array( $episode_watchmode_id, $watched_episodes, true );
                $release_date = $episode['air_date'] ?? esc_html__( 'TBA', 'tvm-tracker' );
                
                // CRITICAL FIX: Determine if the episode has aired (today or before)
                $is_airing_or_past = ( $release_date <= $today && $release_date !== '0000-00-00' );

                // V2.0: Retrieve sources directly from DB using new method
                $episode_sources = $db_client->tvm_tracker_get_episode_source_links( $title_id, $episode_watchmode_id );

                // Set button state based on persistence fix
                $toggle_text = $is_watched ? esc_html__( 'Watched', 'tvm-tracker' ) : esc_html__( 'Unwatched', 'tvm-tracker' );
                $toggle_class = $is_watched ? 'tvm-button-watched' : 'tvm-button-unwatched';
            ?>
                <div class="tvm-episode" data-episode-id="<?php echo $episode_watchmode_id; ?>">
                    <div class="tvm-episode-header">
                        <div class="tvm-episode-title-status">
                            <div class="tvm-episode-actions">
                                <!-- Localized Status Message Area -->
                                <span class="tvm-local-status"></span>

                                <!-- Episode Toggle Button (Only show if tracked AND episode has aired) -->
                                <?php if ( $is_tracked && $is_airing_or_past ) : ?>
                                    <button type="button"
                                        class="tvm-episode-toggle tvm-button <?php echo esc_attr( $toggle_class ); ?>"
                                        data-episode-id="<?php echo $episode_watchmode_id; ?>"
                                        data-title-id="<?php echo absint($title_id); ?>"
                                        data-is-watched="<?php echo $is_watched ? 'true' : 'false'; ?>"
                                    >
                                        <?php echo esc_html($toggle_text); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="tvm-episode-info">
                                <span class="tvm-episode-number-title">
                                    <?php echo absint( $episode['episode_number'] ); ?>. <?php echo esc_html( $episode['episode_name'] ); ?>
                                    (<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $release_date ) ) ); ?>)
                                </span>
                                <div class="tvm-episode-sources-small">
                                    <?php 
                                    // Render sources using the helper function defined in view-render-sources-detail.php
                                    if ( function_exists( 'tvm_tracker_render_sources_list' ) ) {
                                        tvm_tracker_render_sources_list( $episode_sources, $source_map, $enabled_sources, true );
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tvm-episode-content">
                        <div class="tvm-episode-details-inner">
                            <p><?php echo esc_html( $episode['plot_overview'] ); ?></p>
                            <?php if ( ! empty( $episode['thumbnail_url'] ) ) : ?>
                                <p>
                                    <img src="<?php echo esc_url( $episode['thumbnail_url'] ); ?>" alt="<?php echo esc_attr( $episode['episode_name'] ) . esc_attr__( ' Thumbnail', 'tvm-tracker' ); ?>" style="max-width: 250px; border-radius: 4px;">
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; // Closing the episode foreach loop ?>
        </div>
    </div>
<?php endforeach; // CRITICAL FIX: Closing the season foreach loop ?>
</div>
