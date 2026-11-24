<?php

declare(strict_types=1);

namespace Sattleme\Payment\Plugin;

use Magento\Sales\Api\OrderManagementInterface;
use Sattleme\Payment\Model\CancellationRefused;

/**
 * Magento's OrderService (OrderManagementInterface::cancel(): the admin's cancel and mass cancel,
 * the cron of orders left unpaid, the REST API) answers false when OrderCancelGuard refuses the
 * cancellation as it is carried out: its interface's answer for an order not cancelled, so that
 * the mass cancel counts it so and the cron goes on with the other orders. Its transaction is
 * rolled back, the order unchanged.
 */
class OrderManagementCancelGuard
{
    /**
     * OrderManagementInterface::cancel(), false when the cancellation is refused.
     *
     * @param OrderManagementInterface $subject Magento's order management.
     * @param callable $proceed Its cancel().
     * @param int $id The order.
     * @phpstan-param callable(int): bool $proceed
     * @return bool
     */
    public function aroundCancel(OrderManagementInterface $subject, callable $proceed, $id): bool
    {
        try {
            return $proceed($id);
        } catch (CancellationRefused) {
            return false;
        }
    }
}
