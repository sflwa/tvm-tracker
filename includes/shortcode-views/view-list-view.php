<?php
/**
 * Shortcode Template: List View for Tracked Shows
 *
 * @var array $tracked_shows Array of tracked show objects/data.
 * @var Tvm_Tracker_DB $db_client
 * @var string $permalink Base permalink for the page.
 */

// List View rendering logic
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
