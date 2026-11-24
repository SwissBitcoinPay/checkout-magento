<?php

declare(strict_types=1);

namespace Sattleme\Payment\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use Sattleme\Payment\Model\Availability;
use Sattleme\Payment\Model\Config;

/**
 * Hides the method from a cart it cannot pay (payment_method_is_active, which the payment
 * method's facade dispatches when Magento lists the methods of a cart).
 */
class MethodAvailability implements ObserverInterface
{
    /**
     * @param Availability $availability Whether a cart can be paid with bitcoin.
     */
    public function __construct(private readonly Availability $availability)
    {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        $method = $observer->getEvent()->getData('method_instance');
        $result = $observer->getEvent()->getData('result');
        $quote = $observer->getEvent()->getData('quote');
        if (!$method instanceof MethodInterface
            || $method->getCode() !== Config::CODE
            || !$result instanceof DataObject
        ) {
            return;
        }
        if (!(bool) $result->getData('is_available')) {
            return;
        }
        if (!$quote instanceof CartInterface || !$this->availability->isAvailable($quote)) {
            $result->setData('is_available', false);
        }
    }
}
