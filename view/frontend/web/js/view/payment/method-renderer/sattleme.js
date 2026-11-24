/**
 * The method at checkout. Once the order is placed, the customer goes to its payment page on the
 * shop (sattleme/payment/start makes the invoice): no script or page of another host.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Sattleme_Payment/payment/sattleme'
        },

        redirectAfterPlaceOrder: false,

        /**
         * @return {String} the description the merchant set, else the module's
         */
        getDescription: function () {
            var config = window.checkoutConfig.payment.sattleme || {};

            return config.description || '';
        },

        /**
         * @return {String} the brand's icon
         */
        getIconUrl: function () {
            var config = window.checkoutConfig.payment.sattleme || {};

            return config.iconUrl || '';
        },

        /**
         * To the order's payment page.
         */
        afterPlaceOrder: function () {
            window.location.replace(url.build('sattleme/payment/start'));
        }
    });
});
