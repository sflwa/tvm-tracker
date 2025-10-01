<?php
/**
 * Shortcode View: Search Results
 *
 * @var string $permalink Base permalink for the page.
 * @var Tvm_Tracker_API $api_client
 * @var Tvm_Tracker_DB $db_client
 * @var string $search_query The query term.
 */

// Access shared variables
$my_tracker_url = trailingslashit( $permalink ) . 'my-shows';
$results = $api_client->tvm_tracker_search( $search_query );

/* translators: %s: Search query term */
echo '<h3>' . sprintf( esc_html__( 'Search Results for: %s', 'tvm-tracker' ), esc_html( $search_query ) ) . '</h3>';

echo '<div class="tvm-details-actions" style="justify-content: flex-start; margin-bottom: 20px;">';
echo '<a href="' . esc_url( $my_tracker_url ) . '" class="tvm-button tvm-button-back">' . esc_html__( 'My Tracker', 'tvm-tracker' ) . '</a>';
echo '</div>';


if ( is_wp_error( $results ) ) {
    echo '<p class="tvm-error-message">' . esc_html( $results->get_error_message() ) . '</p>';
    return;
}

if ( empty( $results ) ) {
    echo '<p>' . esc_html__( 'No results found. Please try a different search term.', 'tvm-tracker' ) . '</p>';
    return;
}

echo '<ul class="tvm-results-list">';
foreach ( $results as $item ) {
    // Determine icon based on type
    $icon = ( in_array( $item['type'], array( 'tv_series', 'tv_miniseries' ), true ) ) ? '📺' : '🎬';
    $details_url = trailingslashit( $permalink ) . 'details/' . absint( $item['id'] );

    // Only display TV shows or movies
    if ( $item['type'] === 'movie' || strpos( $item['type'], 'tv_' ) !== false ) {
        ?>
        <li class="tvm-result-item">
            <span class="tvm-result-icon"><?php echo $icon; // Safe emoji output ?></span>
            <span class="tvm-result-title"><?php echo esc_html( $item['name'] ); ?> (<?php echo esc_html( absint( $item['year'] ) ); ?>)</span>
            <a href="<?php echo esc_url( $details_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'View Details', 'tvm-tracker' ); ?></a>
        </li>
        <?php
    }
}
echo '</ul>';
