<?php

declare(strict_types=1);

namespace Sattleme\Payment\Plugin;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use Sattleme\Payment\Model\AttemptRepository;
use Sattleme\Payment\Model\CancellationRefused;
use Sattleme\Payment\Model\ClockInterface;
use Sattleme\Payment\Model\OrderMarks;
use Sattleme\Payment\Model\Orders;

/**
 * Keeps an order from being cancelled while a bitcoin payment may still come, and once one came.
 *
 * Magento's cron of orders left unpaid (sales_clean_orders), an admin, the customer (Magento's
 * order cancellation, which CustomerCancelGuard keeps to this rule too) and the REST API cancel
 * through Order::canCancel(), then Order::cancel(), which asks it again. It answers no:
 *
 * - while the order's invoice may still be paid, or its payment be found or confirmed (an
 *   on-chain payment's confirmations), whoever asks: a Lightning invoice cannot be revoked, and an
 *   order cancelled meanwhile could be paid all the same; and while a request is making its
 *   invoice, a first one or one asked again for an attempt that failed (OrderMarks: for the SDK
 *   call's limit at most, and its margin, by the database's clock, whatever lock provider Magento
 *   uses, so that a request that died keeps no order from being cancelled longer);
 * - once bitcoin came for the order (paid, or an underpayment, which reached the merchant's
 *   wallet, or a payment the checks gave up on), but in the admin: the merchant settles such an
 *   order (on hold, then released: back to Pending Payment, where Magento's cron would cancel it
 *   once its lifetime is over), and may cancel it then, never the cron nor the customer.
 *
 * Once an invoice ended with nothing received, the order can be cancelled again.
 *
 * Order::canCancel() is also asked to show a button: it marks nothing. Order::registerCancellation(),
 * where every cancellation is carried out, marks it first (OrderMarks::startCanceling()), until the
 * order is saved, which the callers do after it: no invoice is made meanwhile. When an invoice is
 * being made, it is refused (CancellationRefused) before the order changes.
 */
class OrderCancelGuard
{
    /**
     * Whether Order::canCancel() is asked for Magento's own answer (magentoCanCancel()).
     *
     * @var bool
     */
    private bool $magentosOwn = false;

    /**
     * @param AttemptRepository $attempts The attempts.
     * @param ClockInterface $clock The clock.
     * @param Orders $orders The orders.
     * @param State $state Magento's application state (its area).
     * @param OrderMarks $marks What is under way for an order.
     */
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly ClockInterface $clock,
        private readonly Orders $orders,
        private readonly State $state,
        private readonly OrderMarks $marks
    ) {
    }

    /**
     * Order::canCancel(), false while the order's invoice is live.
     *
     * And, but in the admin, once bitcoin came for it.
     *
     * @param Order $order The order.
     * @param bool $result What Magento found.
     * @return bool
     */
    public function afterCanCancel(Order $order, bool $result): bool
    {
        if (!$result || $this->magentosOwn || !$this->isOurOrder($order)) {
            return $result;
        }

        return $this->refusal($order) === null;
    }

    /**
     * Order::cancel(), refused when Magento would cancel the order but this module refuses it.
     *
     * Refused with CancellationRefused, before the order changes: Magento's own Order::cancel()
     * then cancels nothing and says nothing, and its callers would go on as if it had (Magento's
     * order cancellation by the customer would send its e-mail).
     *
     * @param Order $order The order.
     * @param callable $proceed Order::cancel().
     * @phpstan-param callable(): Order $proceed
     * @return Order
     * @throws CancellationRefused When this module refuses the cancellation.
     */
    public function aroundCancel(Order $order, callable $proceed): Order
    {
        $this->refuse($order, false);

        return $proceed();
    }

    /**
     * Order::registerCancellation(), its cancellation marked first.
     *
     * No invoice is made until the order is saved. Where Magento carries every cancellation out:
     * Order::cancel() calls it, and so do PayPal's paths that cancel an order without
     * Order::cancel(). Refused (CancellationRefused, before the order changes) as Order::cancel()
     * is, and when it cannot be marked: its invoice is being made.
     *
     * @param Order $order The order.
     * @param callable $proceed Order::registerCancellation().
     * @param mixed $arguments Its arguments (the comment, graceful).
     * @phpstan-param callable(mixed ...): Order $proceed
     * @return Order
     * @throws CancellationRefused When this module refuses the cancellation.
     */
    public function aroundRegisterCancellation(Order $order, callable $proceed, mixed ...$arguments): Order
    {
        $this->refuse($order, true);

        return $proceed(...$arguments);
    }

    /**
     * Throws CancellationRefused when Magento would cancel $order but this module refuses it; with
     * $mark, marks its cancellation as carried out first (OrderMarks), or refuses it when it cannot.
     *
     * @param Order $order The order.
     * @param bool $mark Whether the cancellation is carried out now.
     * @return void
     * @throws CancellationRefused When this module refuses the cancellation.
     */
    private function refuse(Order $order, bool $mark): void
    {
        if (!$this->isOurOrder($order) || !$this->magentoCanCancel($order)) {
            return; // Not this method's, or Magento's own answer: no.
        }
        $refusal = $this->refusal($order);
        if ($refusal === null && $mark && !$this->marks->startCanceling($this->orders->idOf($order))) {
            $refusal = __(
                'This order\'s bitcoin invoice is being made: it cannot be cancelled now. Try again in a minute.'
            );
        }
        if ($refusal !== null) {
            throw new CancellationRefused($refusal);
        }
    }

    /**
     * Why this module refuses to cancel $order, or null when it does not.
     *
     * @param Order $order The order, this method's.
     * @return Phrase|null
     */
    private function refusal(Order $order): ?Phrase
    {
        $orderId = $this->orders->idOf($order);
        if ($this->marks->isBeingMade($orderId)) {
            return __(
                'This order\'s bitcoin invoice is being made: it cannot be cancelled now. Try again in a minute.'
            );
        }
        if ($this->attempts->liveForOrder($orderId, $this->clock->now()) !== null) {
            return __('A bitcoin payment of this order may still come: it cannot be cancelled now.');
        }
        if (!$this->isAdmin() && $this->attempts->hasComeForOrder($orderId)) {
            return __('Bitcoin came for this order: only the shop can cancel it.');
        }

        return null;
    }

    /**
     * Whether Magento, this module aside, would cancel $order (its own Order::canCancel()).
     *
     * @param Order $order The order.
     * @return bool
     */
    private function magentoCanCancel(Order $order): bool
    {
        $this->magentosOwn = true;
        try {
            return $order->canCancel();
        } finally {
            $this->magentosOwn = false;
        }
    }

    /**
     * Whether $order is this method's, and saved.
     *
     * @param Order $order The order.
     * @return bool
     */
    private function isOurOrder(Order $order): bool
    {
        return $this->orders->isOurs($order) && $this->orders->idOf($order) !== 0;
    }

    /**
     * Whether this request is the admin's (its area, adminhtml).
     *
     * @return bool
     */
    private function isAdmin(): bool
    {
        try {
            return $this->state->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (LocalizedException) {
            return false; // No area: never the admin.
        }
    }
}
