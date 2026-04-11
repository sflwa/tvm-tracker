<?php
/**
 * Weekly Sunday Digest Mailer
 * Version 1.0.3 - Alphabetical Sorting & Layout Padding
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Weekly_Mailer {

	public function __construct() {
		add_action( 'tvm_sunday_morning_email', array( $this, 'send_weekly_digest' ) );
	}

	/**
	 * Main execution loop for the weekly cron
	 */
	public function send_weekly_digest() {
		global $wpdb;
		
		// Include Administrator, Editor, Author, and Subscriber roles
		$users = get_users( array( 'role__in' => array( 'administrator', 'editor', 'author', 'subscriber' ) ) );

		foreach ( $users as $user ) {
			$episodes = $this->get_weekly_episodes( $user->ID );
			if ( empty( $episodes ) ) continue;

			$this->mail_user( $user, $episodes );
		}
	}

	/**
	 * Fetch episodes airing in the next 7 days for a specific user
	 * Sorted by Date and then Alphabetically by Show Title
	 */
	public function get_weekly_episodes( $user_id ) {
		global $wpdb;
		$progress_table = $wpdb->prefix . 'tvm_user_progress';
		
		$tracked_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT item_id FROM $progress_table WHERE user_id = %d AND media_type = 'tv' AND season_number = 0",
			$user_id
		) );

		if ( empty( $tracked_ids ) ) return array();

		$start_date = current_time( 'Y-m-d' );
		$end_date   = date( 'Y-m-d', strtotime( '+7 days' ) );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title as ep_title, 
			        m1.meta_value as air_date, m2.meta_value as parent_id, 
			        m3.meta_value as ep_num, m4.meta_value as season_num,
			        p_parent.post_title as show_name
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_tvm_air_date'
			 JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_tvm_parent_id'
			 JOIN {$wpdb->postmeta} m3 ON p.ID = m3.post_id AND m3.meta_key = '_tvm_number'
			 JOIN {$wpdb->postmeta} m4 ON p.ID = m4.post_id AND m4.meta_key = '_tvm_season'
			 JOIN {$wpdb->posts} p_parent ON m2.meta_value = p_parent.ID
			 WHERE p.post_type = 'tvm_episode'
			 AND m1.meta_value BETWEEN %s AND %s
			 AND m2.meta_value IN (" . implode( ',', array_map( 'intval', $tracked_ids ) ) . ")
			 ORDER BY m1.meta_value ASC, p_parent.post_title ASC",
			$start_date, $end_date
		) );
	}

	private function mail_user( $user, $episodes ) {
		$to      = $user->user_email;
		$subject = 'Your Weekly TV Digest - ' . date( 'M j, Y' );
		$headers = array('Content-Type: text/html; charset=UTF-8');

		ob_start();
		?>
		<div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
			<h2 style="color: #2271b1; border-bottom: 2px solid #eee; padding-bottom: 10px;">Upcoming Episodes This Week</h2>
			
			<?php 
			$last_date = '';
			foreach ( $episodes as $ep ) : 
				$poster = get_post_meta( $ep->parent_id, '_tvm_poster_path', true );
				$show_title = $ep->show_name;
				$clean_ep_title = preg_replace('/^S\d+E\d+\s-\s/i', '', $ep->ep_title);

				// New Date Header grouping
				if ( $last_date !== $ep->air_date ) :
					$last_date = $ep->air_date;
					?>
					<h3 style="background: #f8f9fa; padding: 10px; margin: 30px 0 20px 0; border-radius: 4px; color: #1d2327; font-size: 16px; border-left: 4px solid #2271b1;">
						<?php echo date( 'l, M j', strtotime( $ep->air_date ) ); ?>
					</h3>
				<?php endif; ?>

				<div style="display: flex; gap: 0; margin-bottom: 20px; border-bottom: 1px solid #f0f0f0; padding-bottom: 20px;">
					<div style="flex: 0 0 100px;">
						<?php if ( $poster ) : ?>
							<img src="https://image.tmdb.org/t/p/w185<?php echo esc_attr( $poster ); ?>" style="width: 100px; height: 150px; object-fit: cover; border-radius: 6px; display: block;">
						<?php else : ?>
							<div style="width: 100px; height: 150px; background: #eee; border-radius: 6px; display: flex; align-items: center; justify-content: center; text-align: center; border: 1px solid #ddd;">
								<span style="font-size: 10px; color: #999; font-weight: bold; text-transform: uppercase;">NO<br>POSTER</span>
							</div>
						<?php endif; ?>
					</div>

					<div style="flex: 1; padding-left: 20px;">
						<strong style="font-size: 18px; display: block; margin-bottom: 5px; color: #1d2327;"><?php echo esc_html( $show_title ); ?></strong>
						<span style="color: #2271b1; font-weight: 700; font-size: 14px;">
							S<?php echo esc_html( $ep->season_num ); ?>E<?php echo esc_html( $ep->ep_num ); ?> - <?php echo esc_html( $clean_ep_title ); ?>
						</span>
					</div>
				</div>
			<?php endforeach; ?>

			<div style="text-align: center; font-size: 11px; color: #bbb; margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee;">
				Sent by your TV & Movie Tracker Library.
			</div>
		</div>
		<?php
		$message = ob_get_clean();

		wp_mail( $to, $subject, $message, $headers );
	}
}

new TVM_Weekly_Mailer();
