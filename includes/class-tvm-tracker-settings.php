<?php
/**
 * TVM Tracker - Admin Settings Class
 * Handles the registration of the admin settings page, fields, and rendering.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 2.2.4
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tvm_Tracker_Settings class.
 */
class Tvm_Tracker_Settings {

    /**
     * @var Tvm_Tracker_API
     */
    private $api_client;

    /**
     * @var string The unique hook suffix returned by add_menu_page.
     */
    private $page_hook;
    
    /**
     * @var Tvm_Tracker_DB The DB client instance.
     */
    private $db_client;

    /**
     * Constructor.
     *
     * @param Tvm_Tracker_API $api_client Instance of the API client.
     */
    public function __construct( Tvm_Tracker_API $api_client ) {
        $this->api_client = $api_client;
        $this->db_client = Tvm_Tracker_Plugin::tvm_tracker_get_instance()->get_db_client();
        
        add_action( 'admin_menu', array( $this, 'tvm_tracker_add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'tvm_tracker_settings_init' ) );
        // Handler for manual database synchronization from the API Log page
        add_action( 'admin_init', array( $this, 'tvm_tracker_handle_manual_sync' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'tvm_tracker_admin_enqueue_styles' ) );
        add_action( 'admin_notices', array( $this, 'tvm_tracker_display_backfill_notice' ) );
    }

    /**
     * Handles the manual database sync request from the API Log page.
     * Overwrites relational tables with fresh data from the API Cache.
     */
    public function tvm_tracker_handle_manual_sync() {
        if ( ! isset( $_GET['tvm_action'] ) || $_GET['tvm_action'] !== 'sync_from_cache' ) {
            return;
        }

        check_admin_referer( 'tvm_sync_cache_nonce' );

        $title_id = absint( $_GET['title_id'] ?? 0 );
        if ( $title_id > 0 ) {
            // Trigger the sync method in the DB class
            $this->db_client->tvm_tracker_sync_db_from_cache( $title_id );
            
            $redirect_url = add_query_arg( 
                array( 
                    'settings-updated' => 'true', 
                    'tvm_message' => urlencode(__('Database synced from cache.', 'tvm-tracker')) 
                ), 
                admin_url( 'admin.php?page=tvm-tracker-api-log' ) 
            );
            wp_safe_redirect( $redirect_url );
            exit;
        }
    }

    /**
     * Adds the top-level admin menu item and submenus.
     */
    public function tvm_tracker_add_admin_menu() {
        $this->page_hook = add_menu_page(
            esc_html__( 'TVM Tracker Settings', 'tvm-tracker' ),
            esc_html__( 'TVM Tracker', 'tvm-tracker' ),
            'manage_options',
            'tvm-tracker-settings',
            array( $this, 'tvm_tracker_options_page' ),
            'dashicons-visibility',
            6
        );
        
        add_submenu_page(
            'tvm-tracker-settings',
            esc_html__( 'Streaming Sources', 'tvm-tracker' ),
            esc_html__( 'Sources', 'tvm-tracker' ),
            'manage_options',
            'tvm-tracker-sources',
            array( $this, 'tvm_tracker_sources_page' )
        );

        add_submenu_page(
            'tvm-tracker-settings',
            esc_html__( 'API Cache Log', 'tvm-tracker' ),
            esc_html__( 'API Log', 'tvm-tracker' ),
            'manage_options',
            'tvm-tracker-api-log',
            array( $this, 'tvm_tracker_api_log_page' )
        );
    }

    /**
     * Conditionally enqueues admin CSS and JS.
     */
    public function tvm_tracker_admin_enqueue_styles( $hook ) {
        if ( false === strpos( $hook, 'tvm-tracker' ) ) {
            return;
        }
        
        wp_enqueue_style( 'tvm-tracker-admin-style', TVM_TRACKER_URL . 'css/tvm-tracker-admin.css', array(), '1.0.2' );
        wp_enqueue_script( 'tvm-tracker-admin-js', TVM_TRACKER_URL . 'js/tvm-tracker-admin.js', array('jquery'), '1.0.0', true );
    }

    /**
     * Registers settings, sections, and fields.
     */
    public function tvm_tracker_settings_init() {
        register_setting( 'tvm_tracker_settings_group', 'tvm_tracker_api_key', [ 'type' => 'string', 'sanitize_callback' => [ $this, 'tvm_tracker_sanitize_api_key' ], 'default' => '' ] );
        register_setting( 'tvm_tracker_settings_group', 'tvm_tracker_debug_mode', [ 'type' => 'boolean', 'sanitize_callback' => [ $this, 'tvm_tracker_sanitize_debug_mode' ], 'default' => false ] );
        register_setting( 'tvm_tracker_settings_group', 'tvm_tracker_enabled_sources', [ 'type' => 'array', 'sanitize_callback' => [ $this, 'tvm_tracker_sanitize_enabled_sources' ], 'default' => [] ] );
        register_setting( 'tvm_tracker_settings_group', 'tvm_tracker_enabled_regions', [ 'type' => 'array', 'sanitize_callback' => [ $this, 'tvm_tracker_sanitize_enabled_regions' ], 'default' => ['US'] ] );

        add_settings_section( 'tvm_tracker_settings_section_api', esc_html__( 'API Configuration & Debug', 'tvm-tracker' ), array( $this, 'tvm_tracker_section_api_callback' ), 'tvm-tracker-settings' );
        add_settings_field( 'tvm_tracker_api_key_field', esc_html__( 'Watchmode API Key', 'tvm-tracker' ), array( $this, 'tvm_tracker_api_key_render' ), 'tvm-tracker-settings', 'tvm_tracker_settings_section_api' );
        add_settings_field( 'tvm_tracker_debug_mode_field', esc_html__( 'Debug Mode', 'tvm-tracker' ), array( $this, 'tvm_tracker_debug_mode_render' ), 'tvm-tracker-settings', 'tvm_tracker_settings_section_api' );

        add_settings_section( 'tvm_tracker_settings_section_sources', esc_html__( 'Streaming Services Selection', 'tvm-tracker' ), array( $this, 'tvm_tracker_section_sources_callback' ), 'tvm-tracker-sources' );
    }

    public function tvm_tracker_api_key_render() {
        $api_key = get_option( 'tvm_tracker_api_key', '' );
        echo '<input type="text" name="tvm_tracker_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text" placeholder="' . esc_attr__( 'Enter your Watchmode API Key', 'tvm-tracker' ) . '">';
    }

    public function tvm_tracker_debug_mode_render() {
        $debug_mode = get_option( 'tvm_tracker_debug_mode', false );
        echo '<input type="checkbox" name="tvm_tracker_debug_mode" value="1" ' . checked( true, $debug_mode, false ) . '> ' . esc_html__( 'Enable debug mode on the frontend.', 'tvm-tracker' );
    }

    private function tvm_tracker_get_flag_emoji( $country_code ) {
        if ( ! is_string( $country_code ) || strlen( $country_code ) !== 2 ) return '';
        $country_code = strtoupper( $country_code );
        $flag = mb_chr( ord( $country_code[0] ) + 0x1F1E6 - 65 ) . mb_chr( ord( $country_code[1] ) + 0x1F1E6 - 65 );
        return $flag;
    }

    private function tvm_tracker_get_processed_source_data() {
        $all_sources_full = $this->api_client->tvm_tracker_get_all_sources();
        if ( is_wp_error( $all_sources_full ) ) return $all_sources_full;
        
        $enabled_sources = array_map('absint', (array)get_option( 'tvm_tracker_enabled_sources', [] ));
        $regions = [];
        $services_by_type = [];
        $service_map = [];

        foreach ($all_sources_full as $provider) {
            $source_id = absint($provider['id']);
            $service_key = md5($provider['name'] . $provider['logo_100px']);

            if (!isset($service_map[$service_key])) {
                $service_map[$service_key] = [
                    'service_name' => sanitize_text_field($provider['name']),
                    'logo_url' => esc_url($provider['logo_100px']),
                    'type' => sanitize_key($provider['type'] ?? 'other'),
                    'source_ids_in_regions' => [],
                    'is_enabled' => false,
                ];
            }
            
            foreach ($provider['regions'] as $region_code) {
                $region_code = strtoupper(sanitize_key($region_code));
                $regions[$region_code] = $region_code;
                $service_map[$service_key]['source_ids_in_regions'][$region_code] = $source_id;
                if (in_array($source_id, $enabled_sources)) $service_map[$service_key]['is_enabled'] = true;
            }
        }
        
        foreach ($service_map as $service_data) {
            $services_by_type[$service_data['type']][] = $service_data;
        }

        return ['regions' => array_keys($regions), 'services_by_type' => $services_by_type];
    }
    
    private function tvm_tracker_render_region_selector( $all_regions, $enabled_regions ) {
        sort($all_regions);
        $region_map = ['US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia', 'BR' => 'Brazil', 'ES' => 'Spain', 'IN' => 'India'];
        ?>
        <div class="tvm-region-group is-open">
            <div class="tvm-region-header">
                <h3><?php esc_html_e('Section 1: Region Filters', 'tvm-tracker'); ?></h3>
                <span class="dashicons dashicons-arrow-up"></span>
            </div>
            <div class="tvm-region-content">
                <div class="tvm-12-column-grid tvm-region-grid">
                    <?php foreach ($all_regions as $region_code): ?>
                        <?php $is_enabled = in_array($region_code, $enabled_regions); ?>
                        <div class="tvm-grid-item">
                            <label class="tvm-region-card <?php echo $is_enabled ? 'is-enabled' : ''; ?>">
                                <input type="checkbox" name="tvm_tracker_enabled_regions[]" value="<?php echo esc_attr($region_code); ?>" <?php checked(true, $is_enabled); ?> hidden />
                                <span class="tvm-flag-emoji"><?php echo esc_html($this->tvm_tracker_get_flag_emoji($region_code)); ?></span>
                                <span class="tvm-region-name"><?php echo esc_html($region_map[$region_code] ?? $region_code); ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function tvm_tracker_render_service_type_grid( $type_code, $type_label, $services_data, $enabled_regions ) {
        ?>
        <div class="tvm-type-group is-open" data-type="<?php echo esc_attr($type_code); ?>">
            <div class="tvm-region-header">
                <h3><?php echo esc_html($type_label); ?></h3>
                <span class="dashicons dashicons-arrow-up"></span>
            </div>
            <div class="tvm-region-content">
                <div class="tvm-12-column-grid tvm-service-grid">
                    <?php foreach ($services_data as $service): ?>
                        <div class="tvm-grid-item">
                            <label class="tvm-service-card <?php echo $service['is_enabled'] ? 'is-enabled' : ''; ?>">
                                <img src="<?php echo esc_url($service['logo_url']); ?>" class="tvm-service-logo" />
                                <span class="tvm-service-name"><?php echo esc_html($service['service_name']); ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function tvm_tracker_api_log_page() {
        $current_type_filter = sanitize_key( $_GET['cache_type'] ?? 'all' );
        $current_title_id_filter = absint( $_GET['title_id'] ?? 0 );
        $log_records = $this->db_client->tvm_tracker_get_api_log_records( $current_type_filter, $current_title_id_filter );
        $plugin_page_url = admin_url( 'admin.php?page=tvm-tracker-api-log' );
        $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $current_time_gmt = time();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('API Cache Log', 'tvm-tracker'); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 10%;"><?php esc_html_e('Type', 'tvm-tracker'); ?></th>
                        <th style="width: 25%;"><?php esc_html_e('Title / Sync', 'tvm-tracker'); ?></th>
                        <th style="width: 15%;"><?php esc_html_e('Last Updated', 'tvm-tracker'); ?></th>
                        <th style="width: 15%;"><?php esc_html_e('Time Left', 'tvm-tracker'); ?></th>
                        <th style="width: 20%;"><?php esc_html_e('Original Path', 'tvm-tracker'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($log_records as $record): 
                        $title_id = 0;
                        $display_title = 'General / Search';
                        if (preg_match('/title\/(\d+)/', $record['request_path'], $matches)) {
                            $title_id = absint($matches[1]);
                            $display_title = $this->db_client->tvm_tracker_get_title_name_by_id($title_id) ?: 'ID: ' . $title_id;
                        }
                        $expires_ts = strtotime($record['cache_expires'] . ' GMT');
                        $is_expired = $expires_ts < $current_time_gmt;
                    ?>
                    <tr>
                        <td><?php echo esc_html(ucwords($record['cache_type'])); ?></td>
                        <td>
                            <strong><?php echo esc_html($display_title); ?></strong>
                            <?php if ( $title_id > 0 ) : 
                                $sync_url = wp_nonce_url( add_query_arg(['tvm_action' => 'sync_from_cache', 'title_id' => $title_id], $plugin_page_url), 'tvm_sync_cache_nonce' );
                            ?>
                                <br><a href="<?php echo esc_url($sync_url); ?>" class="button button-small" style="margin-top:5px;"><?php esc_html_e('Sync DB from Cache', 'tvm-tracker'); ?></a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date_i18n($date_format, strtotime($record['last_updated'] . ' GMT')); ?></td>
                        <td style="color: <?php echo $is_expired ? '#d54e21' : '#46b450'; ?>;">
                            <?php echo $is_expired ? esc_html__('Expired', 'tvm-tracker') : human_time_diff($current_time_gmt, $expires_ts); ?>
                        </td>
                        <td style="font-size: 0.7em; color: #777;"><?php echo esc_html($record['request_path']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function tvm_tracker_sources_page() {
        $api_key = get_option( 'tvm_tracker_api_key', '' );
        $debug_mode = get_option( 'tvm_tracker_debug_mode', false );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Streaming Sources Configuration', 'tvm-tracker'); ?></h1>
            <form action="options.php" method="post">
                <?php settings_fields( 'tvm_tracker_settings_group' );
                $processed_data = $this->tvm_tracker_get_processed_source_data();
                if ( ! is_wp_error( $processed_data ) ) {
                    $this->tvm_tracker_render_region_selector( $processed_data['regions'], (array)get_option( 'tvm_tracker_enabled_regions', ['US'] ) );
                    $type_order = ['sub' => 'Subscription', 'free' => 'Free', 'rent' => 'Rent', 'buy' => 'Buy'];
                    foreach ($type_order as $code => $label) {
                        if (!empty($processed_data['services_by_type'][$code])) {
                            $this->tvm_tracker_render_service_type_grid($code, $label, $processed_data['services_by_type'][$code], (array)get_option('tvm_tracker_enabled_regions', ['US']));
                        }
                    }
                } ?>
                <input type="hidden" name="tvm_tracker_api_key" value="<?php echo esc_attr($api_key); ?>" />
                <input type="hidden" name="tvm_tracker_debug_mode" value="<?php echo (int)$debug_mode; ?>" />
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function tvm_tracker_options_page() {
        $enabled_sources = get_option( 'tvm_tracker_enabled_sources', [] );
        $enabled_regions = (array)get_option( 'tvm_tracker_enabled_regions', ['US'] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TVM Tracker Settings & Stats', 'tvm-tracker' ); ?></h1>
            <form action="options.php" method="post">
                <?php settings_fields( 'tvm_tracker_settings_group' );
                do_settings_sections( 'tvm-tracker-settings' ); 
                $this->tvm_tracker_stats_render();
                foreach ($enabled_sources as $id) echo '<input type="hidden" name="tvm_tracker_enabled_sources[]" value="'.absint($id).'" />';
                foreach ($enabled_regions as $code) echo '<input type="hidden" name="tvm_tracker_enabled_regions[]" value="'.esc_attr($code).'" />';
                submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function tvm_tracker_stats_render() {
        $total_shows = $this->db_client->tvm_tracker_get_total_shows_count();
        $total_movies = $this->db_client->tvm_tracker_get_total_movies_count();
        $total_episodes = $this->db_client->tvm_tracker_get_total_episodes_count();
        $cache_entries = $this->db_client->tvm_tracker_get_cache_count();
        ?>
        <div style="background:#f9f9f9; padding:15px; border:1px solid #ddd; margin-top:20px;">
            <h3><?php esc_html_e('System Stats', 'tvm-tracker'); ?></h3>
            <div style="display:flex; gap:20px;">
                <div><strong>Shows:</strong> <?php echo absint($total_shows); ?></div>
                <div><strong>Movies:</strong> <?php echo absint($total_movies); ?></div>
                <div><strong>Episodes:</strong> <?php echo absint($total_episodes); ?></div>
                <div><strong>Cache:</strong> <?php echo absint($cache_entries); ?></div>
            </div>
        </div>
        <?php
    }

    public function tvm_tracker_section_api_callback() { echo '<p>Configure your API and debug settings.</p>'; }
    public function tvm_tracker_section_sources_callback() { echo '<p>Select services available to users.</p>'; }
    public function tvm_tracker_sanitize_api_key( $input ) { return sanitize_text_field( $input ); }
    public function tvm_tracker_sanitize_debug_mode( $input ) { return (bool) $input; }
    public function tvm_tracker_sanitize_enabled_sources( $input ) { return is_array($input) ? array_map('absint', $input) : []; }
    public function tvm_tracker_sanitize_enabled_regions( $input ) { return is_array($input) ? array_map('strtoupper', array_map('sanitize_key', $input)) : []; }

    public function tvm_tracker_display_backfill_notice() {
        if ( isset( $_GET['tvm_message'] ) && isset( $_GET['settings-updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $_GET['tvm_message'] ) . '</p></div>';
        }
    }
}
