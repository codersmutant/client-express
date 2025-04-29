/**
 * PayPal Express Checkout JS - Client Side
 */
(function($) {
    'use strict';
    
    // Variables to track order status
    var expressOrderId = null;
    var expressCreating = false;
    var expressCompleting = false;
    var paypalExpressOrderId = null;
    
    /**
     * Initialize express checkout
     */
    function initExpressCheckout() {
        // Log initialization for debugging
        debug('Initializing PayPal Express Checkout');
        debug('PayPal Express iframe URL: ' + wpppc_express.iframe_url);
        
        // Create an iframe to load the PayPal button from the proxy server
        var $container = $('#wpppc-express-button-container');
        if ($container.length === 0) {
            debug('Express checkout button container not found');
            return;
        }
        
        // Create and insert the iframe
        var iframe = document.createElement('iframe');
        iframe.id = 'paypal-express-iframe';
        iframe.src = wpppc_express.iframe_url;
        iframe.style.width = '100%';
        iframe.style.height = '60px';
        iframe.style.border = 'none';
        iframe.style.overflow = 'hidden';
        iframe.setAttribute('scrolling', 'no');
        iframe.setAttribute('allowtransparency', 'true');
        
        $container.append(iframe);
        
        // Set up message listener for communication with iframe
        setupMessageListener();
        
        debug('Express checkout iframe created');
    }
    
    /**
     * Set up message listener for communication with iframe
     */
    function setupMessageListener() {
        window.addEventListener('message', function(event) {
            // Validate message source
            debug('Received message from: ' + event.origin);
            
            // Extract iframe URL origin for validation
            var iframeUrl = null;
            try {
                iframeUrl = new URL(wpppc_express.iframe_url);
            } catch (error) {
                debug('Invalid iframe URL: ' + error.message);
                return;
            }
            
            // Skip messages from other sources (but be lenient for development)
            if (event.origin !== iframeUrl.origin && !event.origin.includes('paypal.com')) {
                debug('Ignoring message from unknown origin: ' + event.origin);
                return;
            }
            
            var data = event.data;
            
            // Check if message is for us
            if (!data || !data.action) {
                return;
            }
            
            debug('Received message action: ' + data.action);
            
            // Handle different actions
            switch (data.action) {
                case 'button_loaded':
                    handleButtonLoaded();
                    break;
                    
                case 'button_clicked':
                    handleButtonClicked();
                    break;
                    
                case 'shipping_address_updated':
                    handleShippingAddressUpdate(data.payload);
                    break;
                    
                case 'shipping_method_selected':
                    handleShippingMethodSelected(data.payload);
                    break;
                    
                case 'payment_approved':
                    handlePaymentApproved(data.payload);
                    break;
                    
                case 'payment_cancelled':
                    handlePaymentCancelled();
                    break;
                    
                case 'payment_error':
                    handlePaymentError(data.error);
                    break;
                    
                case 'resize_iframe':
                    handleResizeIframe(data.height);
                    break;
            }
        });
        
        debug('Message listener set up for iframe communication');
    }
    
    /**
     * Handle button loaded event
     */
    function handleButtonLoaded() {
        debug('PayPal Express button loaded in iframe');
    }
    
    /**
     * Handle button clicked event - Create a WooCommerce order
     */
    function handleButtonClicked() {
        debug('PayPal Express button clicked');
        
        if (expressCreating) {
            debug('Already creating order, ignoring click');
            return;
        }
        
        expressCreating = true;
        showSpinner();
        
        // Create an order via AJAX
        $.ajax({
            url: wpppc_express.ajax_url,
            type: 'POST',
            data: {
                action: 'wpppc_create_express_order',
                nonce: wpppc_express.nonce
            },
            success: function(response) {
                if (response.success) {
                    debug('Express order created: ' + response.data.order_id);
                    expressOrderId = response.data.order_id;
                    
                    // Send order data to iframe
                    sendMessageToIframe({
                        action: 'create_paypal_order',
                        order_id: response.data.order_id,
                        order_key: response.data.order_key,
                        order_total: response.data.order_total,
                        currency: response.data.currency,
                        api_key: response.data.server.api_key,
                        timestamp: response.data.security.timestamp,
                        hash: response.data.security.hash,
                        proxy_data: response.data.proxy_data
                    });
                } else {
                    expressCreating = false;
                    hideSpinner();
                    showError(response.data.message || 'Failed to create order');
                }
            },
            error: function(xhr, status, error) {
                expressCreating = false;
                hideSpinner();
                showError('Error creating order: ' + error);
            }
        });
    }
    
    /**
 * Handle shipping address update from PayPal
 */
function handleShippingAddressUpdate(addressData) {
    debug('Shipping address updated from PayPal: ' + JSON.stringify(addressData));
    
    if (!expressOrderId) {
        debug('No order ID available for shipping update');
        return;
    }
    
    // Get the address from PayPal format
    var shippingAddress = {};
    
    if (addressData && addressData.shipping_address) {
        var paypalAddress = addressData.shipping_address;
        
        // PayPal sometimes provides only partial address info
        // We need to handle this gracefully
        shippingAddress = {
            // Set defaults or use empty values for missing fields
            first_name: 'PayPal',
            last_name: 'Customer',
            address_1: paypalAddress.address_line_1 || '',
            address_2: paypalAddress.address_line_2 || '',
            city: paypalAddress.city || paypalAddress.admin_area_2 || '',
            state: paypalAddress.state || paypalAddress.admin_area_1 || '',
            postcode: paypalAddress.postal_code || '',
            country: paypalAddress.country_code || ''
        };
        
        // Some PayPal responses use different field names
        if (!shippingAddress.address_1 && paypalAddress.recipient_name) {
            // Try to extract name from recipient if available
            var nameParts = paypalAddress.recipient_name.split(' ');
            if (nameParts.length > 0) shippingAddress.first_name = nameParts[0];
            if (nameParts.length > 1) shippingAddress.last_name = nameParts.slice(1).join(' ');
        }
    }
    
    // Add payer email if available
    if (addressData && addressData.payer && addressData.payer.email_address) {
        shippingAddress.email = addressData.payer.email_address;
    }
    
    // Add payer phone if available
    if (addressData && addressData.payer && addressData.payer.phone && 
        addressData.payer.phone.phone_number && 
        addressData.payer.phone.phone_number.national_number) {
        shippingAddress.phone = addressData.payer.phone.phone_number.national_number;
    }
    
    debug('Formatted shipping address: ' + JSON.stringify(shippingAddress));
    
    // Only proceed if we have at least some address data
    if (Object.keys(shippingAddress).length === 0 || 
        (!shippingAddress.city && !shippingAddress.country && !shippingAddress.postcode)) {
        debug('No usable address data available');
        return;
    }
    
    // Update shipping methods via AJAX
    $.ajax({
        url: wpppc_express.ajax_url,
        type: 'POST',
        data: {
            action: 'wpppc_update_express_shipping',
            nonce: wpppc_express.nonce,
            order_id: expressOrderId,
            shipping_address: shippingAddress
        },
        success: function(response) {
            if (response.success) {
                debug('Shipping methods updated');
                
                // Send shipping options to iframe
                sendMessageToIframe({
                    action: 'shipping_options_updated',
                    order_id: expressOrderId,
                    order_total: response.data.order_total,
                    shipping_options: response.data.shipping_methods,
                    selected_option_id: response.data.selected_method
                });
            } else {
                showError(response.data.message || 'Failed to update shipping options');
            }
        },
        error: function(xhr, status, error) {
            showError('Error updating shipping: ' + error);
        }
    });
}
    
    /**
     * Handle shipping method selected
     */
    function handleShippingMethodSelected(data) {
        debug('Shipping method selected: ' + JSON.stringify(data));
        
        // This would update the order with the selected shipping method
        // But we're handling this server-side in our implementation
    }
    
    /**
     * Handle payment approved
     */
    function handlePaymentApproved(data) {
        debug('Payment approved from PayPal: ' + JSON.stringify(data));
        
        if (expressCompleting) {
            debug('Already completing order, ignoring duplicate approval');
            return;
        }
        
        if (!expressOrderId) {
            showError('No order ID available for payment completion');
            return;
        }
        
        expressCompleting = true;
        showSpinner();
        
        // Store PayPal order ID
        paypalExpressOrderId = data.orderID;
        
        // Complete the order via AJAX
        $.ajax({
            url: wpppc_express.ajax_url,
            type: 'POST',
            data: {
                action: 'wpppc_complete_express_order',
                nonce: wpppc_express.nonce,
                order_id: expressOrderId,
                paypal_order_id: data.orderID,
                transaction_id: data.transactionID || '',
                payer: data.payer || {},
                shipping_address: data.shipping_address || {}
            },
            success: function(response) {
                if (response.success) {
                    debug('Order completed successfully, redirecting to: ' + response.data.redirect);
                    
                    // Redirect to thank you page
                    window.location.href = response.data.redirect;
                } else {
                    expressCompleting = false;
                    hideSpinner();
                    showError(response.data.message || 'Failed to complete payment');
                }
            },
            error: function(xhr, status, error) {
                expressCompleting = false;
                hideSpinner();
                showError('Error completing payment: ' + error);
            }
        });
    }
    
    /**
     * Handle payment cancelled
     */
    function handlePaymentCancelled() {
        debug('Payment cancelled by user');
        
        // Reset order flags
        expressCreating = false;
        expressCompleting = false;
        hideSpinner();
        
        showMessage('Payment cancelled. You can try again when you\'re ready.');
    }
    
    /**
     * Handle payment error
     */
    function handlePaymentError(error) {
        debug('Payment error from PayPal: ' + JSON.stringify(error));
        
        // Reset order flags
        expressCreating = false;
        expressCompleting = false;
        hideSpinner();
        
        showError('PayPal error: ' + (error.message || 'Unknown error'));
    }
    
    /**
     * Handle iframe resize
     */
    function handleResizeIframe(height) {
        if (height && !isNaN(height)) {
            debug('Resizing iframe to height: ' + height);
            $('#paypal-express-iframe').css('height', height + 'px');
        }
    }
    
    /**
     * Send message to iframe
     */
    function sendMessageToIframe(message) {
        const iframe = document.getElementById('paypal-express-iframe');
        if (!iframe || !iframe.contentWindow) {
            debug('Cannot find PayPal Express iframe');
            return;
        }
        
        // Add source identifier
        message.source = 'woocommerce-site';
        
        debug('Sending message to iframe: ' + JSON.stringify(message));
        
        try {
            // Send message - using wildcard origin for development
            iframe.contentWindow.postMessage(message, '*');
            debug('Message sent successfully to iframe');
        } catch (error) {
            debug('Error sending message to iframe: ' + error.message);
        }
    }
    
    /**
     * Show error message
     */
    function showError(message) {
        debug('Showing error: ' + message);
        $('#wpppc-express-error').text(message).show();
        $('#wpppc-express-message').hide();
    }
    
    /**
     * Show message
     */
    function showMessage(message) {
        debug('Showing message: ' + message);
        $('#wpppc-express-message').text(message).show();
        $('#wpppc-express-error').hide();
    }
    
    /**
     * Show spinner
     */
    function showSpinner() {
        $('#wpppc-express-spinner').show();
    }
    
    /**
     * Hide spinner
     */
    function hideSpinner() {
        $('#wpppc-express-spinner').hide();
    }
    
    /**
     * Debug logging
     */
    function debug(message) {
        if (wpppc_express.debug_mode === 'yes') {
            console.log('[PayPal Express] ' + message);
        }
    }
    
    // Initialize on document ready
    $(document).ready(function() {
        // Only initialize on cart or checkout pages
        if (wpppc_express.is_cart === 'yes' || wpppc_express.is_checkout === 'yes') {
            initExpressCheckout();
        }
    });
    
})(jQuery);