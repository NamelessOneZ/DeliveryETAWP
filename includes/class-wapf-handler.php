<?php
/**
 * WAPF Handler for Delivery ETA Plugin
 * Improved version based on debug data showing successful field detection
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_ETA_RS_WAPF_Handler {

    private $field_key;
    private $buffer_days;
    private $calculator;

    public function __construct() {
        $this->field_key = get_option('wceta_wapf_field_key', 'field_656c7a89bbca8');
        $this->buffer_days = (int) get_option('wceta_wapf_buffer_days', 3);

        add_action('init', array($this, 'init_hooks'));
    }

    public function init_hooks() {
        if (!$this->is_wapf_enabled()) {
            return;
        }

        // AJAX endpoints
        add_action('wp_ajax_wc_eta_update_wapf_date', array($this, 'ajax_update_wapf_date'));
        add_action('wp_ajax_nopriv_wc_eta_update_wapf_date', array($this, 'ajax_update_wapf_date'));
        add_action('wp_ajax_wc_eta_refresh_standard', array($this, 'ajax_refresh_standard'));
        add_action('wp_ajax_nopriv_wc_eta_refresh_standard', array($this, 'ajax_refresh_standard'));

        // WooCommerce hooks
        add_filter('woocommerce_add_cart_item_data', array($this, 'capture_wapf_date'), 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_wapf_to_order'), 10, 4);

        // Frontend scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_wapf_scripts'));

        error_log('WAPF Handler initialized with field key: ' . $this->field_key);
    }

    public function ajax_update_wapf_date() {
        check_ajax_referer('wc_eta_nonce', 'nonce');

        $wapf_date = sanitize_text_field($_POST['wapf_date'] ?? '');
        $product_id = (int) ($_POST['product_id'] ?? 0);

        error_log("WAPF ETA Update - Date: {$wapf_date}, Product: {$product_id}");

        if (!$wapf_date) {
            wp_send_json_error('No date provided');
        }

        try {
            $eta_data = $this->calculate_wapf_eta($wapf_date, $product_id);
            wp_send_json_success($eta_data);
        } catch (Exception $e) {
            error_log('WAPF ETA calculation error: ' . $e->getMessage());
            wp_send_json_error('ETA calculation failed: ' . $e->getMessage());
        }
    }

    public function ajax_refresh_standard() {
        check_ajax_referer('wc_eta_nonce', 'nonce');

        $product_id = (int) ($_POST['product_id'] ?? 0);

        try {
            // Get standard ETA without WAPF
            if (class_exists('WC_ETA_RS_Calculator')) {
                $calculator = new WC_ETA_RS_Calculator();
                $eta_data = $calculator->calculate_eta($product_id);

                if (class_exists('WC_ETA_RS_Renderer')) {
                    $renderer = new WC_ETA_RS_Renderer();
                    $html = $renderer->render_eta_block($eta_data);

                    wp_send_json_success(array('eta_html' => $html));
                }
            }
        } catch (Exception $e) {
            error_log('Standard ETA refresh error: ' . $e->getMessage());
        }

        wp_send_json_error('Failed to refresh standard ETA');
    }

    public function calculate_wapf_eta($target_date, $product_id = 0) {
        if (!class_exists('WC_ETA_RS_Calculator')) {
            throw new Exception('ETA Calculator class not found');
        }

        $calculator = new WC_ETA_RS_Calculator();

        // Parse target date
        $target_timestamp = strtotime($target_date);
        if (!$target_timestamp) {
            throw new Exception('Invalid date format: ' . $target_date);
        }

        $target_date_obj = new DateTime();
        $target_date_obj->setTimestamp($target_timestamp);

        // Get current time in Belgrade timezone
        $now = new DateTime('now', new DateTimeZone('Europe/Belgrade'));

        // Calculate required shipping date (target date minus buffer and shipping time)
        $required_shipping_date = clone $target_date_obj;
        $required_shipping_date->sub(new DateInterval('P' . $this->buffer_days . 'D'));

        // Get standard shipping days
        $shipping_days = $this->get_shipping_days($product_id);
        $required_shipping_date->sub(new DateInterval('P' . $shipping_days . 'D'));

        // Calculate working backwards from required shipping date
        $required_ship_date = $calculator->adjust_for_working_days($required_shipping_date, -1);

        // Determine risk level
        $days_until_ship = $now->diff($required_ship_date)->days;
        $risk_level = $this->calculate_risk_level(
            $days_until_ship,
            $now,
            $required_ship_date,
            $target_date_obj
        );

        // Generate ETA data
        $eta_data = array(
            'type' => 'wapf_target',
            'target_date' => $target_date_obj->format('Y-m-d'),
            'target_date_formatted' => $target_date_obj->format('d.m.Y'),
            'required_ship_date' => $required_ship_date->format('Y-m-d'),
            'required_ship_formatted' => $required_ship_date->format('d.m.Y'),
            'days_until_ship' => $days_until_ship,
            'risk_level' => $risk_level,
            'buffer_days' => $this->buffer_days,
            'shipping_days' => $shipping_days,
            'message' => $this->get_risk_message($risk_level),
            'css_class' => 'wapf-eta risk-' . $risk_level
        );

        // Render HTML
        if (class_exists('WC_ETA_RS_Renderer')) {
            $renderer = new WC_ETA_RS_Renderer();
            $eta_data['eta_html'] = $renderer->render_wapf_eta($eta_data);
        }

        error_log('WAPF ETA calculated: ' . print_r($eta_data, true));

        return $eta_data;
    }

    private function calculate_risk_level($days_until_ship, $now, $required_ship_date, $target_date_obj) {
        if ($required_ship_date < $now) {
            return 'cannot_guarantee'; // Već prekasno
        }

        $days_until_target = (int) $now->diff($target_date_obj)->format('%r%a');

        if ($days_until_target <= 1) {
            return 'cannot_guarantee'; // Datum je danas ili sutra - fizički neizvodljivo
        }

        if ($days_until_ship <= 1) {
            return 'tight'; // Veoma kratak rok
        }

        if ($days_until_ship <= 3) {
            return 'tight'; // Kratak rok
        }

        return 'ok'; // Dovoljno vremena
    }

    private function get_risk_message($risk_level) {
        $messages = get_option('wceta_wapf_messages', array());

        $defaults = array(
            'ok' => '🎯 Možemo garantovati dostavu na željeni datum!',
            'tight' => '⚠️ Kratak rok - možda kasni dan-dva, ali pokušaćemo!',
            'cannot_guarantee' => '🚫 Ne možemo garantovati dostavu - molimo odaberite kasniji datum.'
        );

        return $messages[$risk_level] ?? $defaults[$risk_level] ?? $defaults['ok'];
    }

    private function get_shipping_days($product_id = 0) {
        // Default shipping days - može se proširiti za specific products
        return 2; // Standardno 2 dana za slanje
    }

    public function capture_wapf_date($cart_item_data, $product_id, $variation_id) {
        if (!isset($_POST['wapf'])) {
            return $cart_item_data;
        }

        $wapf_data = $_POST['wapf'];

        if (isset($wapf_data[$this->field_key])) {
            $target_date = sanitize_text_field($wapf_data[$this->field_key]);

            // Convert dd.mm.yyyy. format to Y-m-d
            $converted_date = $this->convert_date_format($target_date);

            if ($converted_date) {
                $cart_item_data['wapf_target_date'] = $converted_date;
                $cart_item_data['wapf_target_date_display'] = $target_date;

                error_log("WAPF date captured: {$target_date} -> {$converted_date}");
            }
        }

        return $cart_item_data;
    }

    public function save_wapf_to_order($item, $cart_item_key, $values, $order) {
        if (isset($values['wapf_target_date'])) {
            $item->add_meta_data('_wapf_target_date', $values['wapf_target_date']);
            $item->add_meta_data('Željeni datum dostave', $values['wapf_target_date_display']);

            error_log("WAPF date saved to order: " . $values['wapf_target_date']);
        }
    }

    private function convert_date_format($date_str) {
        // Input: "30.09.2025." ili "02.10.2025."
        // Output: "2025-09-30"

        if (!$date_str) return false;

        // Ukloni tačke
        $date_str = trim($date_str, '. ');

        // Split po tačkama
        $parts = explode('.', $date_str);

        if (count($parts) === 3) {
            $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
            $year = $parts[2];

            if (checkdate($month, $day, $year)) {
                return $year . '-' . $month . '-' . $day;
            }
        }

        return false;
    }

    public function enqueue_wapf_scripts() {
        if (!is_product()) {
            return;
        }

        wp_enqueue_script(
            'wc-eta-rs-wapf',
            plugin_dir_url(__DIR__) . 'assets/js/wapf-integration.js',
            array('jquery'),
            '1.1.0',
            true
        );

        wp_localize_script('wc-eta-rs-wapf', 'WC_ETA_WAPF', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_eta_nonce'),
            'field_key' => $this->field_key,
            'buffer_days' => $this->buffer_days
        ));
    }

    private function is_wapf_enabled() {
        return get_option('wceta_enable_wapf', 'no') === 'yes';
    }
}
