<?php
/**
 * Admin Search Interface
 *
 * @package TV_Movie_Tracker
 * @version 1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Admin_Search {

	/**
	 * Constructor
	 */
	public function __construct() {
		// NEW: Handler for the Frontend Search Tab
		add_action( 'wp_ajax_tvm_search_tmdb', array( $this, 'handle_frontend_ajax_search' ) );

		add_action( 'admin_menu', array( $this, 'add_search_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * AJAX Handler for Frontend & Admin JS Search
	 * Bridges the frontend search tab to the TMDb API.
	 */
	public function handle_frontend_ajax_search() {
		// Security Check
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

		if ( empty( $query ) ) {
			wp_send_json_error( __( 'Search query is empty.', 'tvm-tracker' ) );
		}

		// Re-use the existing TMDb API instance from the core plugin
		$api = TVM_Tracker::get_instance()->tmdb;
		$results = $api->search( $query );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( $results->get_error_message() );
		}

		// Return the results to the JavaScript
		wp_send_json_success( $results );
	}

	/**
	 * Enqueue scripts and styles for the Admin Search page only.
	 */
	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();

		if ( ! $screen || false === strpos( $screen->id, 'tvm-search' ) ) {
			return;
		}

		wp_enqueue_script( 
			'tvm-admin-search', 
			TVM_URL . 'assets/js/admin-search.js', 
			array( 'jquery' ), 
			TVM_VERSION, 
			true 
		);

		wp_localize_script( 'tvm-admin-search', 'tvm_admin', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'tvm_import_nonce' ),
		) );
	}

	/**
	 * Add the Search submenu under TVM Settings
	 */
	public function add_search_menu() {
		add_submenu_page(
			'tvm-settings',
			__( 'Add New Content', 'tvm-tracker' ),
			__( 'Search TMDb', 'tvm-tracker' ),
			'manage_options',
			'tvm-search',
			array( $this, 'render_search_page' )
		);
	}

	/**
	 * Render the Search Page HTML
	 */
	public function render_search_page() {
		$search_results = array();
		$query = isset( $_GET['s_tmdb'] ) ? sanitize_text_field( wp_unslash( $_GET['s_tmdb'] ) ) : '';

		if ( ! empty( $query ) ) {
			$api = TVM_Tracker::get_instance()->tmdb;
			$search_results = $api->search( $query );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search TMDb for Library Content', 'tvm-tracker' ); ?></h1>
			
			<form method="get" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center;">
				<input type="hidden" name="page" value="tvm-search">
				<input type="text" name="s_tmdb" value="<?php echo esc_attr( $query ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Search for a movie or show...', 'tvm-tracker' ); ?>">
				<?php submit_button( __( 'Search', 'tvm-tracker' ), 'primary', '', false ); ?>
			</form>

			<?php if ( ! empty( $search_results ) && ! is_wp_error( $search_results ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 80px;"><?php esc_html_e( 'Poster', 'tvm-tracker' ); ?></th>
							<th><?php esc_html_e( 'Title', 'tvm-tracker' ); ?></th>
							<th style="width: 120px;"><?php esc_html_e( 'Type', 'tvm-tracker' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'Year', 'tvm-tracker' ); ?></th>
							<th><?php esc_html_e( 'Action', 'tvm-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $search_results as $item ) : 
							$id    = $item['id'];
							$title = isset( $item['title'] ) ? $item['title'] : $item['name'];
							$date  = isset( $item['release_date'] ) ? $item['release_date'] : (isset($item['first_air_date']) ? $item['first_air_date'] : '');
							$year  = ! empty( $date ) ? date( 'Y', strtotime( $date ) ) : __( 'TBA', 'tvm-tracker' );
							$type  = $item['media_type'];
							$type_label = $type === 'tv' ? __( 'TV Show', 'tvm-tracker' ) : __( 'Movie', 'tvm-tracker' );
							?>
							<tr id="tvm-row-<?php echo esc_attr( $id ); ?>">
								<td>
									<?php if ( ! empty( $item['poster_path'] ) ) : ?>
										<img src="https://image.tmdb.org/t/p/w92<?php echo esc_attr( $item['poster_path'] ); ?>" width="60" style="border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
									<?php else : ?>
										<div style="width: 60px; height: 90px; background: #eee; display: flex; align-items: center; justify-content: center; border-radius: 4px;">
											<span class="dashicons dashicons-format-video" style="color: #ccc;"></span>
										</div>
									<?php endif; ?>
								</td>
								<td><strong><?php echo esc_html( $title ); ?></strong></td>
								<td>
									<span class="tvm-type-badge" style="background: #f0f0f1; padding: 3px 8px; border-radius: 3px; font-size: 0.9em; font-weight: 500;">
										<?php echo esc_html( $type_label ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $year ); ?></td>
								<td>
									<button type="button" 
										class="button button-primary tvm-import-btn" 
										data-id="<?php echo esc_attr( $id ); ?>" 
										data-type="<?php echo esc_attr( $type ); ?>">
										<span class="dashicons dashicons-plus" style="vertical-align: middle; font-size: 16px; margin-top: 2px;"></span> 
										<?php esc_html_e( 'Import to Library', 'tvm-tracker' ); ?>
									</button>
									<span class="spinner" style="float: none; margin: 0 0 0 10px;"></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( is_wp_error( $search_results ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $search_results->get_error_message() ); ?></p></div>
			<?php elseif ( ! empty( $query ) ) : ?>
				<p><?php esc_html_e( 'No results found for your search.', 'tvm-tracker' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
