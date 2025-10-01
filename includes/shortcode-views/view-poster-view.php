<?php
/**
 * Shortcode Template: Poster Grid View for Tracked Shows
 *
 * @var array $tracked_shows Array of tracked show objects/data.
 * @var Tvm_Tracker_DB $db_client
 * @var string $permalink Base permalink for the page.
 */

// Poster View rendering logic
?>
<div class="tvm-poster-grid">
    <?php foreach ( $tracked_shows as $show ) :
        // Calculate progress
        $watched_count = $db_client->tvm_tracker_get_watched_episodes_count( get_current_user_id(), absint( $show->title_id ) );
        $total_count = absint( $show->total_episodes );
        $details_url = trailingslashit( $permalink ) . 'details/' . absint( $show->title_id );

        // Use null coalescing for safety, although these are typically set during render_my_tracker pre-processing
        $poster_url = $show->poster ?? '';
        $show_title = esc_attr( $show->title_name ?? '' ); 
    ?>
        <a href="<?php echo esc_url( $details_url ); ?>" class="tvm-poster-item">
            <img src="<?php echo esc_url( $poster_url ); ?>" alt="<?php echo $show_title . esc_attr__( ' Poster', 'tvm-tracker' ); ?>" onerror="this.onerror=null;this.src='<?php echo esc_url( 'https://placehold.co/200x300/eeeeee/333333?text=' . urlencode( $show_title ) ); ?>';">

            <!-- Progress Overlay -->
            <div class="tvm-poster-progress-overlay">
                <span class="tvm-progress-count">
                    <?php echo absint( $watched_count ); ?> / <?php echo absint( $total_count ); ?>
                </span>
            </div>
        </a>
    <?php endforeach; ?>
</div>
