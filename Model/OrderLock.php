<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * An order locked while its invoice is made or its payment applied: Magento's lock manager (a
 * named lock of the database, GET_LOCK, unless env.php names another backend), shared by every
 * PHP worker and cron process of the shop. Two requests for one order (a double click, two
 * workers, a check of the page and one of the cron) never run at once.
 *
 * Re-entrant within a process: a check that runs while its order's invoice is made does not wait
 * for itself, whatever the backend.
 */
class OrderLock
{
    public const PREFIX = 'sattleme_payment_order_';

    /**
     * The locks this process holds, by name: how many times each.
     *
     * @var array<string, int>
     */
    private array $held = [];

    /**
     * @param LockManagerInterface $locks Magento's lock manager.
     */
    public function __construct(private readonly LockManagerInterface $locks)
    {
    }

    /**
     * Runs $work with order $orderId locked, waiting $wait seconds at most for the lock.
     *
     * Its result. $work gets the seconds it waited.
     *
     * @template T
     * @param int $orderId The order.
     * @param int $wait Seconds to wait for the lock, at most.
     * @param callable $work What to run.
     * @phpstan-param callable(float): T $work
     * @return T
     * @throws LockUnavailable When the lock could not be had in time.
     */
    public function run(int $orderId, int $wait, callable $work): mixed
    {
        $name = self::PREFIX . $orderId;
        if (($this->held[$name] ?? 0) > 0) {
            ++$this->held[$name];
            try {
                return $work(0.0);
            } finally {
                --$this->held[$name];
            }
        }
        $start = microtime(true);
        if (!$this->locks->lock($name, $wait)) {
            throw new LockUnavailable(sprintf('Order %d is locked by another request', $orderId));
        }
        $this->held[$name] = 1;
        try {
            return $work(microtime(true) - $start);
        } finally {
            unset($this->held[$name]);
            $this->locks->unlock($name);
        }
    }
}
