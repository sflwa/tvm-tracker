<?php
/**
 * Plugin Name:       TV & Movie Tracker
 * Plugin URI:        https://sflwa.com/
 * Description:       A premium personal library for tracking TV shows and Movies.
 * Version:           1.8.7
 * Author:            South Florida Web Advisors
 * License:           GPLv2 or later
 * Text Domain:       tvm-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVM_VERSION', '1.8.7' );
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

		// FIX: Move CPT registration to the 'init' hook to prevent Fatal Error
		add_action( 'init', function() {
			if ( class_exists( 'TVM_CPT' ) ) {
				$cpt = new TVM_CPT();
				$cpt->register_post_types();
			}
		});

		// Initialize Admin Interface
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
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'tvm-app-styles', TVM_URL . 'assets/css/tvm-app.css', array(), TVM_VERSION );
		wp_enqueue_style( 'dashicons' );
		
		wp_enqueue_script( 'tvm-core-js', TVM_URL . 'assets/js/tvm-core.js', array( 'jquery' ), TVM_VERSION, true );
		wp_enqueue_script( 'tvm-movie-js', TVM_URL . 'assets/js/tvm-movie.js', array( 'tvm-core-js' ), TVM_VERSION, true );
		wp_enqueue_script( 'tvm-tv-js', TVM_URL . 'assets/js/tvm-tv.js', array( 'tvm-core-js' ), TVM_VERSION, true );
		wp_enqueue_script( 'tvm-search-js', TVM_URL . 'assets/js/tvm-search.js', array( 'jquery', 'tvm-core-js' ), TVM_VERSION, true );
		wp_enqueue_script( 'tvm-settings-js', TVM_URL . 'assets/js/tvm-settings.js', array( 'jquery', 'tvm-core-js' ), TVM_VERSION, true );
		
		wp_localize_script( 'tvm-core-js', 'tvm_app', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'tvm_import_nonce' ),
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
	}
}

TVM_Tracker::get_instance();
