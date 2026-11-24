<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderMutexInterface;
use Psr\Log\LoggerInterface;
use Sattleme\Payment\Vendor\Sattleme\Checkout\PaymentStatus;
use Sattleme\Payment\Sdk\Prices;

/**
 * Stores what a check found and updates the order, through Magento's own paths.
 *
 * The order is locked meanwhile (OrderLock, then Magento's row lock of the order):
 *
 * - paid: the payment is registered as captured (Payment::registerCaptureNotification(), the
 *   attempt's reference as the transaction's id): the order is invoiced and processing, its
 *   emails sent, exactly once; when the invoice's price is not what the order owes then, the
 *   order is put on hold instead, for the merchant to settle;
 * - expired with nothing received: the order is cancelled (its stock freed): the customer can
 *   order again, with another method; with something received (an underpayment, which reached
 *   the merchant's wallet), it is put on hold, for the merchant to settle by hand;
 * - unconfirmed: noted once; the order is kept from being cancelled (OrderCancelGuard);
 * - open: stored (the page shows the rest of an underpayment), an underpayment noted once.
 *
 * The attempt is final only once its order was updated, so that a check that stops half-way is
 * simply done again; a final attempt is never checked again, nor made non-final.
 *
 */
class Settlement
{
    /**
     * Seconds a check waits for the order's lock (another request applying a status).
     */
    public const LOCK_WAIT = 30;

    /**
     * Where the payment keeps the reference of the attempt that paid it.
     */
    public const PAID_REF_KEY = 'sattleme_paid_ref';

    /**
     * Applies statuses.
     *
     * @param AttemptRepository $attempts The attempts.
     * @param OrderLock $lock The order's lock.
     * @param Orders $orders The orders, fresh.
     * @param OrderRepositoryInterface $orderRepository Magento's orders.
     * @param OrderMutexInterface $orderMutex Magento's row lock of an order (its cancellation's).
     * @param OrderSender $orderSender The order's email.
     * @param InvoiceSender $invoiceSender The invoice's email.
     * @param NotifierInterface $notifier The admin's notifications.
     * @param Notes $notes The notes, in the store's language.
     * @param Amounts $amounts Amounts in sats.
     * @param Prices $prices The prices the SDK takes.
     * @param ClockInterface $clock The clock.
     * @param LoggerInterface $logger The module's log.
     */
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly OrderLock $lock,
        private readonly Orders $orders,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderMutexInterface $orderMutex,
        private readonly OrderSender $orderSender,
        private readonly InvoiceSender $invoiceSender,
        private readonly NotifierInterface $notifier,
        private readonly Notes $notes,
        private readonly Amounts $amounts,
        private readonly Prices $prices,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Applies $status, found by a check of $attempt holding it until $lease.
     *
     * @param Attempt $attempt The attempt, as the check read it.
     * @param int $lease The check's lease.
     * @param PaymentStatus $status What it found.
     * @return void
     * @throws LockUnavailable When another request held the order.
     * @throws \Throwable When Magento could not take the update: a later check applies it again.
     */
    public function apply(Attempt $attempt, int $lease, PaymentStatus $status): void
    {
        $this->lock->run(
            $attempt->orderId,
            self::LOCK_WAIT,
            function () use ($attempt, $lease, $status): void {
                if (!$this->attempts->storeStatus($attempt->id, $lease, $status->toArray(), $this->clock->now())) {
                    return; // Final meanwhile, or this check outlived its lease: another one decides.
                }
                $stored = $this->attempts->find($attempt->id);
                if ($stored === null) {
                    return;
                }
                $outcome = $this->settle($stored, $attempt->status, $status);
                // Released only now: no other check settles the order meanwhile.
                $this->attempts->release($attempt->id, $lease, $outcome);
                if ($outcome !== null) {
                    $this->attempts->shredIfFinal($attempt->id);
                }
            }
        );
    }

    /**
     * Updates the order of $attempt to $status: the attempt's outcome when it is final now.
     *
     * @param Attempt $attempt The attempt, its new status stored.
     * @param array<string,mixed>|null $previous The status it had before.
     * @param PaymentStatus $status The status found.
     * @return string|null
     */
    public function settle(Attempt $attempt, ?array $previous, PaymentStatus $status): ?string
    {
        $order = $this->orders->fresh($attempt->orderId);
        if ($order === null) {
            // The order was deleted: nothing to update, the attempt's outcome stays recorded.
            $this->logger->warning(sprintf(
                'Invoice %s: its order %d no longer exists (%s)',
                $attempt->ref,
                $attempt->orderId,
                $status->state
            ));
            if (!$status->isFinal()) {
                return null;
            }

            return $status->isPaid() ? Attempt::OUTCOME_PAID : Attempt::OUTCOME_EXPIRED;
        }
        switch ($status->state) {
            case PaymentStatus::PAID:
                $this->inRowLock($order, fn(Order $locked) => $this->paid($locked, $attempt));

                return Attempt::OUTCOME_PAID;
            case PaymentStatus::EXPIRED:
                $this->inRowLock($order, fn(Order $locked) => $this->expired($locked, $attempt));

                return Attempt::OUTCOME_EXPIRED;
            case PaymentStatus::UNCONFIRMED:
                $this->unconfirmed($order, $attempt, $previous);

                return null;
            default:
                $this->open($order, $attempt, $previous);

                return null;
        }
    }

    /**
     * Runs $work on $order read again in Magento's row lock: what an admin did meanwhile counts.
     *
     * @param Order $order The order.
     * @param callable $work What to do.
     * @phpstan-param callable(Order): void $work
     * @return void
     */
    private function inRowLock(Order $order, callable $work): void
    {
        $orderId = $this->orders->idOf($order);
        $this->orderMutex->execute(
            $orderId,
            function () use ($orderId, $work): void {
                $locked = $this->orders->fresh($orderId);
                if ($locked !== null) {
                    $work($locked);
                }
            }
        );
    }

    /**
     * The invoice was paid: the order's payment is registered, once.
     *
     * @param Order $order The order, in its row lock.
     * @param Attempt $attempt The attempt.
     * @return void
     */
    private function paid(Order $order, Attempt $attempt): void
    {
        $payment = $order->getPayment();
        if (!$payment instanceof Payment) {
            throw new \RuntimeException('The order has no payment');
        }
        if ($payment->getAdditionalInformation(self::PAID_REF_KEY) === $attempt->ref) {
            // Applied by an earlier check, which stopped afterwards (before its emails, perhaps):
            // never again, but the emails of a capture it made that are not sent yet.
            $invoice = $this->capturedInvoice($order, $attempt->ref);
            if ($invoice !== null) {
                $this->sendEmails($order, $invoice);
            }

            return;
        }
        if ($order->isCanceled() || in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true)) {
            $this->paidAnyway($order, $attempt);

            return;
        }
        $owed = $this->orders->owed($order);
        $currency = (string) $order->getOrderCurrencyCode();
        $payment->setAdditionalInformation(self::PAID_REF_KEY, $attempt->ref);
        $payment->setLastTransId($attempt->ref);
        if ($owed === null || !$this->prices->isPriceOf($attempt->invoice['fiat'] ?? null, $owed, $currency)) {
            $this->paidAnotherPrice($order, $attempt, $owed, $currency);

            return;
        }
        $this->capture($order, $attempt);
    }

    /**
     * The invoice was paid at what the order owes: captured, the order invoiced and processing.
     *
     * @param Order $order The order, in its row lock.
     * @param Attempt $attempt The attempt.
     * @return void
     */
    private function capture(Order $order, Attempt $attempt): void
    {
        $payment = $order->getPayment();
        if (!$payment instanceof Payment) {
            return;
        }
        $wasHeld = $order->getState() === Order::STATE_HOLDED;
        $payment->setTransactionId($attempt->ref);
        $payment->setCurrencyCode($order->getBaseCurrencyCode());
        $payment->setIsTransactionClosed(true);
        $payment->setPreparedMessage($this->paidNote($order, $attempt));
        $payment->registerCaptureNotification($order->getBaseTotalDue() ?? 0.0, true);
        if ($wasHeld) {
            // Held by the merchant: once released, the order is processing, never pending again.
            $order->setHoldBeforeState(Order::STATE_PROCESSING);
            $order->setHoldBeforeStatus(
                $order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING) ?? Order::STATE_PROCESSING
            );
        }
        $this->orderRepository->save($order);
        $this->logger->info(sprintf(
            'Invoice %s paid (%s): order %s',
            $attempt->ref,
            (string) $attempt->method(),
            $order->getIncrementId()
        ));
        $this->sendEmails($order, $payment->getCreatedInvoice());
    }

    /**
     * The invoice of $order that the capture of attempt $ref made, if it did.
     *
     * @param Order $order The order.
     * @param string $ref The attempt's reference (the capture's transaction id).
     * @return Invoice|null
     */
    private function capturedInvoice(Order $order, string $ref): ?Invoice
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ($invoice instanceof Invoice && $invoice->getTransactionId() === $ref) {
                return $invoice;
            }
        }

        return null;
    }

    /**
     * The order's emails, once paid: its confirmation (held back until then) and its invoice's.
     *
     * A failure is logged: the payment stands.
     *
     * @param Order $order The order.
     * @param mixed $invoice The invoice the capture created.
     * @return void
     */
    private function sendEmails(Order $order, mixed $invoice): void
    {
        try {
            if (!(bool) $order->getEmailSent()) {
                $this->orderSender->send($order);
            }
            if ($invoice instanceof Invoice && !(bool) $invoice->getEmailSent()) {
                $this->invoiceSender->send($invoice);
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Order %s is paid, its emails could not be sent: %s',
                $order->getIncrementId(),
                get_class($e)
            ));
        }
    }

    /**
     * The invoice was paid, but its price is not what the order owes now: never completed.
     *
     * The order changed after the invoice was made, or was paid in part otherwise. It is put on
     * hold for the merchant, with a note of what came and what the order owes.
     *
     * @param Order $order The order, in its row lock.
     * @param Attempt $attempt The attempt.
     * @param string|null $owed What the order owes now.
     * @param string $currency The order's currency.
     * @return void
     */
    private function paidAnotherPrice(Order $order, Attempt $attempt, ?string $owed, string $currency): void
    {
        $storeId = (int) $order->getStoreId();
        $fiat = $attempt->fiat();
        $received = $attempt->received() === '0' ? $attempt->sats() : $attempt->received();
        $note = $this->paidNote($order, $attempt) . ' ' . $this->notes->render(
            $storeId,
            __(
                'The invoice\'s price was %1, and the order now owes %2: it changed after the invoice was made, '
                . 'so it was not completed and is on hold. '
                . 'Settle the difference with the customer, then invoice or cancel the order.',
                $fiat === null ? '–' : $this->prices->display($fiat['amount'], $fiat['currency']),
                $owed === null ? '–' : $this->prices->display($owed, $currency)
            )
        );
        $order->addCommentToStatusHistory($note, false, false);
        if ($order->canHold()) {
            $order->hold();
        }
        $this->orderRepository->save($order);
        $this->notify($order, $note);
        $this->logger->warning(sprintf(
            'Invoice %s paid (%s sats), its order %s owes %s %s now: on hold',
            $attempt->ref,
            $received,
            $order->getIncrementId(),
            (string) $owed,
            $currency
        ));
    }

    /**
     * The invoice was paid, but the order was cancelled or completed meanwhile: noted, and told.
     *
     * Never captured. A cancelled order stays cancelled: Magento takes no order out of that state
     * (Order::canHold(), canInvoice(), canUnhold() refuse it, in 2.4.7 to 2.4.9), so it cannot go
     * on hold as an order that changed does; its note gives what came, the invoice's price, and
     * that the bitcoin is in the merchant's wallet, to refund or to settle by hand.
     *
     * @param Order $order The order, in its row lock.
     * @param Attempt $attempt The attempt.
     * @return void
     */
    private function paidAnyway(Order $order, Attempt $attempt): void
    {
        $storeId = (int) $order->getStoreId();
        $fiat = $attempt->fiat();
        $note = $this->paidNote($order, $attempt) . ' ' . $this->notes->render(
            $storeId,
            $order->isCanceled()
                ? __(
                    'The invoice\'s price was %1. The order had been cancelled: Magento cannot take a cancelled '
                    . 'order back, so it stays cancelled, and the bitcoin is in your wallet. '
                    . 'Refund the customer from your wallet, or settle the order with them by hand.',
                    $fiat === null ? '–' : $this->prices->display($fiat['amount'], $fiat['currency'])
                )
                : __(
                    'The order was %1 already, and the bitcoin is in your wallet. '
                    . 'Refund the customer from your wallet, or settle the order by hand.',
                    $order->getState() === Order::STATE_CLOSED ? __('closed') : __('completed')
                )
        );
        $payment = $order->getPayment();
        if ($payment instanceof Payment) {
            $payment->setAdditionalInformation(self::PAID_REF_KEY, $attempt->ref);
        }
        $order->addCommentToStatusHistory($note, false, false);
        $this->orderRepository->save($order);
        $this->notify($order, $note);
        $this->logger->warning(sprintf(
            'Invoice %s paid, its order %s was %s already',
            $attempt->ref,
            $order->getIncrementId(),
            (string) $order->getState()
        ));
    }

    /**
     * The order's note of a payment: how it was paid, and what came when it is not the amount.
     *
     * The merchant's tolerance, or the merchant's acceptance of what came (in the sattle.me app),
     * accepted less, or more came: for the merchant only, never where the customer reads.
     *
     * @param Order $order The order.
     * @param Attempt $attempt The attempt.
     * @return string
     */
    private function paidNote(Order $order, Attempt $attempt): string
    {
        $storeId = (int) $order->getStoreId();
        $locale = $this->notes->locale($storeId);
        $sats = $this->amounts->sats($attempt->sats(), $locale);
        $received = $attempt->received();
        // What came: in Lightning, the invoice's amount (its status has no "received"); on-chain
        // (and by invoices of the rest), what the status received.
        $came = $attempt->method() === 'lightning' || $received === '0'
            ? $sats
            : $this->amounts->sats($received, $locale);
        $note = match ($attempt->method()) {
            'lightning' => __('Bitcoin invoice %1 paid in Lightning: %2 sats.', $attempt->ref, $came),
            'onchain' => __('Bitcoin invoice %1 paid on-chain (3 confirmations): %2 sats.', $attempt->ref, $came),
            default => __('Bitcoin invoice %1 paid on-chain and in Lightning: %2 sats.', $attempt->ref, $came),
        };
        $text = $this->notes->render($storeId, $note);
        if ($attempt->method() !== 'lightning' && $received !== '0' && $received !== $attempt->sats()) {
            $receivedSats = $this->amounts->sats($received, $locale);
            $text .= ' ' . $this->notes->render(
                $storeId,
                $this->amounts->isLess($received, $attempt->sats())
                    ? __(
                        'Received %1 of %2 sats: accepted in the sattle.me app, by your tolerance or by your '
                        . 'acceptance of what came.',
                        $receivedSats,
                        $sats
                    )
                    : __('Received %1 sats, more than the %2 sats asked.', $receivedSats, $sats)
            );
        }

        return $text;
    }

    /**
     * The invoice ended unpaid: the order is cancelled, or put on hold after an underpayment.
     *
     * With nothing received, the order is cancelled (when it still waits for this invoice); with
     * an underpayment, which reached the merchant's wallet, it is put on hold, for the merchant to
     * settle.
     *
     * @param Order $order The order, in its row lock.
     * @param Attempt $attempt The attempt.
     * @return void
     */
    private function expired(Order $order, Attempt $attempt): void
    {
        $storeId = (int) $order->getStoreId();
        $locale = $this->notes->locale($storeId);
        $received = $attempt->received();
        $latest = $this->attempts->latestForOrder($this->orders->idOf($order));
        $waiting = $latest !== null && $latest->id === $attempt->id
            && $order->getState() === Order::STATE_PENDING_PAYMENT && $this->orders->isOurs($order);
        if ($received !== '0') {
            $note = $this->notes->render(
                $storeId,
                __(
                    'The Bitcoin invoice %1 ended underpaid: %2 of %3 sats came, and reached your wallet. '
                    . 'The order is on hold: settle the difference with the customer, '
                    . 'then invoice or cancel the order.',
                    $attempt->ref,
                    $this->amounts->sats($received, $locale),
                    $this->amounts->sats($attempt->sats(), $locale)
                )
            );
            $order->addCommentToStatusHistory($note, false, false);
            if ($waiting && $order->canHold()) {
                $order->hold();
            }
            $this->orderRepository->save($order);
            $this->notify($order, $note);
            $this->logger->warning(sprintf(
                'Invoice %s (order %s) ended underpaid: %s of %s sats',
                $attempt->ref,
                $order->getIncrementId(),
                $received,
                $attempt->sats()
            ));

            return;
        }
        $note = $this->notes->render($storeId, __('The Bitcoin invoice %1 expired unpaid.', $attempt->ref));
        if ($waiting && $order->canCancel()) {
            // No payment came, for good: the order is cancelled, its stock freed, as Magento does
            // with an order left unpaid; the customer may order again, with any method.
            $order->cancel();
            $note .= ' ' . $this->notes->render($storeId, __('The order was cancelled.'));
        }
        // Else, while a new invoice of the order is being made (OrderCancelGuard says no), the order
        // stays Pending Payment for that invoice, the note saying only that this one expired; if
        // none is made, Magento's cron cancels the order once its Pending Payment Order Lifetime is
        // over.
        $order->addCommentToStatusHistory($note, false, false);
        $this->orderRepository->save($order);
    }

    /**
     * Enough came on-chain in time, being confirmed: noted once.
     *
     * The order is kept from being cancelled meanwhile (OrderCancelGuard).
     *
     * @param Order $order The order.
     * @param Attempt $attempt The attempt.
     * @param array<string,mixed>|null $previous The status before.
     * @return void
     */
    private function unconfirmed(Order $order, Attempt $attempt, ?array $previous): void
    {
        if (($previous['state'] ?? null) === PaymentStatus::UNCONFIRMED) {
            return;
        }
        $storeId = (int) $order->getStoreId();
        $this->addNote(
            $order,
            __(
                'Bitcoin invoice %1: %2 sats came in time for its %3 sats; its on-chain payment is being '
                . 'confirmed (%4 of 3 confirmations, about 30 minutes).',
                $attempt->ref,
                $this->amounts->sats($attempt->received(), $this->notes->locale($storeId)),
                $this->amounts->sats($attempt->sats(), $this->notes->locale($storeId)),
                (string) (int) $attempt->confirmations()
            )
        );
    }

    /**
     * Not paid yet: an underpayment is noted once, as it first comes.
     *
     * @param Order $order The order.
     * @param Attempt $attempt The attempt.
     * @param array<string,mixed>|null $previous The status before.
     * @return void
     */
    private function open(Order $order, Attempt $attempt, ?array $previous): void
    {
        $before = $previous['received'] ?? '0';
        $had = is_string($before) && ltrim($before, '0') !== '';
        if (!$attempt->hasReceived() || $had) {
            return;
        }
        $locale = $this->notes->locale((int) $order->getStoreId());
        $this->addNote(
            $order,
            __(
                'Bitcoin invoice %1: %2 sats came in time for its %3 sats; '
                . 'the customer is asked for the rest until the invoice ends.',
                $attempt->ref,
                $this->amounts->sats($attempt->received(), $locale),
                $this->amounts->sats($attempt->sats(), $locale)
            )
        );
    }

    /**
     * Adds $note to the order's history, in its store's language, and saves it.
     *
     * @param Order $order The order.
     * @param Phrase $note The note.
     * @return void
     */
    private function addNote(Order $order, Phrase $note): void
    {
        $order->addCommentToStatusHistory($this->notes->render((int) $order->getStoreId(), $note), false, false);
        $this->orderRepository->save($order);
    }

    /**
     * Tells the merchant, in the admin's notifications, that $order needs their attention.
     *
     * @param Order $order The order.
     * @param string $note Why.
     * @return void
     */
    private function notify(Order $order, string $note): void
    {
        $this->notifier->addMajor(
            (string) __('sattle.me: order %1 needs your attention', $order->getIncrementId()),
            $note
        );
    }
}
