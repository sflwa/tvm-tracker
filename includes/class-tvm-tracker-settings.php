<?php
/**
 * TVM Tracker - Admin Settings Class
 * Handles the registration of the admin settings page, fields, and rendering.
 *
 * @package Tvm_Tracker
 * @subpackage Includes
 * @version 1.0.3
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
     * Constructor.
     *
     * @param Tvm_Tracker_API $api_client Instance of the API client.
     */
    public function __construct( Tvm_Tracker_API $api_client ) {
        $this->api_client = $api_client;
        add_action( 'admin_menu', array( $this, 'tvm_tracker_add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'tvm_tracker_settings_init' ) );
        // Use the general admin enqueue hook and check the page hook inside the function
        add_action( 'admin_enqueue_scripts', array( $this, 'tvm_tracker_admin_enqueue_styles' ) );
    }

    /**
     * Adds the top-level admin menu item.
     */
    public function tvm_tracker_add_admin_menu() {
        // Add top-level menu item for TVM Tracker
        $this->page_hook = add_menu_page(
            esc_html__( 'TVM Tracker Settings', 'tvm-tracker' ), // Page title
            esc_html__( 'TVM Tracker', 'tvm-tracker' ),           // Menu title
            'manage_options',                                     // Capability
            'tvm-tracker-settings',                               // Menu slug
            array( $this, 'tvm_tracker_options_page' ),           // Callback function
            'dashicons-visibility',                               // Icon URL/Class
            6                                                     // Position (near Dashboard)
        );

        // NOTE: The conditional enqueue hook 'load-{$this->page_hook}' has been moved 
        // to a conditional check within tvm_tracker_admin_enqueue_styles for reliability.
    }

    /**
     * Conditionally enqueues admin CSS only on the settings page.
     *
     * @param string $hook The current admin page hook.
     */
    public function tvm_tracker_admin_enqueue_styles( $hook ) {
        // Only load styles if the current hook matches our settings page hook
        if ( $hook !== $this->page_hook ) {
            return;
        }
        
        wp_enqueue_style(
            'tvm-tracker-admin-style',
            TVM_TRACKER_URL . 'css/tvm-tracker-admin.css',
            array(),
            '1.0.2'
        );
    }

    /**
     * Registers settings, sections, and fields using the Settings API.
     */
    public function tvm_tracker_settings_init() {

        // --- 1. API KEY SETTINGS ---
        register_setting(
            'tvm_tracker_settings_group',
            'tvm_tracker_api_key',
            array(
                'type' => 'string',
                'sanitize_callback' => array( $this, 'tvm_tracker_sanitize_api_key' ),
                'default' => '',
            )
        );

        add_settings_section(
            'tvm_tracker_settings_section_api',
            esc_html__( 'API Configuration', 'tvm-tracker' ),
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

        // --- 2. STREAMING SOURCE SETTINGS ---
        register_setting(
            'tvm_tracker_settings_group',
            'tvm_tracker_enabled_sources',
            array(
                'type' => 'array',
                'sanitize_callback' => array( $this, 'tvm_tracker_sanitize_enabled_sources' ),
                'default' => array(),
            )
        );

        add_settings_section(
            'tvm_tracker_settings_section_sources',
            esc_html__( 'Enabled Streaming Sources', 'tvm-tracker' ),
            array( $this, 'tvm_tracker_section_sources_callback' ),
            'tvm-tracker-settings'
        );

        add_settings_field(
            'tvm_tracker_enabled_sources_field',
            esc_html__( 'Select Sources', 'tvm-tracker' ),
            array( $this, 'tvm_tracker_enabled_sources_render' ),
            'tvm-tracker-settings',
            'tvm_tracker_settings_section_sources'
        );

        // --- 3. DEBUG MODE SETTINGS ---
        register_setting(
            'tvm_tracker_settings_group',
            'tvm_tracker_debug_mode',
            array(
                'type' => 'boolean',
                'sanitize_callback' => array( $this, 'tvm_tracker_sanitize_debug_mode' ),
                'default' => false,
            )
        );

        add_settings_field(
            'tvm_tracker_debug_mode_field',
            esc_html__( 'Debug Mode', 'tvm-tracker' ),
            array( $this, 'tvm_tracker_debug_mode_render' ),
            'tvm-tracker-settings',
            'tvm_tracker_settings_section_api'
        );
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
     * Renders the Streaming Sources selection area.
     */
    public function tvm_tracker_enabled_sources_render() {
        $enabled_sources = get_option( 'tvm_tracker_enabled_sources', array() );
        $all_sources = $this->api_client->tvm_tracker_get_all_sources();

        if ( is_wp_error( $all_sources ) ) {
            // Display error and debug info if API fails
            echo '<p class="tvm-error-message">' . esc_html( $all_sources->get_error_message() ) . '</p>';
            if ( $this->tvm_tracker_is_debug_on() && class_exists( 'Tvm_Tracker_API' ) ) {
                $this->tvm_tracker_display_debug_url( Tvm_Tracker_API::tvm_tracker_get_api_urls_called() );
            }
            return;
        }

        if ( empty( $all_sources ) ) {
            echo '<p class="description">' . esc_html__( 'Could not retrieve sources. Please ensure your API key is correct.', 'tvm-tracker' ) . '</p>';
            return;
        }

        echo '<div class="tvm-source-selection-container">';
        foreach ( $all_sources as $source ) {
            // Only show sources that have a logo and are available in the US (or a specific region, simplify to US for now)
            if ( ! empty( $source['logo_100px'] ) && in_array( 'US', $source['regions'] ) ) {

                $source_id = absint( $source['id'] );
                $source_name = sanitize_text_field( $source['name'] );
                $logo_url = esc_url( $source['logo_100px'] );
                $checked = in_array( $source_id, $enabled_sources );
                $checked_attr = checked( true, $checked, false );
                $alt_text = sprintf( /* translators: 1: Streaming Service Name */ esc_attr__( '%s logo', 'tvm-tracker' ), $source_name );
                $is_enabled_class = $checked ? 'is-enabled' : '';

                ?>
                <label for="tvm_source_<?php echo esc_attr( $source_id ); ?>" class="tvm-source-card <?php echo esc_attr( $is_enabled_class ); ?>">
                    <input
                        type="checkbox"
                        id="tvm_source_<?php echo esc_attr( $source_id ); ?>"
                        name="tvm_tracker_enabled_sources[]"
                        value="<?php echo esc_attr( $source_id ); ?>"
                        <?php echo $checked_attr; // Escaping handled by checked() ?>
                        hidden
                    />
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $alt_text ); ?>" class="tvm-source-logo" />
                    <span class="tvm-source-name"><?php echo esc_html( $source_name ); ?></span>
                    <span class="tvm-source-status">
                        <?php if ( $checked ) : ?>
                            <span class="dashicons dashicons-yes"></span>
                        <?php else : ?>
                            <span class="dashicons dashicons-no-alt"></span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php
            }
        }
        echo '</div>'; // .tvm-source-selection-container

        if ( $this->tvm_tracker_is_debug_on() && class_exists( 'Tvm_Tracker_API' ) ) {
            $this->tvm_tracker_display_debug_url( Tvm_Tracker_API::tvm_tracker_get_api_urls_called() );
        }
    }

    /**
     * Section callback: API Configuration.
     */
    public function tvm_tracker_section_api_callback() {
        echo '<p>' . esc_html__( 'Enter your API key to enable data retrieval and optionally enable debug mode for troubleshooting.', 'tvm-tracker' ) . '</p>';
    }

    /**
     * Section callback: Streaming Sources.
     */
    public function tvm_tracker_section_sources_callback() {
        echo '<p>' . esc_html__( 'Select which streaming services you use. Only titles available on these services will be displayed on the frontend.', 'tvm-tracker' ) . '</p>';
    }

    /**
     * Renders the main options page.
     */
    public function tvm_tracker_options_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TVM Tracker Settings', 'tvm-tracker' ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'tvm_tracker_settings_group' );
                do_settings_sections( 'tvm-tracker-settings' );
                submit_button();
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
     * @param array $input Array of source IDs.
     * @return array
     */
    public function tvm_tracker_sanitize_enabled_sources( $input ) {
        if ( is_array( $input ) ) {
            return array_map( 'absint', $input );
        }
        return array();
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
                echo '<li>' . esc_url( $url ) . '</li>';
            }
            echo '</ol>';
        } else {
            echo '<p>' . esc_html__( 'No API calls logged.', 'tvm-tracker' ) . '</p>';
        }

        echo '</div>';
    }
}
