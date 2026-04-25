<?php
/**
 * Plugin Name:       TV & Movie Tracker
 * Plugin URI:        https://sflwa.com/
 * Description:       A premium personal library for tracking TV shows and Movies.
 * Version:           2.1.0
 * Author:            South Florida Web Advisors
 * License:           GPLv2 or later
 * Text Domain:       tvm-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVM_VERSION', '2.1.0' );
define( 'TVM_PATH', plugin_dir_path( __FILE__ ) );
define( 'TVM_URL', plugin_dir_url( __FILE__ ) );

final class TVM_Tracker {

	protected static $instance = null;
	public $tmdb;

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->includes();
		
		if ( class_exists( 'TVM_API_TMDB' ) ) {
			$this->tmdb = new TVM_API_TMDB();
		}

		// Register custom cron schedules
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_intervals' ) );

		add_action( 'init', function() {
			if ( class_exists( 'TVM_CPT' ) ) {
				$cpt = new TVM_CPT();
				$cpt->register_post_types();
			}
            
            // Register Custom Rewrite Rules for Dedicated Views
            $this->register_rewrite_rules();
		});

        // Add query variables to WordPress
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		if ( class_exists( 'TVM_Admin_Search' ) ) {
			new TVM_Admin_Search();
		}

		if ( class_exists( 'TVM_Admin_Metaboxes' ) ) {
			new TVM_Admin_Metaboxes();
		}

		if ( class_exists( 'TVM_Shortcodes' ) ) {
			new TVM_Shortcodes();
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
        
        // Flush rewrites on activation
        register_activation_hook( __FILE__, 'flush_rewrite_rules' );
	}

    /**
     * Register custom URL segments for dedicated views
     */
    public function register_rewrite_rules() {
        add_rewrite_rule( 
        'test/tv-unwatched/?$', 
        'index.php?page_id=20840&tvm_view=tv-unwatched', 
        'top' 
    );
		
        // Future endpoints can be added here (e.g., tv-upcoming, tv-calendar)
    }

    /**
     * Add tvm_view to the allowed WordPress query variables
     */
    public function register_query_vars( $vars ) {
        $vars[] = 'tvm_view';
        return $vars;
    }

	/**
	 * Define custom monthly interval for WP-Cron
	 */
	public function add_custom_cron_intervals( $schedules ) {
		$schedules['monthly'] = array(
			'interval' => 2635200, // 30.5 days in seconds
			'display'  => __( 'Once Every Month', 'tvm-tracker' )
		);
		return $schedules;
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'tvm-app-styles', TVM_URL . 'assets/css/tvm-app.css', array(), TVM_VERSION );
		wp_enqueue_style( 'dashicons' );
		
		wp_enqueue_script( 'tvm-core-js', TVM_URL . 'assets/js/tvm-core.js', array( 'jquery' ), TVM_VERSION, true );
		wp_enqueue_script( 'tvm-movie-js', TVM_URL . 'assets/js/tvm-movie.js', array( 'tvm-core-js' ), TVM_VERSION, true );
		wp_enqueue_script( 'tvm-tv-js', TVM_URL . 'assets/js/tvm-tv.js', array( 'tvm-core-js' ), TVM_VERSION, true );
		wp_enqueue_script( 'tvm-search-js', TVM_URL . 'assets/js/tvm-search.js', array( 'jquery', 'tvm-core-js' ), TVM_VERSION, true );
		wp_enqueue_script( 'tvm-settings-js', TVM_URL . 'assets/js/tvm-settings.js', array( 'jquery', 'tvm-core-js' ), TVM_VERSION, true );

        // Conditionally load the new dedicated Unwatched script
        if ( get_query_var( 'tvm_view' ) === 'tv-unwatched' ) {
            wp_enqueue_script( 'tvm-tv-unwatched-js', TVM_URL . 'assets/js/tvm-tv-unwatched.js', array( 'jquery', 'tvm-core-js' ), TVM_VERSION, true );
        }
		
		wp_localize_script( 'tvm-core-js', 'tvm_app', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'tvm_import_nonce' ),
            'current_view' => get_query_var( 'tvm_view' ),
		));
	}

	private function includes() {
		require_once TVM_PATH . 'includes/class-tvm-settings.php';
		require_once TVM_PATH . 'includes/class-tvm-api-tmdb.php';
		require_once TVM_PATH . 'includes/class-tvm-api-tvmaze.php'; 
		require_once TVM_PATH . 'includes/class-tvm-api-watchmode.php';
		require_once TVM_PATH . 'includes/class-tvm-shortcodes.php';
		require_once TVM_PATH . 'includes/class-tvm-cpt.php';
		require_once TVM_PATH . 'includes/class-tvm-admin-search.php';
		require_once TVM_PATH . 'includes/class-tvm-admin-metaboxes.php';
		require_once TVM_PATH . 'includes/class-tvm-movie-handler.php';
		require_once TVM_PATH . 'includes/class-tvm-movie-details.php';
		require_once TVM_PATH . 'includes/class-tvm-tv-handler.php';
		require_once TVM_PATH . 'includes/class-tvm-tv-details.php';
		require_once TVM_PATH . 'includes/class-tvm-importer.php';
        
        // New Surgical Handler
        if ( file_exists( TVM_PATH . 'includes/class-tvm-tv-unwatched-handler.php' ) ) {
            require_once TVM_PATH . 'includes/class-tvm-tv-unwatched-handler.php';
        }
	}

	public function activate_plugin() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tvm_user_progress';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			item_id bigint(20) NOT NULL,
			episode_id bigint(20) DEFAULT 0,
			season_number int(11) DEFAULT 0,
			episode_number int(11) DEFAULT 0,
			media_type varchar(20) NOT NULL,
			watched_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY item_id (item_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'tvm_version', TVM_VERSION );
        
        // Ensure rewrites are flushed immediately upon activation
        $this->register_rewrite_rules();
        flush_rewrite_rules();
	}
}

TVM_Tracker::get_instance();
