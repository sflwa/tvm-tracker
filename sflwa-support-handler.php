<?php
/**
 * SFLWA Central Support & Documentation Handler
 *
 * Reusable module across all SFLWA WordPress plugins.
 * Manages the top-level SFLWA Support menu, plugin card dashboard,
 * developer bio, registration submission, and full-width readme rendering.
 *
 * Version: 2.0.7
 * Author: South Florida Web Advisors
 * Author URI: https://sflwa.net
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define handler version for version-aware loading across multiple plugins.
if ( ! defined( 'SFLWA_SUPPORT_HANDLER_VERSION' ) ) {
    define( 'SFLWA_SUPPORT_HANDLER_VERSION', '2.0.7' );
}

// Global registry to collect registered plugins across multiple SFLWA plugins.
if ( ! isset( $GLOBALS['sflwa_registered_plugins'] ) ) {
    $GLOBALS['sflwa_registered_plugins'] = array();
}

/**
 * Public Helper Function to Register any SFLWA Plugin into the Handler.
 * Declared early to ensure availability regardless of engine class declaration state.
 *
 * @param array $config Configuration array for the plugin support page.
 */
if ( ! function_exists( 'sflwa_register_support_page' ) ) {
    function sflwa_register_support_page( $config ) {
        $defaults = array(
            'plugin_name'   => '',
            'plugin_slug'   => '',
            'plugin_file'   => '',
            'text_domain'   => '',
            'submenu_title' => '',
            'description'   => '',
            'version'       => '1.0.0',
        );

        $args = wp_parse_args( $config, $defaults );

        if ( ! empty( $args['plugin_slug'] ) ) {
            $GLOBALS['sflwa_registered_plugins'][ $args['plugin_slug'] ] = $args;
        }

        if ( class_exists( 'SFLWA_Support_Handler_Engine' ) ) {
            SFLWA_Support_Handler_Engine::get_instance();
        }
    }
}

/**
 * Main Handler Engine Class
 */
if ( ! class_exists( 'SFLWA_Support_Handler_Engine' ) ) {

    class SFLWA_Support_Handler_Engine {

        /**
         * Singleton Instance
         *
         * @var SFLWA_Support_Handler_Engine|null
         */
        private static $instance = null;

        /**
         * Get Singleton Instance
         *
         * @return SFLWA_Support_Handler_Engine
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Constructor
         */
        private function __construct() {
            add_action( 'admin_menu', array( $this, 'register_admin_menus' ), 9 );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
            
            // AJAX endpoints for registration form
            add_action( 'wp_ajax_sflwa_register_site_info', array( $this, 'handle_ajax_site_registration' ) );
        }

        /**
         * Helper translation wrapper to dynamically translate using the caller's text domain.
         *
         * @param string $text   Text to translate.
         * @param string $domain Text domain.
         * @return string
         */
        private function t( $text, $domain = '' ) {
            if ( empty( $domain ) ) {
                $domain = $this->get_primary_text_domain();
            }
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain
            return __( $text, $domain );
        }

        /**
         * Get the text domain of the primary registered plugin.
         *
         * @return string
         */
        private function get_primary_text_domain() {
            if ( ! empty( $GLOBALS['sflwa_registered_plugins'] ) ) {
                $first = reset( $GLOBALS['sflwa_registered_plugins'] );
                if ( ! empty( $first['text_domain'] ) ) {
                    return $first['text_domain'];
                }
            }
            return 'default';
        }

        /**
         * Register the Top-Level SFLWA Support menu and individual plugin submenus.
         */
        public function register_admin_menus() {
            static $menu_registered = false;

            $primary_domain = $this->get_primary_text_domain();

            // Register main parent menu if not already created
            if ( ! $menu_registered ) {
                add_menu_page(
                    $this->t( 'SFLWA Support', $primary_domain ),
                    $this->t( 'SFLWA Support', $primary_domain ),
                    'manage_options',
                    'sflwa-support-dashboard',
                    array( $this, 'render_dashboard_page' ),
                    'dashicons-sos',
                    80
                );

                // Rename default first submenu item from "SFLWA Support" to "Dashboard"
                add_submenu_page(
                    'sflwa-support-dashboard',
                    $this->t( 'Dashboard', $primary_domain ),
                    $this->t( 'Dashboard', $primary_domain ),
                    'manage_options',
                    'sflwa-support-dashboard',
                    array( $this, 'render_dashboard_page' )
                );

                $menu_registered = true;
            }

            // Register submenus for each registered plugin
            foreach ( $GLOBALS['sflwa_registered_plugins'] as $slug => $plugin ) {
                $submenu_label  = ! empty( $plugin['submenu_title'] ) ? $plugin['submenu_title'] : $plugin['plugin_name'];
                $page_hook_slug = 'sflwa-support-' . $slug;
                $domain         = ! empty( $plugin['text_domain'] ) ? $plugin['text_domain'] : $primary_domain;

                add_submenu_page(
                    'sflwa-support-dashboard',
                    $plugin['plugin_name'] . ' ' . $this->t( 'Support', $domain ),
                    $submenu_label,
                    'manage_options',
                    $page_hook_slug,
                    function() use ( $plugin ) {
                        $this->render_plugin_support_page( $plugin );
                    }
                );
            }
        }

        /**
         * Enqueue Styles and Scripts for SFLWA Support Pages
         *
         * @param string $hook_suffix Current admin page hook.
         */
        public function enqueue_admin_assets( $hook_suffix ) {
            if ( false === strpos( $hook_suffix, 'sflwa-support' ) ) {
                return;
            }

            wp_enqueue_style( 'dashicons' );
            wp_enqueue_script( 'jquery' );

            // Embedded CSS for layout and styling
            add_action( 'admin_head', array( $this, 'render_inline_admin_styles' ) );
        }

        /**
         * Inline Styles for Dashboard, Cards, Readme, and Registration Bar.
         */
        public function render_inline_admin_styles() {
            ?>
            <style>
                .sflwa-wrap { max-width: 1200px; margin: 20px 20px 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
                .sflwa-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 2px solid #2271b1; padding-bottom: 12px; }
                .sflwa-header h1 { margin: 0; font-size: 24px; color: #1d2327; }
                
                /* Privacy / Registration Notice Banner */
                .sflwa-privacy-notice { background: #f0f6fc; border: 1px solid #c8d7e1; border-left: 4px solid #2271b1; border-radius: 4px; padding: 12px 16px; margin-bottom: 25px; font-size: 13px; color: #1d2327; display: flex; align-items: center; gap: 10px; }
                .sflwa-privacy-notice .dashicons { color: #2271b1; font-size: 20px; width: 20px; height: 20px; }

                /* Cards Grid */
                .sflwa-cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; margin-bottom: 30px; }
                .sflwa-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
                .sflwa-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
                .sflwa-card-title { font-size: 16px; font-weight: 600; margin: 0; color: #1d2327; }
                .sflwa-card-version { background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 4px; padding: 2px 6px; font-size: 11px; font-weight: 600; color: #50575e; }
                .sflwa-card-desc { font-size: 13px; color: #646970; line-height: 1.5; margin-bottom: 15px; flex-grow: 1; }
                .sflwa-card-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; border-top: 1px solid #f0f0f1; padding-top: 15px; }

                /* Bio Card */
                .sflwa-bio-card { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; border-radius: 6px; padding: 25px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
                .sflwa-bio-card h2 { margin-top: 0; font-size: 18px; color: #1d2327; }
                .sflwa-bio-card p { font-size: 14px; line-height: 1.6; color: #3c434a; }

                /* Top Registration Bar */
                .sflwa-reg-bar { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #72a3f2; border-radius: 4px; padding: 12px 18px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; }
                .sflwa-reg-bar-text { font-size: 13px; color: #2c3338; margin: 0; display: flex; align-items: center; gap: 8px; }
                .sflwa-reg-bar-controls { display: flex; align-items: center; gap: 10px; }

                /* Status Badges */
                .sflwa-badge-registered { display: inline-flex; align-items: center; gap: 5px; background: #edfaef; border: 1px solid #00a32a; color: #007017; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
                
                /* Full Width Readme Area */
                .sflwa-readme-container { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 30px; line-height: 1.6; color: #2c3338; }
                .sflwa-readme-container h1, .sflwa-readme-container h2, .sflwa-readme-container h3 { border-bottom: 1px solid #dcdcde; padding-bottom: 8px; margin-top: 25px; color: #1d2327; }
                .sflwa-readme-container code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
                .sflwa-readme-container pre { background: #f0f0f1; padding: 15px; border-radius: 4px; overflow-x: auto; }
                .sflwa-readme-container ul { list-style-type: disc; padding-left: 20px; }
            </style>
            <?php
        }

        /**
         * Render Main SFLWA Dashboard Page (Plugin Cards Grid + Bio at Bottom)
         */
        public function render_dashboard_page() {
            $domain = $this->get_primary_text_domain();
            ?>
            <div class="wrap sflwa-wrap">
                <div class="sflwa-header">
                    <h1><?php echo esc_html( $this->t( 'South Florida Web Advisors — Plugin Support Dashboard', $domain ) ); ?></h1>
                </div>

                <!-- Registration Privacy Notice -->
                <div class="sflwa-privacy-notice">
                    <span class="dashicons dashicons-shield"></span>
                    <span><strong><?php echo esc_html( $this->t( 'Privacy Notice:', $domain ) ); ?></strong> <?php echo esc_html( $this->t( 'Registering a plugin is strictly optional and only transmits your website URL and plugin name to help track active usage.', $domain ) ); ?></span>
                </div>

                <!-- Installed Plugins Card Grid -->
                <div class="sflwa-cards-grid">
                    <?php 
                    if ( ! empty( $GLOBALS['sflwa_registered_plugins'] ) ) :
                        foreach ( $GLOBALS['sflwa_registered_plugins'] as $slug => $plugin ) :
                            $plugin_domain = ! empty( $plugin['text_domain'] ) ? $plugin['text_domain'] : $domain;
                            $is_registered = get_option( 'sflwa_registered_' . sanitize_title( $slug ), false );
                            $wp_forum_url  = $this->get_wp_org_forum_url( $slug );
                            $support_page  = admin_url( 'admin.php?page=sflwa-support-' . esc_attr( $slug ) );
                            ?>
                            <div class="sflwa-card">
                                <div>
                                    <div class="sflwa-card-header">
                                        <h2 class="sflwa-card-title"><?php echo esc_html( $plugin['plugin_name'] ); ?></h2>
                                        <span class="sflwa-card-version">v<?php echo esc_html( $plugin['version'] ); ?></span>
                                    </div>
                                    <p class="sflwa-card-desc"><?php echo esc_html( $plugin['description'] ); ?></p>
                                </div>

                                <div class="sflwa-card-actions">
                                    <!-- Registration Status / Action -->
                                    <div class="sflwa-status-wrapper" data-slug="<?php echo esc_attr( $slug ); ?>">
                                        <?php if ( $is_registered ) : ?>
                                            <span class="sflwa-badge-registered"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html( $this->t( 'Registered', $plugin_domain ) ); ?></span>
                                        <?php else : ?>
                                            <button type="button" class="button button-secondary sflwa-btn-register" data-slug="<?php echo esc_attr( $slug ); ?>" data-name="<?php echo esc_attr( $plugin['plugin_name'] ); ?>">
                                                <?php echo esc_html( $this->t( 'Register Site', $plugin_domain ) ); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Support Forum Button (Right next to registration status if on WP.org) -->
                                    <?php if ( $wp_forum_url ) : ?>
                                        <a href="<?php echo esc_url( $wp_forum_url ); ?>" target="_blank" class="button button-secondary">
                                            <span class="dashicons dashicons-external" style="margin-top: 3px;"></span> <?php echo esc_html( $this->t( 'WP Forum', $plugin_domain ) ); ?>
                                        </a>
                                    <?php endif; ?>

                                    <!-- View Readme Button -->
                                    <a href="<?php echo esc_url( $support_page ); ?>" class="button button-primary">
                                        <?php echo esc_html( $this->t( 'Support & Readme', $plugin_domain ) ); ?>
                                    </a>
                                </div>
                            </div>
                            <?php 
                        endforeach;
                    else :
                        echo '<p>' . esc_html( $this->t( 'No SFLWA plugins detected.', $domain ) ) . '</p>';
                    endif; 
                    ?>
                </div>

                <!-- Developer Bio Card at the Bottom -->
                <div class="sflwa-bio-card">
                    <h2><?php echo esc_html( $this->t( 'About the Developer', $domain ) ); ?></h2>
                    <p>
                        Hi, my name is <strong>Philip</strong>. Thank you so much for installing my plugin. I've been doing website design and development since 1997, and I have a deep passion for this work. I've been working with WordPress exclusively since 2012 and have been conducting plugin development with AI assistance since 2024.
                    </p>
                    <p>
                        I do not charge for my plugins as it is not the main core focus of my business. I simply want to give back to the community, make my life easier, and make other lives easier as well.
                    </p>
                    <p>
                        Please use the WordPress forum if you need support, or if you'd like to reach out to me directly, you can email me at: 
                        <a href="mailto:plugins@sflwa.net"><strong>plugins@sflwa.net</strong></a>.
                    </p>
                </div>
            </div>

            <?php $this->render_registration_javascript(); ?>
            <?php
        }

        /**
         * Render Individual Plugin Support Sub-Page
         *
         * @param array $plugin Plugin configuration array.
         */
        public function render_plugin_support_page( $plugin ) {
            $slug          = $plugin['plugin_slug'];
            $plugin_domain = ! empty( $plugin['text_domain'] ) ? $plugin['text_domain'] : $this->get_primary_text_domain();
            $is_registered = get_option( 'sflwa_registered_' . sanitize_title( $slug ), false );
            $wp_forum_url  = $this->get_wp_org_forum_url( $slug );
            $readme_path   = dirname( $plugin['plugin_file'] ) . '/readme.txt';
            ?>
            <div class="wrap sflwa-wrap">
                <div class="sflwa-header">
                    <h1><?php echo esc_html( $plugin['plugin_name'] ); ?> — <?php echo esc_html( $this->t( 'Support & Documentation', $plugin_domain ) ); ?></h1>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sflwa-support-dashboard' ) ); ?>" class="button button-secondary">
                        &larr; <?php echo esc_html( $this->t( 'Back to Dashboard', $plugin_domain ) ); ?>
                    </a>
                </div>

                <!-- Top Registration & Forum Bar -->
                <div class="sflwa-reg-bar">
                    <div class="sflwa-reg-bar-text">
                        <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                        <span><strong><?php echo esc_html( $this->t( 'Plugin Registration & Support Links', $plugin_domain ) ); ?>:</strong> <?php echo esc_html( $this->t( 'Let us know you use this plugin! Registering is optional and only sends your site URL.', $plugin_domain ) ); ?></span>
                    </div>

                    <div class="sflwa-reg-bar-controls">
                        <!-- Registration Status / Action -->
                        <div class="sflwa-status-wrapper" data-slug="<?php echo esc_attr( $slug ); ?>">
                            <?php if ( $is_registered ) : ?>
                                <span class="sflwa-badge-registered"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html( $this->t( 'Site Registered — Thank You!', $plugin_domain ) ); ?></span>
                            <?php else : ?>
                                <button type="button" class="button button-primary sflwa-btn-register" data-slug="<?php echo esc_attr( $slug ); ?>" data-name="<?php echo esc_attr( $plugin['plugin_name'] ); ?>">
                                    <?php echo esc_html( $this->t( 'Let Us Know You Use This Plugin!', $plugin_domain ) ); ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Forum Link Right Next to Registration -->
                        <?php if ( $wp_forum_url ) : ?>
                            <a href="<?php echo esc_url( $wp_forum_url ); ?>" target="_blank" class="button button-secondary">
                                <span class="dashicons dashicons-external" style="margin-top: 3px;"></span> <?php echo esc_html( $this->t( 'WordPress.org Forum', $plugin_domain ) ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Full Width Readme Documentation Container -->
                <div class="sflwa-readme-container">
                    <?php 
                    if ( file_exists( $readme_path ) ) {
                        $readme_content = file_get_contents( $readme_path );
                        echo wp_kses_post( $this->parse_markdown_to_html( $readme_content ) );
                    } else {
                        echo '<p><em>' . esc_html( $this->t( 'No readme.txt file was found for this plugin.', $plugin_domain ) ) . '</em></p>';
                    }
                    ?>
                </div>
            </div>

            <?php $this->render_registration_javascript(); ?>
            <?php
        }

        /**
         * Dynamic Check for WordPress.org Forum URL.
         * Caches query using a transient (12 hours) to avoid degrading admin performance.
         * Returns false if the plugin is self-hosted on GitHub or not on WP.org.
         *
         * @param string $slug Plugin slug.
         * @return string|false
         */
        private function get_wp_org_forum_url( $slug ) {
            $transient_key = 'sflwa_wp_org_exists_' . sanitize_title( $slug );
            $forum_url     = get_transient( $transient_key );

            if ( false !== $forum_url ) {
                return 'not_found' === $forum_url ? false : $forum_url;
            }

            // Include WordPress Plugin API functions if not loaded
            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }

            $api = plugins_api( 'plugin_information', array(
                'slug'   => $slug,
                'fields' => array( 'sections' => false ),
            ) );

            if ( is_wp_error( $api ) ) {
                set_transient( $transient_key, 'not_found', 12 * HOUR_IN_SECONDS );
                return false;
            }

            $constructed_url = 'https://wordpress.org/support/plugin/' . esc_attr( $slug ) . '/';
            set_transient( $transient_key, $constructed_url, 12 * HOUR_IN_SECONDS );

            return $constructed_url;
        }

        /**
         * Lightweight Native Markdown Parser for readme.txt
         *
         * @param string $markdown Markdown raw text.
         * @return string Parsed HTML.
         */
        private function parse_markdown_to_html( $markdown ) {
            // Clean up headers (=== Header === or ### Header)
            $markdown = preg_replace( '/^===\s*(.*?)\s*===/m', '<h1>$1</h1>', $markdown );
            $markdown = preg_replace( '/^==\s*(.*?)\s*==/m', '<h2>$1</h2>', $markdown );
            $markdown = preg_replace( '/^=\s*(.*?)\s*=/m', '<h3>$1</h3>', $markdown );
            $markdown = preg_replace( '/^###\s*(.*?)$/m', '<h3>$1</h3>', $markdown );
            $markdown = preg_replace( '/^##\s*(.*?)$/m', '<h2>$1</h2>', $markdown );
            $markdown = preg_replace( '/^#\s*(.*?)$/m', '<h1>$1</h1>', $markdown );

            // Bold & Italics
            $markdown = preg_replace( '/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $markdown );
            $markdown = preg_replace( '/\*(.*?)\*/s', '<em>$1</em>', $markdown );

            // Links [Anchor](URL)
            $markdown = preg_replace( '/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank">$1</a>', $markdown );

            // Lists
            $markdown = preg_replace( '/^\*\s*(.*?)$/m', '<li>$1</li>', $markdown );
            $markdown = preg_replace( '/^\-\s*(.*?)$/m', '<li>$1</li>', $markdown );
            $markdown = preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $markdown );

            // Paragraphs
            $markdown = nl2br( $markdown );

            return $markdown;
        }

        /**
         * Inline JS Handler for AJAX Site Registration
         */
        private function render_registration_javascript() {
            static $script_rendered = false;
            if ( $script_rendered ) return;
            $script_rendered = true;
            $domain          = $this->get_primary_text_domain();
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $(document).on('click', '.sflwa-btn-register', function(e) {
                        e.preventDefault();
                        var btn = $(this);
                        var slug = btn.data('slug');
                        var name = btn.data('name');
                        var wrapper = $('.sflwa-status-wrapper[data-slug="' + slug + '"]');

                        btn.prop('disabled', true).text('<?php echo esc_js( $this->t( 'Registering...', $domain ) ); ?>');

                        $.post(ajaxurl, {
                            action: 'sflwa_register_site_info',
                            plugin_slug: slug,
                            plugin_name: name,
                            website_url: '<?php echo esc_url( get_home_url() ); ?>',
                            _nonce: '<?php echo esc_attr( wp_create_nonce( 'sflwa_reg_nonce' ) ); ?>'
                        }, function(response) {
                            if (response.success) {
                                wrapper.html('<span class="sflwa-badge-registered"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_js( $this->t( 'Registered', $domain ) ); ?></span>');
                            } else {
                                btn.prop('disabled', false).text('<?php echo esc_js( $this->t( 'Retry Registration', $domain ) ); ?>');
                                alert('Registration failed. Please try again.');
                            }
                        });
                    });
                });
            </script>
            <?php
        }

        /**
         * AJAX Handler: Submits registration to remote proxy & stores local option
         */
        public function handle_ajax_site_registration() {
            check_ajax_referer( 'sflwa_reg_nonce', '_nonce' );

            $plugin_slug = isset( $_POST['plugin_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_slug'] ) ) : '';
            $plugin_name = isset( $_POST['plugin_name'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_name'] ) ) : '';
            $website_url = isset( $_POST['website_url'] ) ? esc_url_raw( wp_unslash( $_POST['website_url'] ) ) : get_home_url();

            $remote_endpoint = 'https://southfloridawebadvisors.com/wp-json/sflwa/v1/submit-plugin-info';

            $response = wp_remote_post( $remote_endpoint, array(
                'method'    => 'POST',
                'timeout'   => 15,
                'headers'   => array( 'Content-Type' => 'application/json' ),
                'body'      => json_encode( array(
                    'plugin_name' => $plugin_name,
                    'website_url' => $website_url,
                ) ),
                'sslverify' => false,
            ) );

            // Store local flag regardless of remote connectivity to ensure clean UI experience
            update_option( 'sflwa_registered_' . sanitize_title( $plugin_slug ), true );

            wp_send_json_success( 'Registration completed successfully.' );
        }
    }
}

// Initialize engine instance
SFLWA_Support_Handler_Engine::get_instance();