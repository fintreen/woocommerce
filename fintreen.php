<?php
/*
 * Plugin Name: Fintreen Payment Gateway
 * Description: Fintreen payment gateway for WooCommerce.
 * Author: Fintreen
 * Version: 1.0
 */

function fintreen_create_table() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'fintreen_transactions';

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fintreen_id BIGINT UNSIGNED NOT NULL,
       	order_id INT UNSIGNED NULL,
        fiat_amount DECIMAL(35, 2) UNSIGNED NOT NULL,
        fintreen_fiat_code VARCHAR(3) DEFAULT 'EUR',
        crypto_amount DECIMAL(35, 12) UNSIGNED NOT NULL,
        fintreen_crypto_code VARCHAR(10),
        fintreen_status_id SMALLINT UNSIGNED DEFAULT 1,
        is_test SMALLINT UNSIGNED DEFAULT 0,
        link TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY fintreen_id_is_test_unique (fintreen_id, is_test)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE={$charset_collate};";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Вызов функции при активации плагина
register_activation_hook(__FILE__, 'fintreen_create_table');

add_action('plugins_loaded', 'init_fintreen_gateway');

function init_fintreen_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Fintreen_Payment_Gateway extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'fintreen';
            $this->has_fields = false;
            $this->method_title = 'Fintreen Crypto Payments';
            $this->method_description = 'Crypto Payments with Fintreen';
            $this->supports = array(
                'products'
            );
            $this->init_form_fields();
            $this->init_settings();

            $this->api_token = $this->get_option('api_token');
            $this->email = $this->get_option('email');
            $this->min_sum = $this->get_option('min_sum');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            //add_action( 'woocommerce_api_{webhook}', array( $this, 'fintreen' ) );
        }

        public function fintreen() 
        {
        	global $wp;

        	$request_data = file_get_contents('php://input');
        	$request_data = json_decode($request_data, true);

        	$id = $request_data['transaction_id'];

        	global $wpdb;
$order_id_query = $wpdb->prepare(
    "SELECT order_id FROM {$wpdb->prefix}fintreen_transactions WHERE fintreen_id = %d",
    $id
);
$order_id = $wpdb->get_var($order_id_query);

if ($order_id) {
    // Получаем объект заказа по ID
    $order = wc_get_order($order_id);

    if ($order) {

    	$url = 'https://fintreen.com/api/v1/check';
			$data = array(
    			'orderId' => $id,
			);

			$headers = array(
  	  			'Content-Type' => 'application/json',
    			'Accept' => 'application/json',
    			'fintreen_auth' => $this->get_option('api_token'),
    			'fintreen_signature' => sha1($this->get_option('api_token').$this->get_option('email'))
			);

			$args = array(
    			'body' => $data,
    			'headers' => $headers,
			);

			$response = wp_remote_post($url, $args);
			$data = json_decode($response);

			if($data['data']['statusId'] == 3) {
				$new_state = 'completed'; // Используйте статус, который соответствует вашим требованиям
				$order->update_status($new_state);
				$order->save();
			}
        
    }
}
        }

        public function init_form_fields() {
            $this->form_fields = array(
    'api_token' => array(
        'title' => __('API Token', 'woocommerce'),
        'type' => 'text',
        'label' => __('API Token Fintreen', 'woocommerce'),
    ),
    'email' => array(
        'title' => __('Email', 'woocommerce'),
        'type' => 'email',
        'description' => __('This controls the email address which the user provides during checkout.', 'woocommerce'),
        'desc_tip' => true,
    ),
    'min_sum' => array(
        'title' => __('Min sum', 'woocommerce'),
        'type' => 'number',
        'description' => __('This controls the minimum amount allowed for the transaction during checkout.', 'woocommerce'),
        'desc_tip' => true
    ),
);
        }

        public function process_payment($order_id) {

        	$cart_currency = WC()->cart->get_currency();
        	$converted_amount = wc_price(wc_get_price_including_tax(null, 1, WC()->cart->get_total(), false, false, array(
        		'currency' =>  $cart_currency
        	)),
        	array('currency' => 'EUR')
        );
        	if($converted_amount <= $this->get_option('min_sum')) {
        		wc_add_notice(__('The total sum must be at least '.$this->get_option('min_sum').' to use this payment method'), 'error');
        		return array(
        			'result' => 'failure',
        			'redirect' => ''
        		);
        	}

            $url = 'https://fintreen.com/api/v1/create';
			$data = array(
    			'fiatAmount' => $converted_amount,
    			'fiatCode' => 'EUR',
    			'cryptoCode' => 'ETH',
			);

			$headers = array(
  	  			'Content-Type' => 'application/json',
    			'Accept' => 'application/json',
    			'fintreen_auth' => $this->get_option('api_token'),
    			'fintreen_signature' => sha1($this->get_option('api_token').$this->get_option('email'))
			);

			$args = array(
    			'body' => $data,
    			'headers' => $headers,
			);

			$response = wp_remote_post($url, $args);
			$data = json_decode($response);

			$data_to_insert = array(
    'fintreen_id' => $data['data']['id'],
    'order_id' => $order_id,
    'fiat_amount' => WC()->cart->get_total(),
    'fintreen_fiat_code' => 'EUR',
    'crypto_amount' => $data['data']['amount'],
    'fintreen_crypto_code' => 'ETH',
    'fintreen_status_id' =>  $data['data']['statusId'],
    'is_test' => 0,
    'link' => $data['data']['link'],
);

// Вставка данных в таблицу `wp_fintreen_transactions`
$table_name = $wpdb->prefix . 'fintreen_transactions';
$wpdb->insert($table_name, $data_to_insert);

			return array(
      'result'   => 'success',
      'redirect' => $data['data']['link'],
    );
        }
    }

    function add_fintreen_gateway_class($methods) {
        $methods[] = 'WC_Fintreen_Payment_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_fintreen_gateway_class');
}
