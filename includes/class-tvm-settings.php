<?php
/**
 * Admin Settings & Stats Logic
 *
 * Handles the registration of settings and the rendering of the 
 * System Health dashboard.
 *
 * @package TV_Movie_Tracker
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Settings {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the menu item to the WordPress Sidebar
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Tracker Settings', 'tvm-tracker' ),
			__( 'TVM Settings', 'tvm-tracker' ),
			'manage_options',
			'tvm-settings',
			array( $this, 'render_page' ),
			'dashicons-admin-network'
		);
	}

	/**
	 * Register settings in the database
	 */
	public function register_settings() {
		register_setting( 'tvm_settings_group', 'tvm_tmdb_api_key', 'sanitize_text_field' );
		register_setting( 'tvm_settings_group', 'tvm_tvmaze_api_key', 'sanitize_text_field' );
		register_setting( 'tvm_settings_group', 'tvm_watchmode_api_key', 'sanitize_text_field' );
		
		// API Counters (Updated via API Classes)
		register_setting( 'tvm_settings_group', 'tvm_api_calls_tmdb', 'absint' );
	}

	/**
	 * Compile system statistics for the dashboard
	 * * @return array
	 */
	private function get_stats() {
		global $wpdb;
		$table_progress = $wpdb->prefix . 'tvm_user_progress';

		// Count items by media type using metadata
		$movies_query = new WP_Query( array(
			'post_type'  => 'tvm_item',
			'meta_query' => array(
				array(
					'key'     => '_tvm_media_type',
					'value'   => 'movie',
					'compare' => '=',
				),
			),
		) );

		$shows_query = new WP_Query( array(
			'post_type'  => 'tvm_item',
			'meta_query' => array(
				array(
					'key'     => '_tvm_media_type',
					'value'   => 'tv',
					'compare' => '=',
				),
			),
		) );

		return array(
			'movies'     => $movies_query->found_posts,
			'shows'      => $shows_query->found_posts,
			'episodes'   => wp_count_posts( 'tvm_episode' )->publish,
			'watched'    => $wpdb->get_var( "SELECT COUNT(*) FROM $table_progress" ),
			'tmdb_calls' => get_option( 'tvm_api_calls_tmdb', 0 ),
		);
	}

	/**
	 * Render the Settings Page HTML
	 */
	public function render_page() {
		$stats = $this->get_stats();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'TV & Movie Tracker Settings', 'tvm-tracker' ); ?></h1>

			<h2 class="title"><?php esc_html_e( 'System Health & Stats', 'tvm-tracker' ); ?></h2>
			<div class="tvm-stats-dashboard" style="display: flex; flex-wrap: wrap; gap: 20px; margin: 20px 0; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<div class="stat-item" style="flex: 1; min-width: 120px;">
					<strong style="display:block; font-size: 1.5em; color: #0073aa;"><?php echo esc_html( $stats['movies'] ); ?></strong>
					<span style="color: #646970;"><?php esc_html_e( 'Movies Tracked', 'tvm-tracker' ); ?></span>
				</div>
				<div class="stat-item" style="flex: 1; min-width: 120px;">
					<strong style="display:block; font-size: 1.5em; color: #0073aa;"><?php echo esc_html( $stats['shows'] ); ?></strong>
					<span style="color: #646970;"><?php esc_html_e( 'TV Shows Tracked', 'tvm-tracker' ); ?></span>
				</div>
				<div class="stat-item" style="flex: 1; min-width: 120px;">
					<strong style="display:block; font-size: 1.5em; color: #0073aa;"><?php echo esc_html( $stats['episodes'] ); ?></strong>
					<span style="color: #646970;"><?php esc_html_e( 'Episodes Cached', 'tvm-tracker' ); ?></span>
				</div>
				<div class="stat-item" style="flex: 1; min-width: 120px;">
					<strong style="display:block; font-size: 1.5em; color: #46b450;"><?php echo esc_html( $stats['watched'] ); ?></strong>
					<span style="color: #646970;"><?php esc_html_e( 'Total Watched Marks', 'tvm-tracker' ); ?></span>
				</div>
				<div class="stat-item" style="flex: 1; min-width: 120px;">
					<strong style="display:block; font-size: 1.5em; color: #d54e21;"><?php echo esc_html( $stats['tmdb_calls'] ); ?></strong>
					<span style="color: #646970;"><?php esc_html_e( 'TMDb API Calls', 'tvm-tracker' ); ?></span>
				</div>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'tvm_settings_group' );
				?>
				<h2 class="title"><?php esc_html_e( 'API Configuration', 'tvm-tracker' ); ?></h2>
				<p><?php esc_html_e( 'Enter your credentials to connect the library to external data providers.', 'tvm-tracker' ); ?></p>
				
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="tvm_tmdb_api_key"><?php esc_html_e( 'TMDb API Token (v4)', 'tvm-tracker' ); ?></label>
						</th>
						<td>
							<input type="text" id="tvm_tmdb_api_key" name="tvm_tmdb_api_key" value="<?php echo esc_attr( get_option( 'tvm_tmdb_api_key' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Found in your TMDb Account Settings under API > Read Access Token.', 'tvm-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tvm_tvmaze_api_key"><?php esc_html_e( 'TVmaze API Key', 'tvm-tracker' ); ?></label>
						</th>
						<td>
							<input type="text" id="tvm_tvmaze_api_key" name="tvm_tvmaze_api_key" value="<?php echo esc_attr( get_option( 'tvm_tvmaze_api_key' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Optional. Only required for Premium TVmaze features.', 'tvm-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tvm_watchmode_api_key"><?php esc_html_e( 'Watchmode API Key', 'tvm-tracker' ); ?></label>
						</th>
						<td>
							<input type="text" id="tvm_watchmode_api_key" name="tvm_watchmode_api_key" value="<?php echo esc_attr( get_option( 'tvm_watchmode_api_key' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Required for streaming source discovery and deep links.', 'tvm-tracker' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
new TVM_Settings();
