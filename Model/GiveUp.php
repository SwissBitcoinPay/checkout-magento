<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Sattleme\Payment\Sdk\Errors;

/**
 * Gives up on an invoice whose final status is still unknown 90 days after it was made (an
 * on-chain payment made in time, still unconfirmed on a congested network, or checks that kept
 * failing): its checks stop, its order gets a note and is put on hold (Magento's state for an
 * order the merchant must look at: its cron never cancels it), and the merchant is told, to
 * settle it by hand.
 */
class GiveUp
{
    /**
     * @param AttemptRepository $attempts The attempts.
     * @param OrderLock $lock The order's lock.
     * @param Orders $orders The orders, fresh.
     * @param OrderRepositoryInterface $orderRepository Magento's orders.
     * @param Notes $notes The notes, in the store's language.
     * @param NotifierInterface $notifier The admin's notifications.
     * @param LoggerInterface $logger The module's log.
     */
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly OrderLock $lock,
        private readonly Orders $orders,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Notes $notes,
        private readonly NotifierInterface $notifier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Gives up on $attempt: whether this call did.
     *
     * @param Attempt $attempt The attempt.
     * @return bool
     */
    public function attempt(Attempt $attempt): bool
    {
        try {
            return $this->lock->run($attempt->orderId, 10, function () use ($attempt): bool {
                if (!$this->attempts->markFinal($attempt->id, Attempt::OUTCOME_ABANDONED, Errors::ABANDONED)) {
                    return false;
                }
                $this->attempts->shredIfFinal($attempt->id);
                $order = $this->orders->fresh($attempt->orderId);
                if ($order !== null) {
                    $note = $this->notes->render(
                        (int) $order->getStoreId(),
                        __(
                            'The checks of Bitcoin invoice %1 stopped: no final status after 90 days. '
                            . 'Check this payment in the sattle.me app, and settle the order by hand.',
                            $attempt->ref
                        )
                    );
                    $order->addCommentToStatusHistory($note, false, false);
                    if ($order->getState() === Order::STATE_PENDING_PAYMENT && $order->canHold()) {
                        $order->hold();
                    }
                    $this->orderRepository->save($order);
                    $this->notifier->addMajor(
                        (string) __('sattle.me: order %1 needs your attention', $order->getIncrementId()),
                        $note
                    );
                }
                $this->logger->warning(sprintf(
                    'Invoice %s (order %d): no final status after 90 days, checks stopped',
                    $attempt->ref,
                    $attempt->orderId
                ));

                return true;
            });
        } catch (LockUnavailable) {
            return false;
        }
    }
}
