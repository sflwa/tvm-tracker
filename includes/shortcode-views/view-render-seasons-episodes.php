<?php
/**
 * Shortcode View: Renders the collapsible seasons and episodes list.
 *
 * This file is required by view-details-page.php.
 *
 * @var int $title_id The Watchmode title ID.
 * @var array $seasons_data Season data.
 * @var array $episodes_data Episode data.
 * @var Tvm_Tracker_DB $db_client
 * @var array $source_map Master map of all sources.
 * @var array $enabled_sources Array of enabled source IDs.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( empty( $seasons_data ) || empty( $episodes_data ) ) {
    return;
}

// Map episodes to seasons for easy lookup
$episodes_by_season = array();
foreach ( $episodes_data as $episode ) {
    $season_num = absint( $episode['season_number'] ?? $episode['number'] ?? 0 ); // Fallback to 'number' if 'season_number' is missing
    if ( $season_num > 0 ) {
        $episodes_by_season[ $season_num ][] = $episode;
    }
}

$watched_episodes = $db_client->tvm_tracker_get_watched_episodes( get_current_user_id(), $title_id );

echo '<div class="tvm-seasons-episodes">';
echo '<h3>' . esc_html__( 'Seasons', 'tvm-tracker' ) . '</h3>';

foreach ( $seasons_data as $season ) {
    $season_num = absint( $season['number'] ?? 0 );
    $episode_count = absint( $season['episode_count'] ?? 0 );
    $season_episodes = $episodes_by_season[ $season_num ] ?? array();
    
    if ( $season_num === 0 ) {
        continue;
    }
    
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
        </div>
        <div class="tvm-season-content">
            <?php if ( ! empty( $season['overview'] ) ) : ?>
                <p><strong><?php esc_html_e( 'Overview:', 'tvm-tracker' ); ?></strong> <?php echo esc_html( $season['overview'] ); ?></p>
            <?php endif; ?>

            <?php foreach ( $season_episodes as $episode ) :
                $episode_id = absint( $episode['id'] );
                // Check against the array of watched episode IDs for initial state
                $is_watched = in_array( $episode_id, $watched_episodes, true );
                $release_date = $episode['release_date'] ?? esc_html__( 'TBA', 'tvm-tracker' );
                $episode_sources = $episode['sources'] ?? array();

                // Set button state based on persistence fix (v1.1.34)
                $toggle_text = $is_watched ? esc_html__( 'Watched', 'tvm-tracker' ) : esc_html__( 'Unwatched', 'tvm-tracker' );
                $toggle_class = $is_watched ? 'tvm-button-watched' : 'tvm-button-unwatched';
            ?>
                <div class="tvm-episode" data-episode-id="<?php echo $episode_id; ?>">
                    <div class="tvm-episode-header">
                        <div class="tvm-episode-title-status">
                            <div class="tvm-episode-actions">
                                <!-- Localized Status Message Area -->
                                <span class="tvm-local-status"></span>

                                <!-- Episode Toggle Button -->
                                <button type="button"
                                    class="tvm-episode-toggle tvm-button <?php echo esc_attr( $toggle_class ); ?>"
                                    data-episode-id="<?php echo $episode_id; ?>"
                                    data-title-id="<?php echo $title_id; ?>"
                                    data-is-watched="<?php echo $is_watched ? 'true' : 'false'; ?>"
                                >
                                    <?php echo $toggle_text; ?>
                                </button>
                            </div>
                            <div class="tvm-episode-info">
                                <span class="tvm-episode-number-title">
                                    <?php echo esc_html( $episode['episode_number'] ); ?>. <?php echo esc_html( $episode['name'] ); ?>
                                    (<?php echo esc_html( date( get_option( 'date_format' ), strtotime( $release_date ) ) ); ?>)
                                </span>
                                <div class="tvm-episode-sources-small">
                                    <?php 
                                    // Render sources using the helper function defined in view-details-page.php
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
                            <p><?php echo esc_html( $episode['overview'] ); ?></p>
                            <?php if ( ! empty( $episode['thumbnail_url'] ) ) : ?>
                                <p>
                                    <img src="<?php echo esc_url( $episode['thumbnail_url'] ); ?>" alt="<?php echo esc_attr( $episode['name'] ) . esc_attr__( ' Thumbnail', 'tvm-tracker' ); ?>" style="max-width: 250px; border-radius: 4px;">
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
echo '</div>';
