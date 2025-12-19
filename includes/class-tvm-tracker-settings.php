<?php
/**
 * TVM Tracker - Admin Settings Class
 * Handles the registration of the admin settings page, fields, and rendering.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 2.2.3
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
        add_action( 'admin_init', array( $this, 'tvm_tracker_handle_manual_sync' ) );
        add_action( 'admin_init', array( $this, 'tvm_tracker_handle_bulk_sync' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'tvm_tracker_admin_enqueue_styles' ) );
        add_action( 'admin_notices', array( $this, 'tvm_tracker_display_backfill_notice' ) );
    }

/**
     * Handles a single title database sync request from the API Log page.
     */
    public function tvm_tracker_handle_manual_sync() {
        if ( ! isset( $_GET['tvm_action'] ) || $_GET['tvm_action'] !== 'sync_from_cache' ) {
            return;
        }

        check_admin_referer( 'tvm_sync_cache_nonce' );

        $title_id = absint( $_GET['title_id'] ?? 0 );
        if ( $title_id > 0 ) {
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
     * NEW: Handles bulk sync for ALL tracked titles in the system.
     */
    public function tvm_tracker_handle_bulk_sync() {
        if ( ! isset( $_GET['tvm_action'] ) || $_GET['tvm_action'] !== 'bulk_sync_all' ) {
            return;
        }

        check_admin_referer( 'tvm_bulk_sync_nonce' );

        global $wpdb;
        $table_shows = $wpdb->prefix . 'tvm_tracker_shows';
        
        $unique_titles = $wpdb->get_col( "SELECT DISTINCT title_id FROM $table_shows" );

        if ( ! empty( $unique_titles ) ) {
            foreach ( $unique_titles as $title_id ) {
                $this->db_client->tvm_tracker_sync_db_from_cache( absint($title_id) );
            }
            $msg = sprintf( __('Bulk sync complete. Processed %d titles.', 'tvm-tracker'), count($unique_titles) );
        } else {
            $msg = __('No tracked titles found to sync.', 'tvm-tracker');
        }

        $redirect_url = add_query_arg( 
            array( 'settings-updated' => 'true', 'tvm_message' => urlencode($msg) ), 
            admin_url( 'admin.php?page=tvm-tracker-api-log' ) 
        );
        wp_safe_redirect( $redirect_url );
        exit;
    }







    /**
     * Adds the top-level admin menu item and submenus.
     */
    public function tvm_tracker_add_admin_menu() {
        // 1. Add top-level menu item (Settings & Stats - main page)
        $this->page_hook = add_menu_page(
            esc_html__( 'TVM Tracker Settings', 'tvm-tracker' ), // Page title
            esc_html__( 'TVM Tracker', 'tvm-tracker' ),           // Menu title
            'manage_options',                                     // Capability
            'tvm-tracker-settings',                               // Menu slug
            array( $this, 'tvm_tracker_options_page' ),           // Callback function (Settings & Stats)
            'dashicons-visibility',                               // Icon URL/Class
            6                                                     // Position
        );
        
        // 2. Add Submenu: Sources
        add_submenu_page(
            'tvm-tracker-settings',                               // Parent slug
            esc_html__( 'Streaming Sources', 'tvm-tracker' ),      // Page title
            esc_html__( 'Sources', 'tvm-tracker' ),                // Menu title
            'manage_options',                                     // Capability
            'tvm-tracker-sources',                                // Menu slug
            array( $this, 'tvm_tracker_sources_page' )            // Callback function
        );

        // 3. Add Submenu: API Log
        add_submenu_page(
            'tvm-tracker-settings',                               // Parent slug
            esc_html__( 'API Cache Log', 'tvm-tracker' ),          // Page title
            esc_html__( 'API Log', 'tvm-tracker' ),                // Menu title
            'manage_options',                                     // Capability
            'tvm-tracker-api-log',                                // Menu slug
            array( $this, 'tvm_tracker_api_log_page' )             // Callback function
        );
    }

    /**
     * Conditionally enqueues admin CSS only on the settings page.
     *
     * @param string $hook The current admin page hook.
     */
    public function tvm_tracker_admin_enqueue_styles( $hook ) {
        // Apply admin styles and scripts to all our plugin pages
        if ( false === strpos( $hook, 'tvm-tracker' ) ) {
            return;
        }
        
        wp_enqueue_style(
            'tvm-tracker-admin-style',
            TVM_TRACKER_URL . 'css/tvm-tracker-admin.css',
            array(),
            '1.0.2'
        );
        
        // NEW: Enqueue Admin JS for interactivity (Sources collapse)
        wp_enqueue_script( 
            'tvm-tracker-admin-js', 
            TVM_TRACKER_URL . 'js/tvm-tracker-admin.js', 
            array('jquery'), // Dependency on jQuery for slideToggle
            '1.0.0', 
            true // Load in footer
        );
    }

    /**
     * Registers settings, sections, and fields using the Settings API.
     */
    public function tvm_tracker_settings_init() {

        // --- GLOBAL SETTINGS REGISTRATION (Always register these fields) ---
        register_setting( 'tvm_tracker_settings_group', 'tvm_tracker_api_key', [ 'type' => 'string', 'sanitize_callback' => [ $this, 'tvm_tracker_sanitize_api_key' ], 'default' => '' ] );
        register_setting( 'tvm_tracker_settings_group', 'tvm_tracker_debug_mode', [ 'type' => 'boolean', 'sanitize_callback' => [ $this, 'tvm_tracker_sanitize_debug_mode' ], 'default' => false ] );
        register_setting( 'tvm_tracker_settings_group', 'tvm_tracker_enabled_sources', [ 'type' => 'array', 'sanitize_callback' => [ $this, 'tvm_tracker_sanitize_enabled_sources' ], 'default' => [] ] );
        // NEW: Register new option to track selected regions
        register_setting( 'tvm_tracker_settings_group', 'tvm_tracker_enabled_regions', [ 'type' => 'array', 'sanitize_callback' => [ $this, 'tvm_tracker_sanitize_enabled_regions' ], 'default' => ['US'] ] );


        // --- 1. SETTINGS PAGE SECTIONS (tvm-tracker-settings) ---
        add_settings_section(
            'tvm_tracker_settings_section_api',
            esc_html__( 'API Configuration & Debug', 'tvm-tracker' ),
            array( $this, 'tvm_tracker_section_api_callback' ),
            'tvm-tracker-settings'
        );
        add_settings_field(
            'tvm_tracker_api_key_field',
            esc_html__( 'Watchmode API Key', 'tvm-tracker' ),
            array( $this, 'tvm_tracker_api_key_render' ),
            'tvm-tracker-settings',
            'tvm_tracker_settings_section_api'
        );
        add_settings_field(
            'tvm_tracker_debug_mode_field',
            esc_html__( 'Debug Mode', 'tvm-tracker' ),
            array( $this, 'tvm_tracker_debug_mode_render' ),
            'tvm-tracker-settings',
            'tvm_tracker_settings_section_api'
        );

        // --- 2. SOURCES PAGE SECTIONS (tvm-tracker-sources) ---
        add_settings_section(
            'tvm_tracker_settings_section_sources',
            esc_html__( 'Streaming Services Selection', 'tvm-tracker' ),
            array( $this, 'tvm_tracker_section_sources_callback' ),
            'tvm-tracker-sources'
        );
        // Note: The fields are not directly rendered via a single callback here;
        // The main page renders the sections manually below for the new UI.
    }

    /**
     * Renders the API Key input field.
     */
    public function tvm_tracker_api_key_render() {
        $api_key = get_option( 'tvm_tracker_api_key', '' );
        ?>
        <input type="text" name="tvm_tracker_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Enter your Watchmode API Key', 'tvm-tracker' ); ?>">
        <p class="description"><?php esc_html_e( 'Get your API key from Watchmode.com.', 'tvm-tracker' ); ?></p>
        <?php
    }

    /**
     * Renders the Debug Mode checkbox.
     */
    public function tvm_tracker_debug_mode_render() {
        $debug_mode = get_option( 'tvm_tracker_debug_mode', false );
        ?>
        <input type="checkbox" name="tvm_tracker_debug_mode" value="1" <?php checked( true, $debug_mode ); ?>>
        <?php esc_html_e( 'Enable debug mode on the frontend (only visible to administrators).', 'tvm-tracker' ); ?>
        <?php
    }

    /**
     * Converts a two-letter country code (ISO 3166-1 alpha-2) to its flag emoji representation.
     *
     * @param string $country_code The two-letter country code (e.g., 'US').
     * @return string The flag emoji or empty string on invalid code.
     */
    private function tvm_tracker_get_flag_emoji( $country_code ) {
        if ( ! is_string( $country_code ) || strlen( $country_code ) !== 2 ) {
            return '';
        }
        $country_code = strtoupper( $country_code );
        $flag = '';
        for ( $i = 0; $i < 2; $i++ ) {
            // Regional Indicator Symbol Letter A starts at 0x1F1E6. ASCII A starts at 65.
            $flag .= mb_chr( ord( $country_code[ $i ] ) + 0x1F1E6 - 65 );
        }
        return $flag;
    }


    /**
     * Processes raw API data to get separate lists for Regions and Services (by type).
     *
     * @return array|WP_Error An array containing 'regions' and 'services_by_type', or WP_Error.
     */
    private function tvm_tracker_get_processed_source_data() {
        $all_sources_full = $this->api_client->tvm_tracker_get_all_sources();
        
        if ( is_wp_error( $all_sources_full ) ) {
            return $all_sources_full;
        }
        
        $enabled_sources = array_map('absint', (array)get_option( 'tvm_tracker_enabled_sources', [] ));
        
        $regions = [];
        $services_by_type = [];
        $service_map = []; // service_map[service_key] = { name, logo, type, source_ids_in_regions: { REGION: source_id } }

        foreach ($all_sources_full as $provider) {
            if (!isset($provider['id'], $provider['name'], $provider['logo_100px'], $provider['regions']) || !is_array($provider['regions'])) {
                continue;
            }
            
            $source_id = absint($provider['id']);
            $type_code = sanitize_key($provider['type'] ?? 'other');
            $service_name = sanitize_text_field($provider['name']);
            $logo_url = esc_url($provider['logo_100px']);

            $service_key = md5($service_name . $logo_url);

            if (!isset($service_map[$service_key])) {
                $service_map[$service_key] = [
                    'service_name' => $service_name,
                    'logo_url' => $logo_url,
                    'type' => $type_code,
                    'source_ids_in_regions' => [], // Maps Region Code -> Source ID for filtering
                    'is_enabled' => false,
                ];
            }
            
            foreach ($provider['regions'] as $region_code) {
                $region_code = strtoupper(sanitize_key($region_code));
                
                // Track all unique regions
                $regions[$region_code] = $region_code;

                // Map Source ID to Region/Service
                $service_map[$service_key]['source_ids_in_regions'][$region_code] = $source_id;
                
                // Check if service is currently enabled
                if (in_array($source_id, $enabled_sources)) {
                    $service_map[$service_key]['is_enabled'] = true;
                }
            }
        }
        
        // Group services by type for rendering order
        foreach ($service_map as $service_data) {
            $type = $service_data['type'];
            if (!isset($services_by_type[$type])) {
                $services_by_type[$type] = [];
            }
            $services_by_type[$type][] = $service_data;
        }

        return [
            'regions' => array_keys($regions),
            'services_by_type' => $services_by_type,
        ];
    }
    
    /**
     * Renders the Region Selector (Section 1).
     * @param array $all_regions Array of region codes.
     * @param array $enabled_regions Array of currently enabled region codes.
     */
    private function tvm_tracker_render_region_selector( $all_regions, $enabled_regions ) {
        $regions_sorted = $all_regions;
        sort($regions_sorted); // Sort regions alphabetically

         // Define human-readable names for display
        $region_map = [
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'BR' => 'Brazil',
            'ES' => 'Spain',
            'IN' => 'India',
            'AE' => 'United Arab Emirates',
            'AR' => 'Argentina',
            'AT' => 'Austria',
            'BE' => 'Belgium',
            'BG' => 'Bulgaria',
            'CH' => 'Switzerland',
            'CL' => 'Chile',
            'CO' => 'Colombia',
            'CZ' => 'Czechia',
            'DE' => 'Germany',
            'DK' => 'Denmark',
            'EC' => 'Ecuador',
            'EE' => 'Estonia',
            'FI' => 'Finland',
            'FR' => 'France',
            'GR' => 'Greece',
            'HK' => 'Hong Kong',
            'HR' => 'Croatia',
            'HU' => 'Hungary',
            'ID' => 'Indonesia',
            'IE' => 'Ireland',
            'IL' => 'Israel',
            'IS' => 'Iceland',
            'IT' => 'Italy',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'LT' => 'Lithuania',
            'MX' => 'Mexico',
            'MY' => 'Malaysia',
            'NL' => 'Netherlands',
            'NO' => 'Norway',
            'NZ' => 'New Zealand',
            'PA' => 'Panama',
            'PE' => 'Peru',
            'PH' => 'Philippines',
            'PL' => 'Poland',
            'PT' => 'Portugal',
            'RO' => 'Romania',
            'RU' => 'Russia',
            'SE' => 'Sweden',
            'SG' => 'Singapore',
            'TH' => 'Thailand',
            'TR' => 'Turkey',
            'UA' => 'Ukraine',
            'VN' => 'Vietnam',
            'ZA' => 'South Africa',
        ];

        ?>
        <div class="tvm-region-group is-open">
            <div class="tvm-region-header">
                <h3 style="margin: 0;"><?php esc_html_e('Region Filters', 'tvm-tracker'); ?></h3>
                <span class="dashicons dashicons-arrow-up"></span>
                <span class="dashicons dashicons-arrow-down" style="display:none;"></span>
            </div>
            <div class="tvm-region-content">
                <p><?php esc_html_e('Select which regions\' streaming sources you want to track.', 'tvm-tracker'); ?></p>
                <div class="tvm-12-column-grid tvm-region-grid">
                    <?php foreach ($regions_sorted as $region_code): ?>
                        <?php
                            $is_enabled = in_array($region_code, $enabled_regions);
                            $class = $is_enabled ? 'is-enabled' : '';
                            $region_label = $region_map[$region_code] ?? $region_code;
                            $flag_emoji = $this->tvm_tracker_get_flag_emoji($region_code);
                        ?>
                        <div class="tvm-grid-item">
                            <label for="region_<?php echo esc_attr($region_code); ?>" class="tvm-region-card <?php echo esc_attr($class); ?>" data-region-code="<?php echo esc_attr($region_code); ?>">
                                
                                <input type="checkbox" id="region_<?php echo esc_attr($region_code); ?>" 
                                    name="tvm_tracker_enabled_regions[]" 
                                    value="<?php echo esc_attr($region_code); ?>" 
                                    <?php checked(true, $is_enabled); ?> 
                                    hidden 
                                />

                                <span class="tvm-flag-emoji" aria-label="<?php echo esc_attr($region_code); ?>"><?php echo esc_html($flag_emoji); ?></span>
                                <span class="tvm-region-name"><?php echo esc_html($region_label); ?></span>
                                <span class="tvm-service-status">
                                    <span class="dashicons dashicons-yes"></span>
                                    <span class="dashicons dashicons-no-alt"></span>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders a Service Type Grid (Section 2/3).
     * @param string $type_code The type to render ('sub', 'free', etc.).
     * @param string $type_label The human-readable label.
     * @param array $services_data Array of service objects for this type.
     * @param array $enabled_regions Array of currently enabled region codes.
     */
    private function tvm_tracker_render_service_type_grid( $type_code, $type_label, $services_data, $enabled_regions ) {
        
        // Functionality for filtering based on enabled_regions will be in JavaScript.
        // PHP's job is to output the correct class for all services based on their region availability.
        
        ?>
        <div class="tvm-type-group is-open" data-type="<?php echo esc_attr($type_code); ?>">
            <div class="tvm-region-header">
                <h3 style="margin: 0;"><?php echo esc_html($type_label); ?> (<?php echo count($services_data); ?> Services)</h3>
                <span class="dashicons dashicons-arrow-up"></span>
                <span class="dashicons dashicons-arrow-down" style="display:none;"></span>
            </div>
            <div class="tvm-region-content">
                <div class="tvm-12-column-grid tvm-service-grid">
                    <?php foreach ($services_data as $service): ?>
                        <?php
                            // Check if this service is available in ANY currently selected region
                            $is_available_in_enabled_region = false;
                            $service_source_ids = $service['source_ids_in_regions']; // [RegionCode => SourceID]
                            
                            foreach ($service_source_ids as $region_code => $source_id) {
                                if (in_array($region_code, $enabled_regions)) {
                                     // This is the source ID the user selected
                                    $is_available_in_enabled_region = true; 
                                    break;
                                }
                            }

                            // The form field value is the unique Source ID (which ties to a specific service/region pair)
                            // We need to keep the checkbox hidden logic simple for now, as the final field logic will change.
                            
                            $is_enabled = $service['is_enabled'];
                            $class = $is_enabled ? 'is-enabled' : '';
                            
                            // Classes for JS filtering (All regions this service is available in)
                            $filter_classes = array_map(fn($r) => 'region-' . strtolower($r), array_keys($service_source_ids));
                            $filter_classes[] = $is_available_in_enabled_region ? 'is-region-available' : 'is-region-unavailable';

                        ?>
                        <div class="tvm-grid-item <?php echo esc_attr(implode(' ', $filter_classes)); ?>">
                            <label for="service_<?php echo esc_attr($service['service_name']); ?>" 
                                class="tvm-service-card <?php echo esc_attr($class); ?>"
                                data-service-toggle="<?php echo esc_attr(md5($service['service_name'] . $service['logo_url'])); ?>"
                                data-service-key="<?php echo esc_attr(md5($service['service_name'])); ?>"
                                data-source-map="<?php echo esc_attr(json_encode($service['source_ids_in_regions'])); ?>" >
                                
                                <input type="checkbox" id="service_<?php echo esc_attr($service['service_name']); ?>" 
                                    name="tvm_tracker_service_placeholder" 
                                    value="" 
                                    <?php checked(true, $is_enabled); ?> 
                                    hidden 
                                />

                                <img src="<?php echo esc_url($service['logo_url']); ?>" alt="<?php echo esc_attr($service['service_name']); ?>" class="tvm-service-logo" />
                                <span class="tvm-service-name"><?php echo esc_html($service['service_name']); ?></span>
                                <span class="tvm-service-status">
                                    <span class="dashicons dashicons-yes"></span>
                                    <span class="dashicons dashicons-no-alt"></span>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the Streaming Sources selection area.
     */
    public function tvm_tracker_enabled_sources_render() {
        
        $processed_data = $this->tvm_tracker_get_processed_source_data();
        
        if ( is_wp_error( $processed_data ) ) {
             echo '<p class="tvm-error-message">' . esc_html($processed_data->get_error_message()) . '</p>';
             return;
        }

        // --- 1. Get Filters ---
        $all_regions = $processed_data['regions'];
        $services_by_type = $processed_data['services_by_type'];
        $enabled_regions = (array)get_option( 'tvm_tracker_enabled_regions', ['US'] ); // Default to US selected

        // Type mapping (for display order and label)
        $type_order = [
            'sub' => esc_html__('Subscription Services', 'tvm-tracker'),
            'free' => esc_html__('Free Services', 'tvm-tracker'),
            'rent' => esc_html__('Rental Services', 'tvm-tracker'),
            'buy' => esc_html__('Purchase Services', 'tvm-tracker'),
            'other' => esc_html__('Other / Unknown Services', 'tvm-tracker'),
        ];
        
        echo '<div class="tvm-source-selection-refactored">';
        
        // --- SECTION 1: REGION SELECTOR (New Style) ---
        $this->tvm_tracker_render_region_selector( $all_regions, $enabled_regions );

        // --- SECTION 2 & 3: SERVICE GRIDS (Filtered by selected regions) ---
        foreach ($type_order as $type_code => $type_label) {
            if (empty($services_by_type[$type_code])) continue;
            
            $this->tvm_tracker_render_service_type_grid(
                $type_code,
                $type_label,
                $services_by_type[$type_code],
                $enabled_regions
            );
        }
        
        echo '</div>';
    }


    // ... [ rest of the helper methods and callbacks remain unchanged ] ...
    
    // =======================================================================
    // ADMIN STATS GETTER METHODS
    // =======================================================================
    
    // ... [ all getter methods remain unchanged ] ...

    // =======================================================================
    // SETTINGS PAGE TEMPLATES
    // =======================================================================

    /**
     * Renders the Stats Dashboard section for the main settings page.
     */
    public function tvm_tracker_stats_render() {
        // This relies on new DB methods which are assumed to be implemented in Tvm_Tracker_DB
        $total_shows = $this->db_client->tvm_tracker_get_total_shows_count() ?? 0;
        $total_movies = $this->db_client->tvm_tracker_get_total_movies_count() ?? 0;
        $total_episodes = $this->db_client->tvm_tracker_get_total_episodes_count() ?? 0;
        $cache_entries = $this->db_client->tvm_tracker_get_cache_count() ?? 0;

        ?>
        <div class="tvm-global-stats-dashboard" style="margin-top: 20px; border: 1px solid #ddd; padding: 15px; border-radius: 4px; background: #f9f9f9;">
            <h3><?php esc_html_e('System Stats (All Tracked Records)', 'tvm-tracker'); ?></h3>
            <div style="display: flex; gap: 20px; text-align: center;">
                <div class="tvm-stat-item">
                    <h4 class="tvm-stat-label"><?php esc_html_e('Total Series Tracked', 'tvm-tracker'); ?></h4>
                    <div class="tvm-stat-number" style="font-size: 2em;"><?php echo absint($total_shows); ?></div>
                </div>
                <div class="tvm-stat-item">
                    <h4 class="tvm-stat-label"><?php esc_html_e('Total Movies Tracked', 'tvm-tracker'); ?></h4>
                    <div class="tvm-stat-number" style="font-size: 2em;"><?php echo absint($total_movies); ?></div>
                </div>
                <div class="tvm-stat-item">
                    <h4 class="tvm-stat-label"><?php esc_html_e('Total Episodes Cached', 'tvm-tracker'); ?></h4>
                    <div class="tvm-stat-number" style="font-size: 2em;"><?php echo absint($total_episodes); ?></div>
                </div>
                <div class="tvm-stat-item">
                    <h4 class="tvm-stat-label"><?php esc_html_e('API Cache Entries', 'tvm-tracker'); ?></h4>
                    <div class="tvm-stat-number" style="font-size: 2em;"><?php echo absint($cache_entries); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Section callback: API Configuration.
     */
    public function tvm_tracker_section_api_callback() {
        echo '<p>' . esc_html__( 'Enter your API key and configure system debug mode.', 'tvm-tracker' ) . '</p>';
    }

    /**
     * Section callback: Streaming Sources.
     */
    public function tvm_tracker_section_sources_callback() {
        echo '<p>' . esc_html__( 'Select which streaming services are currently available to your users. Sources not selected will be filtered out on the frontend.', 'tvm-tracker' ) . '</p>';
    }
    
    /**
     * Renders the API Log table.
     */
    public function tvm_tracker_api_log_page() {
        // ... [ Logic remains unchanged ] ...
        // --- 1. Parameter Handling ---
        $current_type_filter = sanitize_key( $_GET['cache_type'] ?? 'all' );
        $current_title_id_filter = absint( $_GET['title_id'] ?? 0 );
        
        // --- 2. Data Fetch ---
        // Fetch the filtered records (DB helper needs to be updated to accept title_id filter)
        $log_records = $this->db_client->tvm_tracker_get_api_log_records( $current_type_filter, $current_title_id_filter );
        $plugin_page_url = admin_url( 'admin.php?page=tvm-tracker-api-log' );

        // --- 3. Configuration & Helpers ---
        $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $current_time_gmt = time();
        $title_cache = [];
        $unique_title_ids_in_view = [];
        
        $cache_types = [
            'all'           => esc_html__('All Types', 'tvm-tracker'),
            'details'       => esc_html__('Title Details', 'tvm-tracker'),
            'episodes'      => esc_html__('Episodes', 'tvm-tracker'),
            'title_sources' => esc_html__('Title Sources', 'tvm-tracker'),
            'sources'       => esc_html__('Global Sources', 'tvm-tracker'),
            'search'        => esc_html__('Search Results', 'tvm-tracker'),
            'migrated'      => esc_html__('Migrated (Legacy)', 'tvm-tracker'),
        ];
        
        // --- 4. Processing, Stats Calculation & Title Lookup ---
        $processed_records = [];
        $monthly_calls_total_estimate = 0; // The sum of (30 days / record expiry days) for all records in view

        foreach ($log_records as $record) {
            $record['display_title'] = '';
            $record['title_id'] = 0;
            $record['duration_display'] = '';
            $record['expiry_seconds'] = 0;

            // 1. Extract Title ID and Name
            if (preg_match('/title\/(\d+)/', $record['request_path'], $matches)) {
                $title_id = absint($matches[1]);
                $record['title_id'] = $title_id;
                $unique_title_ids_in_view[$title_id] = true;
                
                if (!isset($title_cache[$title_id])) {
                    $title_cache[$title_id] = $this->db_client->tvm_tracker_get_title_name_by_id($title_id);
                }
                
                $record['display_title'] = $title_cache[$title_id] ?: 'ID: ' . $title_id;
            } elseif ($record['cache_type'] === 'sources') {
                 $record['display_title'] = esc_html__('Global Source List', 'tvm-tracker');
            } else {
                 $record['display_title'] = esc_html__('General / Search', 'tvm-tracker');
            }
            
            // 2. Convert timestamps and calculate duration
            $expires_ts = strtotime($record['cache_expires'] . ' GMT');
            $updated_ts = strtotime($record['last_updated'] . ' GMT');
            
            $record['last_updated_local'] = date_i18n($date_format, $updated_ts);
            $record['cache_expires_local'] = date_i18n($date_format, $expires_ts);

            // Calculate time until/since expiry
            $time_diff = $expires_ts - $current_time_gmt; // In seconds
            
            if ($time_diff > 0) {
                // Not expired: show time remaining
                $record['duration_display'] = human_time_diff($current_time_gmt, $expires_ts);
            } else {
                // Expired: show time since expiry
                $record['duration_display'] = human_time_diff($expires_ts, $current_time_gmt) . ' ' . esc_html__('ago', 'tvm-tracker');
            }
            
            // 3. Calculate Monthly Call Estimate (by finding original duration)
            $seconds_in_30_days = 2592000;
            
            // Calculate the duration from the difference between last update and expiry
            $record['expiry_seconds'] = $expires_ts - $updated_ts;

            if ($record['expiry_seconds'] > 0 && $record['expiry_seconds'] <= $seconds_in_30_days) {
                 // Add the number of times this record would be called per 30 days
                 $monthly_calls_total_estimate += ($seconds_in_30_days / $record['expiry_seconds']);
            }
            
            $processed_records[] = $record;
        }
        
        // Sort unique title IDs by name for the filter dropdown
        $unique_titles_for_filter = [];
        foreach (array_keys($unique_title_ids_in_view) as $id) {
            if (!empty($title_cache[$id])) {
                $unique_titles_for_filter[$id] = $title_cache[$id];
            } else {
                 $unique_titles_for_filter[$id] = 'ID: ' . $id;
            }
        }
        asort($unique_titles_for_filter);

        $formatted_monthly_estimate = number_format(round($monthly_calls_total_estimate));
        $total_calls_all_time = $this->db_client->tvm_tracker_get_cache_count() ?? 0;
        $bulk_sync_url = wp_nonce_url( add_query_arg('tvm_action', 'bulk_sync_all', $plugin_page_url), 'tvm_bulk_sync_nonce' );

        // --- RENDER UI ---
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('API Cache Log', 'tvm-tracker'); ?></h1>
            <p><?php esc_html_e('Displays cached API calls stored in the custom database table, ordered by last update time. Limited to the last 200 entries for performance.', 'tvm-tracker'); ?></p>
            
            <div style="background: #f0f0f0; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
                <strong><?php esc_html_e('API Call Estimate:', 'tvm-tracker'); ?></strong> 
                <?php printf(
                    /* translators: 1: estimated monthly calls, 2: total unique calls logged */
                    esc_html__('Based on the frequency of the %2$d cached calls displayed, the projected rate is approximately %1$s calls per month.', 'tvm-tracker'), 
                    $formatted_monthly_estimate,
                    absint($total_calls_all_time)
                ); ?>
            </div>
            <div class="tvm-api-log-actions" style="margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4; display: flex; justify-content: space-between; align-items: center;">
    <div style="flex-grow: 1;">
        <strong><?php esc_html_e('Database Synchronization:', 'tvm-tracker'); ?></strong> 
        <?php esc_html_e('Force relational tables to update using current cached JSON data.', 'tvm-tracker'); ?>
    </div>
    <a href="<?php echo esc_url($bulk_sync_url); ?>" class="button button-primary"><?php esc_html_e('Sync All Records from Cache', 'tvm-tracker'); ?></a>
</div>

            <div class="alignleft actions">
                <form method="get" style="display: flex; gap: 10px; align-items: center;">
                    <input type="hidden" name="page" value="tvm-tracker-api-log">
                    
                    <select name="cache_type" id="cache_type_filter">
                        <?php foreach ($cache_types as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($current_type_filter, $key); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="title_id" id="title_id_filter">
                        <option value="0"><?php esc_html_e('Filter by Title', 'tvm-tracker'); ?></option>
                         <?php 
                            foreach ($unique_titles_for_filter as $id => $name): 
                        ?>
                            <option value="<?php echo absint($id); ?>" <?php selected($current_title_id_filter, $id); ?>><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <?php submit_button(esc_html__('Filter', 'tvm-tracker'), 'button', 'filter_action', false); ?>
                    
                    <?php 
                        // Clear Filter Button (Only show if filters are active)
                        if ($current_type_filter !== 'all' || $current_title_id_filter > 0): 
                    ?>
                        <a href="<?php echo esc_url($plugin_page_url); ?>" class="button"><?php esc_html_e('Clear Filters', 'tvm-tracker'); ?></a>
                    <?php endif; ?>
                </form>
            </div>
            <br class="clear">
            
            <?php if (empty($processed_records)): ?>
                <p><?php esc_html_e('The API cache log is currently empty or no records match your filter.', 'tvm-tracker'); ?></p>
            <?php else: ?>
                <p><?php printf(esc_html__('Total records retrieved: %d', 'tvm-tracker'), count($processed_records)); ?></p>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 10%;"><?php esc_html_e('Type', 'tvm-tracker'); ?></th>
                            <th scope="col" style="width: 25%;"><?php esc_html_e('Title / Request', 'tvm-tracker'); ?></th>
                            <th scope="col" style="width: 15%;"><?php esc_html_e('Last Updated', 'tvm-tracker'); ?></th>
                            <th scope="col" style="width: 15%;"><?php esc_html_e('Expires', 'tvm-tracker'); ?></th>
                            <th scope="col" style="width: 15%;"><?php esc_html_e('Time Left / Since', 'tvm-tracker'); ?></th>
                            <th scope="col" style="width: 20%;"><?php esc_html_e('Original Path', 'tvm-tracker'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processed_records as $record): ?>
                        <?php
                            $is_expired = strtotime($record['cache_expires']) < $current_time_gmt;
                            $row_class = $is_expired ? 'class="tvm-expired-row"' : '';
                            
                            // Prepare URL for clickable Type filter
                            $type_filter_url = add_query_arg('cache_type', $record['cache_type'], $plugin_page_url);
                            $type_filter_url = remove_query_arg('title_id', $type_filter_url); // Clear other filter when clicking type

                            // Prepare URL for clickable Title filter
                            $title_filter_url = $plugin_page_url;
                            if ($record['title_id'] > 0) {
                                $title_filter_url = add_query_arg('title_id', $record['title_id'], $title_filter_url);
                                $title_filter_url = remove_query_arg('cache_type', $title_filter_url); // Clear other filter when clicking title
                            }
                        ?>
                        <tr <?php echo $row_class; ?>>
                            <td data-colname="<?php esc_attr_e('Type', 'tvm-tracker'); ?>">
                                <a href="<?php echo esc_url($type_filter_url); ?>" style="font-weight: 600;">
                                    <?php echo esc_html(ucwords($record['cache_type'])); ?>
                                </a>
                            </td>
                            <td data-colname="<?php esc_attr_e('Title / Request', 'tvm-tracker'); ?>">
                                <?php if ($record['title_id'] > 0): ?>
                                    <a href="<?php echo esc_url($title_filter_url); ?>" style="font-weight: 600;"><?php echo esc_html($record['display_title']); ?></a>
                                    <?php if ($record['cache_type'] === 'search'): ?>
                                        <br><span style="font-size: 0.9em;"><?php echo esc_html(basename($record['request_path'])); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php echo esc_html($record['display_title']); ?>
                                <?php endif; ?>
                            </td>
                            <td data-colname="<?php esc_attr_e('Last Updated', 'tvm-tracker'); ?>"><?php echo esc_html($record['last_updated_local']); ?></td>
                            <td data-colname="<?php esc_attr_e('Expires', 'tvm-tracker'); ?>">
                                <?php echo esc_html($record['cache_expires_local']); ?>
                                <?php if ($is_expired): ?>
                                    <br><span style="color: #d54e21; font-weight: 600; font-size: 0.9em;"><?php esc_html_e('EXPIRED', 'tvm-tracker'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-colname="<?php esc_attr_e('Time Left / Since', 'tvm-tracker'); ?>">
                                <?php if ($is_expired): ?>
                                    <span style="color: #d54e21;"><?php echo esc_html($record['duration_display']); ?></span>
                                <?php else: ?>
                                    <span style="color: #46b450; font-weight: 600;"><?php echo esc_html($record['duration_display']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-colname="<?php esc_attr_e('Original Path', 'tvm-tracker'); ?>" style="overflow-wrap: break-word; font-size: 0.7em; color: #777;">
                                <?php echo esc_html($record['request_path']); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders the Sources Configuration page content.
     */
    public function tvm_tracker_sources_page() {
        // Fetch current values for fields configured on other pages
        $api_key = get_option( 'tvm_tracker_api_key', '' );
        $debug_mode = get_option( 'tvm_tracker_debug_mode', false );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Streaming Sources Configuration', 'tvm-tracker'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'tvm_tracker_settings_group' );
                // Use the page slug 'tvm-tracker-sources' here to render the correct sections
                
                // --- MANUAL RENDERING OF SECTIONS ---
                
                // 1. Region Selector (Section 1)
                $processed_data = $this->tvm_tracker_get_processed_source_data();
                if ( is_wp_error( $processed_data ) ) {
                    echo '<p class="tvm-error-message">' . esc_html($processed_data->get_error_message()) . '</p>';
                } else {
                    $this->tvm_tracker_render_region_selector( $processed_data['regions'], (array)get_option( 'tvm_tracker_enabled_regions', ['US'] ) );
                }

                // 2. Service Grids (Section 2 & 3 - Subscription, Free, etc.)
                if (!is_wp_error($processed_data)) {
                     $services_by_type = $processed_data['services_by_type'];
                     $enabled_regions = (array)get_option( 'tvm_tracker_enabled_regions', ['US'] );
                     
                     $type_order = [
                         'sub' => esc_html__('Subscription Services', 'tvm-tracker'),
                         'free' => esc_html__('Free Services', 'tvm-tracker'),
                         'rent' => esc_html__('Rental Services', 'tvm-tracker'),
                         'buy' => esc_html__('Purchase Services', 'tvm-tracker'),
                         'other' => esc_html__('Other / Unknown Services', 'tvm-tracker'),
                     ];
                     
                     echo '<div class="tvm-source-service-selection">';

                     foreach ($type_order as $type_code => $type_label) {
                         if (empty($services_by_type[$type_code])) continue;
                         
                         $this->tvm_tracker_render_service_type_grid(
                             $type_code,
                             $type_label,
                             $services_by_type[$type_code],
                             $enabled_regions
                         );
                     }
                     echo '</div>';
                }

                // --- END MANUAL RENDERING ---


                // HIDDEN FIELDS: Preserve settings configured on other pages
                ?>
                <input type="hidden" name="tvm_tracker_api_key" value="<?php echo esc_attr( $api_key ); ?>" />
                <input type="hidden" name="tvm_tracker_debug_mode" value="<?php echo esc_attr( (int) $debug_mode ); ?>" />
                <?php
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }


    /**
     * Renders the main Settings & Stats page.
     */
    public function tvm_tracker_options_page() {
        // Fetch current values for fields configured on other pages
        $enabled_sources = get_option( 'tvm_tracker_enabled_sources', [] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TVM Tracker Settings & Stats', 'tvm-tracker' ); ?></h1>
            
            <form action="options.php" method="post">
                <?php settings_fields( 'tvm_tracker_settings_group' ); ?>
                
                <h2><?php esc_html_e( 'General Configuration', 'tvm-tracker' ); ?></h2>
                <?php 
                // Render API key and Debug sections
                do_settings_sections( 'tvm-tracker-settings' ); 
                
                // Render Stats Dashboard
                $this->tvm_tracker_stats_render();
                
                submit_button();
                
                // HIDDEN FIELDS: Preserve settings configured on other pages
                // Need one hidden field for each enabled source ID (to submit as an array)
                if (!empty($enabled_sources) && is_array($enabled_sources)) {
                    foreach ($enabled_sources as $source_id) {
                        // WordPress expects the array notation for array options
                        echo '<input type="hidden" name="tvm_tracker_enabled_sources[]" value="' . absint($source_id) . '" />';
                    }
                } else {
                    // Send an empty field if no sources are enabled, to prevent misinterpretation
                    echo '<input type="hidden" name="tvm_tracker_enabled_sources" value="" />';
                }
                
                // Preserve enabled regions as a hidden field set
                $enabled_regions = (array)get_option( 'tvm_tracker_enabled_regions', ['US'] );
                if (!empty($enabled_regions)) {
                    foreach ($enabled_regions as $region_code) {
                        echo '<input type="hidden" name="tvm_tracker_enabled_regions[]" value="' . esc_attr($region_code) . '" />';
                    }
                } else {
                     echo '<input type="hidden" name="tvm_tracker_enabled_regions" value="" />';
                }
                
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * SANITIZATION CALLBACKS
     */

    /**
     * Sanitize the API key (just ensure it's a clean string).
     *
     * @param string $input The API key input.
     * @return string
     */
    public function tvm_tracker_sanitize_api_key( $input ) {
        return sanitize_text_field( $input );
    }

    /**
     * Sanitize the debug mode checkbox (boolean check).
     *
     * @param string $input The checkbox value.
     * @return bool
     */
    public function tvm_tracker_sanitize_debug_mode( $input ) {
        return (bool) $input;
    }

    /**
     * Sanitize the array of enabled sources (ensure all values are positive integers).
     *
     * @param array $input Array of source IDs (as strings or integers).
     * @return array Array of source IDs as integers.
     */
    public function tvm_tracker_sanitize_enabled_sources( $input ) {
        if ( is_array( $input ) ) {
            // CRITICAL FIX: Ensure all incoming IDs are cast to integers before saving.
            return array_map( 'absint', $input );
        }
        return array();
    }
    
    /**
     * Sanitize the array of enabled regions (ensure all values are 2-letter codes).
     *
     * @param array $input Array of region codes (as strings).
     * @return array Array of region codes as uppercase strings.
     */
    public function tvm_tracker_sanitize_enabled_regions( $input ) {
        if ( is_array( $input ) ) {
            $sanitized = array_map( 'sanitize_key', $input );
            // Ensure they are 2-letter uppercase codes
            return array_map('strtoupper', array_filter($sanitized, fn($code) => strlen($code) === 2));
        }
        return [];
    }


    /**
     * HELPER FUNCTIONS
     */

    /**
     * Checks if debug mode is active and the current user can manage options.
     *
     * @return bool
     */
    private function tvm_tracker_is_debug_on() {
        return get_option( 'tvm_tracker_debug_mode', false ) && current_user_can( 'manage_options' );
    }

    /**
     * Displays debug URLs in a formatted box.
     *
     * @param array $urls An array of URLs called.
     */
    private function tvm_tracker_display_debug_url( $urls ) {
        if ( empty( $urls ) || ! $this->tvm_tracker_is_debug_on() ) {
            return;
        }

        echo '<div class="tvm-debug-box">';
        echo '<h4>' . esc_html__( 'DEBUG MODE (Admin Only) - API Calls', 'tvm-tracker' ) . '</h4>';

        if ( is_array( $urls ) ) {
            echo '<ol>';
            foreach ( $urls as $url ) {
                echo '<li>' . esc_html( $url ) . '</li>';
            }
            echo '</ol>';
        } else {
            echo '<p>' . esc_html__( 'No API calls logged.', 'tvm-tracker' ) . '</p>';
        }

        echo '</div>';
    }
    
    /**
     * Displays a temporary admin notice to prompt users to run the backfill fix 
     * and shows the success message after redirection.
     */
    public function tvm_tracker_display_backfill_notice() {
        // Display custom success message after backfill
        if ( isset( $_GET['tvm_message'] ) && isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
            $message = sanitize_text_field( wp_unslash( $_GET['tvm_message'] ) );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
            return;
        }

        // Only show the actionable notice on the settings page to prevent clutter
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'toplevel_page_tvm-tracker-settings' ) {
            // Build URL with necessary parameters for the trigger and security nonce
            $url = add_query_arg( 'tvm_action', 'fix_end_year_backfill', admin_url( 'admin.php?page=tvm-tracker-settings' ) );
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'One-Time Action Required:', 'tvm-tracker' ); ?></strong>
                    <?php esc_html_e( 'To correctly categorize all previously tracked series into "Current" and "Ended" sections, you must run a quick database backfill.', 'tvm-tracker' ); ?>
                    <a href="<?php echo esc_url( wp_nonce_url( $url, 'tvm_end_year_backfill_nonce' ) ); ?>" class="button button-primary" style="margin-left: 10px;">
                        <?php esc_html_e( 'Run End Year Status Backfill Now', 'tvm-tracker' ); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }
}
