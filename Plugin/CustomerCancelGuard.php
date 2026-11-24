<?php

declare(strict_types=1);

namespace Sattleme\Payment\Plugin;

use Magento\OrderCancellation\Model\CustomerCanCancel;
use Magento\Sales\Model\Order;
use Sattleme\Payment\Model\Orders;

/**
 * Magento's order cancellation by the customer (Magento_OrderCancellation, "Order Cancellation"
 * in the sales settings: the button of the customer's account, the GraphQL mutations cancelOrder
 * and requestGuestOrderCancel) asks CustomerCanCancel, which reads the order's state only, and then
 * cancels an order not paid with Order::cancel(), but has an order paid refunded offline: never
 * Order::canCancel(). For an order of this method, it answers as Order::canCancel() does
 * (OrderCancelGuard): no while a bitcoin payment may still come, nor once one came. The button is
 * then not shown, and the mutations refuse.
 */
class CustomerCancelGuard
{
    /**
     * @param Orders $orders The orders.
     */
    public function __construct(private readonly Orders $orders)
    {
    }

    /**
     * CustomerCanCancel::execute(), and Order::canCancel() for an order of this method.
     *
     * @param CustomerCanCancel $subject Magento's check.
     * @param bool $result What it found.
     * @param Order $order The order.
     * @return bool
     */
    public function afterExecute(CustomerCanCancel $subject, bool $result, Order $order): bool
    {
        if (!$result || !$this->orders->isOurs($order)) {
            return $result;
        }

        return $order->canCancel();
    }
}
