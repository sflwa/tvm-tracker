<?php
/**
 * Shortcode View: Initial Search Form
 *
 * @var string $permalink Base permalink for the page.
 * @var Tvm_Tracker_API $api_client
 * @var Tvm_Tracker_DB $db_client
 */

// Access shared variables
$my_tracker_url = trailingslashit( $permalink ) . 'my-shows';
$unwatched_url = trailingslashit( $my_tracker_url ) . 'unwatched';
// CRITICAL FIX: Add link to the new Upcoming Calendar Page
$upcoming_url = trailingslashit( $permalink ) . 'upcoming'; 
?>
<div class="tvm-search-form-header">
    <h3><?php esc_html_e( 'Track Shows & Movies', 'tvm-tracker' ); ?></h3>
    <div class="tvm-details-actions">
        <!-- New Link for Calendar View -->
        <a href="<?php echo esc_url( $upcoming_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'Upcoming Schedule', 'tvm-tracker' ); ?></a>
        
        <!-- Existing Links -->
        <a href="<?php echo esc_url( $unwatched_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'Unwatched Episodes', 'tvm-tracker' ); ?></a>
        <a href="<?php echo esc_url( $my_tracker_url ); ?>" class="tvm-button tvm-button-details"><?php esc_html_e( 'My Tracker', 'tvm-tracker' ); ?></a>
    </div>
</div>
<form action="<?php echo esc_url( $permalink ); ?>" method="get" class="tvm-search-form">
    <div class="tvm-input-group">
        <input type="text" name="tvm_search" placeholder="<?php esc_attr_e( 'Enter title name...', 'tvm-tracker' ); ?>" required>
        <button type="submit" class="tvm-button tvm-button-search"><?php esc_html_e( 'Search', 'tvm-tracker' ); ?></button>
    </div>
    <?php wp_nonce_field( 'tvm_search_nonce_action', 'tvm_search_nonce' ); ?>
</form>
