<?php
/**
 * PayPal Express Checkout Handler for Website A (Client)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class to handle PayPal Express Checkout
 */
class WPPPC_Express_Checkout {
    
    /**
     * API Handler instance
     */
    private $api_handler;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Initialize API handler
        $this->api_handler = new WPPPC_API_Handler();
        
        // Add Express Checkout button to cart and checkout pages
        add_action('woocommerce_before_checkout_form', array($this, 'add_express_checkout_button'), 15);
        add_action('woocommerce_cart_totals_after_order_total', array($this, 'add_express_checkout_button'), 20);
        
        // Add scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_wpppc_create_express_order', array($this, 'ajax_create_express_order'));
        add_action('wp_ajax_nopriv_wpppc_create_express_order', array($this, 'ajax_create_express_order'));
        
        add_action('wp_ajax_wpppc_update_express_shipping', array($this, 'ajax_update_express_shipping'));
        add_action('wp_ajax_nopriv_wpppc_update_express_shipping', array($this, 'ajax_update_express_shipping'));
        
        add_action('wp_ajax_wpppc_complete_express_order', array($this, 'ajax_complete_express_order'));
        add_action('wp_ajax_nopriv_wpppc_complete_express_order', array($this, 'ajax_complete_express_order'));
    }
    
    /**
     * Enqueue scripts and styles for Express Checkout
     */
    public function enqueue_scripts() {
        if (!is_cart() && !is_checkout()) {
            return;
        }
        
        // Get server data
        $server = $this->api_handler->get_server();
        if (!$server) {
            return;
        }
        
        // Enqueue express checkout script
        wp_enqueue_script(
            'wpppc-express-checkout',
            WPPPC_PLUGIN_URL . 'assets/js/express-checkout.js',
            array('jquery'),
            WPPPC_VERSION,
            true
        );
        
        // Generate iframe URL for express checkout
        $iframe_url = $this->api_handler->generate_express_iframe_url();
        
        // Add data for JavaScript
        wp_localize_script('wpppc-express-checkout', 'wpppc_express', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpppc-express-nonce'),
            'iframe_url' => $iframe_url,
            'cart_url' => wc_get_cart_url(),
            'checkout_url' => wc_get_checkout_url(),
            'is_cart' => is_cart() ? 'yes' : 'no',
            'is_checkout' => is_checkout() ? 'yes' : 'no',
            'currency' => get_woocommerce_currency(),
            'cart_total' => WC()->cart->get_total(''),
            'debug_mode' => WP_DEBUG ? 'yes' : 'no',
        ));
        
        // Enqueue styles
        wp_enqueue_style(
            'wpppc-express-checkout',
            WPPPC_PLUGIN_URL . 'assets/css/express-checkout.css',
            array(),
            WPPPC_VERSION
        );
    }
    
    /**
     * Add Express Checkout button
     */
    public function add_express_checkout_button() {
        // Don't show button if cart is empty
        if (WC()->cart->is_empty()) {
            return;
        }
        
        // Get server data
        $server = $this->api_handler->get_server();
        if (!$server) {
            return;
        }
        
        // Check if we're on cart or checkout page and set appropriate wrapper
        $wrapper_class = is_cart() ? 'wpppc-express-cart-wrapper' : 'wpppc-express-checkout-wrapper';
        
        echo '<div class="' . esc_attr($wrapper_class) . '">';
        echo '<div class="wpppc-express-title">' . esc_html__('Express Checkout', 'woo-paypal-proxy-client') . '</div>';
        echo '<div id="wpppc-express-button-container"></div>';
        echo '<div id="wpppc-express-message" class="wpppc-message" style="display: none;"></div>';
        echo '<div id="wpppc-express-error" class="wpppc-error" style="display: none;"></div>';
        echo '<div id="wpppc-express-spinner" class="wpppc-spinner" style="display: none;"></div>';
        echo '</div>';
    }
    
    /**
     * AJAX handler for creating an express checkout order
     */
    public function ajax_create_express_order() {
        check_ajax_referer('wpppc-express-nonce', 'nonce');
        
        wpppc_log('Express Checkout - Creating express order');
        
        // Verify cart is not empty
        if (WC()->cart->is_empty()) {
            wp_send_json_error(array(
                'message' => __('Your cart is empty', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        try {
            // Create a temporary order from the cart
            $order = wc_create_order();
            
            // Set default billing/shipping address if user is logged in
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                $customer = new WC_Customer($user_id);
                
                if ($customer) {
                    $billing_address = array(
                        'first_name' => $customer->get_billing_first_name(),
                        'last_name'  => $customer->get_billing_last_name(),
                        'company'    => $customer->get_billing_company(),
                        'email'      => $customer->get_billing_email(),
                        'phone'      => $customer->get_billing_phone(),
                        'address_1'  => $customer->get_billing_address_1(),
                        'address_2'  => $customer->get_billing_address_2(),
                        'city'       => $customer->get_billing_city(),
                        'state'      => $customer->get_billing_state(),
                        'postcode'   => $customer->get_billing_postcode(),
                        'country'    => $customer->get_billing_country()
                    );
                    
                    $shipping_address = array(
                        'first_name' => $customer->get_shipping_first_name(),
                        'last_name'  => $customer->get_shipping_last_name(),
                        'company'    => $customer->get_shipping_company(),
                        'address_1'  => $customer->get_shipping_address_1(),
                        'address_2'  => $customer->get_shipping_address_2(),
                        'city'       => $customer->get_shipping_city(),
                        'state'      => $customer->get_shipping_state(),
                        'postcode'   => $customer->get_shipping_postcode(),
                        'country'    => $customer->get_shipping_country()
                    );
                    
                    $order->set_address($billing_address, 'billing');
                    $order->set_address($shipping_address, 'shipping');
                    
                    wpppc_log('Express Checkout - Using customer addresses from account');
                }
            } else {
                // Set a minimal address to enable shipping calculations
                // These will be replaced by PayPal's address later
                $default_address = array(
                    'first_name' => '',
                    'last_name'  => '',
                    'company'    => '',
                    'email'      => '',
                    'phone'      => '',
                    'address_1'  => '',
                    'address_2'  => '',
                    'city'       => '',
                    'state'      => '',
                    'postcode'   => '',
                    'country'    => WC()->countries->get_base_country()
                );
                
                $order->set_address($default_address, 'billing');
                $order->set_address($default_address, 'shipping');
                
                wpppc_log('Express Checkout - Using default address with base country: ' . WC()->countries->get_base_country());
            }
            
            // Add cart items to order
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                $quantity = $cart_item['quantity'];
                
                // Add line item
                $item_id = $order->add_product(
                    $product,
                    $quantity,
                    array(
                        'total'    => wc_format_decimal($cart_item['line_total'], wc_get_price_decimals()),
                        'subtotal' => wc_format_decimal($cart_item['line_subtotal'], wc_get_price_decimals()),
                        'taxes'    => $cart_item['line_tax_data']
                    )
                );
                
                // Add line item meta data
                if (!empty($cart_item['variation_id'])) {
                    wc_add_order_item_meta($item_id, '_variation_id', $cart_item['variation_id']);
                }
                
                if (!empty($cart_item['variation'])) {
                    foreach ($cart_item['variation'] as $name => $value) {
                        wc_add_order_item_meta($item_id, sanitize_text_field($name), sanitize_text_field($value));
                    }
                }
            }
            
            // Add shipping methods from session if available
            if (WC()->session) {
                $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
                $shipping_packages = WC()->shipping()->get_packages();
                
                if (!empty($chosen_shipping_methods)) {
                    wpppc_log('Express Checkout - Adding shipping methods from session: ' . json_encode($chosen_shipping_methods));
                    
                    foreach ($shipping_packages as $package_key => $package) {
                        if (isset($chosen_shipping_methods[$package_key], $package['rates'][$chosen_shipping_methods[$package_key]])) {
                            $shipping_rate = $package['rates'][$chosen_shipping_methods[$package_key]];
                            
                            $item = new WC_Order_Item_Shipping();
                            $item->set_props(array(
                                'method_title' => $shipping_rate->get_label(),
                                'method_id'    => $shipping_rate->get_id(),
                                'instance_id'  => $shipping_rate->get_instance_id(),
                                'total'        => wc_format_decimal($shipping_rate->get_cost()),
                                'taxes'        => $shipping_rate->get_taxes(),
                            ));
                            
                            foreach ($shipping_rate->get_meta_data() as $key => $value) {
                                $item->add_meta_data($key, $value, true);
                            }
                            
                            $order->add_item($item);
                            wpppc_log('Express Checkout - Added shipping method: ' . $shipping_rate->get_label());
                        }
                    }
                } else {
                    // If no shipping methods chosen, calculate them
                    wpppc_log('Express Checkout - No shipping methods chosen, will be calculated later');
                }
            }
            
            // Add fees if any
            foreach (WC()->cart->get_fees() as $fee_key => $fee) {
                $item = new WC_Order_Item_Fee();
                $item->set_props(array(
                    'name'      => $fee->name,
                    'tax_class' => $fee->tax_class,
                    'total'     => $fee->amount,
                    'total_tax' => $fee->tax,
                    'taxes'     => array(
                        'total' => $fee->tax_data,
                    ),
                ));
                $order->add_item($item);
                wpppc_log('Express Checkout - Added fee: ' . $fee->name);
            }
            
            // Add coupons if any
            foreach (WC()->cart->get_coupons() as $code => $coupon) {
                $item = new WC_Order_Item_Coupon();
                $item->set_props(array(
                    'code'         => $code,
                    'discount'     => WC()->cart->get_coupon_discount_amount($code),
                    'discount_tax' => WC()->cart->get_coupon_discount_tax_amount($code),
                ));
                $item->add_meta_data('coupon_data', $coupon->get_data());
                $order->add_item($item);
                wpppc_log('Express Checkout - Added coupon: ' . $code);
            }
            
            // Set payment method
            $order->set_payment_method('paypal_proxy');
            
            // Mark as Express Checkout
            $order->update_meta_data('_wpppc_express_checkout', 'yes');
            
            // Calculate totals
            $order->calculate_totals();
            
            // Set order status
            $order->update_status('pending', __('Order created via PayPal Express Checkout', 'woo-paypal-proxy-client'));
            
            // Log order details
            wpppc_log('Express Checkout - Created temporary order #' . $order->get_id());
            wpppc_log('Express Checkout - Order total: ' . $order->get_total());
            
            // Get server information
            $server = $this->api_handler->get_server();
            if (!$server) {
                throw new Exception(__('No PayPal proxy server available', 'woo-paypal-proxy-client'));
            }
            
            // Store server ID in order meta
            $order->update_meta_data('_wpppc_server_id', $server->id);
            $order->save();
            
            // Create proxy data to pass to proxy server
            $proxy_data = array(
                'order_id'       => $order->get_id(),
                'order_key'      => $order->get_order_key(),
                'order_total'    => $order->get_total(),
                'currency'       => $order->get_currency(),
                'customer_email' => $order->get_billing_email(),
                'items'          => $this->get_order_items($order),
                'shipping_address' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'company'    => $order->get_shipping_company(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'state'      => $order->get_shipping_state(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
                ),
            'billing_address' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                ),
            'express_checkout' => 'yes'
            );
            
            // Generate security hash
            $timestamp = time();
            $hash_data = $timestamp . $order->get_id() . $order->get_total() . $server->api_key;
            $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
            
            // Return the data needed for the PayPal button
            wp_send_json_success(array(
                'order_id'   => $order->get_id(),
                'order_key'  => $order->get_order_key(),
                'order_total' => $order->get_total(),
                'currency'   => $order->get_currency(),
                'server'     => array(
                    'url'     => $server->url,
                    'api_key' => $server->api_key
                ),
                'security'   => array(
                    'timestamp' => $timestamp,
                    'hash'      => $hash
                ),
                'proxy_data' => $proxy_data
            ));
            
        } catch (Exception $e) {
            wpppc_log('Express Checkout - Error creating order: ' . $e->getMessage());
            
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
        
        wp_die();
    }
    
    /**
 * AJAX handler for updating shipping methods based on PayPal address
 */
public function ajax_update_express_shipping() {
    check_ajax_referer('wpppc-express-nonce', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $shipping_address = isset($_POST['shipping_address']) ? $_POST['shipping_address'] : array();
    
    wpppc_log('Express Checkout - Updating shipping for order #' . $order_id);
    wpppc_log('Express Checkout - Shipping address: ' . json_encode($shipping_address));
    
    if (!$order_id || empty($shipping_address)) {
        wp_send_json_error(array(
            'message' => __('Invalid order or address data', 'woo-paypal-proxy-client')
        ));
        wp_die();
    }
    
    try {
        // Get the order
        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception(__('Order not found', 'woo-paypal-proxy-client'));
        }
        
        // Prepare a clean shipping address
        $address = array(
            'first_name' => isset($shipping_address['first_name']) ? sanitize_text_field($shipping_address['first_name']) : '',
            'last_name'  => isset($shipping_address['last_name']) ? sanitize_text_field($shipping_address['last_name']) : '',
            'company'    => '',
            'address_1'  => isset($shipping_address['address_1']) ? sanitize_text_field($shipping_address['address_1']) : '',
            'address_2'  => isset($shipping_address['address_2']) ? sanitize_text_field($shipping_address['address_2']) : '',
            'city'       => isset($shipping_address['city']) ? sanitize_text_field($shipping_address['city']) : '',
            'state'      => isset($shipping_address['state']) ? sanitize_text_field($shipping_address['state']) : '',
            'postcode'   => isset($shipping_address['postcode']) ? sanitize_text_field($shipping_address['postcode']) : '',
            'country'    => isset($shipping_address['country']) ? sanitize_text_field($shipping_address['country']) : '',
        );
        
        // Update order shipping address
        $order->set_address($address, 'shipping');
        
        // Also update billing address if it was empty
        $billing_address = $order->get_address('billing');
        if (empty($billing_address['address_1'])) {
            $order->set_address($address, 'billing');
            
            // If we have email from PayPal, add it to billing
            if (isset($shipping_address['email']) && !empty($shipping_address['email'])) {
                $order->set_billing_email(sanitize_email($shipping_address['email']));
            }
            
            // If we have phone from PayPal, add it to billing
            if (isset($shipping_address['phone']) && !empty($shipping_address['phone'])) {
                $order->set_billing_phone(sanitize_text_field($shipping_address['phone']));
            }
        }
        
        // Save the updated addresses
        $order->save();
        
        wpppc_log('Express Checkout - Updated order addresses');
        
        // Remove previous shipping methods
        $order->remove_order_items('shipping');
        
        // Calculate available shipping methods for this address
        $shipping_methods = $this->calculate_shipping_methods($order);
        
        wpppc_log('Express Checkout - Calculated shipping methods: ' . json_encode($shipping_methods));
        
        // If we have shipping methods, add the first one to the order
        if (!empty($shipping_methods)) {
            $first_method = reset($shipping_methods);
            
            $item = new WC_Order_Item_Shipping();
            $item->set_props(array(
                'method_title' => $first_method['label'],
                'method_id'    => $first_method['id'],
                'instance_id'  => $first_method['instance_id'],
                'total'        => $first_method['cost'],
                'taxes'        => $first_method['taxes'],
            ));
            
            // Add metadata if any
            if (!empty($first_method['meta_data'])) {
                foreach ($first_method['meta_data'] as $key => $value) {
                    $item->add_meta_data($key, $value, true);
                }
            }
            
            $order->add_item($item);
            
            wpppc_log('Express Checkout - Added shipping method: ' . $first_method['label']);
        }
        
        // Recalculate totals
        $order->calculate_totals();
        $order->save();
        
        // Get server information
        $server = $this->api_handler->get_server();
        if (!$server) {
            throw new Exception(__('No PayPal proxy server available', 'woo-paypal-proxy-client'));
        }
        
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $order->get_id() . $order->get_total() . $server->api_key;
        $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
        
        // Prepare cart items for PayPal format
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $order->get_item_subtotal($item, false),
                'product_id' => $product ? $product->get_id() : 0,
                'sku' => $product ? $product->get_sku() : '',
                'description' => $product ? substr(wp_strip_all_tags($product->get_short_description()), 0, 127) : ''
            );
        }
        
        // Return shipping methods and updated order data
        wp_send_json_success(array(
            'order_id'        => $order->get_id(),
            'order_total'     => $order->get_total(),
            'subtotal'        => $order->get_subtotal(),
            'shipping_total'  => $order->get_shipping_total(),
            'shipping_tax'    => $order->get_shipping_tax(),
            'tax_total'       => $order->get_total_tax(),
            'shipping_methods' => $shipping_methods,
            'selected_method' => !empty($shipping_methods) ? $shipping_methods[0]['id'] : '',
            'items'           => $items,
            'security'       => array(
                'timestamp' => $timestamp,
                'hash'      => $hash
            )
        ));
        
    } catch (Exception $e) {
        wpppc_log('Express Checkout - Error updating shipping: ' . $e->getMessage());
        
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
    
    /**
     * AJAX handler for completing express checkout order
     */
    public function ajax_complete_express_order() {
        check_ajax_referer('wpppc-express-nonce', 'nonce');
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
        $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : '';
        $payer_data = isset($_POST['payer']) ? $_POST['payer'] : array();
        $shipping_address = isset($_POST['shipping_address']) ? $_POST['shipping_address'] : array();
        
        wpppc_log('Express Checkout - Completing order #' . $order_id);
        wpppc_log('Express Checkout - PayPal Order ID: ' . $paypal_order_id);
        
        if (!$order_id || empty($paypal_order_id)) {
            wp_send_json_error(array(
                'message' => __('Invalid order data', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        try {
            // Get the order
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Order not found', 'woo-paypal-proxy-client'));
            }
            
            // Update order with final PayPal data
            if (!empty($payer_data)) {
                $order->update_meta_data('_wpppc_payer_data', $payer_data);
                
                // Update billing info with payer data
                if (isset($payer_data['email_address'])) {
                    $order->set_billing_email($payer_data['email_address']);
                }
                
                if (isset($payer_data['name'])) {
                    if (isset($payer_data['name']['given_name'])) {
                        $order->set_billing_first_name($payer_data['name']['given_name']);
                    }
                    
                    if (isset($payer_data['name']['surname'])) {
                        $order->set_billing_last_name($payer_data['name']['surname']);
                    }
                }
                
                if (isset($payer_data['phone']) && isset($payer_data['phone']['phone_number'])) {
                    $order->set_billing_phone($payer_data['phone']['phone_number']['national_number']);
                }
                
                if (isset($payer_data['address'])) {
                    $billing_address = array(
                        'address_1' => isset($payer_data['address']['address_line_1']) ? $payer_data['address']['address_line_1'] : '',
                        'address_2' => isset($payer_data['address']['address_line_2']) ? $payer_data['address']['address_line_2'] : '',
                        'city'      => isset($payer_data['address']['admin_area_2']) ? $payer_data['address']['admin_area_2'] : '',
                        'state'     => isset($payer_data['address']['admin_area_1']) ? $payer_data['address']['admin_area_1'] : '',
                        'postcode'  => isset($payer_data['address']['postal_code']) ? $payer_data['address']['postal_code'] : '',
                        'country'   => isset($payer_data['address']['country_code']) ? $payer_data['address']['country_code'] : '',
                    );
                    
                    // Only update if we don't already have a billing address
                    $current_billing = $order->get_address('billing');
                    if (empty($current_billing['address_1'])) {
                        // Keep existing first/last name and email/phone
                        $billing_address['first_name'] = $order->get_billing_first_name();
                        $billing_address['last_name'] = $order->get_billing_last_name();
                        $billing_address['email'] = $order->get_billing_email();
                        $billing_address['phone'] = $order->get_billing_phone();
                        
                        $order->set_address($billing_address, 'billing');
                    }
                }
            }
            
            // Update shipping address if provided
            if (!empty($shipping_address)) {
                $shipping = array(
                    'first_name' => isset($shipping_address['name']['full_name']) ? $shipping_address['name']['full_name'] : $order->get_shipping_first_name(),
                    'last_name'  => '',
                    'address_1'  => isset($shipping_address['address']['address_line_1']) ? $shipping_address['address']['address_line_1'] : $order->get_shipping_address_1(),
                    'address_2'  => isset($shipping_address['address']['address_line_2']) ? $shipping_address['address']['address_line_2'] : $order->get_shipping_address_2(),
                    'city'       => isset($shipping_address['address']['admin_area_2']) ? $shipping_address['address']['admin_area_2'] : $order->get_shipping_city(),
                    'state'      => isset($shipping_address['address']['admin_area_1']) ? $shipping_address['address']['admin_area_1'] : $order->get_shipping_state(),
                    'postcode'   => isset($shipping_address['address']['postal_code']) ? $shipping_address['address']['postal_code'] : $order->get_shipping_postcode(),
                    'country'    => isset($shipping_address['address']['country_code']) ? $shipping_address['address']['country_code'] : $order->get_shipping_country(),
                );
                
                // Parse the full name into first and last name if possible
                if (!empty($shipping['first_name'])) {
                    $name_parts = explode(' ', $shipping['first_name'], 2);
                    if (count($name_parts) > 1) {
                        $shipping['first_name'] = $name_parts[0];
                        $shipping['last_name'] = $name_parts[1];
                    }
                }
                
                $order->set_address($shipping, 'shipping');
            }
            
            // Store PayPal transaction data
            $order->update_meta_data('_paypal_order_id', $paypal_order_id);
            if (!empty($transaction_id)) {
                $order->update_meta_data('_transaction_id', $transaction_id);
            }
            
            // Complete payment
            if ($order->needs_payment()) {
                $order->payment_complete($transaction_id);
            }
            
            // Add order note
            $order->add_order_note(
                sprintf(__('Payment completed via PayPal Express Checkout. PayPal Order ID: %s, Transaction ID: %s', 'woo-paypal-proxy-client'),
                    $paypal_order_id,
                    $transaction_id
                )
            );
            
            // Save the order
            $order->save();
            
            // Empty cart
            WC()->cart->empty_cart();
            
            wpppc_log('Express Checkout - Order completed successfully');
            
            // Return success response with order received URL
            wp_send_json_success(array(
                'redirect' => $order->get_checkout_order_received_url()
            ));
            
        } catch (Exception $e) {
            wpppc_log('Express Checkout - Error completing order: ' . $e->getMessage());
            
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
        
        wp_die();
    }
    
    /**
     * Calculate available shipping methods for an order
     */
    private function calculate_shipping_methods($order) {
        $shipping_methods = array();
        
        // Get the customer shipping address from the order
        $address = array(
            'country'   => $order->get_shipping_country(),
            'state'     => $order->get_shipping_state(),
            'postcode'  => $order->get_shipping_postcode(),
            'city'      => $order->get_shipping_city(),
            'address'   => $order->get_shipping_address_1(),
            'address_2' => $order->get_shipping_address_2()
        );
        
        // Create package based on order items
        $line_items = $order->get_items();
        $items = array();
        
        foreach ($line_items as $item) {
            $product = $item->get_product();
            if ($product) {
                $items[] = array(
                    'data'     => $product,
                    'quantity' => $item->get_quantity()
                );
            }
        }
        
        // Create package
        $package = array(
            'contents'        => $items,
            'contents_cost'   => $order->get_subtotal(),
            'applied_coupons' => array(), // We're not handling coupons for shipping yet
            'destination'     => $address
        );
        
        // Calculate shipping for this package
        $shipping_methods_array = WC()->shipping()->calculate_shipping(array($package));
        
        if (!empty($shipping_methods_array[0]['rates'])) {
            foreach ($shipping_methods_array[0]['rates'] as $rate) {
                $method = array(
                    'id'         => $rate->get_id(),
                    'method_id'  => $rate->get_method_id(),
                    'instance_id' => $rate->get_instance_id(),
                    'label'      => $rate->get_label(),
                    'cost'       => $rate->get_cost(),
                    'taxes'      => $rate->get_taxes(),
                    'meta_data'  => $rate->get_meta_data()
                );
                
                $shipping_methods[] = $method;
            }
        }
        
        return $shipping_methods;
    }
    
    /**
     * Get order items in a format suitable for the proxy server
     */
    private function get_order_items($order) {
        $items = array();
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            $item_data = array(
                'product_id'   => $product ? $product->get_id() : 0,
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'price'        => $order->get_item_total($item, false, false),
                'line_total'   => $item->get_total(),
                'sku'          => $product ? $product->get_sku() : '',
            );
            
            $items[] = $item_data;
        }
        
        return $items;
    }
}

// Initialize Express Checkout
new WPPPC_Express_Checkout();