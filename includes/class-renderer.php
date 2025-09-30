<?php
/**
 * Enhanced Renderer with WAPF support
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_ETA_RS_Renderer {

    private $calculator;

    public function __construct() {
        $this->calculator = new WC_ETA_RS_Calculator();
    }

    public function display_eta_on_product() {
        if (!is_product()) {
            return;
        }

        global $product;
        if (!$product || !$product->is_purchasable()) {
            return;
        }

        $eta_data = $this->calculator->calculate_eta($product->get_id());
        if ($eta_data) {
            echo $this->render_eta_block($eta_data);
        }
    }

    public function display_eta_on_checkout() {
        if (!is_checkout()) {
            return;
        }

        $cart_eta = $this->calculate_cart_eta();
        if ($cart_eta) {
            echo $this->render_eta_block($cart_eta, 'checkout');
        }
    }

    public function render_eta_block($eta_data, $context = 'product') {
        $css_class = 'wc-eta-rs-container';
        $css_class .= ' context-' . $context;

        if (isset($eta_data['css_class'])) {
            $css_class .= ' ' . $eta_data['css_class'];
        }

        $html = '<div class="' . esc_attr($css_class) . '">';
        $html .= $this->render_eta_content($eta_data);
        $html .= '</div>';

        return $html;
    }

    public function render_wapf_eta($eta_data) {
        $css_class = 'wc-eta-rs-container wapf-eta risk-' . ($eta_data['risk_level'] ?? 'ok');

        $html = '<div class="' . esc_attr($css_class) . '">';
        $html .= '<div class="eta-header">';
        $html .= '<span class="eta-icon">🎯</span>';
        $html .= '<span class="eta-title">Dostava do željenog datuma</span>';
        $html .= '</div>';

        $html .= '<div class="eta-date-info">';
        $html .= '<div class="target-date">';
        $html .= '<strong>' . esc_html($eta_data['target_date_formatted']) . '</strong>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="eta-message ' . esc_attr($eta_data['risk_level']) . '">';
        $html .= esc_html($eta_data['message']);
        $html .= '</div>';

        if (isset($eta_data['required_ship_formatted'])) {
            $html .= '<div class="eta-details">';
            $html .= '<small>Potrebno slanje do: ' . esc_html($eta_data['required_ship_formatted']) . '</small>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private function render_eta_content($eta_data) {
        $html = '<div class="eta-header">';
        $html .= '<span class="eta-icon">📦</span>';
        $html .= '<span class="eta-title">Procenjeno vreme dostave</span>';
        $html .= '</div>';

        if (isset($eta_data['ship_date']) && isset($eta_data['delivery_date'])) {
            $html .= '<div class="eta-dates">';
            $html .= '<div class="ship-date">';
            $html .= '<span class="date-label">Slanje:</span> ';
            $html .= '<span class="date-value">' . esc_html($eta_data['ship_date_formatted']) . '</span>';
            $html .= '</div>';
            $html .= '<div class="delivery-date">';
            $html .= '<span class="date-label">Dostava:</span> ';
            $html .= '<span class="date-value">' . esc_html($eta_data['delivery_date_formatted']) . '</span>';
            $html .= '</div>';
            $html .= '</div>';
        }

        if (get_option('wceta_enable_animations', 'yes') === 'yes') {
            $html .= '<div class="eta-animation">';
            $html .= '<div class="truck-container">';
            $html .= '<div class="truck">🚛</div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        return $html;
    }

    private function calculate_cart_eta() {
        if (!WC()->cart) {
            return false;
        }

        $latest_eta = null;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];

            // Check for WAPF target date
            if (isset($cart_item['wapf_target_date'])) {
                // This item has a WAPF target date, handle differently
                continue;
            }

            $eta = $this->calculator->calculate_eta($product_id);
            if ($eta && isset($eta['delivery_date'])) {
                if (!$latest_eta || $eta['delivery_date'] > $latest_eta['delivery_date']) {
                    $latest_eta = $eta;
                }
            }
        }

        return $latest_eta;
    }
}
