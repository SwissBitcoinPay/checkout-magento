<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Support;

use Sattleme\Payment\Model\DatabaseClock;

/**
 * The database's clock, stopped by the tests: the database's time when reset() read it, plus what
 * they advanced, so that no test depends on how fast the machine runs it.
 */
class FakeDatabaseClock extends DatabaseClock
{
    /**
     * @var int|null
     */
    private ?int $now = null;

    /**
     * @inheritDoc
     */
    public function now(): int
    {
        return $this->now ??= parent::now();
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

    /**
     * Back to the database's own time, read now.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->now = parent::now();
    }
}
