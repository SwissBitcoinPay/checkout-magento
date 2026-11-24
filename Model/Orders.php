<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Sattleme\Payment\Sdk\Prices;

/**
 * Orders as the module reads them: fresh from the database, and what they still owe.
 *
 * Another process may have changed an order since this request loaded it: the module reads it
 * again where that matters (under its lock), never from Magento's repository cache.
 */
class Orders
{
    /**
     * Reads orders.
     *
     * @param CollectionFactory $collections Magento's order collections.
     * @param Prices $prices The prices the SDK takes.
     */
    public function __construct(
        private readonly CollectionFactory $collections,
        private readonly Prices $prices
    ) {
    }

    /**
     * Order $orderId as the database holds it now, or null.
     *
     * @param int $orderId The order.
     * @return Order|null
     */
    public function fresh(int $orderId): ?Order
    {
        $order = $this->collections->create()
            ->addFieldToFilter('entity_id', ['eq' => $orderId])
            ->setPageSize(1)
            ->getFirstItem();

        return $order instanceof Order && $this->idOf($order) === $orderId ? $order : null;
    }

    /**
     * The id of $order (its entity's), 0 for an order not saved.
     *
     * @param Order $order The order.
     * @return int
     */
    public function idOf(Order $order): int
    {
        $id = $order->getEntityId();

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
    }

    /**
     * Whether $order is paid with this method.
     *
     * @param Order $order The order.
     * @return bool
     */
    public function isOurs(Order $order): bool
    {
        $payment = $order->getPayment();

        return $payment !== null && $payment->getMethod() === Config::CODE;
    }

    /**
     * What $order still owes, in its currency, as a decimal string.
     *
     * Its total, less what its other payments paid (invoices paid otherwise) and less what was
     * refunded meanwhile. Null when Magento's amounts are not numbers.
     *
     * @param Order $order The order.
     * @return string|null
     */
    public function owed(Order $order): ?string
    {
        $total = $this->prices->decimal($order->getGrandTotal());
        $paid = $this->prices->decimal($order->getTotalPaid() ?? 0.0);
        $refunded = $this->prices->decimal($order->getTotalRefunded() ?? 0.0);
        if ($total === null || $paid === null || $refunded === null) {
            return null;
        }
        $owed = bcsub(bcsub($total, $paid, 4), $refunded, 4);

        return bccomp($owed, '0', 4) > 0 ? $owed : '0';
    }
}
