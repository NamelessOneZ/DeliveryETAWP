<?php
/**
 * Settings page with WAPF integration options
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_ETA_RS_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('Delivery ETA Settings', 'wc-delivery-eta-rs'),
            __('Delivery ETA', 'wc-delivery-eta-rs'),
            'manage_woocommerce',
            'wc-delivery-eta-rs',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('wceta_settings', 'wceta_working_days');
        register_setting('wceta_settings', 'wceta_cut_off_time');
        register_setting('wceta_settings', 'wceta_timezone');
        register_setting('wceta_settings', 'wceta_enable_animations');

        // WAPF settings
        register_setting('wceta_settings', 'wceta_enable_wapf');
        register_setting('wceta_settings', 'wceta_wapf_field_key');
        register_setting('wceta_settings', 'wceta_wapf_buffer_days');
        register_setting('wceta_settings', 'wceta_wapf_messages');
    }

    public function settings_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }

        $this->render_settings_page();
    }

    private function save_settings() {
        check_admin_referer('wceta_settings_nonce');

        // Basic settings
        update_option('wceta_working_days', $_POST['wceta_working_days'] ?? array());
        update_option('wceta_cut_off_time', sanitize_text_field($_POST['wceta_cut_off_time'] ?? '14:00'));
        update_option('wceta_timezone', sanitize_text_field($_POST['wceta_timezone'] ?? 'Europe/Belgrade'));
        update_option('wceta_enable_animations', sanitize_text_field($_POST['wceta_enable_animations'] ?? 'no'));

        // WAPF settings
        update_option('wceta_enable_wapf', sanitize_text_field($_POST['wceta_enable_wapf'] ?? 'no'));
        update_option('wceta_wapf_field_key', sanitize_text_field($_POST['wceta_wapf_field_key'] ?? 'field_656c7a89bbca8'));
        update_option('wceta_wapf_buffer_days', (int) ($_POST['wceta_wapf_buffer_days'] ?? 3));

        // WAPF messages
        $messages = array(
            'ok' => sanitize_text_field($_POST['wceta_wapf_message_ok'] ?? ''),
            'tight' => sanitize_text_field($_POST['wceta_wapf_message_tight'] ?? ''),
            'cannot_guarantee' => sanitize_text_field($_POST['wceta_wapf_message_cannot'] ?? '')
        );
        update_option('wceta_wapf_messages', $messages);

        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }

    private function render_settings_page() {
        $working_days = get_option('wceta_working_days', array('monday', 'tuesday', 'wednesday', 'thursday', 'friday'));
        $cut_off_time = get_option('wceta_cut_off_time', '14:00');
        $timezone = get_option('wceta_timezone', 'Europe/Belgrade');
        $enable_animations = get_option('wceta_enable_animations', 'yes');

        // WAPF settings
        $enable_wapf = get_option('wceta_enable_wapf', 'no');
        $wapf_field_key = get_option('wceta_wapf_field_key', 'field_656c7a89bbca8');
        $wapf_buffer_days = get_option('wceta_wapf_buffer_days', 3);
        $wapf_messages = get_option('wceta_wapf_messages', array());

        ?>
        <div class="wrap">
            <h1><?php _e('Delivery ETA Settings', 'wc-delivery-eta-rs'); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field('wceta_settings_nonce'); ?>

                <table class="form-table">
                    <!-- Basic Settings -->
                    <tr>
                        <th scope="row"><?php _e('Working Days', 'wc-delivery-eta-rs'); ?></th>
                        <td>
                            <?php 
                            $days = array(
                                'monday' => 'Monday',
                                'tuesday' => 'Tuesday', 
                                'wednesday' => 'Wednesday',
                                'thursday' => 'Thursday',
                                'friday' => 'Friday',
                                'saturday' => 'Saturday',
                                'sunday' => 'Sunday'
                            );
                            foreach ($days as $key => $label): ?>
                                <label>
                                    <input type="checkbox" name="wceta_working_days[]" value="<?php echo $key; ?>" 
                                           <?php checked(in_array($key, $working_days)); ?> />
                                    <?php echo $label; ?>
                                </label><br/>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('Cut-off Time', 'wc-delivery-eta-rs'); ?></th>
                        <td>
                            <input type="time" name="wceta_cut_off_time" value="<?php echo esc_attr($cut_off_time); ?>" />
                            <p class="description">Orders after this time ship next working day</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('Enable Animations', 'wc-delivery-eta-rs'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wceta_enable_animations" value="yes" 
                                       <?php checked($enable_animations, 'yes'); ?> />
                                Show truck animation
                            </label>
                        </td>
                    </tr>
                </table>

                <h2>🎯 WAPF Integration</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable WAPF Integration</th>
                        <td>
                            <label>
                                <input type="checkbox" name="wceta_enable_wapf" value="yes" 
                                       <?php checked($enable_wapf, 'yes'); ?> />
                                Enable Advanced Product Fields integration for target dates
                            </label>
                            <p class="description">Requires "Advanced Product Fields for WooCommerce" plugin</p>
                        </td>
                    </tr>

                    <tr class="wapf-setting">
                        <th scope="row">WAPF Field Key</th>
                        <td>
                            <input type="text" name="wceta_wapf_field_key" value="<?php echo esc_attr($wapf_field_key); ?>" 
                                   class="regular-text" placeholder="field_656c7a89bbca8" />
                            <p class="description">Field key from WAPF date field (usually starts with "field_")</p>
                        </td>
                    </tr>

                    <tr class="wapf-setting">
                        <th scope="row">Buffer Days</th>
                        <td>
                            <input type="number" name="wceta_wapf_buffer_days" value="<?php echo esc_attr($wapf_buffer_days); ?>" 
                                   min="1" max="10" />
                            <p class="description">Days before target date to allow for preparation and shipping</p>
                        </td>
                    </tr>

                    <tr class="wapf-setting">
                        <th scope="row">Success Message</th>
                        <td>
                            <input type="text" name="wceta_wapf_message_ok" 
                                   value="<?php echo esc_attr($wapf_messages['ok'] ?? '🎯 Možemo garantovati dostavu na željeni datum!'); ?>" 
                                   class="large-text" />
                        </td>
                    </tr>

                    <tr class="wapf-setting">
                        <th scope="row">Tight Schedule Message</th>
                        <td>
                            <input type="text" name="wceta_wapf_message_tight" 
                                   value="<?php echo esc_attr($wapf_messages['tight'] ?? '⚠️ Kratak rok - možda kasni dan-dva, ali pokušaćemo!'); ?>" 
                                   class="large-text" />
                        </td>
                    </tr>

                    <tr class="wapf-setting">
                        <th scope="row">Cannot Guarantee Message</th>
                        <td>
                            <input type="text" name="wceta_wapf_message_cannot" 
                                   value="<?php echo esc_attr($wapf_messages['cannot_guarantee'] ?? '🚫 Ne možemo garantovati dostavu - molimo odaberite kasniji datum.'); ?>" 
                                   class="large-text" />
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>

        <style>
            .wapf-setting { display: none; }
            input[name="wceta_enable_wapf"]:checked ~ * .wapf-setting { display: table-row; }
        </style>

        <script>
            jQuery(document).ready(function($) {
                function toggleWAPFSettings() {
                    if ($('input[name="wceta_enable_wapf"]').is(':checked')) {
                        $('.wapf-setting').show();
                    } else {
                        $('.wapf-setting').hide();
                    }
                }

                $('input[name="wceta_enable_wapf"]').on('change', toggleWAPFSettings);
                toggleWAPFSettings();
            });
        </script>
        <?php
    }
}
