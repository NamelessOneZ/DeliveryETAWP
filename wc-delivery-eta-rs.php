<?php
/**
 * Plugin Name: WC Delivery ETA RS
 * Plugin URI: https://yoursite.com
 * Description: Dynamic delivery estimates for Serbian WooCommerce stores with WAPF integration
 * Version: 1.2.0
 * Author: Your Name
 * Text Domain: wc-delivery-eta-rs
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WC_ETA_RS_VERSION', '1.2.0');
define('WC_ETA_RS_PLUGIN_FILE', __FILE__);
define('WC_ETA_RS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_ETA_RS_PLUGIN_URL', plugin_dir_url(__FILE__));

class WC_Delivery_ETA_RS {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
    }

    public function init() {
        // Check dependencies
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Load text domain
        load_plugin_textdomain('wc-delivery-eta-rs', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Include required files
        $this->includes();

        // Initialize classes
        $this->init_classes();

        // Hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

        // WAPF Integration
        if (get_option('wceta_enable_wapf', 'no') === 'yes') {
            new WC_ETA_RS_WAPF_Handler();
        }
    }

    public function includes() {
        include_once WC_ETA_RS_PLUGIN_PATH . 'includes/class-settings.php';
        include_once WC_ETA_RS_PLUGIN_PATH . 'includes/class-calculator.php';
        include_once WC_ETA_RS_PLUGIN_PATH . 'includes/class-renderer.php';
        include_once WC_ETA_RS_PLUGIN_PATH . 'includes/class-product-meta.php';
        include_once WC_ETA_RS_PLUGIN_PATH . 'includes/class-country-method-matrix.php';

        // WAPF Handler - load only if enabled
        if (get_option('wceta_enable_wapf', 'no') === 'yes') {
            include_once WC_ETA_RS_PLUGIN_PATH . 'includes/class-wapf-handler.php';
        }
    }

    public function init_classes() {
        new WC_ETA_RS_Settings();
        new WC_ETA_RS_Product_Meta();
        new WC_ETA_RS_Country_Method_Matrix();

        // Initialize renderer for hooks
        $renderer = new WC_ETA_RS_Renderer();
        add_action('woocommerce_single_product_summary', array($renderer, 'display_eta_on_product'), 25);
        add_action('woocommerce_review_order_after_shipping', array($renderer, 'display_eta_on_checkout'));
    }

    public function enqueue_scripts() {
        if (is_product() || is_checkout()) {
            wp_enqueue_style(
                'wc-eta-rs-style',
                WC_ETA_RS_PLUGIN_URL . 'assets/css/eta.css',
                array(),
                WC_ETA_RS_VERSION
            );

            wp_enqueue_script(
                'wc-eta-rs-script',
                WC_ETA_RS_PLUGIN_URL . 'assets/js/eta.js',
                array('jquery'),
                WC_ETA_RS_VERSION,
                true
            );

            // WAPF Integration script - samo ako je enabled
            if (get_option('wceta_enable_wapf', 'no') === 'yes') {
                wp_enqueue_script(
                    'wc-eta-rs-wapf',
                    WC_ETA_RS_PLUGIN_URL . 'assets/js/wapf-integration.js',
                    array('jquery'),
                    WC_ETA_RS_VERSION,
                    true
                );

                wp_localize_script('wc-eta-rs-wapf', 'WC_ETA_WAPF', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wc_eta_nonce'),
                    'field_key' => get_option('wceta_wapf_field_key', 'field_656c7a89bbca8'),
                    'buffer_days' => get_option('wceta_wapf_buffer_days', 3)
                ));
            }

            wp_localize_script('wc-eta-rs-script', 'WC_ETA_RS', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_eta_nonce'),
                'is_product' => is_product(),
                'is_checkout' => is_checkout()
            ));
        }
    }

    public function admin_enqueue_scripts($hook) {
        if ('woocommerce_page_wc-delivery-eta-rs' === $hook) {
            wp_enqueue_style(
                'wc-eta-rs-admin-style',
                WC_ETA_RS_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                WC_ETA_RS_VERSION
            );
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="error notice"><p>';
        echo esc_html__('WC Delivery ETA RS requires WooCommerce to be installed and active.', 'wc-delivery-eta-rs');
        echo '</p></div>';
    }
}

// Initialize plugin
WC_Delivery_ETA_RS::get_instance();

// Activation hook
register_activation_hook(__FILE__, array('WC_Delivery_ETA_RS', 'activate'));

class WC_ETA_RS_Activator {
    public static function activate() {
        // Set default options
        $defaults = array(
            'wceta_working_days' => array('monday', 'tuesday', 'wednesday', 'thursday', 'friday'),
            'wceta_cut_off_time' => '14:00',
            'wceta_timezone' => 'Europe/Belgrade',
            'wceta_enable_animations' => 'yes',
            'wceta_enable_wapf' => 'no', // WAPF disabled by default
            'wceta_wapf_field_key' => 'field_656c7a89bbca8',
            'wceta_wapf_buffer_days' => '3'
        );

        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                update_option($option, $value);
            }
        }

        // Create default country-method matrix
        if (get_option('wceta_country_method_matrix') === false) {
            $default_matrix = array(
                'RS' => array(
                    'local_pickup' => 1,
                    'flat_rate' => 2,
                    'free_shipping' => 3
                ),
                'default' => array(
                    'local_pickup' => 2,
                    'flat_rate' => 3,
                    'free_shipping' => 5
                )
            );
            update_option('wceta_country_method_matrix', $default_matrix);
        }

        flush_rewrite_rules();
    }
}
