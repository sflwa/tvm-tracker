<?php
/**
 * TVM Tracker - Database Installation Class
 * Handles the creation of custom database tables on plugin activation.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 2.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tvm_Tracker_Installer class.
 * Handles all database table creation and versioning.
 */
class Tvm_Tracker_Installer {

    /**
     * Define the database version. Used to trigger updates.
     *
     * @var string
     */
    const DB_VERSION = '2.1.0'; // Bumped version to ensure columns are added

    /**
     * Installs the necessary database tables.
     */
    public static function tvm_tracker_install() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $charset_collate = $wpdb->get_charset_collate();

        // --- V2.0 SCHEMA ---

        // 1. Shows Table (To track titles being followed by users)
        $table_name_shows = $wpdb->prefix . 'tvm_tracker_shows';
        $sql_shows = "CREATE TABLE $table_name_shows (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            title_id INT(11) UNSIGNED NOT NULL,
            title_name VARCHAR(255) NOT NULL,
            total_episodes INT(11) UNSIGNED NOT NULL,
            tracked_date DATETIME NOT NULL,
            end_year YEAR(4) DEFAULT NULL,
            item_type VARCHAR(50) NOT NULL DEFAULT 'tv_series',
            release_date DATE DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY title_id (title_id)
        ) $charset_collate;";
        dbDelta( $sql_shows );

        // 2. Episode Data Table (Static data per episode, shared across all users)
        $table_name_episode_data = $wpdb->prefix . 'tvm_tracker_episode_data';
        $sql_episode_data = "CREATE TABLE $table_name_episode_data (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            title_id INT(11) UNSIGNED NOT NULL,
            watchmode_id INT(11) UNSIGNED NOT NULL,
            season_number INT(11) UNSIGNED NOT NULL,
            episode_number INT(11) UNSIGNED NOT NULL,
            episode_name VARCHAR(255) NOT NULL,
            air_date DATE NOT NULL,
            plot_overview TEXT,
            thumbnail_url VARCHAR(255),
            PRIMARY KEY (id),
            UNIQUE KEY title_episode (title_id, watchmode_id),
            KEY title_id (title_id)
        ) $charset_collate;";
        dbDelta( $sql_episode_data );

        // 3. Episode Links Table (Streaming links for each episode/source/region)
        $table_name_episode_links = $wpdb->prefix . 'tvm_tracker_episode_links';
        $sql_episode_links = "CREATE TABLE $table_name_episode_links (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            title_id INT(11) UNSIGNED NOT NULL,
            episode_id INT(11) UNSIGNED NOT NULL,
            source_id INT(11) UNSIGNED NOT NULL,
            web_url VARCHAR(255) NOT NULL,
            region VARCHAR(10) NOT NULL,
            PRIMARY KEY (id),
            KEY title_episode (title_id, episode_id)
        ) $charset_collate;";
        dbDelta( $sql_episode_links );

        // 4. Global Sources Table (Master list of streaming services)
        $table_name_sources = $wpdb->prefix . 'tvm_tracker_sources';
        $sql_sources = "CREATE TABLE $table_name_sources (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id INT(11) UNSIGNED NOT NULL UNIQUE,
            source_name VARCHAR(255) NOT NULL,
            logo_url VARCHAR(255) NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";
        dbDelta( $sql_sources );

        // 5. Episodes Table (User-specific tracking status)
        $table_name_episodes = $wpdb->prefix . 'tvm_tracker_episodes';
        $sql_episodes = "CREATE TABLE $table_name_episodes (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            title_id INT(11) UNSIGNED NOT NULL,
            episode_id INT(11) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_episode (user_id, title_id, episode_id),
            KEY user_id (user_id),
            KEY title_id (title_id)
        ) $charset_collate;";
        dbDelta( $sql_episodes );


        // Store the database version in options
        update_option( 'tvm_tracker_db_version', self::DB_VERSION );
    }

    /**
     * Uninstalls/deletes the database tables.
     */
    public static function tvm_tracker_uninstall() {
        // Implementation omitted for brevity but required for completeness
    }
}
