<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;

/**
 * A customer's personal data in the module's records, for the merchant to answer a request
 * (Magento Open Source has no hooks of its own for exporting or erasing personal data): the
 * bitcoin payments of their orders, found by the order's number or the customer's email address.
 * Erasing removes what leads to the customer's coins on the blockchain (the deposit address and
 * the transactions); the references, amounts, rates, dates and states stay, for the merchant's
 * accounting, as the Lightning invoices and their proofs, which name the merchant's node.
 */
class Privacy
{
    /**
     * @param CollectionFactory $collections Magento's order collections.
     * @param AttemptRepository $attempts The attempts.
     * @param Orders $ordersHelper The orders' ids.
     */
    public function __construct(
        private readonly CollectionFactory $collections,
        private readonly AttemptRepository $attempts,
        private readonly Orders $ordersHelper
    ) {
    }

    /**
     * The orders of $email (their customer's email address), or the order numbered $number.
     *
     * @param string|null $email The email address.
     * @param string|null $number The order's number.
     * @return list<Order>
     */
    public function orders(?string $email, ?string $number): array
    {
        $collection = $this->collections->create();
        if ($number !== null && $number !== '') {
            $collection->addFieldToFilter('increment_id', $number);
        } elseif ($email !== null && $email !== '') {
            $collection->addFieldToFilter('customer_email', $email);
        } else {
            return [];
        }
        $orders = [];
        foreach ($collection as $order) {
            if ($order instanceof Order) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /**
     * The bitcoin payments of $orders, as stored.
     *
     * @param list<Order> $orders The orders.
     * @return list<array<string, mixed>>
     */
    public function export(array $orders): array
    {
        $export = [];
        foreach ($orders as $order) {
            foreach ($this->attempts->forOrder($this->ordersHelper->idOf($order)) as $attempt) {
                $export[] = [
                    'order' => $order->getIncrementId(),
                    'reference' => $attempt->ref,
                    'createdAt' => $attempt->createdAt,
                    'invoice' => $attempt->invoice,
                    'status' => $attempt->status,
                    'outcome' => $attempt->outcome,
                ];
            }
        }

        return $export;
    }

    /**
     * Erases what leads to the customer's coins from the payments of $orders.
     *
     * Now for those final, once final for the others. How many lost it now.
     *
     * @param list<Order> $orders The orders.
     * @return int
     */
    public function erase(array $orders): int
    {
        $count = 0;
        foreach ($orders as $order) {
            $count += $this->attempts->shredOrder($this->ordersHelper->idOf($order));
        }

        return $count;
    }
}
