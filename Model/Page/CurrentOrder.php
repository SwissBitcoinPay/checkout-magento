<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model\Page;

use Magento\Sales\Model\Order;

/**
 * The order of the payment page being rendered, as its controller authorized it, for the page's
 * view model.
 */
class CurrentOrder
{
    /**
     * @var Order|null
     */
    private ?Order $order = null;

    /**
     * Sets the order.
     *
     * @param Order $order The order.
     * @return void
     */
    public function set(Order $order): void
    {
        $this->order = $order;
    }

    /**
     * The order, or null when no controller authorized one.
     *
     * @return Order|null
     */
    public function get(): ?Order
    {
        return $this->order;
    }
}
