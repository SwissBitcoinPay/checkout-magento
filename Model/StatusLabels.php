<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;

/**
 * The label Magento shows for an order's status, for the notes (Notes::render()): the label the
 * merchant gave that status in the order's store (Stores → Order Status), else Magento's own
 * label of the status, which the module's dictionaries translate (Magento's statuses as it
 * installs them), else as the merchant named it.
 */
class StatusLabels
{
    /**
     * @param CollectionFactory $statuses Magento's order statuses.
     */
    public function __construct(private readonly CollectionFactory $statuses)
    {
    }

    /**
     * The label of $order's status, in its store.
     *
     * @param Order $order The order.
     * @return Phrase|string
     */
    public function of(Order $order): Phrase|string
    {
        $code = (string) $order->getStatus();
        $status = $this->statuses->create()->addFieldToFilter('status', $code)->getFirstItem();
        if (!$status instanceof Status) {
            return $code;
        }
        $storeLabel = $status->getStoreLabels()[(int) $order->getStoreId()] ?? null;
        if (is_string($storeLabel) && $storeLabel !== '') {
            return $storeLabel;
        }
        $label = $status->getData('label');
        $label = is_string($label) && $label !== '' ? $label : $code;

        return $this->magentos($label) ?? $label;
    }

    /**
     * Magento's own label $label, translated by the module's dictionaries; null for another one.
     *
     * The statuses an order may have once it left Pending Payment and On Hold.
     *
     * @param string $label The label.
     * @return Phrase|null
     */
    private function magentos(string $label): ?Phrase
    {
        return match ($label) {
            'Pending' => __('Pending'),
            'Processing' => __('Processing'),
            'Suspected Fraud' => __('Suspected Fraud'),
            'Payment Review' => __('Payment Review'),
            'Complete' => __('Complete'),
            'Closed' => __('Closed'),
            'Canceled' => __('Canceled'),
            default => null,
        };
    }
}
