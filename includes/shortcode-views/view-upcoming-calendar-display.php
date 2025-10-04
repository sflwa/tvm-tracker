<?php
/**
 * Shortcode View: Renders the default Calendar Grid for Upcoming Episodes.
 *
 * @var string $permalink Base permalink for the page.
 * @var array $episodes_by_date Episodes grouped by air date (YYYY-MM-DD).
 * @var array $upcoming_episodes_raw Raw list of all upcoming episodes (sorted).
 * @var string $today Current date (YYYY-MM-DD).
 */

// Define constants for the calendar structure
$days_to_display = 35; // Display 5 full weeks (7 days * 5 rows)
$current_time = time();
$date_format_day_name = get_option( 'date_format' ) . ' (l)';
$date_format_grid = 'j'; // Just the day number (e.g., 1, 25)

// Determine the first Sunday before today to start the calendar grid cleanly
$start_of_week = strtotime( 'last Sunday', $current_time );
if ( date('w', $start_of_week) != 0 ) {
    $start_of_week = strtotime( 'next Sunday', $start_of_week );
}
// If today is Sunday, we want to start on today, not last week's Sunday.
if ( date('w', $current_time) == 0 ) {
    $start_of_week = $current_time;
}
$start_of_week = strtotime( date('Y-m-d', $start_of_week) ); // Normalize time to midnight

// Array of full day names for header row
$day_names = [ 
    esc_html__('Sunday', 'tvm-tracker'), 
    esc_html__('Monday', 'tvm-tracker'), 
    esc_html__('Tuesday', 'tvm-tracker'), 
    esc_html__('Wednesday', 'tvm-tracker'), 
    esc_html__('Thursday', 'tvm-tracker'), 
    esc_html__('Friday', 'tvm-tracker'), 
    esc_html__('Saturday', 'tvm-tracker') 
];

?>

<div class="tvm-calendar-grid-wrapper">
    <h4><?php esc_html_e('Next 5 Weeks of Episodes', 'tvm-tracker'); ?></h4>
    <div class="tvm-calendar-grid">
        
        <!-- Day Headers -->
        <?php foreach ($day_names as $day_name) : ?>
            <div class="tvm-calendar-header">
                <?php echo esc_html($day_name); ?>
            </div>
        <?php endforeach; ?>

        <!-- Date Cells -->
        <?php 
        $current_day_ts = $start_of_week;
        for ($i = 0; $i < $days_to_display; $i++) :
            $date_key = date('Y-m-d', $current_day_ts);
            $is_today = ($date_key === $today);
            $is_past = ($current_day_ts < $today_timestamp);
            
            $cell_class = '';
            if ($is_today) {
                $cell_class = 'is-today';
            } elseif ($is_past) {
                $cell_class = 'is-past';
            }
            
            $episodes_for_day = $episodes_by_date[$date_key] ?? [];
            ?>
            
            <div class="tvm-calendar-cell <?php echo esc_attr($cell_class); ?>">
                <div class="tvm-calendar-date">
                    <?php echo esc_html(date($date_format_grid, $current_day_ts)); ?>
                </div>

                <div class="tvm-calendar-episodes">
                    <?php 
                    // Render episode summaries for the current day
                    foreach ($episodes_for_day as $episode) :
                        $title = $episode['title_name'] ?? esc_html__('Show', 'tvm-tracker');
                        $season = absint($episode['season_number']);
                        $ep_num = absint($episode['episode_number']);
                        ?>
                        <div class="tvm-episode-summary" title="<?php echo esc_attr($title); ?>">
                            <span class="tvm-episode-label">S<?php echo $season; ?>E<?php echo $ep_num; ?></span>
                            <span class="tvm-episode-title-short">
                                <?php 
                                // Display a truncated show title (first two words)
                                $words = explode(' ', $title);
                                echo esc_html(implode(' ', array_slice($words, 0, 2))); 
                                ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
        <?php 
            $current_day_ts = strtotime('+1 day', $current_day_ts);
        endfor; 
        ?>

    </div>
</div>

<style>
/* Basic CSS for the Calendar Grid. This should be moved to tvm-tracker-frontend.css later. */
.tvm-calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    border: 1px solid #ddd;
    border-radius: 6px;
    margin-top: 15px;
    overflow: hidden;
}
.tvm-calendar-header {
    background-color: #f0f0f0;
    font-weight: 600;
    padding: 8px 5px;
    text-align: center;
    border-bottom: 1px solid #ddd;
    border-right: 1px solid #eee;
    font-size: 0.9em;
}
.tvm-calendar-cell {
    min-height: 80px;
    padding: 5px;
    border-right: 1px solid #eee;
    border-bottom: 1px solid #eee;
    background-color: #fff;
    position: relative;
    font-size: 0.8em;
}
.tvm-calendar-date {
    font-weight: 700;
    font-size: 1.1em;
    color: #333;
    margin-bottom: 3px;
}
.tvm-calendar-cell.is-past {
    background-color: #fafafa;
    color: #999;
}
.tvm-calendar-cell.is-today {
    background-color: #e6f7ff;
    border: 2px solid #0073aa;
    margin: -1px; /* Correct margin overlap */
    z-index: 10;
}
.tvm-episode-summary {
    background-color: #46b450;
    color: #fff;
    padding: 2px 4px;
    margin-bottom: 2px;
    border-radius: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.tvm-episode-label {
    font-weight: 700;
    margin-right: 3px;
}
</style>
<?php
// End of view-upcoming-calendar-display.php
