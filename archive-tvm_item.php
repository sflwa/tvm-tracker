<?php
/**
 * Archive Template for CPT: tvm_item (Admin Library Archive Index)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// RESTRICT ARCHIVE VIEW TO ADMINS ONLY
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 
		__( 'You do not have permission to view this administrative archive page.', 'tvm-tracker' ), 
		__( 'Access Denied', 'tvm-tracker' ), 
		array( 'response' => 403 ) 
	);
}

get_header();

// Process Filter Input Parameters
$filter_type   = isset( $_GET['media_type'] ) ? sanitize_text_field( $_GET['media_type'] ) : '';
$filter_status = isset( $_GET['item_status'] ) ? sanitize_text_field( $_GET['item_status'] ) : '';
$search_query  = isset( $_GET['tvm_search'] ) ? sanitize_text_field( $_GET['tvm_search'] ) : '';

// Build Meta Query Array
$meta_query = array();

if ( ! empty( $filter_type ) ) {
	$meta_query[] = array(
		'key'     => '_tvm_media_type',
		'value'   => $filter_type,
		'compare' => '=',
	);
}

if ( ! empty( $filter_status ) ) {
	$meta_query[] = array(
		'key'     => '_tvm_status',
		'value'   => $filter_status,
		'compare' => '=',
	);
}

// WP_Query Config: 100 per page, Alpha Sorted
$paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : ( ( get_query_var( 'page' ) ) ? get_query_var( 'page' ) : 1 );

$args = array(
	'post_type'      => 'tvm_item',
	'posts_per_page' => 100,
	'paged'          => $paged,
	's'              => $search_query,
	'meta_query'     => $meta_query,
	'orderby'        => 'title',
	'order'          => 'ASC',
);

$archive_query = new WP_Query( $args );
?>

<div class="tvm-archive-wrapper" style="max-width: 1600px; margin: 30px auto; padding: 0 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
	
	<!-- Archive Header -->
	<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
		<div>
			<h1 style="margin: 0; font-size: 2em; color: #0f172a; font-weight: 800;"><?php _e( 'Master Library Index', 'tvm-tracker' ); ?></h1>
			<p style="margin: 4px 0 0 0; color: #64748b; font-size: 0.95em;">
				<?php printf( __( 'Showing %d of %d total library items', 'tvm-tracker' ), $archive_query->post_count, $archive_query->found_posts ); ?>
			</p>
		</div>
		
		<div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
			<button type="button" id="tvm-bulk-sync-status" class="button button-secondary button-large" style="display: inline-flex; align-items: center; gap: 5px;">
				<span class="dashicons dashicons-update-alt" style="margin-top: 2px;"></span> <?php _e( 'Sync Page Statuses', 'tvm-tracker' ); ?>
			</button>
			
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=tvm-search' ) ); ?>" class="button button-primary button-large" style="display: inline-flex; align-items: center; gap: 5px;">
				<span class="dashicons dashicons-plus-alt" style="margin-top: 2px;"></span> <?php _e( 'Add Content (TMDb)', 'tvm-tracker' ); ?>
			</a>
			<span id="tvm-bulk-progress" style="font-weight: 600; color: #0284c7; font-size: 0.9em; width: 100%;"></span>
		</div>
	</div>

	<!-- Search & Filter Bar -->
	<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px 20px; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
		<form method="get" action="<?php echo esc_url( get_post_type_archive_link( 'tvm_item' ) ); ?>" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
			
			<div style="flex: 2; min-width: 220px;">
				<label style="display: block; font-size: 0.8em; font-weight: 700; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;"><?php _e( 'Search', 'tvm-tracker' ); ?></label>
				<input type="text" name="tvm_search" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php _e( 'Search by title...', 'tvm-tracker' ); ?>" style="width: 100%; height: 36px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 0 10px; font-size: 0.9em;">
			</div>

			<div style="flex: 1; min-width: 140px;">
				<label style="display: block; font-size: 0.8em; font-weight: 700; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;"><?php _e( 'Type', 'tvm-tracker' ); ?></label>
				<select name="media_type" style="width: 100%; height: 36px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 0.9em;">
					<option value=""><?php _e( 'All Types', 'tvm-tracker' ); ?></option>
					<option value="tv" <?php selected( $filter_type, 'tv' ); ?>><?php _e( 'TV Show', 'tvm-tracker' ); ?></option>
					<option value="movie" <?php selected( $filter_type, 'movie' ); ?>><?php _e( 'Movie', 'tvm-tracker' ); ?></option>
				</select>
			</div>

			<div style="flex: 1; min-width: 140px;">
				<label style="display: block; font-size: 0.8em; font-weight: 700; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;"><?php _e( 'Status', 'tvm-tracker' ); ?></label>
				<select name="item_status" style="width: 100%; height: 36px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 0.9em;">
					<option value=""><?php _e( 'All Statuses', 'tvm-tracker' ); ?></option>
					<option value="Running" <?php selected( $filter_status, 'Running' ); ?>><?php _e( 'Running', 'tvm-tracker' ); ?></option>
					<option value="Ended" <?php selected( $filter_status, 'Ended' ); ?>><?php _e( 'Ended', 'tvm-tracker' ); ?></option>
					<option value="Released" <?php selected( $filter_status, 'Released' ); ?>><?php _e( 'Released', 'tvm-tracker' ); ?></option>
					<option value="Canceled" <?php selected( $filter_status, 'Canceled' ); ?>><?php _e( 'Canceled', 'tvm-tracker' ); ?></option>
				</select>
			</div>

			<div style="display: flex; gap: 8px;">
				<button type="submit" class="button button-secondary" style="height: 36px; padding: 0 16px; font-weight: 600; font-size: 0.9em;"><?php _e( 'Filter', 'tvm-tracker' ); ?></button>
				<?php if ( ! empty( $filter_type ) || ! empty( $filter_status ) || ! empty( $search_query ) ) : ?>
					<a href="<?php echo esc_url( get_post_type_archive_link( 'tvm_item' ) ); ?>" class="button" style="height: 36px; line-height: 34px; color: #dc2626; border-color: #fca5a5; font-size: 0.9em; text-decoration: none;"><?php _e( 'Reset', 'tvm-tracker' ); ?></a>
				<?php endif; ?>
			</div>

		</form>
	</div>

	<!-- Grid Container (10 Columns per Row) -->
	<?php if ( $archive_query->have_posts() ) : ?>
		<div class="tvm-10-col-grid" style="display: grid; grid-template-columns: repeat(10, minmax(0, 1fr)); gap: 10px;">
			<?php while ( $archive_query->have_posts() ) : $archive_query->the_post(); 
				$item_id     = get_the_ID();
				$m_type      = get_post_meta( $item_id, '_tvm_media_type', true );
				$m_status    = get_post_meta( $item_id, '_tvm_status', true );
				$poster_path = get_post_meta( $item_id, '_tvm_poster_path', true );
				
				// Status Color Mapping
				$status_clean = strtolower( $m_status );
				$status_bg    = '#64748b'; // default gray
				if ( in_array( $status_clean, array( 'ended', 'canceled' ) ) ) {
					$status_bg = '#ef4444'; // red
				} elseif ( in_array( $status_clean, array( 'running', 'returning series' ) ) ) {
					$status_bg = '#10b981'; // green
				} elseif ( $status_clean === 'released' ) {
					$status_bg = '#0284c7'; // blue
				}
				?>
				
				<div class="tvm-card-item" data-id="<?php echo esc_attr( $item_id ); ?>" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; transition: transform 0.15s ease, box-shadow 0.15s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
					
					<!-- Poster Container -->
					<a href="<?php the_permalink(); ?>" style="position: relative; width: 100%; aspect-ratio: 2 / 3; display: block; background: #f1f5f9; overflow: hidden;">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'medium', array( 'style' => 'width:100%; height:100%; object-fit:cover; display:block;' ) ); ?>
						<?php elseif ( ! empty( $poster_path ) ) : ?>
							<img src="https://image.tmdb.org/t/p/w342<?php echo esc_attr( $poster_path ); ?>" alt="<?php the_title_attribute(); ?>" style="width:100%; height:100%; object-fit:cover; display:block;" loading="lazy">
						<?php else : ?>
							<div style="height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; text-align:center; padding: 5px;">
								<span class="dashicons dashicons-format-video" style="font-size:24px; width:24px; height:24px;"></span>
								<span style="font-size: 0.7em; margin-top: 3px;"><?php _e( 'No Image', 'tvm-tracker' ); ?></span>
							</div>
						<?php endif; ?>

						<!-- Media Type Badge (Top Left) -->
						<span style="position: absolute; top: 4px; left: 4px; background: rgba(15, 23, 42, 0.85); color: #fff; padding: 2px 4px; border-radius: 3px; font-size: 0.6em; font-weight: 700; text-transform: uppercase; backdrop-filter: blur(4px);">
							<?php echo esc_html( strtoupper( $m_type ? $m_type : 'TV' ) ); ?>
						</span>
					</a>

					<!-- Card Footer (Title & Status Only) -->
					<div style="padding: 6px 8px 8px 8px; flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
						<h3 style="margin: 0 0 6px 0; font-size: 0.8em; font-weight: 700; color: #0f172a; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
							<a href="<?php the_permalink(); ?>" style="text-decoration: none; color: inherit;"><?php the_title(); ?></a>
						</h3>

						<?php if ( $m_status ) : ?>
							<div>
								<span style="display: inline-block; background: <?php echo $status_bg; ?>; color: #fff; padding: 2px 5px; border-radius: 3px; font-size: 0.6em; font-weight: 700; text-transform: uppercase;">
									<?php echo esc_html( $m_status ); ?>
								</span>
							</div>
						<?php endif; ?>
					</div>

				</div>

			<?php endwhile; wp_reset_postdata(); ?>
		</div>

		<!-- Pagination Navigation -->
		<div style="margin-top: 35px; text-align: center;">
			<?php 
			echo paginate_links( array(
				'total'        => $archive_query->max_num_pages,
				'current'      => $paged,
				'prev_text'    => '&laquo; ' . __( 'Previous', 'tvm-tracker' ),
				'next_text'    => __( 'Next', 'tvm-tracker' ) . ' &raquo;',
				'type'         => 'plain',
			) );
			?>
		</div>

	<?php else : ?>
		<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 40px; text-align: center; color: #64748b;">
			<span class="dashicons dashicons-search" style="font-size: 32px; width:32px; height:32px; margin-bottom: 10px;"></span>
			<p style="margin: 0; font-size: 1.05em; font-weight: 600;"><?php _e( 'No library items match your current filter criteria.', 'tvm-tracker' ); ?></p>
		</div>
	<?php endif; ?>

</div>

<!-- CSS Rules for Responsive Grid Fallback on Smaller Screens -->
<style>
@media (max-width: 1500px) {
	.tvm-10-col-grid { grid-template-columns: repeat(8, minmax(0, 1fr)) !important; }
}
@media (max-width: 1200px) {
	.tvm-10-col-grid { grid-template-columns: repeat(6, minmax(0, 1fr)) !important; }
}
@media (max-width: 900px) {
	.tvm-10-col-grid { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }
}
@media (max-width: 600px) {
	.tvm-10-col-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
}
</style>

<!-- Bulk Status Sync Script -->
<script type="text/javascript">
jQuery(document).ready(function($) {
    // Ensure tvm_app variables exist locally
    var tvmApp = window.tvm_app || {
        ajax_url: '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
        nonce: '<?php echo esc_js( wp_create_nonce( 'tvm_ajax_nonce' ) ); ?>'
    };

    $('#tvm-bulk-sync-status').on('click', function(e) {
        e.preventDefault();

        // Collect all post IDs rendered on screen in the cards
        var ids = [];
        $('.tvm-card-item').each(function() {
            var id = $(this).data('id');
            if (id) {
                ids.push(id);
            }
        });

        if (ids.length === 0) {
            alert('No items found on this page to sync.');
            return;
        }

        if (!confirm('This will update status values for all ' + ids.length + ' items on this page. Continue?')) {
            return;
        }

        var $btn = $(this);
        var $progress = $('#tvm-bulk-progress');

        $btn.prop('disabled', true);
        
        var total = ids.length;
        var completed = 0;

        function processNextItem() {
            if (ids.length === 0) {
                $progress.text('Page status sync complete! Reloading...').css('color', '#16a34a');
                setTimeout(function() {
                    location.reload();
                }, 1000);
                return;
            }

            var currentId = ids.shift();
            $progress.text('Updating item ' + (completed + 1) + ' of ' + total + '...').css('color', '#0284c7');

            $.post(tvmApp.ajax_url, {
                action: 'tvm_sync_series_status',
                post_id: currentId,
                nonce: tvmApp.nonce
            }).always(function() {
                completed++;
                setTimeout(processNextItem, 250); // 250ms spacing between calls
            });
        }

        processNextItem();
    });
});
</script>

<?php
get_footer();
