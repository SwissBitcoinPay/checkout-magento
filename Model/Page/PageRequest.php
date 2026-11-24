<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model\Page;

use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\Order;
use Sattleme\Payment\Model\Orders;
use Sattleme\Payment\Model\PageAccess;

/**
 * The order a request of the payment page is for: its "order" (the order's id) and "token" (its
 * page's secret) parameters, the order paid with this method, the secret compared in constant
 * time. Anything else is no order: the page is not found.
 */
class PageRequest
{
    /**
     * @param Orders $orders The orders.
     * @param PageAccess $access The pages' secrets.
     */
    public function __construct(
        private readonly Orders $orders,
        private readonly PageAccess $access
    ) {
    }

    /**
     * The order $request is for, or null.
     *
     * @param RequestInterface $request The request.
     * @return Order|null
     */
    public function order(RequestInterface $request): ?Order
    {
        $id = $request->getParam('order');
        $token = $request->getParam('token');
        if (!is_string($id) || preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1 || !is_string($token)) {
            return null;
        }
        $order = $this->orders->fresh((int) $id);

        return $order !== null && $this->access->opens($order, $token) ? $order : null;
    }
}
