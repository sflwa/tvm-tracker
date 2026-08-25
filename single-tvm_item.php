<?php
/**
 * Single Template for TV & Movie Tracker Items (CPT: tvm_item)
 * Restricted Access: Admins Only
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// RESTRICT SINGLE VIEW TO ADMINS ONLY
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 
		__( 'You do not have permission to view this administrative page.', 'tvm-tracker' ), 
		__( 'Access Denied', 'tvm-tracker' ), 
		array( 'response' => 403 ) 
	);
}

get_header();

while ( have_posts() ) :
	the_post();
	$post_id     = get_the_ID();
	$media_type  = get_post_meta( $post_id, '_tvm_media_type', true );
	$tvmaze_id   = get_post_meta( $post_id, '_tvm_tvmaze_id', true );
	$tmdb_id     = get_post_meta( $post_id, '_tvm_tmdb_id', true );
	$imdb_id     = get_post_meta( $post_id, '_tvm_imdb_id', true );
	$last_synced = get_post_meta( $post_id, '_tvm_last_sync', true );

	// Formatted Meta Fields
	$status       = get_post_meta( $post_id, '_tvm_status', true );
	$network      = get_post_meta( $post_id, '_tvm_network', true );
	$genres       = get_post_meta( $post_id, '_tvm_genres', true );
	$release_date = get_post_meta( $post_id, '_tvm_release_date', true );
	$poster_path  = get_post_meta( $post_id, '_tvm_poster_path', true );
	$sources      = get_post_meta( $post_id, '_tvm_streaming_sources', true );

	// Formatted Dates
	$last_synced_formatted  = $last_synced ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_synced ) ) : __( 'Never', 'tvm-tracker' );
	$release_date_formatted = $release_date ? date_i18n( get_option( 'date_format' ), strtotime( $release_date ) ) : __( 'TBA', 'tvm-tracker' );

	// Episode Count & Season Breakdown
	global $wpdb;
	$episodes_count = 0;
	$seasons_count  = 0;
	if ( 'tv' === $media_type || ! $media_type ) {
		$episodes_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p 
			 INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_tvm_parent_id'
			 WHERE p.post_type = 'tvm_episode' AND m.meta_value = %d",
			$post_id
		) );

		$seasons_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT m.meta_value) FROM {$wpdb->posts} p 
			 INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_tvm_season'
			 INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_parent_id'
			 WHERE p.post_type = 'tvm_episode' AND m2.meta_value = %d",
			$post_id
		) );
	}

	// Query User Progress Tracking
	$progress_table = $wpdb->prefix . 'tvm_user_progress';
	$tracking_users = $wpdb->get_results( $wpdb->prepare(
		"SELECT user_id, COUNT(id) as watched_count, MAX(watched_at) as last_watched 
		 FROM {$progress_table} 
		 WHERE item_id = %d 
		 GROUP BY user_id",
		$post_id
	) );

	// Query Raw Metadata
	$all_meta = get_post_meta( $post_id );
	
	// Direct link back to the plugin's library archive page
	$archive_url = get_post_type_archive_link( 'tvm_item' );
	if ( ! $archive_url ) {
		$archive_url = admin_url( 'edit.php?post_type=tvm_item' );
	}
	?>

	<div class="tvm-single-item-wrapper" style="max-width: 1200px; margin: 30px auto; padding: 0 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
		
		<!-- Dynamic Back to Archive Index Link -->
		<div style="margin-bottom: 20px;">
			<a href="<?php echo esc_url( $archive_url ); ?>" class="button button-secondary" style="text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
				<span class="dashicons dashicons-arrow-left-alt2"></span> <?php _e( 'Back to Library Index', 'tvm-tracker' ); ?>
			</a>
		</div>

		<article id="post-<?php the_ID(); ?>" <?php post_class( 'tvm-single-item' ); ?>>
			
			<div class="tvm-single-grid" style="display: flex; gap: 30px; flex-wrap: wrap;">
				
				<!-- Left Column: Poster & Details -->
				<div class="tvm-sidebar-col" style="flex: 1; min-width: 280px; max-width: 320px;">
					
					<div class="tvm-poster-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'large', array( 'style' => 'width:100%; height:auto; border-radius: 8px; display:block;' ) ); ?>
						<?php elseif ( ! empty( $poster_path ) ) : ?>
							<img src="https://image.tmdb.org/t/p/w500<?php echo esc_attr( $poster_path ); ?>" alt="<?php the_title_attribute(); ?>" style="width:100%; height:auto; border-radius: 8px; display:block;">
						<?php else : ?>
							<div style="background: #f1f5f9; height: 380px; display: flex; flex-direction: column; align-items: center; justify-content: center; border-radius: 8px; color: #94a3b8;">
								<span class="dashicons dashicons-format-image" style="font-size: 48px; width:48px; height:48px;"></span>
								<p style="margin-top: 10px; font-weight: 600;"><?php _e( 'No Poster Available', 'tvm-tracker' ); ?></p>
							</div>
						<?php endif; ?>
					</div>

					<div class="tvm-info-card" style="margin-top: 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px;">
						<h3 style="margin-top: 0; font-size: 1.1em; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
							<span class="dashicons dashicons-info" style="color: #0284c7;"></span> <?php _e( 'Details & Specs', 'tvm-tracker' ); ?>
						</h3>
						
						<ul style="list-style: none; padding: 0; margin: 0; font-size: 0.95em; line-height: 1.8; color: #334155;">
							<li style="display: flex; justify-content: space-between; border-bottom: 1px solid #f8fafc; padding: 6px 0;">
								<strong><?php _e( 'Type:', 'tvm-tracker' ); ?></strong>
								<span style="text-transform: uppercase; font-weight: 700; color: #0284c7;"><?php echo esc_html( $media_type ? $media_type : 'TV' ); ?></span>
							</li>

							<?php if ( $status ) : ?>
								<li style="display: flex; justify-content: space-between; border-bottom: 1px solid #f8fafc; padding: 6px 0;">
									<strong><?php _e( 'Status:', 'tvm-tracker' ); ?></strong>
									<span><?php echo esc_html( $status ); ?></span>
								</li>
							<?php endif; ?>

							<li style="display: flex; justify-content: space-between; border-bottom: 1px solid #f8fafc; padding: 6px 0;">
								<strong><?php _e( 'Release/Air Date:', 'tvm-tracker' ); ?></strong>
								<span><?php echo esc_html( $release_date_formatted ); ?></span>
							</li>

							<?php if ( $network ) : ?>
								<li style="display: flex; justify-content: space-between; border-bottom: 1px solid #f8fafc; padding: 6px 0;">
									<strong><?php _e( 'Network:', 'tvm-tracker' ); ?></strong>
									<span><?php echo esc_html( $network ); ?></span>
								</li>
							<?php endif; ?>

							<?php if ( $episodes_count > 0 ) : ?>
								<li style="display: flex; justify-content: space-between; border-bottom: 1px solid #f8fafc; padding: 6px 0;">
									<strong><?php _e( 'Seasons / Episodes:', 'tvm-tracker' ); ?></strong>
									<span><?php echo esc_html( $seasons_count . ' S (' . $episodes_count . ' Ep)' ); ?></span>
								</li>
							<?php endif; ?>
						</ul>
					</div>

					<!-- Database Utilities Card -->
					<div class="tvm-admin-card" style="margin-top: 20px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 20px;">
						<h4 style="margin-top: 0; font-size: 1em; color: #334155;"><?php _e( 'Admin Control', 'tvm-tracker' ); ?></h4>
						<p style="font-size: 0.85em; color: #64748b; margin-bottom: 12px;">
							<strong><?php _e( 'Last Synced:', 'tvm-tracker' ); ?></strong><br>
							<span><?php echo esc_html( $last_synced_formatted ); ?></span>
						</p>

						<button type="button" class="button button-primary tvm-force-sync-btn" data-show-id="<?php echo esc_attr( $post_id ); ?>" style="width: 100%;">
							<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> <?php _e( 'Force Sync Metadata', 'tvm-tracker' ); ?>
						</button>

						<button type="button" class="button button-secondary tvm-update-status-btn" data-show-id="<?php echo esc_attr( $post_id ); ?>" style="width: 100%; margin-top: 10px;">
							<span class="dashicons dashicons-update-alt" style="vertical-align: middle;"></span> <?php _e( 'Update Status Only', 'tvm-tracker' ); ?>
						</button>

						<div class="tvm-sync-status-msg" style="margin-top: 8px; font-size: 0.85em; font-weight: 600;"></div>
					</div>

				</div>

				<!-- Right Column: Content & Reports -->
				<div class="tvm-main-col" style="flex: 2; min-width: 320px;">
					
					<h1 style="margin: 0 0 15px 0; font-size: 2.4em; color: #0f172a; font-weight: 800;"><?php the_title(); ?></h1>

					<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
						<h3 style="margin-top: 0; font-size: 1.2em; color: #1e293b; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;"><?php _e( 'Synopsis', 'tvm-tracker' ); ?></h3>
						<div style="line-height: 1.7; color: #334155;">
							<?php the_content(); ?>
						</div>
					</div>

					<!-- USER TRACKING REPORT -->
					<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
						<h3 style="margin-top: 0; font-size: 1.2em; color: #1e293b; border-bottom: 2px solid #0284c7; padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
							<span class="dashicons dashicons-groups" style="color: #0284c7;"></span> <?php _e( 'User Tracking Activity', 'tvm-tracker' ); ?>
						</h3>

						<?php if ( ! empty( $tracking_users ) ) : ?>
							<table class="widefat fixed striped" style="width: 100%; border-collapse: collapse; margin-top: 15px; border-radius: 8px; overflow: hidden;">
								<thead>
									<tr style="background: #f8fafc; text-align: left;">
										<th style="padding: 12px; border-bottom: 1px solid #e2e8f0;"><?php _e( 'User', 'tvm-tracker' ); ?></th>
										<th style="padding: 12px; border-bottom: 1px solid #e2e8f0;"><?php _e( 'Logged Activity', 'tvm-tracker' ); ?></th>
										<th style="padding: 12px; border-bottom: 1px solid #e2e8f0;"><?php _e( 'Last Active', 'tvm-tracker' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $tracking_users as $user_row ) : 
										$user_info = get_userdata( $user_row->user_id );
										$user_name = $user_info ? $user_info->display_name : __( 'Unknown User ID: ', 'tvm-tracker' ) . $user_row->user_id;
										$last_active = $user_row->last_watched ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $user_row->last_watched ) ) : __( 'N/A', 'tvm-tracker' );
										?>
										<tr>
											<td style="padding: 12px; border-bottom: 1px solid #f1f5f9;">
												<strong><?php echo esc_html( $user_name ); ?></strong>
											</td>
											<td style="padding: 12px; border-bottom: 1px solid #f1f5f9;">
												<span style="background: #0284c7; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 0.85em; font-weight: 600;">
													<?php echo esc_html( $user_row->watched_count ); ?> <?php _e( 'Records', 'tvm-tracker' ); ?>
												</span>
											</td>
											<td style="padding: 12px; border-bottom: 1px solid #f1f5f9; color: #64748b;">
												<?php echo esc_html( $last_active ); ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p style="color: #64748b; font-style: italic; margin-top: 15px;"><?php _e( 'No user tracking records exist for this item yet.', 'tvm-tracker' ); ?></p>
						<?php endif; ?>
					</div>

					<!-- RAW METADATA INSPECTOR -->
					<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px;">
						<details>
							<summary style="font-weight: 700; cursor: pointer; color: #334155; font-size: 1.05em; display: flex; align-items: center; gap: 8px;">
								<span class="dashicons dashicons-database" style="color: #64748b;"></span> <?php _e( 'Developer Raw Meta Inspector', 'tvm-tracker' ); ?> (<?php echo count( $all_meta ); ?> keys)
							</summary>
							<div style="margin-top: 15px; max-height: 400px; overflow-y: auto; background: #0f172a; color: #38bdf8; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 0.85em;">
								<?php 
								ksort( $all_meta );
								foreach ( $all_meta as $meta_key => $meta_values ) : 
									if ( in_array( $meta_key, array( '_edit_lock', '_edit_last' ) ) ) continue;
									$value = count( $meta_values ) === 1 ? maybe_unserialize( $meta_values[0] ) : array_map( 'maybe_unserialize', $meta_values );
									?>
									<div style="margin-bottom: 10px; border-bottom: 1px solid #1e293b; padding-bottom: 6px;">
										<strong style="color: #f43f5e;"><?php echo esc_html( $meta_key ); ?>:</strong>
										<pre style="margin: 4px 0 0 10px; white-space: pre-wrap; word-break: break-all; color: #a5f3fc; background: transparent; padding: 0;"><?php echo esc_html( print_r( $value, true ) ); ?></pre>
									</div>
								<?php endforeach; ?>
							</div>
						</details>
					</div>

				</div>

			</div>

		</article>
	</div>

	<!-- Admin Control Button Handlers -->
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Force Sync Metadata Handler
			$('.tvm-force-sync-btn').on('click', function(e) {
				e.preventDefault();

				var $btn = $(this);
				var $msg = $('.tvm-sync-status-msg');
				var showId = $btn.data('show-id');

				$btn.prop('disabled', true);
				$msg.text('Syncing with external APIs...').css('color', '#64748b');

				$.ajax({
					url: tvm_app.ajax_url,
					type: 'POST',
					data: {
						action: 'tvm_sync_series',
						post_id: showId,
						nonce: tvm_app.nonce
					},
					success: function(response) {
						$btn.prop('disabled', false);
						if (response.success) {
							$msg.text('Sync successful! Reloading...').css('color', '#16a34a');
							setTimeout(function() {
								location.reload();
							}, 1000);
						} else {
							$msg.text(response.data || 'Sync failed.').css('color', '#dc2626');
						}
					},
					error: function(xhr, status, error) {
						$btn.prop('disabled', false);
						$msg.text('Communication error (' + xhr.status + ').').css('color', '#dc2626');
					}
				});
			});

			// Update Status Only Handler
			$('.tvm-update-status-btn').on('click', function(e) {
				e.preventDefault();

				var $btn = $(this);
				var $msg = $('.tvm-sync-status-msg');
				var showId = $btn.data('show-id');

				$btn.prop('disabled', true);
				$msg.text('Updating status...').css('color', '#64748b');

				$.ajax({
					url: tvm_app.ajax_url,
					type: 'POST',
					data: {
						action: 'tvm_sync_series_status',
						post_id: showId,
						nonce: tvm_app.nonce
					},
					success: function(response) {
						$btn.prop('disabled', false);
						if (response.success) {
							$msg.text('Status updated! Reloading...').css('color', '#16a34a');
							setTimeout(function() {
								location.reload();
							}, 1000);
						} else {
							$msg.text(response.data || 'Status update failed.').css('color', '#dc2626');
						}
					},
					error: function(xhr, status, error) {
						$btn.prop('disabled', false);
						$msg.text('Communication error (' + xhr.status + ').').css('color', '#dc2626');
					}
				});
			});
		});
	</script>

<?php
endwhile;

get_footer();
