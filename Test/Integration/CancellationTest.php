<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\Framework\App\ResourceConnection;
use Magento\OrderCancellation\Model\CustomerCanCancel;
use Magento\OrderCancellationUi\ViewModel\Config as CancellationButton;
use Magento\OrderCancellation\Model\CancelOrder;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\CronJob\CleanExpiredOrders;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\CancellationRefused;
use Sattleme\Payment\Model\Checker;
use Sattleme\Payment\Model\OrderMarks;
use Sattleme\Payment\Model\Page\Progress;
use Sattleme\Payment\Model\PagePhase;
use Sattleme\Payment\Model\PaymentFailure;
use Sattleme\Payment\Test\Support\Sdk;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Invoice;

/**
 * No cancellation by the customer (Magento's order cancellation: its button, and its GraphQL
 * mutations, which ask the same CustomerCanCancel) nor by Magento's cron of orders left unpaid
 * while a bitcoin payment may still come, nor once one came: paid, or an underpayment that reached
 * the merchant's wallet, even once the merchant released the order from hold (Pending Payment
 * again). An order nothing came for can be cancelled as usual.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class CancellationTest extends OrderTestCase
{
    /**
     * Magento's order cancellation, on: its button, its checks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setConfig('sales/cancellation/enabled', '1');
    }

    public function testAnOrderWithoutAnInvoiceCanBeCancelledByItsCustomer(): void
    {
        $order = $this->placeOrder();

        $this->assertTrue($this->customerCanCancel($order));
        $this->assertTrue($this->get(CancellationButton::class)->canCancel($this->idOf($order)));
    }

    public function testNoCancellationByTheCustomerWhileTheInvoiceMayBePaid(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);

        $this->assertFalse($this->customerCanCancel($order));
        $this->assertFalse($this->get(CancellationButton::class)->canCancel($this->idOf($order)), 'No button');

        // Being confirmed: still not.
        $this->sdk()->statuses = [Sdk::unconfirmed('59000', 1)];
        $this->check($attempt);
        $this->assertFalse($this->customerCanCancel($order));
    }

    public function testNoCancellationByTheCustomerOfAnOrderPaidInBitcoin(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::paidLightning()];
        $this->check($attempt);
        $paid = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_PROCESSING, $paid->getState());

        // Magento's own check reads the state alone, and would have the order refunded offline.
        $this->assertFalse($this->customerCanCancel($order));
        $this->assertFalse($this->get(CancellationButton::class)->canCancel($this->idOf($order)));
    }

    public function testAnUnderpaidOrderReleasedFromHoldIsNeverCancelledByTheCustomerNorTheCron(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::open((int) $attempt->expiresAt, '20000', '39000')];
        $this->check($attempt);
        $this->clock()->advance(900 + Attempt::GRACE_SECONDS);
        $this->sdk()->statuses = [Sdk::expired('20000')];
        $this->check($attempt);
        $held = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_HOLDED, $held->getState());

        // The merchant releases it: Pending Payment again, the sats in the wallet.
        $held->unhold();
        $this->save($held);
        $released = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $released->getState());

        $this->assertFalse($released->canCancel());
        $this->assertFalse($this->customerCanCancel($order));
        $this->age($order, 9 * 3600);
        $this->get(CleanExpiredOrders::class)->execute();
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($this->idOf($order))->getState());
    }

    public function testAnOrderPaidAtAnotherPriceReleasedFromHoldIsNeverCancelledByTheCron(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $edited = $this->order($this->idOf($order));
        $edited->setGrandTotal(35.5)->setBaseGrandTotal(35.5);
        $this->save($edited);
        $this->sdk()->statuses = [Sdk::paidLightning()];
        $this->check($attempt);
        $held = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_HOLDED, $held->getState());
        $held->unhold();
        $this->save($held);

        $this->assertFalse($this->order($this->idOf($order))->canCancel());
        $this->age($order, 9 * 3600);
        $this->get(CleanExpiredOrders::class)->execute();
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($this->idOf($order))->getState());
    }

    public function testNoCancellationWhileTheInvoiceOfAnAttemptThatFailedIsMadeAgain(): void
    {
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        $refused = [];
        $this->sdk()->creates = [
            new NetworkException(),
            function (array $params) use ($orderId, &$refused): Invoice {
                // Meanwhile, the customer's button, and the admin's or the REST API's cancellation.
                $refused[] = !$this->customerCanCancel($this->order($orderId));
                try {
                    $this->get(CancelOrder::class)->execute($this->order($orderId), 'Changed my mind');
                } catch (CancellationRefused) {
                    // Refused, as below.
                }
                $refused[] = $this->order($orderId)->getState() === Order::STATE_PENDING_PAYMENT;
                $refused[] = !$this->get(OrderManagementInterface::class)->cancel($orderId);

                return Sdk::invoice($params, $this->clock()->now());
            },
        ];
        try {
            $this->invoiceFor($order);
            $this->fail('An invoice was made');
        } catch (PaymentFailure) {
            // The network failed: the attempt is to try again.
        }
        // Its retry, long after (no longer "being made" by its age).
        $this->clock()->advance(120);

        $attempt = $this->invoiceFor($order);

        $this->assertSame([true, true, true], $refused);
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($orderId)->getState());
        $this->assertTrue($attempt->isPayable($this->clock()->now()));
        $this->assertSame(PagePhase::PAYABLE, $this->get(Progress::class)->phase($this->order($orderId)));
    }

    public function testTheCustomersCancellationDuringAnInvoicesMakingIsRefusedWithNoEmailNorComment(): void
    {
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        $mails = $this->get(TransportBuilderMock::class);
        $before = $mails->getSentMessage();
        $refusal = null;
        $this->sdk()->creates = [
            function (array $params) use ($orderId, &$refusal): Invoice {
                // Magento's order cancellation checked the order before this request began making
                // its invoice (its button, its GraphQL mutations): then it cancels it.
                try {
                    $this->get(CancelOrder::class)->execute($this->order($orderId), 'Changed my mind');
                } catch (CancellationRefused $e) {
                    $refusal = $e->getMessage();
                }

                return Sdk::invoice($params, $this->clock()->now());
            },
        ];

        $attempt = $this->invoiceFor($order);

        $this->assertSame(
            'This order\'s bitcoin invoice is being made: it cannot be cancelled now. Try again in a minute.',
            $refusal
        );
        $this->assertSame($before, $mails->getSentMessage(), 'No e-mail to the customer');
        $notes = implode("\n", $this->notes($order));
        $this->assertStringNotContainsString('Changed my mind', $notes);
        $this->assertStringNotContainsString('notification', $notes);
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($orderId)->getState());
        $this->assertTrue($attempt->isPayable($this->clock()->now()));
    }

    public function testACancellationWithoutOrderCancelIsMarkedAndRefusedAsAnyOther(): void
    {
        // PayPal's paths cancel an order with Order::registerCancellation() alone.
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        $refused = false;
        $this->sdk()->creates = [
            function (array $params) use ($orderId, &$refused): Invoice {
                try {
                    $this->order($orderId)->registerCancellation('Cancelled at PayPal');
                } catch (CancellationRefused) {
                    $refused = true;
                }

                return Sdk::invoice($params, $this->clock()->now());
            },
        ];
        $this->invoiceFor($order);
        $this->assertTrue($refused, 'Refused while the invoice is made');

        // Carried out, not saved yet: no invoice is made meanwhile.
        $other = $this->placeOrder();
        $this->order($this->idOf($other))->registerCancellation('Cancelled at PayPal');
        try {
            $this->invoiceFor($other);
            $this->fail('An invoice was made while the order was being cancelled');
        } catch (PaymentFailure $e) {
            $this->assertSame(PaymentFailure::TRANSIENT, $e->getKind());
        }
    }

    public function testTheCustomersCancellationIsRefusedWithItsTrueReason(): void
    {
        // An invoice that may still be paid.
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->assertSame(
            'A bitcoin payment of this order may still come: it cannot be cancelled now.',
            $this->customerCancellationRefusal($order)
        );

        // Bitcoin came (an underpayment), the order released from hold by the merchant.
        $this->sdk()->statuses = [Sdk::open((int) $attempt->expiresAt, '20000', '39000')];
        $this->check($attempt);
        $this->clock()->advance(900 + Attempt::GRACE_SECONDS);
        $this->sdk()->statuses = [Sdk::expired('20000')];
        $this->check($attempt);
        $held = $this->order($this->idOf($order));
        $held->unhold();
        $this->save($held);
        $this->assertSame(
            'Bitcoin came for this order: only the shop can cancel it.',
            $this->customerCancellationRefusal($order)
        );
    }

    public function testAnInvoiceThatEndsWhileAnotherIsMadeLeavesTheOrderToMagentosCron(): void
    {
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        $attempt = $this->invoiceFor($order);
        $this->clock()->advance(900 + Attempt::GRACE_SECONDS);
        // Another request makes a new invoice of the order as the check finds this one expired.
        $this->assertTrue($this->get(OrderMarks::class)->startMaking($orderId));
        $this->sdk()->statuses = [Sdk::expired('0')];
        $this->check($attempt);

        $final = $this->attempts()->find($attempt->id);
        $this->assertNotNull($final);
        $this->assertSame(Attempt::OUTCOME_EXPIRED, $final->outcome);
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($orderId)->getState());
        $notes = $this->notes($order);
        $this->assertSame('The Bitcoin invoice ' . $attempt->ref . ' expired unpaid.', end($notes));

        // No invoice was made after all: Magento's cron cancels the order at its lifetime's end.
        $this->advance(OrderMarks::MAKING_SECONDS);
        $this->age($order, 9 * 3600);
        $this->get(CleanExpiredOrders::class)->execute();
        $this->assertSame(Order::STATE_CANCELED, $this->order($orderId)->getState());
    }

    public function testAnInvoiceBeingMadeKeepsTheOrderFromBeingCancelledForTheCallsLimitAtMost(): void
    {
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        // Another request makes the invoice of an attempt made two minutes ago (asked again after a
        // failure), and dies in the SDK's call: its mark is never cleared.
        $ref = 'm2-' . $order->getIncrementId();
        $attemptId = $this->attempts()->insertAttempt($orderId, 1, $ref, $this->clock()->now() - 120);
        $this->attempts()->markRetry($attemptId, 'network');
        $this->assertTrue($this->get(OrderMarks::class)->startMaking($orderId));

        $this->assertFalse($this->order($orderId)->canCancel());
        $this->assertFalse($this->customerCanCancel($order));
        try {
            $this->order($orderId)->cancel();
            $this->fail('Cancelled while its invoice is made');
        } catch (CancellationRefused) {
            $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($orderId)->getState());
        }

        // Past the call's limit and its margin: as without it.
        $this->advance(OrderMarks::MAKING_SECONDS);
        $this->assertTrue($this->order($orderId)->canCancel());
        $this->assertTrue($this->customerCanCancel($order));
    }

    public function testTheMarksFollowTheDatabasesClockNotTheServers(): void
    {
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        // A server makes the invoice; another one, whose clock is an hour ahead, asks to cancel.
        $this->assertTrue($this->get(OrderMarks::class)->startMaking($orderId));
        $this->clock()->advance(3600);

        $this->assertFalse($this->order($orderId)->canCancel());
        $this->assertFalse($this->customerCanCancel($order));

        // By the database's clock, the mark lasts its time, and no longer.
        $this->databaseClock()->advance(OrderMarks::MAKING_SECONDS - 1);
        $this->assertFalse($this->order($orderId)->canCancel());
        $this->databaseClock()->advance(1);
        $this->assertTrue($this->order($orderId)->canCancel());
    }

    public function testNoInvoiceIsShownWhileACancellationDecidedIsNotSavedYet(): void
    {
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        $this->sdk()->creates = [
            new NetworkException(),
            fn(array $params): Invoice => Sdk::invoice($params, $this->clock()->now()),
        ];
        try {
            $this->invoiceFor($order);
            $this->fail('An invoice was made');
        } catch (PaymentFailure) {
            // The network failed: the attempt is to try again.
        }
        $this->clock()->advance(120);
        // Magento's order cancellation by the customer: Order::cancel(), then its e-mail, then
        // the order's save. Meanwhile, the customer's retry on the payment page.
        $cancelling = $this->order($orderId);
        $this->assertTrue($this->customerCanCancel($cancelling));
        $cancelling->cancel();
        $this->assertSame(Order::STATE_CANCELED, $cancelling->getState());
        try {
            $this->invoiceFor($order);
            $this->fail('An invoice was made while the order was being cancelled');
        } catch (PaymentFailure $e) {
            $this->assertSame(PaymentFailure::TRANSIENT, $e->getKind());
        }
        $this->save($cancelling);

        $this->assertSame(Order::STATE_CANCELED, $this->order($orderId)->getState());
        $this->assertCount(1, $this->sdk()->createCalls, 'The SDK was not asked again');
        foreach ($this->attemptsOf($order) as $attempt) {
            $this->assertFalse($attempt->isPayable($this->clock()->now()));
        }
        $this->assertSame(PagePhase::CLOSED, $this->get(Progress::class)->phase($this->order($orderId)));
    }

    public function testACancellationThatWasNeverSavedKeepsTheOrderFromBeingPaidForItsTimeAtMost(): void
    {
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        // Decided, and its request died before the order's save.
        $this->order($orderId)->cancel();
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($orderId)->getState());
        try {
            $this->invoiceFor($order);
            $this->fail('An invoice was made while the order was being cancelled');
        } catch (PaymentFailure $e) {
            $this->assertSame(PaymentFailure::TRANSIENT, $e->getKind());
        }

        $this->advance(OrderMarks::CANCELING_SECONDS);
        $this->assertTrue($this->invoiceFor($order)->isPayable($this->clock()->now()));
    }

    public function testAnInvoiceMadeWhileTheAdminInvoicedTheOrderOfflineIsNeverShownAndSaysSo(): void
    {
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        $this->sdk()->creates = [
            function (array $params) use ($orderId): Invoice {
                // Meanwhile, the admin settled the order otherwise: processing.
                $settled = $this->order($orderId);
                $settled->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
                $this->save($settled);

                return Sdk::invoice($params, $this->clock()->now());
            },
        ];

        try {
            $this->invoiceFor($order);
            $this->fail('The invoice was handed out');
        } catch (PaymentFailure $e) {
            $this->assertSame(PaymentFailure::NOT_PAYABLE, $e->getKind());
        }

        [$attempt] = $this->attemptsOf($order);
        $notes = implode("\n", $this->notes($order));
        $this->assertStringContainsString(
            'Bitcoin invoice ' . $attempt->ref . ' was made while the order\'s status became Processing: '
            . 'it was never shown to the customer.',
            $notes
        );
        $this->assertStringNotContainsString('cancel', $notes);
        $this->assertSame(PagePhase::CLOSED, $this->get(Progress::class)->phase($this->order($orderId)));
    }

    public function testAnInvoiceMadeWhileTheOrderWasCancelledOtherwiseIsNeverShown(): void
    {
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        $this->sdk()->creates = [
            function (array $params) use ($orderId): Invoice {
                // Cancelled by a path that asks no one (another module, the database).
                $cancelled = $this->order($orderId);
                $cancelled->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
                $this->save($cancelled);

                return Sdk::invoice($params, $this->clock()->now());
            },
        ];

        try {
            $this->invoiceFor($order);
            $this->fail('The invoice was handed out');
        } catch (PaymentFailure $e) {
            $this->assertSame(PaymentFailure::NOT_PAYABLE, $e->getKind());
        }

        $this->assertSame(PagePhase::CLOSED, $this->get(Progress::class)->phase($this->order($orderId)));
        [$attempt] = $this->attemptsOf($order);
        $this->assertTrue($attempt->hasInvoice(), 'Kept, and checked as any other');
        $this->assertContains(
            'Bitcoin invoice ' . $attempt->ref . ' was made while the order\'s status became Canceled: '
            . 'it was never shown to the customer.',
            $this->notes($order)
        );
    }

    public function testTheNoteOfAnInvoiceNeverShownNamesTheStatusInTheStoresLanguage(): void
    {
        $this->setConfig('general/locale/code', 'de_CH');
        $canceled = $this->neverShown(Order::STATE_CANCELED);
        $this->assertStringContainsString(
            'wurde erstellt, während der Status der Bestellung zu «Storniert» wechselte: '
            . 'Sie wurde dem Kunden nie angezeigt.',
            $canceled
        );

        // A label the merchant gave the status in that store: theirs.
        $status = $this->make(Status::class);
        $resource = $this->get(StatusResource::class);
        $resource->load($status, Order::STATE_PROCESSING);
        $status->setData('store_labels', [1 => 'In Arbeit beim Shop']);
        $resource->save($status);
        $processing = $this->neverShown(Order::STATE_PROCESSING);
        $this->assertStringContainsString('zu «In Arbeit beim Shop» wechselte', $processing);
    }

    public function testAnOrderWhoseInvoiceExpiredUnpaidWasCancelledAsUsual(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->clock()->advance(900 + Attempt::GRACE_SECONDS);
        $this->sdk()->statuses = [Sdk::expired('0')];
        $this->check($attempt);

        $this->assertSame(Order::STATE_CANCELED, $this->order($this->idOf($order))->getState());
    }

    /**
     * The note of an invoice made while the order went to $state (its status, Magento's default
     * for that state): never shown.
     *
     * @param string $state The order's state.
     * @return string
     */
    private function neverShown(string $state): string
    {
        $order = $this->placeOrder();
        $orderId = $this->idOf($order);
        $this->sdk()->creates = [
            function (array $params) use ($orderId, $state): Invoice {
                $changed = $this->order($orderId);
                $changed->setState($state)->setStatus($state);
                $this->save($changed);

                return Sdk::invoice($params, $this->clock()->now());
            },
        ];
        try {
            $this->invoiceFor($order);
            $this->fail('The invoice was handed out');
        } catch (PaymentFailure $e) {
            $this->assertSame(PaymentFailure::NOT_PAYABLE, $e->getKind());
        }
        $notes = $this->notes($order);

        return (string) end($notes);
    }

    /**
     * Whether Magento's order cancellation lets the customer cancel $order.
     *
     * @param Order $order The order.
     * @return bool
     */
    private function customerCanCancel(Order $order): bool
    {
        return $this->get(CustomerCanCancel::class)->execute($this->order($this->idOf($order)));
    }

    /**
     * The message Magento's order cancellation by the customer (CancelOrder, as its button and its
     * GraphQL mutations call it) is refused with for $order; null when it cancelled it.
     *
     * @param Order $order The order.
     * @return string|null
     */
    private function customerCancellationRefusal(Order $order): ?string
    {
        try {
            $this->get(CancelOrder::class)->execute($this->order($this->idOf($order)), 'Changed my mind');
        } catch (CancellationRefused $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Checks $attempt now, as the cron does.
     *
     * @param Attempt $attempt The attempt.
     * @return bool
     */
    private function check(Attempt $attempt): bool
    {
        return $this->get(Checker::class)->check($attempt->id, 0, 10);
    }

    /**
     * Makes $order's last change $seconds old (Magento's cron of orders left unpaid reads it).
     *
     * @param Order $order The order.
     * @param int $seconds How old.
     * @return void
     */
    private function age(Order $order, int $seconds): void
    {
        $resource = $this->get(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName('sales_order'),
            ['updated_at' => new \Zend_Db_Expr('CURRENT_TIMESTAMP - INTERVAL ' . $seconds . ' SECOND')],
            ['entity_id = ?' => $this->idOf($order)]
        );
    }
}
