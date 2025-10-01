<?php
/**
 * TVM Tracker - Database Installation Class
 * Handles the creation of custom database tables on plugin activation.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 1.0.1
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
    const DB_VERSION = '1.0.1';

    /**
     * Installs the necessary database tables.
     * This method is called during plugin activation via the activation hook.
     * NOTE: Renamed from 'install' to 'tvm_tracker_install' to match the hook call in the main plugin file.
     */
    public static function tvm_tracker_install() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $charset_collate = $wpdb->get_charset_collate();

        // 1. Shows Table (To track titles being followed by users)
        $table_name_shows = $wpdb->prefix . 'tvm_tracker_shows';
        $sql_shows = "CREATE TABLE $table_name_shows (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            title_id INT(11) UNSIGNED NOT NULL,
            title_name VARCHAR(255) NOT NULL,
            total_episodes INT(11) UNSIGNED NOT NULL,
            tracked_date DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY title_id (title_id)
        ) $charset_collate;";

        dbDelta( $sql_shows );


        // 2. Episodes Table (To track individual episode watch status)
        $table_name_episodes = $wpdb->prefix . 'tvm_tracker_episodes';
        $sql_episodes = "CREATE TABLE $table_name_episodes (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            title_id INT(11) UNSIGNED NOT NULL,
            episode_id INT(11) UNSIGNED NOT NULL,
            is_watched TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY title_id (title_id)
        ) $charset_collate;";

        dbDelta( $sql_episodes );

        // Store the database version in options
        add_option( 'tvm_tracker_db_version', self::DB_VERSION );
    }
}
