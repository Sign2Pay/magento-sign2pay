'use strict';

(function($) {

    var throwError = function(msg) {
        throw new Error('Sign2Pay: ' + msg);
    }

    var Sign2Pay = (function (settings) {

        /**
         * Sign2Pay constructor
         *
         * @param object settings
         */

        function Sign2Pay(settings) {
            this.merchantId = settings.merchantId || throwError('No merchant id');
            this.token = settings.token || throwError('No token');
            this.baseUrl = settings.baseUrl || throwError('No base url');

            // Remove protocol from base url
            this.baseUrl = this.baseUrl.replace(/^http:/, '');

            if (settings.initialize) this.initializePayment();
        }

        /**
         * @var Default options
         */

        Sign2Pay.prototype.defaultOptions = {
            domain: "sign2pay.com",
            el: "#sign2pay"
        }

        /**
         * Initialize Sign2Pay transport with given options
         *
         * @param object options
         */
        Sign2Pay.prototype.initTransport = function(options) {
            window.sign2PayOptions = options;

            if (!this.scriptAttached) {
                this.scriptAttached = true;
                $('head').append('<script type="text/javascript" src="https://sign2pay.com/merchant.js" async></script>');
            }
        }

        /**
         * Fetches all payment related options
         *
         * @param function callback
         */

        Sign2Pay.prototype.fetchPaymentOptions = function(callback) {
            var self = this;

            $.ajax(this.baseUrl + 'sign2pay/payment/fetchPaymentOptions', {
                type: 'POST',
                dataType: 'json',
                success: function(options) {
                    var options = $.extend(self.defaultOptions, options, {
                        merchant_id: self.merchantId,
                        token: self.token
                    });

                    callback(options);
                },
                error: function(err) {
                    console.log(err);
                    throwError('Could not fetch payment options');
                }
            });
        }

        /**
         * Perform riskAssessment
         */

        Sign2Pay.prototype.riskAssessment = function() {
            var self = this;

            var callback = function(options) {
                options['success'] = function() {
                    // Disable the Sign2Pay payment method
                    $('input[name="payment[method]"][value="sign2pay"]').removeAttr('disabled');
                }

                self.initTransport(options);
            }

            this.fetchPaymentOptions(callback);
        };

        /**
         * Initialize payment
         */

        Sign2Pay.prototype.initializePayment = function() {
            var self = this;

            var callback = function(options) {
                options['launch'] = "on_load";
                options['map'] = {};
                options['success']  = function() {
                    $(".s2p-button-text").addClass("button btn-cart").html("Pay with Sign2Pay");
                    $(".loading").hide();
                };
                options['error']  = function() {
                    alert('There was a problem during Sign2Pay initialization. Your ref_id is ' + options['ref_id'] + '.');
                    $(".loading").hide();
                };
                options['close'] = function() {
                    window.location = self.baseUrl + "sign2pay/payment/cancel";
                }

                self.initTransport(options);
            }

            this.fetchPaymentOptions(callback);
        }

        return Sign2Pay;
    })();

    $(window).load(function() {
        if (!s2pOptions || !s2pOptions['merchantId'] || !s2pOptions['token']) {
            throwError('The Sign2Pay Module is enabled, but you are missing required settings.');
        } else {
            window.sign2pay = new Sign2Pay(s2pOptions);
        }
    });

    window.initializeRiskAssessment = function() {
        var interval;
        interval = setInterval(function() {
            if (!window.sign2pay) return;
            clearInterval(interval);

            // Perform risk assessment
            window.sign2pay.riskAssessment();

            // Disable the Sign2Pay payment method
            $('input[name="payment[method]"][value="sign2pay"]').attr('disabled', 'disabled');
        });
    };

})(jQuery.noConflict());
