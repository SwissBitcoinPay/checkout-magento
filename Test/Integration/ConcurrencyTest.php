<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Lock\Backend\Cache as CacheLock;
use Magento\Framework\Lock\Backend\Database as DatabaseLock;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\Checker;
use Sattleme\Payment\Model\OrderLock;
use Sattleme\Payment\Model\PaymentFailure;
use Sattleme\Payment\Model\Schedule;
use Sattleme\Payment\Model\Settlement;
use Sattleme\Payment\Plugin\OrderCancelGuard;
use Sattleme\Payment\Sdk\Errors;
use Sattleme\Payment\Test\Support\Sdk;

/**
 * Two requests on one order at once (a double click, two PHP workers, the page and the cron):
 * the other one in a process of its own, holding the order's lock in the database. Committed
 * data: the other process sees no transaction of the test.
 *
 * Magento's integration test framework replaces its lock manager with one that locks nothing
 * (TestFramework\Lock\Backend\DummyLocker): these tests give the module the database's,
 * Magento's own (Framework\Lock\Backend\Database), which a shop uses by default.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class ConcurrencyTest extends OrderTestCase
{
    /**
     * The module's lock on Magento's database lock.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->om()->addSharedInstance(
            $this->make(OrderLock::class, ['locks' => $this->databaseLock()]),
            OrderLock::class
        );
    }

    public function testTheModuleLocksTheOrderInTheDatabase(): void
    {
        $order = $this->placeOrder();
        $lock = $this->get(OrderLock::class);
        $database = $this->databaseLock();

        $name = OrderLock::PREFIX . $this->idOf($order);
        $held = $lock->run($this->idOf($order), 1, fn(): bool => $database->isLocked($name));

        $this->assertTrue($held);
        $this->assertFalse($database->isLocked($name));
    }

    public function testARequestWaitsForAnotherMakingTheInvoiceAndShowsThatInvoice(): void
    {
        $order = $this->placeOrder();
        $ref = 'm2-' . $order->getIncrementId() . '-' . str_repeat('c', 32);
        $now = $this->clock()->now();
        $invoice = Sdk::invoice(['ref' => $ref, 'fiat' => ['amount' => '29.5', 'currency' => 'USD']], $now);
        $other = $this->otherRequest($order, 1.0, [
            'order_id' => $this->idOf($order),
            'store_id' => 1,
            'ref' => $ref,
            'state' => Attempt::STATE_INVOICE,
            'invoice' => json_encode($invoice->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'expires_at' => $invoice->expiresAt,
            'created_at' => $now,
            'next_check_at' => $now + 60,
        ]);

        $attempt = $this->invoiceFor($order);

        $this->assertSame("done\n", (string) fgets($other['out']));
        $this->assertSame(0, $this->finish($other));
        $this->assertSame($ref, $attempt->ref, 'The other request\'s invoice');
        $this->assertSame([], $this->sdk()->createCalls, 'No second invoice');
        $this->assertCount(1, $this->attemptsOf($order));
    }

    public function testALockOfTheCacheProviderLeftByARequestThatDiedKeepsNoOrderFromBeingCancelled(): void
    {
        // Magento's lock provider "cache" (env.php, lock/provider): a lock its holder never
        // released (a worker killed) stays for 7200 seconds, or until the cache is cleaned.
        $cache = $this->make(CacheLock::class);
        $order = $this->placeOrder();
        $name = OrderLock::PREFIX . $this->idOf($order);
        $this->assertTrue($cache->lock($name, 1));
        try {
            $this->assertTrue($cache->isLocked($name));

            $guard = $this->get(OrderCancelGuard::class);
            $this->assertTrue($guard->afterCanCancel($this->order($this->idOf($order)), true));
            $this->assertTrue($this->order($this->idOf($order))->canCancel());
        } finally {
            $cache->unlock($name);
        }
    }

    public function testAPaymentCheckedWhileALockOfTheCacheProviderIsLeftIsRecordedOnceItIsGone(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        // The module on the cache provider, and a request that died leaving the order's lock there.
        $lock = $this->make(OrderLock::class, ['locks' => $this->make(CacheLock::class)]);
        $checker = $this->make(Checker::class, ['settlement' => $this->make(Settlement::class, ['lock' => $lock])]);
        $dead = $this->make(CacheLock::class);
        $name = OrderLock::PREFIX . $this->idOf($order);
        $this->assertTrue($dead->lock($name, 1));
        $this->sdk()->statuses = [Sdk::paidLightning(), Sdk::paidLightning()];

        $checker->check($attempt->id, 0, 10);
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($this->idOf($order))->getState());
        $failed = $this->attempts()->find($attempt->id);
        $this->assertNotNull($failed);
        $this->assertSame(Errors::LOCK, $failed->error);
        $this->assertFalse($failed->isFinal, 'Checked again later');

        // Magento's command bin/magento cache:clean config removes it, as its lifetime's end does.
        $this->get(CacheManager::class)->clean([ConfigCache::TYPE_IDENTIFIER]);
        $this->assertFalse($dead->isLocked($name));
        $this->clock()->advance(Schedule::MAX_BACKOFF);
        $checker->check($attempt->id, 0, 10);

        $this->assertSame(Order::STATE_PROCESSING, $this->order($this->idOf($order))->getState());
    }

    public function testARequestThatWaitedLongForAnotherMakesNoInvoiceItself(): void
    {
        $order = $this->placeOrder();
        $other = $this->otherRequest($order, 6.0, null);

        try {
            $this->invoiceFor($order);
            $this->fail('An invoice was made');
        } catch (PaymentFailure $e) {
            $this->assertSame(PaymentFailure::TRANSIENT, $e->getKind());
        }

        $this->assertSame(0, $this->finish($other));
        $this->assertSame([], $this->sdk()->createCalls);
        // The next request makes it.
        $this->assertSame(Attempt::STATE_INVOICE, $this->invoiceFor($order)->state);
        $this->assertCount(1, $this->sdk()->createCalls);
    }

    public function testAnOrderThatCouldNotBeUpdatedIsUpdatedByALaterCheck(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $repository = $this->get(OrderRepositoryInterface::class);
        $failing = new class ($repository) implements OrderRepositoryInterface {
            /**
             * @param OrderRepositoryInterface $repository Magento's.
             */
            public function __construct(private readonly OrderRepositoryInterface $repository)
            {
            }

            /**
             * @inheritDoc
             */
            public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria)
            {
                return $this->repository->getList($searchCriteria);
            }

            /**
             * @inheritDoc
             */
            public function get($id)
            {
                return $this->repository->get($id);
            }

            /**
             * @inheritDoc
             */
            public function delete(OrderInterface $entity)
            {
                return $this->repository->delete($entity);
            }

            /**
             * @inheritDoc
             */
            public function save(OrderInterface $entity)
            {
                throw new CouldNotSaveException(__('The database is gone'));
            }
        };
        $settlement = $this->make(Settlement::class, ['orderRepository' => $failing]);
        $checker = $this->make(Checker::class, ['settlement' => $settlement]);
        $this->sdk()->statuses = [Sdk::paidLightning()];

        $this->assertTrue($checker->check($attempt->id, 0, 10));

        $failed = $this->attempts()->find($attempt->id);
        $this->assertNotNull($failed);
        $this->assertSame(Errors::APPLY, $failed->error);
        $this->assertFalse($failed->isFinal);
        $this->assertNull($failed->leaseUntil);
        $unchanged = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $unchanged->getState());
        $this->assertCount(0, $unchanged->getInvoiceCollection());

        $this->clock()->advance(60);
        $this->assertTrue($this->get(Checker::class)->check($attempt->id, 0, 10));
        $paid = $this->order($this->idOf($order));
        $this->assertSame(Order::STATE_PROCESSING, $paid->getState());
        $this->assertCount(1, $paid->getInvoiceCollection());
    }

    /**
     * Magento's database lock (the test framework's preference for it locks nothing).
     *
     * @return DatabaseLock
     */
    private function databaseLock(): DatabaseLock
    {
        return new DatabaseLock(
            $this->get(ResourceConnection::class),
            $this->get(DeploymentConfig::class)
        );
    }

    /**
     * Starts another request on $order: it holds the order's lock, once this one waits for it,
     * $hold seconds more, then stores $attempt (a row of the attempts' table) and lets go.
     *
     * @param Order $order The order.
     * @param float $hold Seconds it holds the lock while this one waits.
     * @param array<string, mixed>|null $attempt The attempt it stores.
     * @return array{process: resource, out: resource}
     */
    private function otherRequest(Order $order, float $hold, ?array $attempt): array
    {
        $db = $this->get(DeploymentConfig::class)->get('db/connection/default');
        $this->assertIsArray($db);
        $setting = static fn(string $name): string => is_string($db[$name] ?? null) ? $db[$name] : '';
        [$host, $port] = array_pad(explode(':', $setting('host'), 2), 2, '3306');
        $lock = $setting('dbname') . '|' . OrderLock::PREFIX . $this->idOf($order);
        $command = [
            PHP_BINARY, __DIR__ . '/_files/other_request.php', $host, $port, $setting('dbname'),
            $setting('username'), $setting('password'), $lock, (string) $hold,
        ];
        if ($attempt !== null) {
            $command[] = json_encode($attempt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        $this->assertIsResource($process);
        // It holds the lock once it says so.
        $this->assertSame("locked\n", (string) fgets($pipes[1]));

        return ['process' => $process, 'out' => $pipes[1]];
    }

    /**
     * Waits for the other request to end: its exit code.
     *
     * @param array{process: resource, out: resource} $other The other request.
     * @return int
     */
    private function finish(array $other): int
    {
        stream_get_contents($other['out']);
        fclose($other['out']);

        return proc_close($other['process']);
    }
}
