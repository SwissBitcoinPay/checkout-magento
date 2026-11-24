<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\AdminNotification\Model\ResourceModel\Inbox\Collection as InboxCollection;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Sattleme\Payment\Cron\CheckPayments;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\AttemptRepository;
use Sattleme\Payment\Model\Background;
use Sattleme\Payment\Model\Schedule;
use Sattleme\Payment\Sdk\Errors;
use Sattleme\Payment\Test\Support\Sdk;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\PaymentStatus;

/**
 * The checks in the background (Magento's cron, every minute: the SDK has no webhooks): the
 * invoices due, bounded in number and in time, rescheduled, and given up on after 90 days.
 *
 * @magentoAppArea crontab
 * @magentoDbIsolation enabled
 */
class BackgroundTest extends OrderTestCase
{
    /**
     * Only this test's invoices are due: those that tests without database isolation left are
     * left out (in this test's transaction, rolled back after it).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $resource = $this->get(ResourceConnection::class);
        $table = $resource->getTableName(AttemptRepository::TABLE);
        $resource->getConnection()->update($table, ['next_check_at' => null]);
        $resource->getConnection()->update(
            $resource->getTableName(AttemptRepository::TABLE),
            ['created_at' => $this->clock()->now()],
            ['is_final = ?' => 0]
        );
    }

    public function testTheCronChecksTheInvoicesWhoseCheckIsDue(): void
    {
        $first = $this->invoiceFor($this->placeOrder());
        $second = $this->invoiceFor($this->placeOrder());

        $this->assertSame(0, $this->runOnce(), 'A minute after they were made');

        $this->clock()->advance(Schedule::OPEN_INTERVAL);
        $this->assertSame(2, $this->runOnce());

        $this->assertCount(2, $this->sdk()->statusCalls);
        foreach ([$first, $second] as $attempt) {
            $checked = $this->attempts()->find($attempt->id);
            $this->assertNotNull($checked);
            $this->assertSame('open', $checked->statusState());
            $this->assertSame($this->clock()->now() + Schedule::OPEN_INTERVAL - Schedule::SLACK, $checked->nextCheckAt);
        }
    }

    public function testTheNextCheckIsCountedFromTheRunsStartNotFromTheEndOfItsCheck(): void
    {
        $attempt = $this->invoiceFor($this->placeOrder());
        $this->clock()->advance(Schedule::OPEN_INTERVAL);
        $start = $this->clock()->now();
        // The check takes 15 seconds.
        $this->sdk()->statuses = [
            function (array $invoice): PaymentStatus {
                $this->clock()->advance(15);
                $expiresAt = $invoice['expiresAt'] ?? null;

                return Sdk::open(is_int($expiresAt) ? $expiresAt : 0);
            },
        ];
        $this->assertSame(1, $this->runOnce());
        $this->assertSame($start + Schedule::OPEN_INTERVAL - Schedule::SLACK, $this->next($attempt));

        // The cron's next run, a minute after this one started (and even a few seconds sooner).
        $this->clock()->set($start + Schedule::OPEN_INTERVAL - 5);
        $this->assertSame(1, $this->runOnce(), 'Checked again at the next run, not the one after');
        $this->assertCount(2, $this->sdk()->statusCalls);
    }

    public function testTheCronRunsMagentosJobOfTheModule(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->clock()->advance(Schedule::OPEN_INTERVAL);
        $this->sdk()->statuses = [Sdk::paidLightning()];

        $this->get(CheckPayments::class)->execute();

        $this->assertSame(Order::STATE_PROCESSING, $this->order($this->idOf($order))->getState());
        $final = $this->attempts()->find($attempt->id);
        $this->assertNotNull($final);
        $this->assertTrue($final->isFinal);
        $this->assertNull($final->nextCheckAt, 'Never checked again');
    }

    public function testTheChecksFollowTheInvoiceUntilItsFinalStatus(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $end = (int) $attempt->expiresAt + Attempt::GRACE_SECONDS;

        // Every minute while payable, then just before the end of the grace period, and after it.
        $this->clock()->set($end - Schedule::BEFORE_END - 30);
        $this->runOnce();
        $this->assertSame($end - Schedule::BEFORE_END, $this->next($attempt));
        $this->clock()->set($end - Schedule::BEFORE_END);
        $this->runOnce();
        $this->assertSame(
            $end - Schedule::BEFORE_END + Schedule::OPEN_INTERVAL - Schedule::SLACK,
            $this->next($attempt)
        );
        $this->clock()->set($end - Schedule::BEFORE_END + Schedule::OPEN_INTERVAL);
        $this->runOnce();
        $this->assertSame($end + Schedule::AFTER_END, $this->next($attempt));

        // On-chain, being confirmed: every 5 minutes.
        $this->clock()->set($end + Schedule::AFTER_END);
        $this->sdk()->statuses = [Sdk::unconfirmed('59000', 1)];
        $this->runOnce();
        $this->assertSame(
            $this->clock()->now() + Schedule::SETTLING_INTERVAL - Schedule::SLACK,
            $this->next($attempt)
        );

        // Failing: less often.
        $this->clock()->advance(Schedule::SETTLING_INTERVAL);
        $this->sdk()->statuses = [new NetworkException()];
        $this->runOnce();
        $this->assertSame($this->clock()->now() + Schedule::OPEN_INTERVAL - Schedule::SLACK, $this->next($attempt));
        $this->clock()->advance(Schedule::OPEN_INTERVAL);
        $this->runOnce();
        $this->assertSame($this->clock()->now() + 2 * Schedule::OPEN_INTERVAL - Schedule::SLACK, $this->next($attempt));

        $this->clock()->advance(2 * Schedule::OPEN_INTERVAL);
        $this->sdk()->statuses = [Sdk::paidOnchain('59000')];
        $this->runOnce();
        $this->assertNull($this->next($attempt));
        $this->assertSame(Order::STATE_PROCESSING, $this->order($this->idOf($order))->getState());
    }

    public function testARunChecksTwentyInvoicesAtMost(): void
    {
        $attempts = [];
        for ($i = 0; $i < Background::BATCH + 2; $i++) {
            $attempts[] = $this->invoiceFor($this->placeOrder(5.0));
        }
        $this->clock()->advance(Schedule::OPEN_INTERVAL);

        $this->assertSame(Background::BATCH, $this->runOnce());
        $this->assertSame(2, $this->runOnce(), 'The others at the next run');
        $this->assertCount(Background::BATCH + 2, $this->sdk()->statusCalls);
    }

    public function testARunStartsNoCheckAfter45Seconds(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->invoiceFor($this->placeOrder(5.0));
        }
        $this->clock()->advance(Schedule::OPEN_INTERVAL);
        // Each check takes 10 seconds.
        $this->sdk()->statuses = [
            function (array $invoice): PaymentStatus {
                $this->clock()->advance(10);

                $expiresAt = $invoice['expiresAt'] ?? null;

                return Sdk::open(is_int($expiresAt) ? $expiresAt : 0);
            },
        ];

        $this->assertSame(5, $this->runOnce());
    }

    public function testAnInvoiceWithoutAFinalStatusAfter90DaysIsGivenUpOnAndItsOrderHeld(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::unconfirmed('59000', 0)];
        $this->clock()->advance(Schedule::OPEN_INTERVAL);
        $this->runOnce();
        $resource = $this->get(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName(AttemptRepository::TABLE),
            ['created_at' => $this->clock()->now() - Schedule::GIVE_UP_AFTER - 1],
            ['attempt_id = ?' => $attempt->id]
        );
        $this->clock()->advance(Schedule::SETTLING_INTERVAL);
        $calls = count($this->sdk()->statusCalls);

        $this->assertSame(0, $this->runOnce());

        $this->assertCount($calls, $this->sdk()->statusCalls, 'Not checked again');
        $final = $this->attempts()->find($attempt->id);
        $this->assertNotNull($final);
        $this->assertTrue($final->isFinal);
        $this->assertSame(Attempt::OUTCOME_ABANDONED, $final->outcome);
        $this->assertSame(Errors::ABANDONED, $final->error);
        $held = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_HOLDED, $held->getState());
        $this->assertStringContainsString(
            'The checks of Bitcoin invoice ' . $attempt->ref . ' stopped: no final status after 90 days.',
            implode("\n", $this->notes($order))
        );
        $inbox = $this->make(InboxCollection::class);
        $inbox->addFieldToFilter('title', 'sattle.me: order ' . $order->getIncrementId() . ' needs your attention');
        $this->assertSame(1, $inbox->getSize());
    }

    /**
     * One run of the background checks: how many invoices it checked.
     *
     * @return int
     */
    private function runOnce(): int
    {
        return $this->get(Background::class)->run();
    }

    /**
     * When $attempt is checked next in the background.
     *
     * @param Attempt $attempt The attempt.
     * @return int|null
     */
    private function next(Attempt $attempt): ?int
    {
        return $this->attempts()->find($attempt->id)?->nextCheckAt;
    }
}
