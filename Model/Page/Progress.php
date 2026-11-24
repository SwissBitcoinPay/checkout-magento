<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model\Page;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderMutexInterface;
use Psr\Log\LoggerInterface;
use Sattleme\Payment\Model\AttemptRepository;
use Sattleme\Payment\Model\Checker;
use Sattleme\Payment\Model\ClockInterface;
use Sattleme\Payment\Model\CancellationRefused;
use Sattleme\Payment\Model\LockUnavailable;
use Sattleme\Payment\Model\Notes;
use Sattleme\Payment\Model\OrderLock;
use Sattleme\Payment\Model\Orders;
use Sattleme\Payment\Model\PagePhase;
use Sattleme\Payment\Model\Schedule;

/**
 * What the payment page asks of an order's payment: its phase, a check of it (at most every
 * Schedule::WEB_INTERVAL seconds per invoice, whatever the requests), and leaving the method.
 */
class Progress
{
    /**
     * A check a page asks for, in seconds (the request waits for it).
     */
    public const CHECK_TIMEOUT = 15;

    /**
     * @param AttemptRepository $attempts The attempts.
     * @param Checker $checker The checks.
     * @param Orders $orders The orders, fresh.
     * @param OrderLock $lock The order's lock.
     * @param OrderMutexInterface $orderMutex Magento's row lock of an order.
     * @param OrderRepositoryInterface $orderRepository Magento's orders.
     * @param Notes $notes The notes, in the store's language.
     * @param PagePhase $phases The payments' phases.
     * @param ClockInterface $clock The clock.
     * @param LoggerInterface $logger The module's log.
     */
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly Checker $checker,
        private readonly Orders $orders,
        private readonly OrderLock $lock,
        private readonly OrderMutexInterface $orderMutex,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Notes $notes,
        private readonly PagePhase $phases,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The phase of $order's payment (PagePhase).
     *
     * @param Order $order The order.
     * @return string
     */
    public function phase(Order $order): string
    {
        $orderId = $this->orders->idOf($order);
        $now = $this->clock->now();

        return $this->phases->of(
            (string) $order->getState(),
            $this->attempts->latestForOrder($orderId),
            $this->attempts->liveForOrder($orderId, $now),
            $now
        );
    }

    /**
     * Checks the payment of $order's live invoice now (unless checked a moment ago): its phase.
     *
     * @param Order $order The order.
     * @return string
     */
    public function check(Order $order): string
    {
        $orderId = $this->orders->idOf($order);
        $live = $this->attempts->liveForOrder($orderId, $this->clock->now());
        if ($live !== null && $live->hasInvoice()) {
            $this->checker->check($live->id, Schedule::WEB_INTERVAL, self::CHECK_TIMEOUT);
        }

        return $this->phase($this->orders->fresh($orderId) ?? $order);
    }

    /**
     * The customer leaves the method for another one.
     *
     * $order is cancelled, when no invoice of it may be paid (PagePhase::canLeave). Whether it
     * was.
     *
     * @param Order $order The order.
     * @return bool
     */
    public function leave(Order $order): bool
    {
        $orderId = $this->orders->idOf($order);
        try {
            return $this->lock->run(
                $orderId,
                10,
                fn(): bool => (bool) $this->orderMutex->execute($orderId, fn(): bool => $this->cancel($orderId))
            );
        } catch (LockUnavailable) {
            return false;
        }
    }

    /**
     * Cancels order $orderId, its locks held, when it may be.
     *
     * @param int $orderId The order.
     * @return bool
     */
    private function cancel(int $orderId): bool
    {
        $order = $this->orders->fresh($orderId);
        if ($order === null || !$this->phases->canLeave($this->phase($order)) || !$order->canCancel()) {
            return false;
        }
        try {
            $order->cancel();
        } catch (CancellationRefused) {
            return false; // Its invoice is being made (OrderCancelGuard): the page stays.
        }
        $order->addCommentToStatusHistory(
            $this->notes->render(
                (int) $order->getStoreId(),
                __('The customer chose another payment method: the order was cancelled.')
            ),
            false,
            false
        );
        $this->orderRepository->save($order);
        $this->logger->info(sprintf('Order %s: the customer chose another payment method', $order->getIncrementId()));

        return true;
    }
}
