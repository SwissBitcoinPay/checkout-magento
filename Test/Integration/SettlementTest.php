<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\AdminNotification\Model\ResourceModel\Inbox\Collection as InboxCollection;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\CronJob\CleanExpiredOrders;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\Checker;
use Sattleme\Payment\Model\PagePhase;
use Sattleme\Payment\Model\Page\Progress;
use Sattleme\Payment\Model\Settlement;
use Sattleme\Payment\Test\Support\Sdk;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\PaymentStatus;

/**
 * What a check found, applied to the order through Magento's own paths: paid exactly once,
 * through the payment's capture; the notes; on hold when the merchant must settle; cancelled
 * when it ended unpaid; kept from being cancelled while it may be paid or confirmed.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class SettlementTest extends OrderTestCase
{
    public function testAnOpenInvoiceStaysPayable(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $notes = $this->notes($order);
        $this->sdk()->statuses = [Sdk::open((int) $attempt->expiresAt)];

        $this->assertTrue($this->check($attempt));

        $checked = $this->attempts()->find($attempt->id);
        $this->assertNotNull($checked);
        $this->assertSame('open', $checked->statusState());
        $this->assertSame(1, $checked->checks);
        $this->assertSame($this->clock()->now(), $checked->checkedAt);
        $this->assertNull($checked->leaseUntil);
        $this->assertFalse($checked->isFinal);
        $this->assertSame($attempt->invoice, $this->sdk()->statusCalls[0], 'The stored invoice is what is checked');
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($this->idOf($order))->getState());
        $this->assertSame($notes, $this->notes($order), 'Nothing to note');
    }

    public function testAPaymentInLightningPaysTheOrderOnceThroughItsCapture(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $this->sdk()->statuses = [Sdk::paidLightning()];

        $this->assertTrue($this->check($attempt));

        $paid = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_PROCESSING, $paid->getState());
        $this->assertSame('29.5000', (string) $paid->getTotalPaid());
        $this->assertSame('29.5000', (string) $paid->getBaseTotalPaid());
        $invoices = $paid->getInvoiceCollection();
        $this->assertCount(1, $invoices);
        $invoice = $invoices->getFirstItem();
        $this->assertInstanceOf(Order\Invoice::class, $invoice);
        $this->assertEquals(Order\Invoice::STATE_PAID, $invoice->getState());
        $this->assertSame($attempt->ref, $invoice->getTransactionId());
        $payment = $paid->getPayment();
        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertSame($attempt->ref, $payment->getLastTransId());
        $transactions = $this->make(\Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\Collection::class)
            ->addOrderIdFilter($this->idOf($paid))
            ->addFieldToFilter('txn_id', $attempt->ref);
        $this->assertCount(1, $transactions);
        $transaction = $transactions->getFirstItem();
        $this->assertInstanceOf(\Magento\Sales\Api\Data\TransactionInterface::class, $transaction);
        $this->assertSame('capture', $transaction->getTxnType());
        $this->assertSame($this->idOfModel($payment), $this->intOf($transaction->getPaymentId()));
        $this->assertTrue((bool) $paid->getEmailSent(), 'The order\'s confirmation, held back until paid');
        $this->assertTrue((bool) $invoice->getEmailSent());
        $this->assertStringContainsString(
            'Bitcoin invoice ' . $attempt->ref . ' paid in Lightning: 59,000 sats.',
            implode("\n", $this->notes($order))
        );
        $final = $this->attempts()->find($attempt->id);
        $this->assertNotNull($final);
        $this->assertTrue($final->isFinal);
        $this->assertSame(Attempt::OUTCOME_PAID, $final->outcome);
        $this->assertNull($final->nextCheckAt);

        // Checked again (the page and the cron): never applied twice.
        $this->clock()->advance(60);
        $this->assertFalse($this->check($attempt));
        $this->assertCount(1, $this->sdk()->statusCalls);
        $this->assertCount(1, $this->order($this->idOf($order))->getInvoiceCollection());
        $this->assertSame(PagePhase::PAID, $this->phase($order));
    }

    public function testAPaidStatusAppliedTwiceCompletesTheOrderOnce(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $settlement = $this->get(Settlement::class);
        $stored = $this->withStatus($attempt, Sdk::paidLightning());

        // Two checks that both found it paid (the second outlived the first's update).
        $outcomes = [
            $settlement->settle($stored, null, Sdk::paidLightning()),
            $settlement->settle($stored, null, Sdk::paidLightning()),
        ];
        $this->assertSame([Attempt::OUTCOME_PAID, Attempt::OUTCOME_PAID], $outcomes);

        $paid = $this->order($this->idOf($order));
        $this->assertCount(1, $paid->getInvoiceCollection());
        $this->assertSame('29.5000', (string) $paid->getTotalPaid());
        $paidNotes = array_filter(
            $this->notes($order),
            static fn(string $note): bool => str_contains($note, 'paid in')
        );
        $this->assertCount(1, $paidNotes);
    }

    public function testACompletionInterruptedBeforeItsEmailsIsFinishedByALaterCheckOnce(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $this->sdk()->statuses = [Sdk::paidLightning()];
        $this->check($attempt);
        $paid = $this->order($this->idOf($order));
        $invoice = $paid->getInvoiceCollection()->getFirstItem();
        $this->assertInstanceOf(Order\Invoice::class, $invoice);
        // The worker stopped after the capture was saved, before its emails and the attempt's end.
        $resource = $this->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $connection->update($resource->getTableName('sales_order'), ['email_sent' => null], [
            'entity_id = ?' => $this->idOf($order),
        ]);
        $connection->update($resource->getTableName('sales_invoice'), ['email_sent' => null], [
            'entity_id = ?' => $this->idOfModel($invoice),
        ]);
        $connection->update(
            $resource->getTableName(\Sattleme\Payment\Model\AttemptRepository::TABLE),
            ['is_final' => 0, 'outcome' => ''],
            ['attempt_id = ?' => $attempt->id]
        );
        $this->clock()->advance(60);

        $this->assertTrue($this->check($attempt));

        $done = $this->order($this->idOf($order));
        $this->assertTrue((bool) $done->getEmailSent(), 'The order\'s confirmation, sent by the later check');
        $this->assertTrue((bool) $done->getInvoiceCollection()->getFirstItem()->getEmailSent());
        $this->assertCount(1, $done->getInvoiceCollection(), 'Never captured twice');
        $this->assertSame('29.5000', (string) $done->getTotalPaid());
        $paidNotes = array_filter(
            $this->notes($order),
            static fn(string $note): bool => str_contains($note, 'paid in')
        );
        $this->assertCount(1, $paidNotes, 'Its note written once');
        $final = $this->attempts()->find($attempt->id);
        $this->assertNotNull($final);
        $this->assertSame(Attempt::OUTCOME_PAID, $final->outcome);
    }

    public function testAnOnchainPaymentTheToleranceAcceptedIsNoted(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $this->sdk()->statuses = [Sdk::paidOnchain('58500')];

        $this->check($attempt);

        $this->assertSame(Order::STATE_PROCESSING, $this->order($this->idOf($order))->getState());
        $this->assertStringContainsString(
            'Bitcoin invoice ' . $attempt->ref . ' paid on-chain (3 confirmations): 58,500 sats. '
            . 'Received 58,500 of 59,000 sats: accepted in the sattle.me app, by your tolerance or by your '
            . 'acceptance of what came.',
            implode("\n", $this->notes($order))
        );
    }

    public function testAnOrderTheMerchantAcceptedUnderpaidIsCompletedNeverHeld(): void
    {
        // The merchant's acceptance (SPEC.md §31): being confirmed, then paid, received far below sats.
        [$order, $attempt] = $this->orderWithInvoice();
        $this->sdk()->statuses = [Sdk::unconfirmed('35000', 1), Sdk::paidOnchain('35000')];

        $this->check($attempt);
        $this->assertSame(PagePhase::CONFIRMING, $this->phase($order));
        $this->assertContains(
            'Bitcoin invoice ' . $attempt->ref . ': 35,000 sats came in time for its 59,000 sats; '
            . 'its on-chain payment is being confirmed (1 of 3 confirmations, about 30 minutes).',
            $this->notes($order)
        );
        $this->clock()->advance(1800);
        $this->check($attempt);

        $paid = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_PROCESSING, $paid->getState());
        $this->assertCount(1, $paid->getInvoiceCollection());
        $this->assertSame('29.5000', (string) $paid->getTotalPaid());
        $this->assertSame(PagePhase::PAID, $this->phase($order));
        $notes = implode("\n", $this->notes($order));
        $this->assertStringContainsString(
            'Bitcoin invoice ' . $attempt->ref . ' paid on-chain (3 confirmations): 35,000 sats. '
            . 'Received 35,000 of 59,000 sats: accepted in the sattle.me app, by your tolerance or by your '
            . 'acceptance of what came.',
            $notes
        );
        $this->assertStringNotContainsString('on hold', $notes);
        $final = $this->attempts()->find($attempt->id);
        $this->assertNotNull($final);
        $this->assertSame(Attempt::OUTCOME_PAID, $final->outcome);
    }

    public function testAnOverpaymentIsNoted(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $this->sdk()->statuses = [Sdk::paidMixed('60000')];

        $this->check($attempt);

        $this->assertSame(Order::STATE_PROCESSING, $this->order($this->idOf($order))->getState());
        $this->assertStringContainsString(
            'paid on-chain and in Lightning: 60,000 sats. Received 60,000 sats, more than the 59,000 sats asked.',
            implode("\n", $this->notes($order))
        );
    }

    public function testAnInvoicePaidAfterTheOrdersTotalChangedPutsTheOrderOnHold(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        // An admin edited the order awaiting its payment: it costs 6.00 more now.
        $edited = $this->order($this->idOf($order));
        $edited->setGrandTotal(35.5)->setBaseGrandTotal(35.5);
        $this->save($edited);
        $this->sdk()->statuses = [Sdk::paidLightning()];

        $this->check($attempt);

        $held = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_HOLDED, $held->getState());
        $this->assertCount(0, $held->getInvoiceCollection());
        $this->assertSame(0.0, (float) $held->getTotalPaid());
        $note = 'Bitcoin invoice ' . $attempt->ref . ' paid in Lightning: 59,000 sats. '
            . 'The invoice\'s price was 29.50 USD, and the order now owes 35.50 USD: it changed after the invoice '
            . 'was made, so it was not completed and is on hold.';
        $this->assertStringContainsString($note, implode("\n", $this->notes($order)));
        $this->assertNotified($order->getIncrementId());
        $final = $this->attempts()->find($attempt->id);
        $this->assertNotNull($final);
        $this->assertSame(Attempt::OUTCOME_PAID, $final->outcome);
        $this->assertFalse($this->check($attempt));
        $this->assertSame(Order::STATE_HOLDED, $this->order($this->idOf($order))->getState());
    }

    public function testAnInvoicePaidWhenTheOrderOwesLessPutsItOnHold(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        // Part of it was paid otherwise meanwhile.
        $edited = $this->order($this->idOf($order));
        $edited->setTotalPaid(10.0)->setBaseTotalPaid(10.0);
        $this->save($edited);
        $this->sdk()->statuses = [Sdk::paidOnchain('59000')];

        $this->check($attempt);

        $held = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_HOLDED, $held->getState());
        $this->assertStringContainsString('and the order now owes 19.50 USD', implode("\n", $this->notes($order)));
    }

    public function testAnInvoicePaidWhileTheMerchantHeldTheOrderPaysIt(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $held = $this->order($this->idOf($order));
        $held->hold();
        $this->save($held);
        $this->sdk()->statuses = [Sdk::paidLightning()];

        $this->check($attempt);

        $paid = $this->order($this->idOf($order));
        $this->assertCount(1, $paid->getInvoiceCollection());
        $this->assertSame('29.5000', (string) $paid->getTotalPaid());
        if ($paid->getState() === Order::STATE_HOLDED) {
            // Still held by the merchant: released, it is processing, never pending again.
            $this->assertSame(Order::STATE_PROCESSING, $paid->getHoldBeforeState());
            $paid->unhold();
            $this->save($paid);
        }
        $this->assertSame(Order::STATE_PROCESSING, $this->order($this->idOf($order))->getState());
    }

    public function testAnInvoicePaidAfterTheOrderWasCancelledIsNotedForTheMerchant(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        // Cancelled despite the module (directly in the database, say).
        $cancelled = $this->order($this->idOf($order));
        $cancelled->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
        $this->save($cancelled);
        $this->sdk()->statuses = [Sdk::paidLightning()];

        $this->check($attempt);

        // Magento takes no order out of "canceled": not on hold, never captured, its note and the
        // admin's notification giving what came and the invoice's price.
        $after = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_CANCELED, $after->getState());
        $this->assertFalse($after->canHold());
        $this->assertCount(0, $after->getInvoiceCollection());
        $this->assertEquals(0, (float) $after->getTotalPaid());
        $note = 'Bitcoin invoice ' . $attempt->ref . ' paid in Lightning: 59,000 sats. '
            . 'The invoice\'s price was 29.50 USD. The order had been cancelled: Magento cannot take a cancelled '
            . 'order back, so it stays cancelled, and the bitcoin is in your wallet. Refund the customer from your '
            . 'wallet, or settle the order with them by hand.';
        // Once: a check after it changes nothing.
        $this->check($attempt);
        $this->assertCount(1, array_filter($this->notes($order), static fn(string $n): bool => $n === $note));
        $this->assertSame(Order::STATE_CANCELED, $this->order($this->idOf($order))->getState());
        $this->assertNotified($order->getIncrementId(), $note);
    }

    public function testAnInvoiceThatExpiredUnpaidCancelsTheOrder(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $this->clock()->advance(900 + Attempt::GRACE_SECONDS + 10);
        $this->sdk()->statuses = [Sdk::expired()];

        $this->check($attempt);

        $cancelled = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_CANCELED, $cancelled->getState());
        $this->assertStringContainsString(
            'The Bitcoin invoice ' . $attempt->ref . ' expired unpaid. The order was cancelled.',
            implode("\n", $this->notes($order))
        );
        $final = $this->attempts()->find($attempt->id);
        $this->assertNotNull($final);
        $this->assertSame(Attempt::OUTCOME_EXPIRED, $final->outcome);
        $this->assertSame(PagePhase::EXPIRED, $this->phase($order));
    }

    public function testAnInvoiceThatEndedUnderpaidPutsTheOrderOnHold(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $this->sdk()->statuses = [Sdk::open((int) $attempt->expiresAt, '20000', '39000')];
        $this->check($attempt);
        $this->clock()->advance(10);
        $this->check($attempt);
        $underpaid = 'Bitcoin invoice ' . $attempt->ref . ': 20,000 sats came in time for its 59,000 sats; '
            . 'the customer is asked for the rest until the invoice ends.';
        $this->assertCount(
            1,
            array_filter($this->notes($order), static fn(string $note): bool => str_contains($note, $underpaid)),
            'Noted once, as it first came'
        );

        $this->clock()->advance(900 + Attempt::GRACE_SECONDS);
        $this->sdk()->statuses = [Sdk::expired('20000')];
        $this->check($attempt);

        $held = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_HOLDED, $held->getState());
        $this->assertStringContainsString(
            'The Bitcoin invoice ' . $attempt->ref
            . ' ended underpaid: 20,000 of 59,000 sats came, and reached your wallet.',
            implode("\n", $this->notes($order))
        );
        $this->assertNotified($order->getIncrementId());
        $this->assertSame(PagePhase::UNDERPAID, $this->phase($order));
    }

    public function testAPaymentBeingConfirmedKeepsTheOrderFromBeingCancelled(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $this->sdk()->statuses = [Sdk::unconfirmed('59000', 1), Sdk::unconfirmed('59000', 2)];
        $this->check($attempt);
        $this->clock()->advance(60);
        $this->check($attempt);

        $confirming = 'Bitcoin invoice ' . $attempt->ref . ': 59,000 sats came in time for its 59,000 sats; '
            . 'its on-chain payment is being confirmed (1 of 3 confirmations, about 30 minutes).';
        $this->assertSame(
            1,
            count(array_filter(
                $this->notes($order),
                static fn(string $note): bool => str_contains($note, 'is being confirmed')
            ))
        );
        $this->assertContains($confirming, $this->notes($order));
        $this->assertSame(PagePhase::CONFIRMING, $this->phase($order));
        $this->assertFalse($this->order($this->idOf($order))->canCancel());

        // Magento's cron cancels orders left unpaid: not this one.
        $this->age($order, 9 * 3600);
        $this->get(CleanExpiredOrders::class)->execute();
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($this->idOf($order))->getState());

        $this->sdk()->statuses = [Sdk::paidOnchain('59000')];
        $this->clock()->advance(1800);
        $this->check($attempt);
        $this->assertSame(Order::STATE_PROCESSING, $this->order($this->idOf($order))->getState());
    }

    public function testAnOrderWhoseInvoiceMayBePaidIsKeptFromBeingCancelled(): void
    {
        [$order] = $this->orderWithInvoice();
        $this->assertFalse($this->order($this->idOf($order))->canCancel());

        // Its time is over, its final status unknown: still kept.
        $this->clock()->advance(2000);
        $this->age($order, 9 * 3600);
        $this->get(CleanExpiredOrders::class)->execute();
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($this->idOf($order))->getState());
    }

    public function testAnOrderWithoutAnInvoiceIsCancelledByMagentoAsUsual(): void
    {
        $order = $this->placeOrder();
        $this->age($order, 9 * 3600);

        $this->get(CleanExpiredOrders::class)->execute();

        $this->assertSame(Order::STATE_CANCELED, $this->order($this->idOf($order))->getState());
    }

    public function testAFailedCheckIsRecordedAndChangesNothing(): void
    {
        [$order, $attempt] = $this->orderWithInvoice();
        $this->sdk()->statuses = [new NetworkException(), new InvalidInvoiceException()];

        $this->assertTrue($this->check($attempt));
        $failed = $this->attempts()->find($attempt->id);
        $this->assertNotNull($failed);
        $this->assertSame('network', $failed->error);
        $this->assertSame(1, $failed->errors);
        $this->assertNull($failed->leaseUntil);
        $this->assertFalse($failed->isFinal);
        $this->assertNull($failed->statusState());

        $this->clock()->advance(60);
        $this->check($attempt);
        $failed = $this->attempts()->find($attempt->id);
        $this->assertNotNull($failed);
        $this->assertSame('invalid_invoice', $failed->error);
        $this->assertSame(2, $failed->errors);
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($this->idOf($order))->getState());

        $this->sdk()->statuses = [Sdk::open((int) $attempt->expiresAt)];
        $this->clock()->advance(60);
        $this->check($attempt);
        $recovered = $this->attempts()->find($attempt->id);
        $this->assertNotNull($recovered);
        $this->assertSame('', $recovered->error);
        $this->assertSame(0, $recovered->errors);
    }

    public function testChecksInARowAreSpacedAndOneHoldsTheInvoiceAtATime(): void
    {
        [, $attempt] = $this->orderWithInvoice();
        $checker = $this->get(Checker::class);

        $this->assertTrue($checker->check($attempt->id, 6, 10));
        $this->assertFalse($checker->check($attempt->id, 6, 10), 'Less than 6 s after the last');
        $this->clock()->advance(6);
        // Another check holds it (its lease, until it ends).
        $this->assertTrue($this->attempts()->claim($attempt->id, $this->clock()->now(), $this->clock()->now() + 40, 0));
        $this->assertFalse($checker->check($attempt->id, 0, 10));
        $this->assertCount(1, $this->sdk()->statusCalls);
        // A check that outlived its lease writes nothing.
        $this->assertFalse(
            $this->attempts()->storeStatus(
                $attempt->id,
                $this->clock()->now() + 1,
                Sdk::paidLightning()->toArray(),
                $this->clock()->now()
            )
        );
    }

    /**
     * An order placed with this method, and its invoice.
     *
     * @return array{0: Order, 1: Attempt}
     */
    private function orderWithInvoice(): array
    {
        $order = $this->placeOrder(24.5);

        return [$order, $this->invoiceFor($order)];
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
     * $attempt with $status stored, as a check stores it before applying it.
     *
     * @param Attempt $attempt The attempt.
     * @param PaymentStatus $status The status.
     * @return Attempt
     */
    private function withStatus(Attempt $attempt, PaymentStatus $status): Attempt
    {
        $lease = $this->clock()->now() + 40;
        $this->assertTrue($this->attempts()->claim($attempt->id, $this->clock()->now(), $lease, 0));
        $stored = $this->attempts()->storeStatus($attempt->id, $lease, $status->toArray(), $this->clock()->now());
        $this->assertTrue($stored);
        $stored = $this->attempts()->find($attempt->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    /**
     * Makes $order's last change $seconds old, as the database sees it.
     *
     * @param Order $order The order.
     * @param int $seconds Its age.
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

    /**
     * Asserts that the admin was told order $number needs their attention, once (with $description).
     *
     * @param string $number The order's number.
     * @param string|null $description What the notification says, when it matters.
     * @return void
     */
    private function assertNotified(string $number, ?string $description = null): void
    {
        $inbox = $this->make(InboxCollection::class);
        $inbox->addFieldToFilter('title', 'sattle.me: order ' . $number . ' needs your attention');
        $this->assertSame(1, $inbox->getSize());
        if ($description !== null) {
            $this->assertSame($description, $inbox->getFirstItem()->getData('description'));
        }
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
