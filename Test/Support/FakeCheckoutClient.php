<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Support;

use Sattleme\Payment\Sdk\CheckoutClientInterface;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Cancellation;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Merchant;
use Sattleme\Payment\Vendor\Sattleme\Checkout\PaymentStatus;

/**
 * The SDK faked at the module's seam: it answers what the test queued (the SDK's own values and
 * exceptions), and records the calls.
 */
class FakeCheckoutClient implements CheckoutClientInterface
{
    /**
     * What createInvoice answers, in turn (the last one stays). Empty: an invoice of the order.
     *
     * @var list<Invoice|\Throwable|\Closure>
     */
    public array $creates = [];

    /**
     * What getStatus answers, in turn (the last one stays). Empty: open, nothing received.
     *
     * @var list<PaymentStatus|\Throwable|\Closure>
     */
    public array $statuses = [];

    /**
     * What merchant() answers, in turn (the last one stays). Empty: Sdk::merchant().
     *
     * @var list<Merchant|\Throwable|\Closure>
     */
    public array $merchants = [];

    /**
     * The orders createInvoice was called with.
     *
     * @var list<array<string,mixed>>
     */
    public array $createCalls = [];

    /**
     * The invoices getStatus was called with.
     *
     * @var list<array<string,mixed>>
     */
    public array $statusCalls = [];

    /**
     * How many times merchant() was called.
     *
     * @var int
     */
    public int $merchantCalls = 0;

    /**
     * A fake whose invoices are made at the time of $clock.
     *
     * @param FakeClock $clock The clock.
     */
    public function __construct(private readonly FakeClock $clock)
    {
    }

    /**
     * Forgets the queued answers and the calls.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->creates = [];
        $this->statuses = [];
        $this->merchants = [];
        $this->createCalls = [];
        $this->statusCalls = [];
        $this->merchantCalls = 0;
    }

    /**
     * @inheritDoc
     */
    public function createInvoice(array $order, Cancellation $cancellation): Invoice
    {
        $this->createCalls[] = $order;
        $answer = $this->next($this->creates, $order) ?? Sdk::invoice($order, $this->clock->now());
        if (!$answer instanceof Invoice) {
            throw new \LogicException('Not an invoice');
        }

        return $answer;
    }

    /**
     * @inheritDoc
     */
    public function getStatus(array $invoice, Cancellation $cancellation): PaymentStatus
    {
        $this->statusCalls[] = $invoice;
        $answer = $this->next($this->statuses, $invoice);
        if ($answer === null) {
            $expiresAt = $invoice['expiresAt'] ?? null;

            return Sdk::open(is_int($expiresAt) ? $expiresAt : $this->clock->now() + 900);
        }
        if (!$answer instanceof PaymentStatus) {
            throw new \LogicException('Not a status');
        }

        return $answer;
    }

    /**
     * @inheritDoc
     */
    public function merchant(Cancellation $cancellation): Merchant
    {
        ++$this->merchantCalls;
        $answer = $this->next($this->merchants, null) ?? Sdk::merchant();
        if (!$answer instanceof Merchant) {
            throw new \LogicException('Not a merchant');
        }

        return $answer;
    }

    /**
     * The next answer of $queue (its last one stays): a Closure is called with $argument, a
     * Throwable is thrown.
     *
     * @template T of object
     * @param list<T|\Closure|\Throwable> $queue The answers, in turn.
     * @param-out list<T|\Closure|\Throwable> $queue
     * @param mixed $argument The call's argument, for a Closure.
     * @return object|null
     * @throws \Throwable The answer, when it is one.
     */
    private function next(array &$queue, mixed $argument): ?object
    {
        if ($queue === []) {
            return null;
        }
        $answer = count($queue) > 1 ? array_shift($queue) : $queue[0];
        if ($answer instanceof \Closure) {
            $answer = $answer($argument);
        }
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return is_object($answer) ? $answer : null;
    }
}
