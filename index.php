<?php

/*
 *
 * Plugin Name: MyGate WP
 * Plugin URI: https://mygate.me/
 * Description: Decentralized cryptocurrency payment gateway for WooCommerce and Easy Digital Downloads
 * Version: 1.0.0
 * Author: MyGate 
 * Author URI: https://mygate.me/docs/#wordpress
 * © 2024-2025 mygate.me. All rights reserved.
 *
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    function mgt_declare_cart_checkout_blocks_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }

    function mgt_wc_add_to_gateways($gateways) {
        $gateways[] = 'WC_MyGate';
        return $gateways;
    }

    function mgt_wc_plugin_links($links) {
        return array_merge(['<a href="' . admin_url('options-general.php?page=mygate-wp') . '">Settings</a>'], $links);
    }

    function mgt_checkout_block_support() {
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType') && file_exists(__DIR__ . '/config.php')) {

            final class WC_MyGate_Blocks extends AbstractPaymentMethodType {

                private $gateway;
                protected $name = 'mygate';

                public function initialize() {
                    $this->settings = get_option('woocommerce_mygate_settings', []);
                    $this->gateway = new WC_MyGate();
                }

                public function is_active() {
                    return $this->gateway->is_available();
                }

                public function get_payment_method_script_handles() {
                    wp_register_script('mygate-blocks-integration', plugin_dir_url(__FILE__) . '/assets/checkout.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'], null, true);
                    if (function_exists('wp_set_script_translations')) {
                        wp_set_script_translations('mygate-blocks-integration');
                    }
                    return ['mygate-blocks-integration'];
                }

                public function get_payment_method_data() {
                    return ['title' => $this->gateway->title, 'description' => $this->gateway->description];
                }
            }

            add_action('woocommerce_blocks_payment_method_type_registration', function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_MyGate_Blocks);
            });
        }
    }

    function mgt_wc_init() {
        class WC_MyGate extends WC_Payment_Gateway {
            public function __construct() {
                $settings = mgt_get_wp_settings();
                $this->id = 'mygate';
                $this->has_fields = false;
                $this->method_title = 'MyGate';
                $this->method_description = 'Decentralized cryptocurrency payment gateway for WooCommerce and Easy Digital Downloads';
                $this->title = __(mgt_isset($settings, 'mygate-payment-option-name', 'Pay with cryptocurrency'), 'mygate');
                $this->description = __(mgt_isset($settings, 'mygate-payment-option-text', 'Pay via Bitcoin, Ethereum and other cryptocurrencies.'), 'mygate');
                $this->init_form_fields();
                $this->init_settings();
                $icon = mgt_isset($settings, 'mygate-payment-option-icon');
                if ($icon) {
                    $this->icon = $icon;
                }
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            }

            public function process_payment($order_id) {
                $settings = mgt_get_wp_settings();
                $order = wc_get_order($order_id);
                $order->update_status('pending');
                wc_reduce_stock_levels($order_id);
                return ['result' => 'success', 'redirect' => 'https://app.mygate.me/pay.php?checkout_id=custom-wc-' . $order_id . '&price=' . $order->get_total() . '&currency=' . strtolower($order->get_currency()) . '&external_reference=' . mgt_wp_encryption($order_id . '|' . $this->get_return_url($order) . '|woo') . '&plugin=woocommerce&redirect=' . urlencode($this->get_return_url($order)) . '&cloud=' . mgt_isset($settings, 'mygate-wp-key') . '&plugin=woocommerce&note=' . urlencode('WooCommerce order ID ' . $order_id)];
            }

            public function init_form_fields() {
                $this->form_fields = apply_filters('wc_offline_form_fields', ['enabled' => ['title' => __('Enable/Disable', 'mygate'), 'type' => 'checkbox', 'label' => __('Enable MyGate', 'mygate'), 'default' => 'yes']]);
            }
        }
    }
    add_filter('woocommerce_payment_gateways', 'mgt_wc_add_to_gateways');
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'mgt_wc_plugin_links');
    add_action('woocommerce_blocks_loaded', 'mgt_checkout_block_support');
    add_action('plugins_loaded', 'mgt_wc_init', 11);
    add_action('before_woocommerce_init', 'mgt_declare_cart_checkout_blocks_compatibility');
}

function mgt_edd_register_gateway($gateways) {
    $settings = mgt_get_wp_settings();
    $gateways['mygate'] = ['admin_label' => 'MyGate', 'checkout_label' => __(mgt_isset($settings, 'mygate-payment-option-name', 'Pay with cryptocurrency'), 'mygate')];
    return $gateways;
}

function mgt_edd_process_payment($data) {
    if (!edd_get_errors()) {
        $settings = mgt_get_wp_settings();
        $payment_id = edd_insert_payment($data);
        $url = 'checkout_id=custom-edd-' . $payment_id . '&price=' . $data['price'] . '&currency=' . strtolower(edd_get_currency()) . '&external_reference=' . mgt_wp_encryption('edd|' . $payment_id) . '&redirect=' . urlencode(edd_get_success_page_uri()) . '&cloud=' . mgt_isset($settings, 'mygate-wp-key') . '&note=' . urlencode('Easy Digital Download payment ID ' . $payment_id);
        edd_send_back_to_checkout($url);
    }
}

function mgt_wp_on_load() {
    if (function_exists('edd_is_checkout') && edd_is_checkout()) {
        echo '<script>var mgt_href = document.location.href; if (mgt_href.includes("custom-edd-")) { document.location = "https://app.mygate.me/pay.php" + mgt_href.substring(mgt_href.indexOf("?")); }</script>';
    }
}

function mgt_edd_disable_gateway_cc_form() {
    return;
}

function mgt_set_admin_menu() {
    add_submenu_page('options-general.php', 'MyGate', 'MyGate', 'administrator', 'mygate-wp', 'mgt_admin');
}

function mgt_enqueue_admin() {
    if (key_exists('page', $_GET) && $_GET['page'] == 'mygate-wp') {
        wp_enqueue_style('mgt-wp-admin-css', plugin_dir_url(__FILE__) . '/assets/style.css', [], '1.0', 'all');
    }
}

function mgt_wp_encryption($string, $encrypt = true) {
    $settings = mgt_get_wp_settings();
    $output = false;
    $encrypt_method = 'AES-256-CBC';
    $secret_key = mgt_isset($settings, 'mygate-key');
    $key = hash('sha256', $secret_key);
    $iv = substr(hash('sha256', mgt_isset($settings, 'mygate-wp-key')), 0, 16);
    if ($encrypt) {
        $output = openssl_encrypt(is_string($string) ? $string : json_encode($string, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE), $encrypt_method, $key, 0, $iv);
        $output = base64_encode($output);
        if (substr($output, -1) == '=')
            $output = substr($output, 0, -1);
    } else {
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    }
    return $output;
}

function mgt_get_wp_settings() {
    return json_decode(get_option('mgt-wp-settings'), true);
}

function mgt_isset($array, $key, $default = '') {
    return !empty($array) && isset($array[$key]) && $array[$key] !== '' ? $array[$key] : $default;
}

function mgt_admin() {
    if (isset($_POST['mgt_submit'])) {
        if (!isset($_POST['mgt_nonce']) || !wp_verify_nonce($_POST['mgt_nonce'], 'mgt-nonce'))
            die('nonce-check-failed');
        $settings = [
            'mygate-key' => sanitize_text_field($_POST['mygate-key']),
            'mygate-wp-key' => sanitize_text_field($_POST['mygate-wp-key']),
            'mygate-payment-option-name' => sanitize_text_field($_POST['mygate-payment-option-name']),
            'mygate-payment-option-text' => sanitize_text_field($_POST['mygate-payment-option-text']),
            'mygate-payment-option-icon' => sanitize_text_field($_POST['mygate-payment-option-icon'])
        ];
        update_option('mgt-wp-settings', json_encode($settings));
    }
    $settings = mgt_get_wp_settings();
    ?>
    <form method="post" action="">
        <div class="wrap">
            <h1>MyGate</h1>
            <div class="postbox-container">
                <table class="form-table mgt-table">
                    <tbody>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Webhook secret key</label>
                            </th>
                            <td>
                                <input type="password" id="mygate-key" name="mygate-key" value="<?php echo esc_html(mgt_isset($settings, 'mygate-key')) ?>" />
                                <br />
                                <p class="description">Enter the MyGate webhook secret key. Get it from MyGate > Settings > Webhook > Webhook secret key.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Webhook URL</label>
                            </th>
                            <td>
                                <input type="text" readonly value="<?php echo site_url() ?>/wp-json/mygate/webhook" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">API key</label>
                            </th>
                            <td>
                                <input type="password" id="mygate-wp-key" name="mygate-wp-key" value="<?php echo esc_html(mgt_isset($settings, 'mygate-wp-key')) ?>" />
                                <br />
                                <p class="description">Enter the MyGate API key. Get it from MyGate > Account > API key.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Payment option name</label>
                            </th>
                            <td>
                                <input type="text" id="mygate-payment-option-name" name="mygate-payment-option-name" value="<?php echo esc_html(mgt_isset($settings, 'mygate-payment-option-name')) ?>" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Payment option description</label>
                            </th>
                            <td>
                                <input type="text" id="mygate-payment-option-text" name="mygate-payment-option-text" value="<?php echo esc_html(mgt_isset($settings, 'mygate-payment-option-text')) ?>" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Payment option icon URL</label>
                            </th>
                            <td>
                                <input type="text" id="mygate-payment-option-icon" name="mygate-payment-option-icon" value="<?php echo esc_html(mgt_isset($settings, 'mygate-payment-option-icon')) ?>" />
                            </td>
                        </tr>              
                    </tbody>
                </table>
                <p class="submit">
                    <input type="hidden" name="mgt_nonce" id="mgt_nonce" value="<?php echo wp_create_nonce('mgt-nonce') ?>" />
                    <input type="submit" class="button-primary" name="mgt_submit" value="Save changes" />
                </p>
            </div>
        </div>
    </form>
<?php }

function mgt_wp_webhook_callback($request) {
    $response = json_decode(file_get_contents('php://input'), true);
    if (!isset($response['key'])) {
        return;
    }
    $settings = mgt_get_wp_settings();
    if ($response['key'] !== $settings['mygate-key']) {
        return 'Invalid Webhook Key';
    }
    $transaction = mgt_isset($response, 'transaction');
    if ($transaction) {
        $external_reference = explode('|', mgt_wp_encryption($transaction['external_reference'], false));
        $text = 'MyGate transaction ID: ' . $transaction['id'];
        if (in_array('woo', $external_reference)) {
            $order = wc_get_order($external_reference[0]);
            $amount_fiat = $transaction['amount_fiat'];
            if (($amount_fiat && floatval($amount_fiat) < floatval($order->get_total())) || (strtoupper($transaction['currency']) != strtoupper($order->get_currency()))) {
                return 'Invalid amount or currency';
            }
            if ($order->get_status() == 'pending') {
                $products = $order->get_items();
                $is_virtual = true;
                foreach ($products as $product) {
                    $product = wc_get_product($product->get_data()['product_id']);
                    if (!$product->is_virtual() && !$product->is_downloadable()) {
                        $is_virtual = false;
                        break;
                    }
                }
                if ($is_virtual) {
                    $order->payment_complete();
                } else {
                    $order->update_status('processing');
                }
                $order->add_order_note($text);
                return 'success';
            }
        } else if (in_array('edd', $external_reference)) {
            edd_update_payment_status($external_reference[0], 'complete');
            edd_insert_payment_note($external_reference[0], $text);
            return 'success';
        }
        return 'Invalid order status';
    }
    return 'Transaction not found';
}

function mgt_wp_on_user_logout($user_id) {
    if (!headers_sent()) {
        setcookie('BXC_LOGIN', '', time() - 3600);
    }
    return $user_id;
}

add_action('admin_menu', 'mgt_set_admin_menu');
add_action('network_admin_menu', 'mgt_set_admin_menu');
add_action('admin_enqueue_scripts', 'mgt_enqueue_admin');
add_action('edd_gateway_mygate', 'mgt_edd_process_payment');
add_action('edd_mygate_cc_form', 'mgt_edd_disable_gateway_cc_form');
add_filter('edd_payment_gateways', 'mgt_edd_register_gateway');
add_action('wp_logout', 'mgt_wp_on_user_logout');
add_action('wp_head', 'mgt_wp_on_load');
add_action('rest_api_init', function () {
    register_rest_route('mygate', '/webhook', [
        'methods' => 'POST',
        'callback' => 'mgt_wp_webhook_callback',
        'permission_callback' => '__return_true',
        'args' => [
            'id' => [
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param);
                }
            ]
        ]
    ]);
});

?>