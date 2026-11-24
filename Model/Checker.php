<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Psr\Log\LoggerInterface;
use Sattleme\Payment\Sdk\ClientProviderInterface;
use Sattleme\Payment\Sdk\Errors;
use Sattleme\Payment\Vendor\Sattleme\Checkout\PaymentStatus;
use Sattleme\Payment\Vendor\Sattleme\Checkout\TimeoutCancellation;

/**
 * Checks the payment of an invoice now (the SDK's getStatus: the server's own check), and has
 * what it found applied to its order (Settlement).
 *
 * Two checks of one invoice never run at once: a check claims it first (a lease in its row,
 * which only the check holding it writes through), and checks in a row are harmless: the SDK's
 * checks are made to be repeated, and applying a status is idempotent. Only a "paid" status of
 * the SDK, for the invoice the attempt holds, pays an order: never the browser.
 */
class Checker
{
    /**
     * A check holds its invoice this much longer than its call to the SDK may take (seconds).
     */
    public const LEASE_MARGIN = 30;

    /**
     * Checks payments.
     *
     * @param AttemptRepository $attempts The attempts.
     * @param ClientProviderInterface $clients The SDK's clients.
     * @param Config $config The settings.
     * @param Settlement $settlement What applies a status.
     * @param Errors $errors The SDK's errors.
     * @param ClockInterface $clock The clock.
     * @param LoggerInterface $logger The module's log.
     */
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly ClientProviderInterface $clients,
        private readonly Config $config,
        private readonly Settlement $settlement,
        private readonly Errors $errors,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Checks attempt $id, then applies what was found.
     *
     * Skipped (false) when it is final, when another check of it is under way, or when one ended
     * less than $minInterval seconds ago.
     *
     * @param int $id The attempt.
     * @param int $minInterval Seconds since the last check, at least.
     * @param int $timeout Of the SDK's call, in seconds.
     * @return bool
     */
    public function check(int $id, int $minInterval, int $timeout): bool
    {
        $now = $this->clock->now();
        $lease = $now + $timeout + self::LEASE_MARGIN;
        if (!$this->attempts->claim($id, $now, $lease, $minInterval)) {
            return false;
        }
        $attempt = $this->attempts->find($id);
        if ($attempt === null || $attempt->invoice === null) {
            return false;
        }
        try {
            $key = $this->config->integrationKey($attempt->storeId);
            $status = $this->clients->forKey($key)
                ->getStatus($attempt->invoice, new TimeoutCancellation($timeout));
        } catch (\Throwable $e) {
            $this->failed($attempt, $lease, $e, $this->errors->code($e));

            return true;
        }
        try {
            $this->settlement->apply($attempt, $lease, $status);
        } catch (LockUnavailable $e) {
            $this->failed($attempt, $lease, $e, Errors::LOCK);
        } catch (\Throwable $e) {
            // Magento could not take the update (a database error, a module reacting to the
            // payment): the attempt is not final, a later check applies its status again.
            $this->failed($attempt, $lease, $e, Errors::APPLY);
        }

        return true;
    }

    /**
     * Applies $status, known without a check (the SDK's OrderPaid carries the paid status of an
     * invoice), to attempt $id as a check would: claimed first, so that no check runs meanwhile.
     * Whether it was applied.
     *
     * @param int $id The attempt.
     * @param PaymentStatus $status The status.
     * @return bool
     */
    public function applyKnown(int $id, PaymentStatus $status): bool
    {
        $now = $this->clock->now();
        $lease = $now + self::LEASE_MARGIN;
        if (!$this->attempts->claim($id, $now, $lease, 0)) {
            return false;
        }
        $attempt = $this->attempts->find($id);
        if ($attempt === null) {
            return false;
        }
        try {
            $this->settlement->apply($attempt, $lease, $status);
        } catch (\Throwable $e) {
            $this->failed($attempt, $lease, $e, $e instanceof LockUnavailable ? Errors::LOCK : Errors::APPLY);

            return false;
        }

        return true;
    }

    /**
     * Records a failed check.
     *
     * Logged when it starts failing, or fails otherwise, not at each of its retries.
     *
     * @param Attempt $attempt The attempt.
     * @param int $lease The check's lease.
     * @param \Throwable $error What failed.
     * @param string $code Its code.
     * @return void
     */
    private function failed(Attempt $attempt, int $lease, \Throwable $error, string $code): void
    {
        $this->attempts->recordFailure($attempt->id, $lease, $code, $this->clock->now());
        if ($code === $attempt->error) {
            return;
        }
        $message = sprintf(
            'The check of invoice %s (order %d) failed: %s',
            $attempt->ref,
            $attempt->orderId,
            $this->errors->describe($error)
        );
        if ($code === Errors::APPLY || $code === Errors::UNEXPECTED) {
            $this->logger->error($message);
        } else {
            $this->logger->warning($message);
        }
    }
}
