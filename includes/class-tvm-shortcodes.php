<?php
/**
 * Frontend Shortcodes
 * Version 2.1.0 - Surgical Routing Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Shortcodes {

	public function __construct() {
		$includes = array(
			'class-tvm-search-handler.php',
			'class-tvm-vault-handler.php',
			'class-tvm-details-handler.php',
			'class-tvm-watchlist-handler.php',
			'class-tvm-settings-handler.php',
			'class-tvm-importer.php',
			'class-tvm-movie-handler.php',
			'class-tvm-movie-details.php',
			'class-tvm-tv-handler.php',
			'class-tvm-tv-details.php',
			'class-tvm-stats-handler.php',
		);

		foreach ( $includes as $file ) {
			if ( file_exists( TVM_PATH . 'includes/' . $file ) ) {
				require_once TVM_PATH . 'includes/' . $file;
			}
		}

		add_shortcode( 'tvm_tracker_app', array( $this, 'render_app' ) );
		$this->init_handlers();
	}

	private function init_handlers() {
		if ( class_exists( 'TVM_Search_Handler' ) ) { new TVM_Search_Handler(); }
		if ( class_exists( 'TVM_Settings_Handler' ) ) { new TVM_Settings_Handler(); }
		if ( class_exists( 'TVM_Importer' ) ) { new TVM_Importer(); } 
		if ( class_exists( 'TVM_Movie_Handler' ) ) { new TVM_Movie_Handler(); } 
		if ( class_exists( 'TVM_Movie_Details' ) ) { new TVM_Movie_Details(); }
		if ( class_exists( 'TVM_TV_Handler' ) ) { new TVM_TV_Handler(); } 
		if ( class_exists( 'TVM_TV_Details' ) ) { new TVM_TV_Details(); }
		if ( class_exists( 'TVM_Stats_Handler' ) ) { new TVM_Stats_Handler(); }
	}

	public function render_app() {
		if ( ! is_user_logged_in() ) {
			return '<div style="text-align:center; padding:40px;"><h3>Please log in.</h3>' . wp_login_form( array( 'echo' => false ) ) . '</div>';
		}

		// Detect if we are on a surgical view
		$current_view = get_query_var( 'tvm_view' );

		wp_enqueue_style( 'dashicons' );
		
		// Always load settings and core
		wp_enqueue_script( 'tvm-settings-js', TVM_URL . 'assets/js/tvm-settings.js', array( 'jquery' ), TVM_VERSION, true );

		if ( $current_view === 'tv-unwatched' ) {
			// SURGICAL ENQUEUE: Only load core and the dedicated script
			wp_enqueue_script( 'tvm-core-js', TVM_URL . 'assets/js/tvm-core.js', array( 'jquery', 'tvm-settings-js' ), TVM_VERSION, true );
			wp_enqueue_script( 'tvm-tv-unwatched-js', TVM_URL . 'assets/js/tvm-tv-unwatched.js', array( 'tvm-core-js' ), TVM_VERSION, true );
		} else {
			// STANDARD ENQUEUE: Load all modules for the full dashboard
			wp_enqueue_script( 'tvm-movie-js', TVM_URL . 'assets/js/tvm-movie.js', array( 'jquery' ), TVM_VERSION, true );
			wp_enqueue_script( 'tvm-tv-js', TVM_URL . 'assets/js/tvm-tv.js', array( 'jquery' ), TVM_VERSION, true );
			wp_enqueue_script( 'tvm-search-js', TVM_URL . 'assets/js/tvm-search.js', array( 'jquery' ), TVM_VERSION, true );
			wp_enqueue_script( 'tvm-core-js', TVM_URL . 'assets/js/tvm-core.js', array( 'jquery', 'tvm-movie-js', 'tvm-tv-js', 'tvm-search-js', 'tvm-settings-js' ), TVM_VERSION, true );
		}

		wp_localize_script( 'tvm-core-js', 'tvm_app', array(
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'tvm_import_nonce' ),
			'current_view' => $current_view,
		));

		ob_start();
		?>
		<div id="tvm-app-container">
			<header style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">
				<h2 style="margin:0; display:flex; align-items:center; gap:10px;">
                    <span class="dashicons dashicons-format-video" style="font-size:28px; width:28px; height:28px;"></span> 
                    <?php echo ( $current_view === 'tv-unwatched' ) ? 'TV Unwatched Queue' : 'My Media Vault'; ?>
                </h2>
				<div id="tvm-stats-display" style="font-size: 12px; color: #666; font-weight: 600; text-align: right; line-height: 1.4;"></div>
			</header>

			<?php if ( ! $current_view ) : // Only show Main Nav on full dashboard ?>
			<nav id="tvm-main-nav" style="margin-bottom: 25px;">
				<ul style="list-style: none; padding: 0; display: flex; gap: 20px; border-bottom: 1px solid #eee; margin:0;">
					<li><a href="#" class="tvm-nav-link active" data-tab="watchlist">My Vault</a></li>
					<li><a href="#" class="tvm-nav-link" data-tab="search">Search & Add</a></li>
					<li><a href="#" class="tvm-nav-link" data-tab="stats">My Stats</a></li>
					<li><a href="#" class="tvm-nav-link" data-tab="settings">My Settings</a></li>
				</ul>
			</nav>
			<?php endif; ?>

			<main id="tvm-app-content">
				<section id="tvm-view-watchlist">
					<?php if ( ! $current_view ) : // Only show Toggles on full dashboard ?>
					<div class="tvm-media-toggle" style="margin-bottom: 20px; display: flex; gap: 10px; border-bottom: 1px solid #f0f0f0; padding-bottom: 15px;">
						<button class="tvm-type-tab active" data-type="tv">TV Shows</button>
						<button class="tvm-type-tab" data-type="movie">Movies</button>
					</div>
					<?php endif; ?>

					<div class="tvm-filters-container" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
						<div class="tvm-filters" style="display: flex; gap: 8px;">
							<?php if ( ! $current_view ) : ?>
								<button class="tvm-filter-btn active" data-filter="all">All</button>
								<button class="tvm-filter-btn" data-filter="watched">Watched</button>
								<button class="tvm-filter-btn" data-filter="released">Unwatched</button>
								<button class="tvm-filter-btn" data-filter="upcoming">Upcoming</button>
								<button class="tvm-filter-btn tvm-calendar-toggle" data-filter="calendar">Calendar</button>
							<?php else : ?>
								<button class="tvm-filter-btn active">Filter: Unwatched</button>
								<a href="<?php echo home_url('/'); ?>" class="tvm-filter-btn" style="text-decoration:none;">&larr; Exit to Full Vault</a>
							<?php endif; ?>
						</div>
						<div class="tvm-vault-controls" style="display: flex; align-items: center; gap: 15px;">
							<label style="font-size: 12px; display: flex; align-items: center; gap: 5px; cursor: pointer; color: #666; font-weight: 600;">
								<input type="checkbox" id="tvm-stream-only-toggle"> Stream Only
							</label>
							<input type="text" id="tvm-vault-search-input" placeholder="Filter vault..." style="padding: 6px 12px; border: 1px solid #ccc; border-radius: 20px; font-size: 12px; width: 200px;">
						</div>
					</div>
					
					<div id="tvm-watchlist-grid" class="tvm-locked-grid"></div>

					<div id="tvm-tv-detail-view" style="display:none; margin-top:20px;">
						<button id="tvm-back-to-grid" class="button" style="margin-bottom:20px; border-radius:8px; display:flex; align-items:center; gap:5px;">
                            <span class="dashicons dashicons-arrow-left-alt2"></span> Back to List
                        </button>
						<div id="tvm-series-content"></div>
					</div>
				</section>

				<?php if ( ! $current_view ) : // Keep all other sections intact ?>
				<section id="tvm-view-search" style="display:none;">
					<div style="display:flex; gap:10px; margin-bottom:20px;">
						<input type="text" id="tvm-frontend-search-input" placeholder="Search TMDb..." style="flex:1; padding:12px; border:1px solid #ddd; border-radius:8px;">
						<button id="tvm-frontend-search-btn" class="button button-primary" style="padding:0 25px; border-radius:8px; font-weight:700;">Search</button>
					</div>

                    <div class="tvm-search-toggles" style="margin-bottom: 20px; display: flex; gap: 10px;">
                        <button class="tvm-search-filter active" data-type="all">All Results</button>
                        <button class="tvm-search-filter" data-type="tv">TV Shows</button>
                        <button class="tvm-search-filter" data-type="movie">Movies</button>
                    </div>

					<div id="tvm-frontend-results" style="display: flex; flex-direction: column; gap: 12px; width: 100%;"></div>
				</section>

                <section id="tvm-view-stats" style="display:none; padding:20px 0;">
                    <div id="tvm-stats-container">
                        <p style="text-align:center; padding:40px;">Calculating stats...</p>
                    </div>
                </section>

				<section id="tvm-view-settings" style="display:none;">
					<div id="tvm-settings-form-container">
						<p>Loading settings...</p>
					</div>
				</section>
				<?php endif; ?>
			</main>

			<div id="tvm-details-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:99999; align-items:center; justify-content:center; padding:20px;">
				<div style="background:#fff; width:100%; max-width:850px; border-radius:12px; position:relative; overflow:hidden; display:flex; flex-direction:row; max-height: 90vh;">
					<span id="tvm-close-modal" style="position:absolute; top:15px; right:20px; font-size:32px; cursor:pointer; z-index:40; color:#333;">&times;</span>
					<div style="flex: 1; background:#000; display:flex; align-items:center; justify-content:center;"><img id="tvm-modal-poster" src="" style="width:100%; height:auto; display:block;"></div>
					<div style="flex: 1.4; padding: 40px; overflow-y: auto;">
						<h2 id="tvm-modal-title" style="margin:0 0 10px 0; font-size:26px; color:#1d2327;"></h2>
						<div id="tvm-modal-status-area" style="margin-bottom:25px;"></div>
						<p id="tvm-modal-overview" style="font-size:15px; line-height:1.6; color:#444; margin-bottom:30px;"></p>
						<div id="tvm-modal-sources"></div>
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
