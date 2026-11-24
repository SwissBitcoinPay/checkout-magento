<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Sattleme\Payment\Sdk\ClientProviderInterface;
use Sattleme\Payment\Sdk\Errors;
use Sattleme\Payment\Sdk\Prices;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\TimeoutCancellation;

/**
 * The invoice of an order: the one it has that may still be paid, else a new one.
 *
 * Made as its customer is sent to pay it, the order locked (OrderLock), so that two requests
 * for it (a double click, two PHP workers) never make two invoices: the second waits, then gets
 * the first one's. Each attempt has a random reference, stored before the SDK is called; an
 * attempt that failed when an invoice may exist for its reference (the network, the time) asks
 * again with that reference, and the SDK hands that invoice out (InvoicePending), or reports it
 * paid (OrderPaid).
 * One payable invoice per order: another one only once the last one ended unpaid, for good.
 *
 */
class InvoiceCreator
{
    /**
     * Seconds a request waits for another one making the invoice of the same order.
     */
    public const LOCK_WAIT = 25;

    /**
     * A request that waited longer than this (seconds) for another one makes no invoice either.
     *
     * Two calls to the SDK in a row could outlast the request.
     */
    public const RETRY_AFTER_WAIT = 5;

    /**
     * The check of an earlier invoice the SDK hands out again, in seconds.
     */
    public const PENDING_CHECK_TIMEOUT = 10;

    /**
     * Makes invoices.
     *
     * @param AttemptRepository $attempts The attempts.
     * @param ClientProviderInterface $clients The SDK's clients.
     * @param Config $config The settings.
     * @param OrderLock $lock The order's lock.
     * @param Orders $orders The orders, fresh.
     * @param OrderRepositoryInterface $orderRepository Magento's orders.
     * @param Checker $checker The checks.
     * @param StoreManagerInterface $stores The stores.
     * @param Notes $notes The notes, in the store's language.
     * @param Refs $refs The references and notes of the SDK.
     * @param Durations $durations The invoices' durations.
     * @param Amounts $amounts Amounts in sats.
     * @param Prices $prices The prices the SDK takes.
     * @param Errors $errors The SDK's errors.
     * @param ClockInterface $clock The clock.
     * @param LoggerInterface $logger The module's log.
     * @param PagePhase $phases The payments' phases.
     * @param OrderMarks $marks What is under way for an order.
     * @param StatusLabels $statusLabels The labels of the orders' statuses.
     */
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly ClientProviderInterface $clients,
        private readonly Config $config,
        private readonly OrderLock $lock,
        private readonly Orders $orders,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Checker $checker,
        private readonly StoreManagerInterface $stores,
        private readonly Notes $notes,
        private readonly Refs $refs,
        private readonly Durations $durations,
        private readonly Amounts $amounts,
        private readonly Prices $prices,
        private readonly Errors $errors,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly PagePhase $phases,
        private readonly OrderMarks $marks,
        private readonly StatusLabels $statusLabels
    ) {
    }

    /**
     * The invoice to show for order $orderId: the one it has that may still be paid, else a new one.
     *
     * @param int $orderId The order.
     * @return Attempt The attempt holding the SDK's invoice.
     * @throws PaymentFailure With what to tell the customer.
     */
    public function invoiceFor(int $orderId): Attempt
    {
        try {
            return $this->lock->run(
                $orderId,
                self::LOCK_WAIT,
                fn(float $waited): Attempt => $this->locked($orderId, $waited)
            );
        } catch (LockUnavailable $e) {
            $this->logger->warning(sprintf('No invoice for order %d: %s', $orderId, $e->getMessage()));

            throw new PaymentFailure(PaymentFailure::TRANSIENT);
        }
    }

    /**
     * The invoice to show for order $orderId, or null when none could be made now.
     *
     * The attempt recorded why, which the payment page tells the customer.
     *
     * @param int $orderId The order.
     * @return Attempt|null
     */
    public function tryInvoiceFor(int $orderId): ?Attempt
    {
        try {
            return $this->invoiceFor($orderId);
        } catch (PaymentFailure) {
            return null;
        }
    }

    /**
     * The invoice of order $orderId, its lock held.
     *
     * @param int $orderId The order.
     * @param float $waited Seconds this request waited for the lock.
     * @return Attempt
     * @throws PaymentFailure With what to tell the customer.
     */
    private function locked(int $orderId, float $waited): Attempt
    {
        // Read again under the lock: another request may have changed it meanwhile.
        $order = $this->orders->fresh($orderId);
        if ($order === null || !$this->orders->isOurs($order)) {
            throw new PaymentFailure(PaymentFailure::NOT_PAYABLE);
        }
        $now = $this->clock->now();
        $live = $this->attempts->liveForOrder($orderId, $now);
        if ($live !== null) {
            if ($live->hasInvoice()) {
                return $live; // One payable invoice per order: this one.
            }
            throw new PaymentFailure(PaymentFailure::TRANSIENT); // Being made by a request that stopped.
        }
        $latest = $this->attempts->latestForOrder($orderId);
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT || ($latest !== null && $latest->outcome !== '')) {
            // Paid, cancelled, on hold, or its invoice ended (paid, underpaid, expired: then the
            // order was cancelled, or is on hold for the merchant).
            throw new PaymentFailure(PaymentFailure::NOT_PAYABLE);
        }
        if ($waited > self::RETRY_AFTER_WAIT) {
            throw new PaymentFailure(PaymentFailure::TRANSIENT);
        }
        $storeId = (int) $order->getStoreId();
        $key = $this->config->integrationKey($storeId);
        if ($key === '') {
            $this->logger->error(
                sprintf('No invoice for order %s: no integration key is set', $order->getIncrementId())
            );

            throw new PaymentFailure(PaymentFailure::UNAVAILABLE);
        }
        $currency = (string) $order->getOrderCurrencyCode();
        $owed = $this->orders->owed($order);
        $amount = $owed === null ? null : $this->prices->amount($owed, $currency);
        if ($amount === null) {
            $this->logger->warning(sprintf(
                'No invoice for order %s: what it owes in %s is not a price the SDK takes',
                $order->getIncrementId(),
                $currency
            ));

            throw new PaymentFailure(PaymentFailure::ORDER);
        }
        // Meanwhile, the order is not cancelled (OrderCancelGuard), for OrderMarks::MAKING_SECONDS
        // at most: a request that dies keeps no order from being cancelled longer. Not while its
        // cancellation is carried out, nor while another request makes its invoice (a lock lost).
        if (!$this->marks->startMaking($orderId)) {
            throw new PaymentFailure(PaymentFailure::TRANSIENT);
        }
        try {
            $made = $this->make($order, $latest, $storeId, $key, $amount, $currency, $now);
        } finally {
            $this->marks->stopMaking($orderId);
        }
        if ($made['paid'] !== null) {
            return $this->alreadyPaid($made['attempt']->id, $order, $made['paid']);
        }
        if ($made['pending']) {
            // Checked once it is no longer being made: a check may cancel the order (expired).
            $this->checker->check($made['attempt']->id, 0, self::PENDING_CHECK_TIMEOUT);
        }

        // As stored now: a pending one's status found.
        return $this->attempts->find($made['attempt']->id) ?? $made['attempt'];
    }

    /**
     * Makes the invoice of $order with the SDK, the order marked as its invoice being made.
     *
     * The attempt holding it; the SDK's OrderPaid when an invoice of its reference was paid
     * already; whether the SDK handed out an earlier invoice of its reference (InvoicePending).
     *
     * @param Order $order The order.
     * @param Attempt|null $latest Its latest attempt.
     * @param int $storeId Its store.
     * @param string $key The store's integration key.
     * @param string $amount What it owes, as the SDK takes it.
     * @param string $currency Its currency.
     * @param int $now The time, in Unix seconds.
     * @return array{attempt: Attempt, paid: OrderPaidException|null, pending: bool}
     * @throws PaymentFailure With what to tell the customer.
     */
    private function make(
        Order $order,
        ?Attempt $latest,
        int $storeId,
        string $key,
        string $amount,
        string $currency,
        int $now
    ): array {
        $orderId = $this->orders->idOf($order);
        // Read again once marked: a cancellation saved before is seen, none can start after.
        $marked = $this->orders->fresh($orderId);
        if ($marked === null || $marked->getState() !== Order::STATE_PENDING_PAYMENT) {
            throw new PaymentFailure(PaymentFailure::NOT_PAYABLE);
        }
        if ($latest !== null && in_array($latest->state, [Attempt::STATE_RETRY, Attempt::STATE_CREATING], true)) {
            // An invoice may exist for its reference: asked again with it.
            $attemptId = $latest->id;
            $ref = $latest->ref;
        } else {
            $ref = $this->refs->newRef($order->getIncrementId());
            $attemptId = $this->attempts->insertAttempt($orderId, $storeId, $ref, $now);
        }
        $deadline = $this->deadline($order);
        $left = $deadline === null ? null : $deadline - $now;
        $seconds = $this->durations->seconds($this->config->minutes($storeId), $left);
        if ($seconds === null) {
            $this->attempts->markFailed($attemptId, Errors::DEADLINE);
            $this->logger->info(sprintf(
                'No invoice for order %s: %d s are left before Magento cancels it',
                $order->getIncrementId(),
                (int) $left
            ));

            throw new PaymentFailure(PaymentFailure::TOO_LATE);
        }
        $params = [
            'ref' => $ref,
            'fiat' => ['amount' => $amount, 'currency' => $currency],
            'durationSeconds' => $seconds,
            'note' => $this->refs->note(
                $this->storeName($storeId),
                $this->notes->render($storeId, __('Order %1', $order->getIncrementId()))
            ),
        ];
        $pending = false;
        try {
            $made = $this->clients->forKey($key)
                ->createInvoice($params, new TimeoutCancellation(Durations::CREATE_TIMEOUT));
            $data = $made->toArray();
            $expires = $made->expiresAt;
        } catch (InvoicePendingException $e) {
            // An invoice of this reference that may still be paid (an earlier try failed after the
            // SDK made it): this attempt's, checked once stored, and shown only while it is open.
            $declared = $e->getInvoice();
            $data = $declared->toArray();
            $expires = $declared->expiresAt;
            $pending = true;
        } catch (OrderPaidException $e) {
            // Stored while the order is still marked: paid, it is never cancelled meanwhile.
            $declared = $e->getInvoice();
            $this->attempts->storeInvoice($attemptId, $declared->toArray(), $declared->expiresAt, $this->clock->now());
            $attempt = $this->attempts->find($attemptId);
            if ($attempt === null) {
                throw new PaymentFailure(PaymentFailure::TRANSIENT);
            }

            return ['attempt' => $attempt, 'paid' => $e, 'pending' => false];
        } catch (\Throwable $e) {
            throw $this->failure($attemptId, $order, $ref, $e);
        }
        if (!$this->prices->isPriceOf($data['fiat'] ?? null, $amount, $currency)) {
            // An earlier try of this attempt made it for another price (the order changed since):
            // never shown, it would pay the order's old price. The next attempt has its own reference.
            $this->attempts->markFailed($attemptId, Errors::MISMATCH);
            $this->logger->warning(sprintf(
                'Invoice %s (order %s) is not of the price %s %s: never shown',
                $ref,
                $order->getIncrementId(),
                $amount,
                $currency
            ));

            throw new PaymentFailure(PaymentFailure::TRANSIENT);
        }
        if (!$this->durations->endsInTime($expires, $deadline)) {
            // The clock of the merchant's Lightning address is ahead (or an earlier try's invoice
            // ends later): never shown, it would be payable after Magento cancels the order.
            $this->attempts->markFailed($attemptId, Errors::DEADLINE);
            $this->logger->warning(sprintf(
                'Invoice %s (order %s) ends at %d, after the order\'s deadline (%d): never shown',
                $ref,
                $order->getIncrementId(),
                $expires,
                (int) $deadline
            ));

            throw new PaymentFailure(PaymentFailure::TOO_LATE);
        }
        // Stored while the order is still marked: from now on the attempt keeps it from being
        // cancelled (OrderCancelGuard), as long as it may be paid.
        $this->attempts->storeInvoice($attemptId, $data, $expires, $now + Schedule::OPEN_INTERVAL);
        $attempt = $this->attempts->find($attemptId);
        if ($attempt === null) {
            throw new PaymentFailure(PaymentFailure::TRANSIENT);
        }
        // Read again: an order whose state changed while the SDK made the invoice (the SDK's call
        // outlasted the mark, or a path that never cancels: the admin's offline invoice) never gets
        // it shown. It is kept, and checked as any other, so that a payment of it would be told.
        $current = $this->orders->fresh($orderId);
        if ($current === null || !$this->phases->mayShowInvoice((string) $current->getState())) {
            $this->noteNeverShown($current ?? $order, $attempt);

            throw new PaymentFailure(PaymentFailure::NOT_PAYABLE);
        }
        $this->noteCreated($order, $attempt);
        $this->logger->info(sprintf(
            'Invoice %s made for order %s: %s sats, until %d',
            $ref,
            $order->getIncrementId(),
            $attempt->sats(),
            $expires
        ));

        return ['attempt' => $attempt, 'paid' => null, 'pending' => $pending];
    }

    /**
     * When Magento's cron would cancel $order as unpaid, in Unix seconds; null when it never would.
     *
     * Its last change and the store's Pending Payment Order Lifetime (sales_clean_orders).
     *
     * @param Order $order The order.
     * @return int|null
     */
    private function deadline(Order $order): ?int
    {
        $lifetime = $this->config->pendingLifetimeSeconds((int) $order->getStoreId());
        if ($lifetime === null) {
            return null;
        }
        // Magento keeps the order's dates in UTC.
        $updated = (string) $order->getUpdatedAt();
        $changed = $updated === '' ? false : strtotime($updated . ' UTC');

        return ($changed === false ? $this->clock->now() : $changed) + $lifetime;
    }

    /**
     * An invoice of this reference was paid already (the SDK's OrderPaid): its payment applied.
     *
     * Stored by make(), it is applied to the order as a check would.
     *
     * @param int $attemptId The attempt.
     * @param Order $order The order.
     * @param OrderPaidException $paid The SDK's OrderPaid.
     * @return Attempt
     * @throws PaymentFailure When the attempt cannot be read again.
     */
    private function alreadyPaid(int $attemptId, Order $order, OrderPaidException $paid): Attempt
    {
        $this->checker->applyKnown($attemptId, $paid->getStatus());
        $attempt = $this->attempts->find($attemptId);
        if ($attempt === null) {
            throw new PaymentFailure(PaymentFailure::TRANSIENT);
        }
        $this->logger->info(
            sprintf('Invoice %s of order %s was paid already', $attempt->ref, $order->getIncrementId())
        );

        return $attempt;
    }

    /**
     * Records why no invoice was made, logs it, and gives what to tell the customer.
     *
     * @param int $attemptId The attempt.
     * @param Order $order The order.
     * @param string $ref The attempt's reference.
     * @param \Throwable $error The error.
     * @return PaymentFailure
     */
    private function failure(int $attemptId, Order $order, string $ref, \Throwable $error): PaymentFailure
    {
        $code = $this->errors->code($error);
        if ($error instanceof SattlemeException && !$error->isRetryable()) {
            $this->attempts->markFailed($attemptId, $code);
        } else {
            // The network, the time, or a fault after the SDK answered: an invoice may exist.
            $this->attempts->markRetry($attemptId, $code);
        }
        $message = sprintf(
            'No invoice for order %s (%s): %s',
            $order->getIncrementId(),
            $ref,
            $this->errors->describe($error)
        );
        if ($this->errors->isTransient($error)) {
            $this->logger->warning($message);

            return new PaymentFailure(PaymentFailure::TRANSIENT);
        }
        if ($this->errors->isOrderError($error)) {
            $this->logger->warning($message);

            return new PaymentFailure(PaymentFailure::ORDER);
        }
        // A key that is invalid or was revoked, the merchant's Lightning address answering wrongly,
        // the server's PHP, or a fault: the merchant has to act.
        $this->logger->error($message);

        return new PaymentFailure($code === Errors::DEADLINE ? PaymentFailure::TOO_LATE : PaymentFailure::UNAVAILABLE);
    }

    /**
     * Notes on the order an invoice made while it left the states it may be paid in: never shown.
     *
     * Its status as Magento shows it, in the store's language (StatusLabels).
     *
     * @param Order $order The order, as it is now.
     * @param Attempt $attempt The attempt.
     * @return void
     */
    private function noteNeverShown(Order $order, Attempt $attempt): void
    {
        $this->logger->warning(sprintf(
            'Invoice %s was made while order %s became %s: never shown',
            $attempt->ref,
            $order->getIncrementId(),
            (string) $order->getState()
        ));
        $order->addCommentToStatusHistory(
            $this->notes->render(
                (int) $order->getStoreId(),
                __(
                    'Bitcoin invoice %1 was made while the order\'s status became %2: '
                    . 'it was never shown to the customer.',
                    $attempt->ref,
                    $this->statusLabels->of($order)
                )
            ),
            false,
            false
        );
        $this->orderRepository->save($order);
    }

    /**
     * Notes the new invoice on the order: its reference, amount, methods and end.
     *
     * @param Order $order The order.
     * @param Attempt $attempt The attempt.
     * @return void
     */
    private function noteCreated(Order $order, Attempt $attempt): void
    {
        $storeId = (int) $order->getStoreId();
        $lightning = $attempt->bolt11() !== null;
        $onchain = $attempt->onchainAddress() !== null;
        $methods = match (true) {
            $lightning && $onchain => __('in Lightning or on-chain'),
            $onchain => __('on-chain'),
            default => __('in Lightning'),
        };
        $note = $this->notes->render(
            $storeId,
            __(
                'Bitcoin invoice %1 created: %2 sats, payable %3 until %4 (%5).',
                $attempt->ref,
                $this->amounts->sats($attempt->sats(), $this->notes->locale($storeId)),
                $methods,
                $this->notes->localTime($storeId, (int) $attempt->expiresAt),
                $this->notes->timeZone($storeId)
            )
        );
        $fresh = $this->orders->fresh($this->orders->idOf($order)) ?? $order;
        $fresh->addCommentToStatusHistory($note, false, false);
        $this->orderRepository->save($fresh);
    }

    /**
     * The name of store $storeId, as its customers read it.
     *
     * @param int $storeId The store.
     * @return string
     */
    private function storeName(int $storeId): string
    {
        try {
            $store = $this->stores->getStore($storeId);
            return $store instanceof Store ? $store->getFrontendName() : $store->getName();
        } catch (\Exception) {
            return '';
        }
    }
}
