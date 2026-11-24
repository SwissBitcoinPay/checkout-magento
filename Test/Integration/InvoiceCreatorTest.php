<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\Sales\Model\Order;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\OnchainUnavailableException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Invoice;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\PagePhase;
use Sattleme\Payment\Model\PaymentFailure;
use Sattleme\Payment\Model\Config;
use Sattleme\Payment\Model\Page\Progress;
use Sattleme\Payment\Sdk\Errors;
use Sattleme\Payment\Test\Support\Sdk;

/**
 * Making an order's invoice: one per order, a random reference stored first, the SDK's
 * InvoicePending and OrderPaid, its failures, the deadlines.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class InvoiceCreatorTest extends OrderTestCase
{
    public function testAnInvoiceIsMadeWithARandomReferenceStoredBeforeTheSdkIsCalled(): void
    {
        $order = $this->placeOrder(24.5);
        $stored = [];
        $this->sdk()->creates = [
            function (array $params) use (&$stored): Invoice {
                // The attempt is in the database before the SDK is asked.
                $stored[] = $this->attempts()->findByRef(is_string($params['ref']) ? $params['ref'] : '');

                return Sdk::invoice($params, $this->clock()->now());
            },
        ];

        $attempt = $this->invoiceFor($order);

        $this->assertCount(1, $this->sdk()->createCalls);
        $params = $this->sdk()->createCalls[0];
        $this->assertMatchesRegularExpression(
            '/^m2-' . preg_quote($order->getIncrementId(), '/') . '-[0-9a-f]{32}$/D',
            is_string($params['ref']) ? $params['ref'] : ''
        );
        $this->assertSame(['amount' => '29.5', 'currency' => 'USD'], $params['fiat']);
        $this->assertSame(900, $params['durationSeconds']);
        $this->assertIsString($params['note']);
        $this->assertStringContainsString('Order ' . $order->getIncrementId(), $params['note']);
        $this->assertArrayNotHasKey('sats', $params);
        $this->assertInstanceOf(Attempt::class, $stored[0]);
        $this->assertSame(Attempt::STATE_CREATING, $stored[0]->state);

        $this->assertSame($params['ref'], $attempt->ref);
        $this->assertSame(Attempt::STATE_INVOICE, $attempt->state);
        $this->assertSame(Sdk::invoice($params, $this->clock()->now())->toArray(), $attempt->invoice);
        $this->assertSame($this->clock()->now() + 900, $attempt->expiresAt);
        $this->assertSame($this->clock()->now() + 60, $attempt->nextCheckAt);
        $this->assertFalse($attempt->isFinal);
        $this->assertSame(PagePhase::PAYABLE, $this->phase($order));
        $notes = $this->notes($order);
        $this->assertStringContainsString(
            'Bitcoin invoice ' . $attempt->ref . ' created: 59,000 sats, payable in Lightning or on-chain until ',
            (string) end($notes)
        );
    }

    public function testTheNoteOfAnInvoiceGivesItsEndInTheStoresTimeZone(): void
    {
        $this->setConfig('general/locale/timezone', 'Europe/Zurich');
        $order = $this->placeOrder();

        $attempt = $this->invoiceFor($order);

        $end = (new \DateTimeImmutable('@' . (int) $attempt->expiresAt))
            ->setTimezone(new \DateTimeZone('Europe/Zurich'))
            ->format('Y-m-d H:i:s');
        $notes = $this->notes($order);
        $this->assertStringEndsWith(' until ' . $end . ' (Europe/Zurich).', (string) end($notes));
    }

    public function testTwoRandomReferencesDiffer(): void
    {
        $this->sdk()->creates = [new InvalidKeyException()];
        $order = $this->placeOrder();
        $this->failure($order);
        $this->failure($order);

        $this->assertCount(2, $this->sdk()->createCalls);
        $this->assertNotSame($this->sdk()->createCalls[0]['ref'], $this->sdk()->createCalls[1]['ref']);
    }

    public function testAnOrderGetsOnePayableInvoice(): void
    {
        $order = $this->placeOrder();
        $first = $this->invoiceFor($order);
        $second = $this->invoiceFor($order);
        $this->clock()->advance(1000); // Its time is over: its final status is not known yet.
        $third = $this->invoiceFor($order);

        $this->assertCount(1, $this->sdk()->createCalls);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertCount(1, $this->attemptsOf($order));
        $this->assertSame(PagePhase::CHECKING, $this->phase($order));
    }

    public function testANetworkFailureAsksAgainWithTheSameReferenceAndShowsTheSdksInvoiceOnceOpen(): void
    {
        $order = $this->placeOrder();
        $made = $this->clock()->now();
        $this->sdk()->creates = [
            new NetworkException(),
            fn(array $params): \Throwable => new InvoicePendingException(
                Sdk::declared(Sdk::invoice($params, $made), $made + 5),
                null
            ),
        ];

        $this->assertSame(PaymentFailure::TRANSIENT, $this->failure($order));
        [$attempt] = $this->attemptsOf($order);
        $this->assertSame(Attempt::STATE_RETRY, $attempt->state);
        $this->assertSame('network', $attempt->error);
        $this->assertFalse($attempt->isFinal);
        $this->assertSame(PagePhase::FAILED, $this->phase($order));

        $this->clock()->advance(20);
        $again = $this->invoiceFor($order);

        $this->assertCount(2, $this->sdk()->createCalls);
        $this->assertSame($this->sdk()->createCalls[0]['ref'], $this->sdk()->createCalls[1]['ref']);
        $this->assertSame($attempt->id, $again->id);
        $this->assertTrue($again->isDeclared());
        $this->assertSame($made + 900, $again->expiresAt);
        // Checked before it is shown: the SDK found it open.
        $this->assertCount(1, $this->sdk()->statusCalls);
        $this->assertSame('open', $again->statusState());
        $this->assertSame(PagePhase::PAYABLE, $this->phase($order));
    }

    public function testAnInvoiceHandedOutAgainIsNotShownBeforeACheckFoundItOpen(): void
    {
        $order = $this->placeOrder();
        $made = $this->clock()->now();
        $this->sdk()->creates = [
            new NetworkException(),
            fn(array $params): \Throwable => new InvoicePendingException(
                Sdk::declared(Sdk::invoice($params, $made), $made + 5),
                null
            ),
        ];
        $this->sdk()->statuses = [new NetworkException()];
        $this->failure($order);
        $attempt = $this->invoiceFor($order);

        $this->assertNull($attempt->statusState());
        $this->assertSame(PagePhase::CHECKING, $this->phase($order));

        $this->sdk()->statuses = [Sdk::open($made + 900)];
        $this->clock()->advance(10);
        $this->get(Progress::class)->check($order);

        $this->assertSame(PagePhase::PAYABLE, $this->phase($order));
    }

    public function testAnInvoicePaidEarlierPaysTheOrder(): void
    {
        $order = $this->placeOrder();
        $made = $this->clock()->now();
        $this->sdk()->creates = [
            new NetworkException(),
            fn(array $params): \Throwable => new OrderPaidException(
                Sdk::declared(Sdk::invoice($params, $made), $made + 5),
                Sdk::paidLightning()
            ),
        ];
        $this->failure($order);
        $attempt = $this->invoiceFor($order);

        $this->assertTrue($attempt->isFinal);
        $this->assertSame(Attempt::OUTCOME_PAID, $attempt->outcome);
        $paid = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_PROCESSING, $paid->getState());
        $this->assertSame('29.5000', (string) $paid->getTotalPaid());
        $this->assertCount(1, $paid->getInvoiceCollection());
        $this->assertSame(PagePhase::PAID, $this->phase($order));
    }

    public function testAKeyTheSdkRefusesFailsTheAttemptForGood(): void
    {
        $order = $this->placeOrder();
        $this->sdk()->creates = [new InvalidKeyException(), new RevokedException()];

        $this->assertSame(PaymentFailure::UNAVAILABLE, $this->failure($order));
        [$attempt] = $this->attemptsOf($order);
        $this->assertSame(Attempt::STATE_FAILED, $attempt->state);
        $this->assertTrue($attempt->isFinal);
        $this->assertSame('', $attempt->outcome);
        $this->assertSame('invalid_key', $attempt->error);
        $this->assertSame(PagePhase::FAILED, $this->phase($order));

        $this->assertSame(PaymentFailure::UNAVAILABLE, $this->failure($order));
        $this->assertCount(2, $this->attemptsOf($order));
        $this->assertSame('revoked', $this->attemptsOf($order)[0]->error);
    }

    public function testAFailureOfTheOrderOrOfTheShopTellsTheCustomerWhatToDo(): void
    {
        $order = $this->placeOrder();
        $this->sdk()->creates = [new UnsupportedCurrencyException('USD'), new OnchainUnavailableException()];

        $this->assertSame(PaymentFailure::ORDER, $this->failure($order));
        $this->assertSame(PaymentFailure::TRANSIENT, $this->failure($order));
        $this->assertSame(Attempt::STATE_RETRY, $this->attemptsOf($order)[0]->state);
    }

    public function testNoKeyNoInvoice(): void
    {
        $order = $this->placeOrder();
        $this->setConfig(Config::PATH_KEY, '');

        $this->assertSame(PaymentFailure::UNAVAILABLE, $this->failure($order));
        $this->assertSame([], $this->sdk()->createCalls);
        $this->assertSame([], $this->attemptsOf($order));
    }

    public function testAnOrderThatNoLongerWaitsForItsPaymentGetsNoInvoice(): void
    {
        $order = $this->placeOrder();
        $order->cancel();
        $this->save($order);

        $this->assertSame(PaymentFailure::NOT_PAYABLE, $this->failure($order));
        $this->assertSame([], $this->sdk()->createCalls);
    }

    public function testAnInvoiceMadeForAnotherPriceIsNeverShown(): void
    {
        $order = $this->placeOrder();
        $made = $this->clock()->now();
        $this->sdk()->creates = [
            new NetworkException(),
            // The order's total changed since the first try: the SDK hands its invoice out.
            fn(array $params): \Throwable => new InvoicePendingException(
                Sdk::declared(
                    Sdk::invoice(['fiat' => ['amount' => '19.5', 'currency' => 'USD']] + $params, $made),
                    $made
                ),
                null
            ),
            fn(array $params): Invoice => Sdk::invoice($params, $this->clock()->now()),
        ];
        $this->failure($order);

        $this->assertSame(PaymentFailure::TRANSIENT, $this->failure($order));
        $this->assertSame(Errors::MISMATCH, $this->attemptsOf($order)[0]->error);
        $this->assertTrue($this->attemptsOf($order)[0]->isFinal);
        $this->assertSame([], $this->sdk()->statusCalls, 'Never checked, never shown');

        $attempt = $this->invoiceFor($order);
        $this->assertNotSame($this->sdk()->createCalls[0]['ref'], $attempt->ref);
        $this->assertSame(
            ['amount' => '29.50', 'currency' => 'USD', 'minor' => '2950', 'digits' => 2],
            $attempt->invoice['fiat'] ?? null
        );
    }

    public function testTheInvoiceEndsBeforeMagentoCancelsTheOrder(): void
    {
        $this->setConfig(Config::PATH_PENDING_LIFETIME, '10');
        $order = $this->placeOrder();
        $deadline = (int) strtotime($order->getUpdatedAt() . ' UTC') + 600;

        $attempt = $this->invoiceFor($order);

        $duration = $this->sdk()->createCalls[0]['durationSeconds'];
        $this->assertIsInt($duration);
        $this->assertSame($deadline - $this->clock()->now() - Attempt::GRACE_SECONDS - 20, $duration);
        $this->assertLessThanOrEqual($deadline, (int) $attempt->expiresAt + Attempt::GRACE_SECONDS);
    }

    public function testTooLittleTimeLeftBeforeMagentoCancelsTheOrderMakesNoInvoice(): void
    {
        $this->setConfig(Config::PATH_PENDING_LIFETIME, '3');
        $order = $this->placeOrder();

        $this->assertSame(PaymentFailure::TOO_LATE, $this->failure($order));
        $this->assertSame([], $this->sdk()->createCalls);
        $this->assertSame(Errors::DEADLINE, $this->attemptsOf($order)[0]->error);
        $this->assertSame(PagePhase::FAILED, $this->phase($order));
    }

    public function testAnInvoiceEndingAfterTheOrdersDeadlineIsNeverShown(): void
    {
        $this->setConfig(Config::PATH_PENDING_LIFETIME, '30');
        $order = $this->placeOrder();
        // The clock of the merchant's Lightning address is an hour ahead.
        $this->sdk()->creates = [fn(array $params): Invoice => Sdk::invoice($params, $this->clock()->now() + 3600)];

        $this->assertSame(PaymentFailure::TOO_LATE, $this->failure($order));
        $this->assertSame(Errors::DEADLINE, $this->attemptsOf($order)[0]->error);
        $this->assertTrue($this->attemptsOf($order)[0]->isFinal);
    }

    public function testWithoutALifetimeTheTimeToPayIsTheMerchants(): void
    {
        $this->setConfig(Config::PATH_PENDING_LIFETIME, '');
        $this->setConfig(Config::PATH_MINUTES, '60');
        $order = $this->placeOrder();
        $this->invoiceFor($order);

        $this->assertSame(3600, $this->sdk()->createCalls[0]['durationSeconds']);
    }

    /**
     * The kind of failure of making $order's invoice.
     *
     * @param Order $order The order.
     * @return string
     */
    private function failure(Order $order): string
    {
        try {
            $this->invoiceFor($order);
        } catch (PaymentFailure $e) {
            return $e->getKind();
        }
        $this->fail('An invoice was made');
    }

    /**
     * The phase of $order's payment, as its page shows it.
     *
     * @param Order $order The order.
     * @return string
     */
    private function phase(Order $order): string
    {
        return $this->get(Progress::class)->phase($this->order($this->idOf($order)));
    }
}
