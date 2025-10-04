<?php
/**
 * Shortcode View: Renders the Agenda (List) View for Upcoming Episodes.
 *
 * @var string $permalink Base permalink for the page.
 * @var array $episodes_by_date Episodes grouped by air date (YYYY-MM-DD).
 * @var string $today Current date (YYYY-MM-DD).
 */

// Define the URLs for use in the action buttons
$details_base_url = trailingslashit( $permalink ) . 'details';
$current_time = time();

?>

<div class="tvm-upcoming-agenda-wrapper">
    <?php 
    // Sort dates (keys) to ensure the agenda is chronological
    $sorted_dates = array_keys($episodes_by_date);
    sort($sorted_dates);
    
    foreach ($sorted_dates as $date_key) :
        $episodes_for_day = $episodes_by_date[$date_key];
        $is_today = ($date_key === $today);
        
        $day_ts = strtotime($date_key);
        $date_display = date( get_option( 'date_format' ) . ' (l)', $day_ts );
        
        // Skip dates older than today
        if ($day_ts < $current_time && !$is_today) continue;
        
        ?>
        <div class="tvm-agenda-day">
            <h4>
                <?php echo esc_html($date_display); ?>
                <?php if ($is_today) : ?>
                    <span class="tvm-today-label"><?php esc_html_e('(TODAY)', 'tvm-tracker'); ?></span>
                <?php endif; ?>
            </h4>
            
            <ul class="tvm-agenda-list">
                <?php foreach ($episodes_for_day as $episode) :
                    $title_id = absint($episode['title_id']);
                    $episode_id = absint($episode['watchmode_id']); // V2.0 ID
                    $show_name = esc_html($episode['title_name']);
                    
                    // Check if the episode is airing today or in the past (to show the button)
                    $can_be_watched = $is_today; 
                    ?>
                    <li class="tvm-agenda-item">
                        <div class="tvm-agenda-info">
                            <span class="tvm-agenda-show-title"><?php echo $show_name; ?></span>
                            <span class="tvm-agenda-episode-detail">
                                S<?php echo absint($episode['season_number']); ?>E<?php echo absint($episode['episode_number']); ?> - <?php echo esc_html($episode['episode_name']); ?>
                            </span>
                        </div>
                        
                        <div class="tvm-agenda-actions">
                            <?php if ($can_be_watched) : ?>
                                <!-- Mark Watched Button (Only if airing TODAY) -->
                                <button type="button" 
                                    class="tvm-unwatched-toggle tvm-button tvm-button-watched" 
                                    data-episode-id="<?php echo $episode_id; ?>" 
                                    data-title-id="<?php echo $title_id; ?>" 
                                    data-is-watched="true"
                                    data-unwatched-type="upcoming"
                                >
                                    <?php esc_html_e( 'Mark Watched', 'tvm-tracker' ); ?>
                                </button>
                            <?php endif; ?>
                            
                            <!-- Removed View Details Link as requested -->
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</div>
