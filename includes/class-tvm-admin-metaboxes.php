<?php
/**
 * Admin Meta Boxes
 * Displays TMDb/Watchmode data for Items and Episodes.
 *
 * @package TV_Movie_Tracker
 * @version 1.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Admin_Metaboxes {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metaboxes' ) );
	}

	public function register_metaboxes() {
		// Library Item Box (Wide, at the bottom)
		add_meta_box(
			'tvm_item_details',
			__( 'Library Item Details & Sources', 'tvm-tracker' ),
			array( $this, 'render_item_details' ),
			'tvm_item',
			'normal', 
			'high'
		);

		// Episode Box (Sidebar)
		add_meta_box(
			'tvm_episode_details',
			__( 'Episode Information', 'tvm-tracker' ),
			array( $this, 'render_episode_details' ),
			'tvm_episode',
			'side',
			'high'
		);
	}

	/**
	 * Helper: Source Badge Styles
	 */
	private function get_source_styles( $stype ) {
		switch ( $stype ) {
			case 'sub':      return [ 'bg' => '#ecf7ed', 'text' => '#1e4620' ];
			case 'free':     return [ 'bg' => '#fff8e5', 'text' => '#856404' ];
			case 'purchase':
			case 'rent':
			case 'buy':      return [ 'bg' => '#f0f6fb', 'text' => '#00448a' ];
			default:         return [ 'bg' => '#f6f7f7', 'text' => '#1d2327' ];
		}
	}

	/**
	 * Helper: Render a specific source badge
	 */
	private function render_source_tag( $source ) {
		$style = $this->get_source_styles( $source['type'] );
		$url   = $source['web_url'] ?? '';
		$name  = $source['name'];
		
		// Validate link (Hides "Paid Plans Only" placeholders)
		$is_restricted = ( 
			empty($url) || 
			str_contains( strtolower($url), 'paid' ) || 
			str_contains( strtolower($url), 'upgrade' ) ||
			str_contains( strtolower($url), 'deeplink' )
		);

		$css = "display:flex; justify-content:space-between; align-items:center; padding:6px 10px; background:{$style['bg']}; border:1px solid #dcdcde; border-radius:6px; text-decoration:none; color:{$style['text']}; margin-bottom:6px; font-size:12px;";

		if ( $is_restricted ) {
			return "<div style='{$css} opacity:0.8; cursor:default;' title='Direct link unavailable'>
						<span style='font-weight:600;'>{$name}</span>
						<span style='font-size:9px; text-transform:uppercase; opacity:0.7;'>{$source['type']}</span>
					</div>";
		}

		return "<a href='" . esc_url($url) . "' target='_blank' style='{$css}'>
					<span style='font-weight:600;'>{$name}</span>
					<span style='font-size:9px; opacity:0.8; text-transform:uppercase;'>{$source['type']}</span>
				</a>";
	}

	/**
	 * Render Item Details (Movies/Shows)
	 */
	public function render_item_details( $post ) {
		$type         = get_post_meta( $post->ID, '_tvm_media_type', true );
		$status       = get_post_meta( $post->ID, '_tvm_status', true );
		$poster       = get_post_meta( $post->ID, '_tvm_poster_path', true );
		$sources      = get_post_meta( $post->ID, '_tvm_streaming_sources', true );
		$release_date = get_post_meta( $post->ID, '_tvm_release_date', true );

		$status_color = ( in_array(strtolower($status), ['ended', 'canceled', 'released']) ) ? '#d63638' : '#46b450';
		?>
		<div style="display: flex; gap: 24px; padding: 10px 0;">
			<?php if ( $poster ) : ?>
				<div style="flex: 0 0 160px;">
					<img src="https://image.tmdb.org/t/p/w185<?php echo esc_attr( $poster ); ?>" style="width:100%; border-radius:6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
				</div>
			<?php endif; ?>

			<div style="flex: 1;">
				<div style="display: flex; gap: 20px; margin-bottom: 20px; align-items: center;">
					<p style="margin:0;"><strong>Status:</strong> <span style="background:<?php echo $status_color; ?>; color:#fff; padding:3px 10px; border-radius:4px; font-size:11px; font-weight:600; text-transform:uppercase;"><?php echo esc_html( $status ); ?></span></p>
					
					<?php if ( 'movie' === $type && $release_date ) : 
						$date_obj = new DateTime( $release_date );
						$today    = new DateTime();
						$diff     = $today->diff( $date_obj );
						$is_future = $date_obj > $today;
					?>
						<p style="margin:0;"><strong>Release:</strong> 
							<span style="color:<?php echo $is_future ? '#2271b1' : '#50575e'; ?>; font-weight:bold;">
								<?php echo $date_obj->format('M j, Y'); ?>
							</span>
							<?php if ( $is_future ) : ?>
								<span style="background:#f0f6fb; color:#00448a; padding:2px 10px; border-radius:12px; font-size:10px; margin-left:8px; font-weight:600;">
									<?php echo $diff->format('%a'); ?> days to go
								</span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
				
				<h3 style="border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;"><?php echo ('tv' === $type) ? 'Series Availability' : 'Movie Availability'; ?></h3>
				<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px;">
					<?php 
					if ( ! empty( $sources ) ) { 
						foreach( $sources as $s ) { echo $this->render_source_tag($s); } 
					} else { 
						echo "<p style='font-style:italic; color:#666;'>No streaming data found.</p>"; 
					} 
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Episode Details (Sidebar)
	 */
	public function render_episode_details( $post ) {
		$parent_id = get_post_meta( $post->ID, '_tvm_parent_id', true );
		$sources   = get_post_meta( $post->ID, '_tvm_episode_sources', true );
		$season    = get_post_meta( $post->ID, '_tvm_season', true );
		$number    = get_post_meta( $post->ID, '_tvm_number', true );
		$air_date  = get_post_meta( $post->ID, '_tvm_air_date', true );

		if ( $parent_id ) {
			echo '<p><strong>Show:</strong> <a href="' . get_edit_post_link( $parent_id ) . '" style="text-decoration:none;">' . get_the_title( $parent_id ) . '</a></p>';
		}

		echo '<hr><h4>Episode Streaming</h4>';
		if ( ! empty( $sources ) ) {
			echo '<div style="margin-bottom:15px;">';
			foreach( array_slice($sources, 0, 5) as $s ) { echo $this->render_source_tag($s); }
			echo '</div>';
		} else {
			echo "<p style='font-size:11px; font-style:italic; color:#666;'>No specific links for this episode.</p>";
		}

		echo '<hr><p><strong>Sequence:</strong> S' . esc_html($season) . ' E' . esc_html($number) . '</p>';

		if ( $air_date ) {
			$date_obj = new DateTime( $air_date );
			$is_future = $date_obj > new DateTime();
			echo '<p><strong>Air Date:</strong> <br><span style="color:' . ($is_future ? '#2271b1' : '#50575e') . '; font-weight:bold;">' . $date_obj->format('M j, Y') . '</span>';
			echo $is_future ? ' <small>(Upcoming)</small>' : '';
			echo '</p>';
		}
	}
}
