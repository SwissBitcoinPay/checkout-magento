<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Support;

use Sattleme\Payment\Model\ClockInterface;

/**
 * A clock the tests set.
 */
class FakeClock implements ClockInterface
{
    /**
     * A clock at $now (the system's time by default: Magento's own dates are the database's).
     *
     * @param int|null $now The time, in Unix seconds.
     */
    public function __construct(private ?int $now = null)
    {
        $this->now ??= time();
    }

    /**
     * @inheritDoc
     */
    public function now(): int
    {
        return (int) $this->now;
    }

    /**
     * Sets the time.
     *
     * @param int $now The time, in Unix seconds.
     * @return void
     */
    public function set(int $now): void
    {
        $this->now = $now;
    }

    /**
     * Moves the time on.
     *
     * @param int $seconds Seconds.
     * @return void
     */
    public function advance(int $seconds): void
    {
        $this->now = $this->now() + $seconds;
    }
}
