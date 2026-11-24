<?php

declare(strict_types=1);

namespace Sattleme\Payment\Gateway\Command;

use Magento\Framework\DataObject;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Sattleme\Payment\Model\PageAccess;

/**
 * The payment's "initialize" command, run as the order is placed (Payment::place()): the order
 * waits for its payment (pending_payment, its customer not notified yet: the order's email goes
 * once it is paid), and its payment page gets its secret. No invoice is made here: the order is
 * not saved yet, and the SDK may take seconds; the customer's browser then goes to
 * sattleme/payment/start, which makes it.
 */
class InitializeCommand implements CommandInterface
{
    /**
     * Initializes payments.
     *
     * @param PageAccess $access The pages' secrets.
     */
    public function __construct(private readonly PageAccess $access)
    {
    }

    /**
     * Runs the command, as Payment::place() asks it.
     *
     * @param array<mixed> $commandSubject The payment, the payment action, the state object.
     * @return null
     */
    public function execute(array $commandSubject)
    {
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        $stateObject = $commandSubject['stateObject'] ?? null;
        if (!$stateObject instanceof DataObject) {
            throw new \InvalidArgumentException('The state object must be given');
        }
        $payment->setAdditionalInformation(PageAccess::TOKEN_KEY, $this->access->newToken());
        $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('is_notified', false);

        return null;
    }
}
