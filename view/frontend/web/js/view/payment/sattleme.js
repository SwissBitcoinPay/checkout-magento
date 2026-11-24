/**
 * Adds the method's renderer to the checkout's payment step.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'sattleme',
        component: 'Sattleme_Payment/js/view/payment/method-renderer/sattleme'
    });

    return Component.extend({});
});
