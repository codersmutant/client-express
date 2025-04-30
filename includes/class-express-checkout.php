<?php
/**
 * PayPal Express Checkout functionality for Website A (Client)
 * 
 * Adds PayPal Express Checkout buttons to cart and checkout pages
 * and handles the express checkout flow through our proxy architecture.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PayPal Express Checkout Class
 */
class WPPPC_Express_Checkout {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add PayPal buttons to cart page
        add_action('woocommerce_proceed_to_checkout', array($this, 'add_express_checkout_button_to_cart'), 20);
        
        // Add PayPal buttons to checkout page before customer details
        add_action('woocommerce_before_checkout_form', array($this, 'add_express_checkout_button_to_checkout'), 10);
        
        // Add scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handler for creating PayPal order for express checkout
        add_action('wp_ajax_wpppc_create_express_order', array($this, 'ajax_create_express_order'));
        add_action('wp_ajax_nopriv_wpppc_create_express_order', array($this, 'ajax_create_express_order'));
        
        // AJAX handler for updating shipping methods
        add_action('wp_ajax_wpppc_update_shipping_methods', array($this, 'ajax_update_shipping_methods'));
        add_action('wp_ajax_nopriv_wpppc_update_shipping_methods', array($this, 'ajax_update_shipping_methods'));
        
        // AJAX handler for completing express order
        add_action('wp_ajax_wpppc_complete_express_order', array($this, 'ajax_complete_express_order'));
        add_action('wp_ajax_nopriv_wpppc_complete_express_order', array($this, 'ajax_complete_express_order'));
        
        // Callback endpoint for PayPal webhooks from proxy server
        add_action('woocommerce_api_wpppc_shipping', array($this, 'handle_shipping_callback'));
    }
    
    /**
     * Add Express Checkout button to cart page
     */
    public function add_express_checkout_button_to_cart() {
        // Only show if we have a server
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            wpppc_log("Express Checkout: No PayPal server available for cart buttons");
            return;
        }
        
        echo '<div class="wpppc-express-checkout-container">';
        echo '<p>' . __('Or checkout with PayPal', 'woo-paypal-proxy-client') . '</p>';
        echo '<div id="wpppc-express-paypal-button-cart" class="wpppc-express-paypal-button"></div>';
        echo '<div id="wpppc-express-message" class="wpppc-message" style="display: none;"></div>';
        echo '<div id="wpppc-express-error" class="wpppc-error" style="display: none;"></div>';
        echo '</div>';
    }
    
    /**
     * Add Express Checkout button to checkout page
     */
    public function add_express_checkout_button_to_checkout() {
        // Only show if we have a server
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            wpppc_log("Express Checkout: No PayPal server available for checkout buttons");
            return;
        }
        
        echo '<div class="wpppc-express-checkout-container">';
        echo '<h3>' . __('Express Checkout', 'woo-paypal-proxy-client') . '</h3>';
        echo '<p>' . __('Check out faster with PayPal', 'woo-paypal-proxy-client') . '</p>';
        echo '<div id="wpppc-express-paypal-button-checkout" class="wpppc-express-paypal-button"></div>';
        echo '</div>';
        echo '<div class="wpppc-express-separator"><span>' . __('OR', 'woo-paypal-proxy-client') . '</span></div>';
    }
    
    /**
     * Enqueue scripts and styles for Express Checkout
     */
    public function enqueue_scripts() {
        if (!is_cart() && !is_checkout()) {
            return;
        }
        
        // Enqueue custom express checkout styles
        wp_enqueue_style('wpppc-express-checkout', WPPPC_PLUGIN_URL . 'assets/css/express-checkout.css', array(), WPPPC_VERSION);
        
        // Enqueue custom script for Express Checkout
        wp_enqueue_script('wpppc-express-checkout', WPPPC_PLUGIN_URL . 'assets/js/express-checkout.js', array('jquery'), WPPPC_VERSION, true);
        
        // Get server for button URL
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            return;
        }
        
        // Get API handler with server
        $api_handler = new WPPPC_API_Handler();
        
        // Create button iframe URL
        $iframe_url = $api_handler->generate_express_iframe_url();
        
        // Pass data to JavaScript
        wp_localize_script('wpppc-express-checkout', 'wpppc_express_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpppc-express-nonce'),
            'iframe_url' => $iframe_url,
            'cart_total' => WC()->cart->get_total(''),
            'currency' => get_woocommerce_currency(),
            'shipping_required' => WC()->cart->needs_shipping(),
            'is_checkout_page' => is_checkout(),
            'is_cart_page' => is_cart(),
            'debug_mode' => true
        ));
    }
    
/**
 * AJAX handler for creating a PayPal order for Express Checkout
 */
public function ajax_create_express_order() {
    check_ajax_referer('wpppc-express-nonce', 'nonce');
    
    wpppc_log("Express Checkout: Creating order via AJAX");
    
    try {
        // Make sure cart is not empty
        if (WC()->cart->is_empty()) {
            wpppc_log("Express Checkout: Cart is empty");
            throw new Exception(__('Your cart is empty', 'woo-paypal-proxy-client'));
        }
        
        // Create temporary order with pending status
        $order = wc_create_order();
        
        // Mark as express checkout
        $order->add_meta_data('_wpppc_express_checkout', 'yes');
        
        // Add cart items to order
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $variation_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;
            
            // Add line item
            $item = new WC_Order_Item_Product();
            $item->set_props(array(
                'product_id'   => $product->get_id(),
                'variation_id' => $variation_id,
                'quantity'     => $cart_item['quantity'],
                'subtotal'     => $cart_item['line_subtotal'],
                'total'        => $cart_item['line_total'],
                'subtotal_tax' => $cart_item['line_subtotal_tax'],
                'total_tax'    => $cart_item['line_tax'],
                'taxes'        => $cart_item['line_tax_data']
            ));
            
            // Add item name
            $item->set_name($product->get_name());
            
            // Add any meta data from cart item
            if (!empty($cart_item['variation'])) {
                foreach ($cart_item['variation'] as $meta_name => $meta_value) {
                    $item->add_meta_data(str_replace('attribute_', '', $meta_name), $meta_value);
                }
            }
            
            // Add the item to the order
            $order->add_item($item);
        }
        
        // Add fees
        foreach (WC()->cart->get_fees() as $fee) {
            $fee_item = new WC_Order_Item_Fee();
            $fee_item->set_props(array(
                'name'      => $fee->name,
                'tax_class' => $fee->tax_class,
                'total'     => $fee->amount,
                'total_tax' => $fee->tax,
                'taxes'     => array(
                    'total' => $fee->tax_data,
                ),
            ));
            $order->add_item($fee_item);
        }
        
        // Add coupons
        foreach (WC()->cart->get_coupons() as $code => $coupon) {
            $coupon_item = new WC_Order_Item_Coupon();
            $coupon_item->set_props(array(
                'code'         => $code,
                'discount'     => WC()->cart->get_coupon_discount_amount($code),
                'discount_tax' => WC()->cart->get_coupon_discount_tax_amount($code),
            ));
            
            // Store coupon meta data
            if (method_exists($coupon_item, 'add_meta_data')) {
                $coupon_item->add_meta_data('coupon_data', $coupon->get_data());
            }
            
            $order->add_item($coupon_item);
        }
        
        // Set payment method
        $order->set_payment_method('paypal_proxy');
        
        // Initially set empty addresses - PayPal will provide these later
        $order->set_address(array(), 'billing');
        $order->set_address(array(), 'shipping');
        
        // Calculate totals - these will be updated after shipping is determined
        $order->calculate_totals();
        
        // Set order status to pending
        $order->update_status('pending', __('Order created via PayPal Express Checkout', 'woo-paypal-proxy-client'));
        
        // Save the order
        $order->save();
        
        wpppc_log("Express Checkout: Created order #{$order->get_id()} with total {$order->get_total()}");
        
        // Get server to use
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            throw new Exception(__('No PayPal server available', 'woo-paypal-proxy-client'));
        }
        
        // Store server ID in order
        update_post_meta($order->get_id(), '_wpppc_server_id', $server->id);
        
        // Prepare line items for PayPal with detailed information
        $line_items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if (!$product) continue;
            
            $line_items[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'unit_price' => $order->get_item_subtotal($item, false),
                'tax_amount' => $item->get_total_tax(),
                'sku' => $product ? $product->get_sku() : '',
                'product_id' => $product ? $product->get_id() : 0,
                'description' => $product ? wp_trim_words($product->get_short_description(), 15) : ('Product ID: ' . $product->get_id())
            );
        }
        
        // Pass server ID to the mapping function if applicable
        if (function_exists('add_product_mappings_to_items')) {
            $line_items = add_product_mappings_to_items($line_items, $server->id);
        }
        
        // Get customer information if available
        $customer_info = array();
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $customer_info = array(
                'first_name' => $current_user->first_name,
                'last_name' => $current_user->last_name,
                'email' => $current_user->user_email
            );
        }
        
        // Create order data for proxy server with COMPLETE information
        $order_data = array(
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'line_items' => $line_items,
            'cart_total' => $order->get_subtotal(),
            'order_total' => $order->get_total(),
            'tax_total' => $order->get_total_tax(),
            'shipping_total' => $order->get_shipping_total(),
            'discount_total' => $order->get_discount_total(),
            'currency' => $order->get_currency(),
            'return_url' => wc_get_checkout_url(),
            'cancel_url' => wc_get_cart_url(),
            'callback_url' => WC()->api_request_url('wpppc_shipping'),
            'needs_shipping' => WC()->cart->needs_shipping(),
            'server_id' => $server->id,
            'customer_info' => $customer_info
        );
        
        // Encode the order data to base64
        $order_data_encoded = base64_encode(json_encode($order_data));
        
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $order->get_id() . $order->get_total() . $server->api_key;
        $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
        
        // Create request data with proper format for proxy server
        $request_data = array(
            'api_key' => $server->api_key,
            'timestamp' => $timestamp,
            'hash' => $hash,
            'order_data' => $order_data_encoded
        );
        
        wpppc_log("Express Checkout: Sending properly formatted request to proxy server");
        
        // Send request to proxy server
        $response = wp_remote_post(
            $server->url . '/wp-json/wppps/v1/create-express-checkout',
            array(
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($request_data)
            )
        );
        
        // Check for errors
        if (is_wp_error($response)) {
            wpppc_log("Express Checkout: Error communicating with proxy server: " . $response->get_error_message());
            throw new Exception(__('Error communicating with proxy server: ', 'woo-paypal-proxy-client') . $response->get_error_message());
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            wpppc_log("Express Checkout: Proxy server returned error code: $response_code");
            wpppc_log("Express Checkout: Response body: " . wp_remote_retrieve_body($response));
            throw new Exception(__('Proxy server returned error', 'woo-paypal-proxy-client'));
        }
        
        // Parse response
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$body || !isset($body['success']) || $body['success'] !== true) {
            $error_message = isset($body['message']) ? $body['message'] : __('Unknown error from proxy server', 'woo-paypal-proxy-client');
            wpppc_log("Express Checkout: Proxy server error: $error_message");
            throw new Exception($error_message);
        }
        
        // Store PayPal order ID in WooCommerce order
        $paypal_order_id = isset($body['paypal_order_id']) ? $body['paypal_order_id'] : '';
        if (!empty($paypal_order_id)) {
            update_post_meta($order->get_id(), '_paypal_order_id', $paypal_order_id);
            wpppc_log("Express Checkout: Stored PayPal order ID: $paypal_order_id for order #{$order->get_id()}");
        } else {
            wpppc_log("Express Checkout: No PayPal order ID received from proxy server");
            throw new Exception(__('No PayPal order ID received from proxy server', 'woo-paypal-proxy-client'));
        }
        
        // Return success with PayPal order ID
        wp_send_json_success(array(
            'order_id' => $order->get_id(),
            'paypal_order_id' => $paypal_order_id,
            'approveUrl' => isset($body['approve_url']) ? $body['approve_url'] : ''
        ));
        
    } catch (Exception $e) {
        wpppc_log("Express Checkout: Error creating order: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
    
/**
 * AJAX handler for updating shipping methods with proper tax handling
 */
public function ajax_update_shipping_methods() {
    check_ajax_referer('wpppc-express-nonce', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    $selected_shipping_method = isset($_POST['shipping_method']) ? sanitize_text_field($_POST['shipping_method']) : '';
    $shipping_address = isset($_POST['shipping_address']) ? $_POST['shipping_address'] : array();
    
    wpppc_log("Express Checkout: Updating shipping methods for order #$order_id, PayPal order $paypal_order_id");
    
    try {
        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception(__('Order not found', 'woo-paypal-proxy-client'));
        }
        
        // Get server ID from order
        $server_id = get_post_meta($order->get_id(), '_wpppc_server_id', true);
        if (!$server_id) {
            throw new Exception(__('Server ID not found for order', 'woo-paypal-proxy-client'));
        }
        
        // Get server
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_server($server_id);
        
        if (!$server) {
            throw new Exception(__('PayPal server not found', 'woo-paypal-proxy-server'));
        }
        
        // If we have a shipping address, set it to the order for calculation
        if (!empty($shipping_address)) {
            $formatted_address = array(
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => '',
                'address_1'  => isset($shipping_address['address_line_1']) ? $shipping_address['address_line_1'] : '',
                'address_2'  => isset($shipping_address['address_line_2']) ? $shipping_address['address_line_2'] : '',
                'city'       => isset($shipping_address['admin_area_2']) ? $shipping_address['admin_area_2'] : '',
                'state'      => isset($shipping_address['admin_area_1']) ? $shipping_address['admin_area_1'] : '',
                'postcode'   => isset($shipping_address['postal_code']) ? $shipping_address['postal_code'] : '',
                'country'    => isset($shipping_address['country_code']) ? $shipping_address['country_code'] : '',
            );
            
            // Update order with this address for shipping calculation
            $order->set_address($formatted_address, 'shipping');
            $order->save();
            
            // Calculate shipping options for this address
            $shipping_options = wpppc_calculate_shipping_for_address($formatted_address);
            wpppc_log("Express Checkout: Calculated " . count($shipping_options) . " shipping options");
        }
        
        // Variable to hold the selected shipping cost and tax
        $selected_shipping_cost = 0;
        $selected_shipping_tax = 0;
        $selected_option = null;
        
        // If we have a selected shipping method, use it
        if (!empty($selected_shipping_method) && isset($shipping_options)) {
            // Find the selected method in options
            foreach ($shipping_options as $option) {
                if ($option['id'] == $selected_shipping_method) {
                    $selected_option = $option;
                    $selected_shipping_cost = floatval($option['cost']);
                    
                    // Store shipping tax if available
                    if (isset($option['tax'])) {
                        $selected_shipping_tax = floatval($option['tax']);
                        wpppc_log("Express Checkout: Selected shipping option tax: " . $selected_shipping_tax);
                    }
                    
                    wpppc_log("Express Checkout: Selected shipping option: " . $option['label'] . " (Cost: " . $selected_shipping_cost . ")");
                    break;
                }
            }
        }
        // If no shipping method selected but we have options, use the first one
        elseif (empty($selected_shipping_method) && !empty($shipping_options)) {
            $selected_option = $shipping_options[0];
            $selected_shipping_method = $selected_option['id'];
            $selected_shipping_cost = floatval($selected_option['cost']);
            
            // Store shipping tax if available
            if (isset($selected_option['tax'])) {
                $selected_shipping_tax = floatval($selected_option['tax']);
                wpppc_log("Express Checkout: First shipping option tax: " . $selected_shipping_tax);
            }
            
            wpppc_log("Express Checkout: No method selected, using first option: " . $selected_option['label'] . " (Cost: " . $selected_shipping_cost . ")");
        }
        
        // Add the selected shipping method to the order if found
        if ($selected_option) {
            // Remove any existing shipping items
            foreach ($order->get_items('shipping') as $item) {
                $item->delete();
            }
            
            // Add new shipping item
            $item = new WC_Order_Item_Shipping();
            $item->set_props(array(
                'method_title' => $selected_option['label'],
                'method_id'    => $selected_option['method_id'],
                'instance_id'  => $selected_option['instance_id'],
                'total'        => $selected_shipping_cost,
                'taxes'        => isset($selected_option['taxes']) ? $selected_option['taxes'] : array()
            ));
            
            $order->add_item($item);
            $order->calculate_totals();
            $order->save();
            
            wpppc_log("Express Checkout: Updated order with shipping method: " . $selected_option['label']);
        }
        
        // Get updated order totals
        $order_subtotal = $order->get_subtotal();
        $order_shipping_tax = $order->get_shipping_tax();
        $order_item_tax = $order->get_cart_tax();
        $order_total_tax = $order->get_total_tax(); // This should include both item tax and shipping tax
        $shipping_total = $order->get_shipping_total();
        $discount_total = $order->get_discount_total();
        $order_total = $order->get_total();
        
        wpppc_log("Express Checkout: Order tax breakdown - Item tax: $order_item_tax, Shipping tax: $order_shipping_tax, Total tax: $order_total_tax");
        
        // If there's a mismatch between shipping tax in order and in shipping option, log it
        if (abs($order_shipping_tax - $selected_shipping_tax) > 0.01 && $selected_shipping_tax > 0) {
            wpppc_log("Express Checkout: WARNING - Shipping tax mismatch. Order: $order_shipping_tax, Option: $selected_shipping_tax. Using option tax.");
            // Use the shipping tax from the option
            $order_total_tax = $order_item_tax + $selected_shipping_tax;
            
            // Recalculate order total for consistency
            $order_total = $order_subtotal + $shipping_total + $order_total_tax - $discount_total;
            
            wpppc_log("Express Checkout: Adjusted total tax to: $order_total_tax and total to: $order_total");
        }
        
        // CRITICAL: Ensure shipping tax is included in total tax if not already
        if ($order_total_tax < ($order_item_tax + $selected_shipping_tax) && $selected_shipping_tax > 0) {
            wpppc_log("Express Checkout: Adding shipping tax to total tax. Before: $order_total_tax");
            $order_total_tax = $order_item_tax + $selected_shipping_tax;
            wpppc_log("Express Checkout: After: $order_total_tax");
            
            // Recalculate order total to include shipping tax
            $order_total = $order_subtotal + $shipping_total + $order_total_tax - $discount_total;
            wpppc_log("Express Checkout: Adjusted total to: $order_total");
        }
        
        // CRITICAL: Make sure shipping_total matches the selected option's cost
        if (abs($shipping_total - $selected_shipping_cost) > 0.01) {
            wpppc_log("Express Checkout: WARNING - Order shipping total ($shipping_total) doesn't match selected option cost ($selected_shipping_cost). Using selected option cost.");
            $shipping_total = $selected_shipping_cost;
            
            // Recalculate order total for consistency
            $order_total = $order_subtotal + $shipping_total + $order_total_tax - $discount_total;
            wpppc_log("Express Checkout: Adjusted total to: $order_total");
        }
        
        wpppc_log("Express Checkout: Final order totals - Subtotal: $order_subtotal, Tax: $order_total_tax, Shipping: $shipping_total, Discount: $discount_total, Total: $order_total");
        
        // Prepare line items for PayPal with detailed information
        $line_items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $line_items[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'unit_price' => $order->get_item_subtotal($item, false),
                'tax_amount' => $item->get_total_tax(),
                'sku' => $product ? $product->get_sku() : '',
                'product_id' => $product ? $product->get_id() : 0,
                'description' => $product ? wp_trim_words($product->get_short_description(), 15) : ('Product ID: ' . $item->get_product_id())
            );
        }
        
        // Prepare complete shipping data for proxy server
        $shipping_data = array(
            'order_id' => $order_id,
            'paypal_order_id' => $paypal_order_id,
            'shipping_method' => $selected_shipping_method,
            'shipping_options' => isset($shipping_options) ? $shipping_options : array(),
            'order_subtotal' => $order_subtotal,
            'tax_total' => $order_total_tax, // Using the correctly calculated total tax
            'shipping_total' => $shipping_total,
            'shipping_tax' => $selected_shipping_tax, // Add shipping tax explicitly
            'discount_total' => $discount_total,
            'order_total' => $order_total,
            'currency' => $order->get_currency(),
            'server_id' => $server_id,
            'line_items' => $line_items
        );
        
        // Encode the shipping data
        $request_data_encoded = base64_encode(json_encode($shipping_data));
        
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $order_id . $paypal_order_id . $server->api_key;
        $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
        
        // Create request data with proper format for proxy server
        $request_data = array(
            'api_key' => $server->api_key,
            'timestamp' => $timestamp,
            'hash' => $hash,
            'request_data' => $request_data_encoded
        );
        
        wpppc_log("Express Checkout: Sending shipping update to proxy server with complete data");
        
        // Send request to proxy server
        $response = wp_remote_post(
            $server->url . '/wp-json/wppps/v1/update-express-shipping',
            array(
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($request_data)
            )
        );
        
        // Check for errors
        if (is_wp_error($response)) {
            wpppc_log("Express Checkout: Error communicating with proxy server: " . $response->get_error_message());
            throw new Exception(__('Error communicating with proxy server: ', 'woo-paypal-proxy-client') . $response->get_error_message());
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            wpppc_log("Express Checkout: Proxy server returned error code: $response_code");
            wpppc_log("Express Checkout: Response body: " . wp_remote_retrieve_body($response));
            throw new Exception(__('Proxy server returned error', 'woo-paypal-proxy-client'));
        }
        
        // Parse response
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$body || !isset($body['success']) || $body['success'] !== true) {
            $error_message = isset($body['message']) ? $body['message'] : __('Unknown error from proxy server', 'woo-paypal-proxy-client');
            wpppc_log("Express Checkout: Proxy server error: $error_message");
            throw new Exception($error_message);
        }
        
        wpppc_log("Express Checkout: Successfully updated shipping method");
        
        // Return success with shipping options if available
        $response_data = array(
            'success' => true,
            'message' => __('Shipping method updated', 'woo-paypal-proxy-client')
        );
        
        if (isset($shipping_options) && !empty($shipping_options)) {
            $response_data['shipping_options'] = $shipping_options;
        }
        
        wp_send_json_success($response_data);
        
    } catch (Exception $e) {
        wpppc_log("Express Checkout: Error updating shipping methods: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
    
   /**
 * Handle shipping callback from proxy server
 */
public function handle_shipping_callback() {
    wpppc_log("Express Checkout: Received shipping callback");
    
    // Get callback data
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    $paypal_order_id = isset($_GET['paypal_order_id']) ? sanitize_text_field($_GET['paypal_order_id']) : '';
    $shipping_data = isset($_POST['shipping_data']) ? json_decode(wp_unslash($_POST['shipping_data']), true) : array();
    $hash = isset($_GET['hash']) ? sanitize_text_field($_GET['hash']) : '';
    $timestamp = isset($_GET['timestamp']) ? intval($_GET['timestamp']) : 0;
    
    wpppc_log("Express Checkout: Shipping callback for order #$order_id, PayPal order $paypal_order_id");
    wpppc_log("Express Checkout: Shipping data: " . json_encode($shipping_data));
    
    // Send response headers early
    status_header(200);
    header('Content-Type: application/json');
    
    try {
        // Validate essential data
        if (!$order_id || !$paypal_order_id || empty($shipping_data)) {
            throw new Exception('Missing required data');
        }
        
        // Get the order
        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception('Order not found');
        }
        
        // Get server ID from order
        $server_id = get_post_meta($order->get_id(), '_wpppc_server_id', true);
        if (!$server_id) {
            throw new Exception('Server ID not found for order');
        }
        
        // Get server
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_server($server_id);
        
        if (!$server) {
            throw new Exception('PayPal server not found');
        }
        
        // Verify security hash
        $expected_hash = hash_hmac('sha256', $timestamp . $order_id . $paypal_order_id . $server->api_key, $server->api_secret);
        if (!hash_equals($expected_hash, $hash)) {
            throw new Exception('Invalid security hash');
        }
        
        // Extract address from shipping data
        $address = isset($shipping_data['address']) ? $shipping_data['address'] : array();
        
        if (empty($address)) {
            throw new Exception('No shipping address provided');
        }
        
        // Format the address for WooCommerce
        $shipping_address = array(
            'first_name' => isset($shipping_data['name']['given_name']) ? $shipping_data['name']['given_name'] : '',
            'last_name'  => isset($shipping_data['name']['surname']) ? $shipping_data['name']['surname'] : '',
            'company'    => '',
            'address_1'  => isset($address['address_line_1']) ? $address['address_line_1'] : '',
            'address_2'  => isset($address['address_line_2']) ? $address['address_line_2'] : '',
            'city'       => isset($address['admin_area_2']) ? $address['admin_area_2'] : '',
            'state'      => isset($address['admin_area_1']) ? $address['admin_area_1'] : '',
            'postcode'   => isset($address['postal_code']) ? $address['postal_code'] : '',
            'country'    => isset($address['country_code']) ? $address['country_code'] : '',
        );
        
        wpppc_log("Express Checkout: Formatted shipping address: " . json_encode($shipping_address));
        
        // Set shipping address on order
        $order->set_address($shipping_address, 'shipping');
        $order->save();
        
        // Calculate shipping options for this address
        $shipping_options = wpppc_calculate_shipping_for_address($shipping_address);
        
        wpppc_log("Express Checkout: Available shipping options: " . json_encode($shipping_options));
        
        // If no shipping options available, add a default one
        if (empty($shipping_options) && WC()->cart->needs_shipping()) {
            wpppc_log("Express Checkout: No shipping methods available for this address");
            
            // Return an error response to PayPal
            echo json_encode(array(
                'success' => false,
                'error' => 'NO_SHIPPING_OPTIONS',
                'message' => 'No shipping options available for this address'
            ));
            exit;
        }
        
        // Prepare response for proxy server
        $response = array(
            'success' => true,
            'shipping_options' => $shipping_options,
            'order_id' => $order_id,
            'paypal_order_id' => $paypal_order_id
        );
        
        wpppc_log("Express Checkout: Sending shipping options response: " . json_encode($response));
        
        // Send JSON response
        echo json_encode($response);
        exit;
        
    } catch (Exception $e) {
        wpppc_log("Express Checkout: Error in shipping callback: " . $e->getMessage());
        
        // Return error response
        echo json_encode(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
        exit;
    }
}
    
    /**
 * AJAX handler for completing an Express Checkout order
 */
public function ajax_complete_express_order() {
    check_ajax_referer('wpppc-express-nonce', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    
    wpppc_log("Express Checkout: Completing order #$order_id, PayPal order $paypal_order_id");
    
    try {
        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception(__('Order not found', 'woo-paypal-proxy-client'));
        }
        
        // Get server ID from order
        $server_id = get_post_meta($order->get_id(), '_wpppc_server_id', true);
        if (!$server_id) {
            throw new Exception(__('Server ID not found for order', 'woo-paypal-proxy-client'));
        }
        
        // Get server
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_server($server_id);
        
        if (!$server) {
            throw new Exception(__('PayPal server not found', 'woo-paypal-proxy-client'));
        }
        
        // Create request data for proxy server
        $request_data = array(
            'order_id' => $order_id,
            'paypal_order_id' => $paypal_order_id,
            'server_id' => $server_id,
            'api_key' => $server->api_key
        );
        
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $order_id . $paypal_order_id . $server->api_key;
        $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
        
        // Add security parameters
        $request_data['timestamp'] = $timestamp;
        $request_data['hash'] = $hash;
        
        // Log the data we're sending
        wpppc_log("Express Checkout: Sending capture request to proxy server: " . json_encode($request_data));
        
        // Send request to proxy server
        $response = wp_remote_post(
            $server->url . '/wp-json/wppps/v1/capture-express-payment',
            array(
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($request_data)
            )
        );
        
        // Check for errors
        if (is_wp_error($response)) {
            wpppc_log("Express Checkout: Error communicating with proxy server: " . $response->get_error_message());
            throw new Exception(__('Error communicating with proxy server: ', 'woo-paypal-proxy-client') . $response->get_error_message());
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            wpppc_log("Express Checkout: Proxy server returned error code: $response_code");
            wpppc_log("Express Checkout: Response body: " . wp_remote_retrieve_body($response));
            throw new Exception(__('Proxy server returned error', 'woo-paypal-proxy-client'));
        }
        
        // Parse response
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$body || !isset($body['success']) || $body['success'] !== true) {
            $error_message = isset($body['message']) ? $body['message'] : __('Unknown error from proxy server', 'woo-paypal-proxy-client');
            wpppc_log("Express Checkout: Proxy server error: $error_message");
            throw new Exception($error_message);
        }
        
        // Get transaction ID from response
        $transaction_id = isset($body['transaction_id']) ? $body['transaction_id'] : '';
        $seller_protection = isset($body['seller_protection']) ? $body['seller_protection'] : 'UNKNOWN';
        
        // Update order with transaction ID and complete payment
        if (!empty($transaction_id)) {
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(__('Payment completed via PayPal Express Checkout. Transaction ID: %s, Seller Protection: %s', 'woo-paypal-proxy-client'), $transaction_id, $seller_protection));
            update_post_meta($order->get_id(), '_paypal_transaction_id', $transaction_id);
            update_post_meta($order->get_id(), '_paypal_seller_protection', $seller_protection);
            
            // Get the order total amount for usage tracking
            $order_amount = floatval($order->get_total());
            
            // Add to server usage
            $result = $server_manager->add_server_usage($server_id, $order_amount);
            wpppc_log("Express Checkout: Added $order_amount to server usage for server ID $server_id. Result: " . ($result ? "success" : "failed"));
        }
        
        // Empty the cart
        WC()->cart->empty_cart();
        
        wpppc_log("Express Checkout: Order #$order_id completed successfully");
        
        // Return success with redirect URL
        wp_send_json_success(array(
            'redirect' => $order->get_checkout_order_received_url()
        ));
        
    } catch (Exception $e) {
        wpppc_log("Express Checkout: Error completing order: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
    
}

// Initialize Express Checkout
add_action('init', function() {
    new WPPPC_Express_Checkout();
});