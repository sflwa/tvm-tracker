<?php
/**
 * Shortcode View: Renders a list of all PAST unwatched episodes for a show.
 *
 * This file is included via AJAX request in Tvm_Tracker_Shortcode::tvm_tracker_load_unwatched_episode_callback().
 *
 * @var array $past_episodes_list Array of episode data (passed from shortcode AJAX).
 * @var array $source_map Array of all available sources.
 * @var array $enabled_sources Array of enabled source IDs.
 * @var Tvm_Tracker_DB $db_client
 */

// Global access to ensure dependencies are available.
global $db_client, $source_map, $enabled_sources; 

// Define today's date for determining if the 'Mark Watched' button should be shown.
$today = date_i18n('Y-m-d');
$show_name = esc_html( $past_episodes_list[0]['title_name'] ?? 'Show Details' );

?>
<div class="tvm-unwatched-episode-list tvm-past-list-wrapper">
    <h3><?php echo $show_name; ?> - <?php esc_html_e( 'Past Episodes (Catch Up)', 'tvm-tracker' ); ?></h3>

    <?php if ( empty( $past_episodes_list ) ) : ?>
        <p class="tvm-empty-list"><?php esc_html_e( 'No past unwatched episodes found for this show.', 'tvm-tracker' ); ?></p>
    <?php else : ?>
        <ul class="tvm-episode-list">
            <?php foreach ( $past_episodes_list as $episode ) :
                $title_id = absint( $episode['title_id'] );
                // CRITICAL FIX: Read the Watchmode ID from the correct column name ('watchmode_id')
                $episode_id = absint( $episode['watchmode_id'] ); 
                
                // CRITICAL: Corrected key usage for V2.0 DB structure
                $release_date = $episode['air_date'] ?? '0000-00-00'; 
                // Past episodes should always be available to be marked watched (unless date is invalid)
                $can_be_watched = ( $release_date !== '0000-00-00' );

                // --- V2.0 FIX: Retrieve sources directly from DB ---
                $episode_sources = [];
                // Check for valid DB client object and method before attempting call (prevents 500 error)
                if ( is_object( $db_client ) && method_exists($db_client, 'tvm_tracker_get_episode_source_links')) {
                    // This method fetches the source name, logo, and web URL via a JOIN
                    $episode_sources = $db_client->tvm_tracker_get_episode_source_links( $title_id, $episode_id );
                }
                // --- END V2.0 FIX ---
            ?>
                <li class="tvm-episode-item tvm-unwatched-item" data-episode-id="<?php echo $episode_id; ?>">
                    <div class="tvm-episode-info-main">
                        <span class="tvm-episode-title">
                            S<?php echo absint($episode['season_number']); ?>E<?php echo absint($episode['episode_number']); ?> - <?php echo esc_html($episode['episode_name'] ?? $episode['name'] ?? ''); ?>
                        </span>
                        
                        <span class="tvm-episode-airdate">
                            <?php 
                            if ($release_date === '0000-00-00') {
                                esc_html_e('Air Date: TBA', 'tvm-tracker');
                            } else {
                                /* translators: %s: formatted release date */
                                printf( esc_html__( 'Air Date: %s', 'tvm-tracker' ), esc_html( date( get_option( 'date_format' ), strtotime( $release_date ) ) ) );
                            }
                            ?>
                        </span>
                    </div>

                    <p class="tvm-episode-overview"><?php echo esc_html($episode['plot_overview'] ?? $episode['overview'] ?? ''); ?></p>

                    <div class="tvm-episode-actions-container">
                        <!-- Streaming Sources -->
                        <div class="tvm-episode-sources-small">
                            <?php 
                            // --- V2.0 DIRECT RENDERING ---
                            $unique_source_ids = [];
                            $class = 'tvm-episode-source-logo';
                            
                            foreach ($episode_sources as $source) {
                                $source_id = absint($source['source_id'] ?? 0); 
                                $region = sanitize_text_field($source['region'] ?? '');

                                // 1. Must be enabled by the user
                                if (!in_array($source_id, $enabled_sources, true)) continue;

                                // 2. Prioritize US region
                                if (!isset($unique_source_ids[$source_id]) || $region === 'US') {
                                    $unique_source_ids[$source_id] = $source;
                                }
                            }
                            
                            // Render the unique list
                            foreach ($unique_source_ids as $source) {
                                $logo_url = esc_url($source['logo_url'] ?? '#'); 
                                $web_url = esc_url($source['web_url'] ?? '#');
                                $source_name = sanitize_text_field($source['source_name'] ?? 'Source'); 

                                if (!empty($logo_url) && $web_url !== '#') {
                                    echo '<a href="' . $web_url . '" target="_blank">';
                                    echo '<img src="' . $logo_url . '" alt="' . esc_attr($source_name) . esc_attr__(' logo', 'tvm-tracker') . '" class="' . esc_attr($class) . '">';
                                    echo '</a>';
                                }
                            }
                            // --- END V2.0 DIRECT RENDERING ---
                            ?>
                        </div>

                        <!-- Mark Watched Button -->
                        <?php if ( $can_be_watched ) : ?>
                            <button type="button" 
                                class="tvm-unwatched-toggle tvm-button tvm-button-watched" 
                                data-episode-id="<?php echo absint( $episode_id ); ?>" 
                                data-title-id="<?php echo absint( $title_id ); ?>" 
                                data-is-watched="true"
                                data-unwatched-type="past"
                            >
                                <?php esc_html_e( 'Mark Watched', 'tvm-tracker' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="tvm-unwatched-status"></div>
    <?php endif; ?>
</div>
