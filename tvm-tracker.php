<?php
/**
 * Plugin Name:       TV & Movie Tracker
 * Plugin URI:        https://sflwa.com/
 * Description:       A premium personal library for tracking TV shows and Movies.
 * Version:           2.3.2
 * Author:            South Florida Web Advisors
 * License:           GPLv2 or later
 * Text Domain:       tvm-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVM_VERSION', '2.3.2' );
define( 'TVM_PATH', plugin_dir_path( __FILE__ ) );
define( 'TVM_URL', plugin_dir_url( __FILE__ ) );



// Include the shared support handler
require_once plugin_dir_path( __FILE__ ) . 'sflwa-support-handler.php';

// Register this plugin into the SFLWA Support Hub
sflwa_register_support_page( array(
    'plugin_name'   => 'TV & Movie Tracker',
    'plugin_slug'   => 'tvm-tracker',
    'text_domain'   => 'tvm-tracker',
    'plugin_file'   => __FILE__,
    'submenu_title' => 'TV & Movie Tracker',
    'description'   => 'A premium personal library for tracking TV shows and Movies.',
    'version'       => '2.3.2'
) );










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

		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_intervals' ) );

		add_action( 'init', function() {
			if ( class_exists( 'TVM_CPT' ) ) {
				$cpt = new TVM_CPT();
				$cpt->register_post_types();
			}
            $this->register_rewrite_rules();
		});

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
        register_activation_hook( __FILE__, 'flush_rewrite_rules' );
		add_filter( 'template_include', array( $this, 'load_cpt_templates' ) );
	}

    public function load_cpt_templates( $template ) {
        if ( is_singular( 'tvm_item' ) ) {
            $theme_template = locate_template( array( 'single-tvm_item.php' ) );
            if ( $theme_template ) {
                return $theme_template;
            }
            
            $plugin_template = TVM_PATH . 'single-tvm_item.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        if ( is_post_type_archive( 'tvm_item' ) ) {
            $theme_archive = locate_template( array( 'archive-tvm_item.php' ) );
            if ( $theme_archive ) {
                return $theme_archive;
            }

            $plugin_archive = TVM_PATH . 'archive-tvm_item.php';
            if ( file_exists( $plugin_archive ) ) {
                return $plugin_archive;
            }
        }

        return $template;
    }

    public function register_rewrite_rules() {
        // Front-page & Subpage rewrite routing rules
        $front_id = get_option( 'page_on_front' );
        $target   = $front_id ? 'index.php?page_id=' . absint( $front_id ) : 'index.php?';

        add_rewrite_rule( '^tv/(watched|unwatched|released|upcoming|calendar)/?$', $target . '&tvm_media=tv&tvm_filter=$matches[1]', 'top' );
        add_rewrite_rule( '^tv/([^/]+)/?$', $target . '&tvm_media=tv&tvm_show_slug=$matches[1]', 'top' );
        add_rewrite_rule( '^tv/?$', $target . '&tvm_media=tv', 'top' );

        add_rewrite_rule( '^movies/(watched|released|upcoming)/?$', $target . '&tvm_media=movies&tvm_filter=$matches[1]', 'top' );
        add_rewrite_rule( '^movies/?$', $target . '&tvm_media=movies', 'top' );
    }

    public function register_query_vars( $vars ) {
        $vars[] = 'tvm_media';
        $vars[] = 'tvm_filter';
        $vars[] = 'tvm_show_slug';
        return $vars;
    }

	public function add_custom_cron_intervals( $schedules ) {
		$schedules['monthly'] = array(
			'interval' => 2635200,
			'display'  => __( 'Once Every Month', 'tvm-tracker' )
		);
		return $schedules;
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'tvm-app-styles', TVM_URL . 'assets/css/tvm-app.css', array(), TVM_VERSION );
		wp_enqueue_style( 'dashicons' );
		
		wp_enqueue_script( 'tvm-core-js', TVM_URL . 'assets/js/tvm-core.js', array( 'jquery' ), TVM_VERSION, true );
		wp_enqueue_script( 'tvm-movie-js', TVM_URL . 'assets/js/tvm-movie.js', array( 'tvm-core-js' ), TVM_VERSION, true );
		
        if ( ! is_singular( 'tvm_item' ) ) {
            wp_enqueue_script( 'tvm-tv-js', TVM_URL . 'assets/js/tvm-tv.js', array( 'tvm-core-js' ), TVM_VERSION, true );
        }

		wp_enqueue_script( 'tvm-search-js', TVM_URL . 'assets/js/tvm-search.js', array( 'jquery', 'tvm-core-js' ), TVM_VERSION, true );
		wp_enqueue_script( 'tvm-settings-js', TVM_URL . 'assets/js/tvm-settings.js', array( 'jquery', 'tvm-core-js' ), TVM_VERSION, true );

        if ( get_query_var( 'tvm_view' ) === 'tv-unwatched' ) {
            wp_enqueue_script( 'tvm-tv-unwatched-js', TVM_URL . 'assets/js/tvm-tv-unwatched.js', array( 'jquery', 'tvm-core-js' ), TVM_VERSION, true );
        }
		
		wp_localize_script( 'tvm-core-js', 'tvm_app', array(
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'tvm_import_nonce' ),
            'current_media' => get_query_var( 'tvm_media' ) ?: 'tv',
            'current_filter'=> get_query_var( 'tvm_filter' ) ?: 'all',
            'current_show'  => get_query_var( 'tvm_show_slug' ) ?: '',
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
        require_once TVM_PATH . 'includes/class-tvm-weekly-mailer.php';
        
        if ( file_exists( TVM_PATH . 'includes/class-tvm-tv-unwatched-handler.php' ) ) {
            require_once TVM_PATH . 'includes/class-tvm-tv-unwatched-handler.php';
        }
	}

	public function activate_plugin() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_progress = $wpdb->prefix . 'tvm_user_progress';
		$sql_progress = "CREATE TABLE $table_progress (
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
		dbDelta( $sql_progress );

		$table_stats = $wpdb->prefix . 'tvm_series_stats';
		$sql_stats = "CREATE TABLE $table_stats (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			item_id bigint(20) NOT NULL,
			watched_count int(11) DEFAULT 0,
			unwatched_count int(11) DEFAULT 0,
			upcoming_count int(11) DEFAULT 0,
			last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_item (user_id, item_id)
		) $charset_collate;";
		dbDelta( $sql_stats );

		update_option( 'tvm_version', TVM_VERSION );
        
        if ( ! wp_next_scheduled( 'tvm_sunday_morning_email' ) ) {
            wp_schedule_event( strtotime( 'next Sunday 6:00am' ), 'weekly', 'tvm_sunday_morning_email' );
        }

        $this->register_rewrite_rules();
        flush_rewrite_rules();
	}
}

TVM_Tracker::get_instance();
