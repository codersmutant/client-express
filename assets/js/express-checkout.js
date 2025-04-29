/**
 * WooCommerce PayPal Proxy Client Express Checkout JS
 */

(function($) {
    'use strict';
    
    // Variables to track order status
    var paypalOrderId = null;
    var wcOrderId = null;
    var expressCheckoutActive = false;
    var selectedShippingMethod = null;
    
    /**
     * Debug logging helper
     */
    function debug(message, data) {
        if (wpppc_express_params.debug_mode && console && console.log) {
            if (data) {
                console.log('[PayPal Express]', message, data);
            } else {
                console.log('[PayPal Express]', message);
            }
        }
    }
    
    /**
     * Show loading indicator
     */
    function showLoading(container) {
        var loadingHtml = '<div class="wpppc-express-loading"><div class="wpppc-express-spinner"></div><span>Processing...</span></div>';
        $(container).find('.wpppc-express-loading').remove();
        $(container).append(loadingHtml);
        $(container).find('.wpppc-express-loading').show();
    }
    
    /**
     * Hide loading indicator
     */
    function hideLoading(container) {
        $(container).find('.wpppc-express-loading').hide();
    }
    
    /**
     * Show error message
     */
    function showError(message, container) {
        var targetContainer = container || '';
        
        if (targetContainer) {
            $(targetContainer).find('#wpppc-express-error').text(message).show();
            $(targetContainer).find('#wpppc-express-message').hide();
        } else {
            $('#wpppc-express-error').text(message).show();
            $('#wpppc-express-message').hide();
        }
        
        // Scroll to the message
        $('html, body').animate({
            scrollTop: $(targetContainer || '#wpppc-express-error').offset().top - 100
        }, 300);
        
        debug('Error displayed: ' + message);
    }
    
    /**
     * Show success message
     */
    function showMessage(message, container) {
        var targetContainer = container || '';
        
        if (targetContainer) {
            $(targetContainer).find('#wpppc-express-message').text(message).show();
            $(targetContainer).find('#wpppc-express-error').hide();
        } else {
            $('#wpppc-express-message').text(message).show();
            $('#wpppc-express-error').hide();
        }
        
        debug('Message displayed: ' + message);
    }
    
    /**
     * Creates the express checkout iframe for the PayPal Smart Buttons
     */
    function createExpressButtonIframe(target) {
        debug('Creating Express Checkout button iframe on ' + target);
        
        // Create iframe element
        var iframe = document.createElement('iframe');
        iframe.id = 'paypal-express-iframe-' + target.replace('#', '');
        iframe.src = wpppc_express_params.iframe_url + '&context=' + target.replace('#', '');
        iframe.frameBorder = 0;
        iframe.scrolling = 'no';
        iframe.style.width = '100%';
        iframe.style.minHeight = '45px';
        iframe.style.height = '45px';
        iframe.style.overflow = 'hidden';
        iframe.style.border = 'none';
        
        // Set sandbox attributes for security
        iframe.setAttribute('sandbox', 'allow-scripts allow-forms allow-popups allow-same-origin allow-top-navigation');
        
        // Append iframe to container
        $(target).html('');
        $(target).append(iframe);
        
        // Setup message event listener for iframe communication
        window.addEventListener('message', handleIframeMessages);
        
        debug('Iframe created for Express Checkout button on ' + target);
    }
    
    /**
     * Handle messages from the iframe
     */
    function handleIframeMessages(event) {
        // Validate message
        if (!event.data || !event.data.action || event.data.source !== 'paypal-express-proxy') {
            return;
        }
        
        debug('Received message from iframe:', event.data);
        
        var container = '#wpppc-express-paypal-button-cart';
        if (wpppc_express_params.is_checkout_page) {
            container = '#wpppc-express-paypal-button-checkout';
        }
        
        // Handle different actions
        switch (event.data.action) {
            case 'button_loaded':
                debug('PayPal Express button loaded');
                break;
                
            case 'button_clicked':
                debug('PayPal Express button clicked');
                handleExpressCheckoutStart(container);
                break;
                
            case 'payment_approved':
                debug('Payment approved in PayPal', event.data.payload);
                completeExpressCheckout(event.data.payload, container);
                break;
                
            case 'payment_cancelled':
                debug('Payment cancelled by user');
                showError('Payment cancelled. You can try again when ready.', container);
                expressCheckoutActive = false;
                break;
                
            case 'payment_error':
                debug('Payment error:', event.data.error);
                showError('Error processing payment: ' + (event.data.error.message || 'Unknown error'), container);
                expressCheckoutActive = false;
                break;
                
            case 'resize_iframe':
                // Resize the iframe based on content
                if (event.data.height) {
                    $('#' + event.data.iframeId).css('height', event.data.height + 'px');
                    debug('Resized iframe to ' + event.data.height + 'px');
                }
                break;
                
            case 'shipping_options_needed':
                debug('Shipping options needed for address', event.data.address);
                updateShippingOptions(event.data, container);
                break;
                
            case 'shipping_option_selected':
                debug('Shipping option selected', event.data.selectedOption);
                handleShippingMethodSelected(event.data.selectedOption, container);
                break;
        }
    }
    
    /**
     * Start Express Checkout process
     */
    function handleExpressCheckoutStart(container) {
        if (expressCheckoutActive) {
            debug('Express checkout already in progress, ignoring click');
            return;
        }
        
        expressCheckoutActive = true;
        showLoading(container);
        
        debug('Starting Express Checkout process');
        
        // Create WooCommerce order via AJAX
        $.ajax({
            url: wpppc_express_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wpppc_create_express_order',
                nonce: wpppc_express_params.nonce
            },
            success: function(response) {
                if (response.success) {
                    wcOrderId = response.data.order_id;
                    paypalOrderId = response.data.paypal_order_id;
                    
                    debug('Express order created. WC Order ID: ' + wcOrderId + ', PayPal Order ID: ' + paypalOrderId);
                    
                    // Send order data to iframe
                    sendMessageToIframe({
                        action: 'create_paypal_order',
                        order_id: wcOrderId,
                        paypal_order_id: paypalOrderId
                    });
                    
                    hideLoading(container);
                } else {
                    expressCheckoutActive = false;
                    hideLoading(container);
                    showError(response.data.message || 'Failed to create order', container);
                }
            },
            error: function() {
                expressCheckoutActive = false;
                hideLoading(container);
                showError('Error communicating with the server', container);
            }
        });
    }
    
    /**
     * Update shipping options based on selected address
     */
    function updateShippingOptions(data, container) {
        debug('Updating shipping options for address', data.address);
        
        // Show loading indicator
        showLoading(container);
        
        // Get shipping address from data
        var shippingAddress = data.address;
        
        // Send message to indicate we're processing
        sendMessageToIframe({
            action: 'shipping_update_processing'
        });
        
        // Call server to get shipping options
        $.ajax({
            url: wpppc_express_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wpppc_update_shipping_methods',
                nonce: wpppc_express_params.nonce,
                order_id: wcOrderId,
                paypal_order_id: paypalOrderId,
                shipping_address: shippingAddress
            },
            success: function(response) {
                hideLoading(container);
                
                if (response.success) {
                    debug('Shipping options received', response.data);
                    
                    // Send shipping options to iframe
                    sendMessageToIframe({
                        action: 'shipping_options_available',
                        shipping_options: response.data.shipping_options
                    });
                } else {
                    debug('Error getting shipping options', response.data);
                    
                    // Send error to iframe
                    sendMessageToIframe({
                        action: 'shipping_options_error',
                        message: response.data.message || 'No shipping options available for this address'
                    });
                    
                    showError(response.data.message || 'No shipping options available for this address', container);
                }
            },
            error: function() {
                hideLoading(container);
                
                // Send error to iframe
                sendMessageToIframe({
                    action: 'shipping_options_error',
                    message: 'Error communicating with the server'
                });
                
                showError('Error communicating with the server', container);
            }
        });
    }
    
    /**
     * Handle shipping method selection
     */
    function handleShippingMethodSelected(shippingMethod, container) {
        debug('Shipping method selected', shippingMethod);
        
        selectedShippingMethod = shippingMethod;
        
        // Update order with selected shipping method
        $.ajax({
            url: wpppc_express_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wpppc_update_shipping_methods',
                nonce: wpppc_express_params.nonce,
                order_id: wcOrderId,
                paypal_order_id: paypalOrderId,
                shipping_method: shippingMethod
            },
            success: function(response) {
                if (response.success) {
                    debug('Shipping method updated successfully');
                    
                    // Notify iframe of success
                    sendMessageToIframe({
                        action: 'shipping_method_updated'
                    });
                } else {
                    debug('Error updating shipping method', response.data);
                    
                    // Notify iframe of error
                    sendMessageToIframe({
                        action: 'shipping_method_error',
                        message: response.data.message || 'Failed to update shipping method'
                    });
                    
                    showError(response.data.message || 'Failed to update shipping method', container);
                }
            },
            error: function() {
                debug('AJAX error updating shipping method');
                
                // Notify iframe of error
                sendMessageToIframe({
                    action: 'shipping_method_error',
                    message: 'Error communicating with the server'
                });
                
                showError('Error communicating with the server', container);
            }
        });
    }
    
    /**
     * Complete Express Checkout after payment approval
     */
    function completeExpressCheckout(paymentData, container) {
        debug('Completing express checkout with payment data', paymentData);
        
        // Show loading indicator
        showLoading(container);
        showMessage('Finalizing your order...', container);
        
        // Complete the order via AJAX
        $.ajax({
            url: wpppc_express_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wpppc_complete_express_order',
                nonce: wpppc_express_params.nonce,
                order_id: wcOrderId,
                paypal_order_id: paypalOrderId
            },
            success: function(response) {
                hideLoading(container);
                
                if (response.success) {
                    debug('Order completed successfully, redirecting to:', response.data.redirect);
                    
                    // Show success message before redirect
                    showMessage('Payment successful! Redirecting to order confirmation...', container);
                    
                    // Redirect to thank you page
                    setTimeout(function() {
                        window.location.href = response.data.redirect;
                    }, 1000);
                } else {
                    expressCheckoutActive = false;
                    showError(response.data.message || 'Failed to complete order', container);
                }
            },
            error: function() {
                expressCheckoutActive = false;
                hideLoading(container);
                showError('Error communicating with the server', container);
            }
        });
    }
    
    /**
     * Send message to the iframe
     */
    function sendMessageToIframe(message) {
        var iframe;
        
        if (wpppc_express_params.is_checkout_page) {
            iframe = document.getElementById('paypal-express-iframe-wpppc-express-paypal-button-checkout');
        } else {
            iframe = document.getElementById('paypal-express-iframe-wpppc-express-paypal-button-cart');
        }
        
        if (!iframe || !iframe.contentWindow) {
            debug('Cannot find PayPal Express iframe');
            return;
        }
        
        // Add source identifier
        message.source = 'woocommerce-client';
        
        debug('Sending message to iframe', message);
        
        // Send message to iframe
        iframe.contentWindow.postMessage(message, '*');
    }
    
    /**
     * Initialize Express Checkout
     */
    function initExpressCheckout() {
        debug('Initializing PayPal Express Checkout');
        
        // Create express checkout buttons
        if (wpppc_express_params.is_cart_page) {
            createExpressButtonIframe('#wpppc-express-paypal-button-cart');
        }
        
        if (wpppc_express_params.is_checkout_page) {
            createExpressButtonIframe('#wpppc-express-paypal-button-checkout');
        }
    }
    
    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Initialize only if we have PayPal button containers
        if ($('.wpppc-express-paypal-button').length > 0) {
            initExpressCheckout();
        } else {
            debug('No PayPal Express button containers found');
        }
    });
    
})(jQuery);