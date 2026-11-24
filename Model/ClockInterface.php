<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

/**
 * The time, in Unix seconds: the system's in production, a fake one in the tests.
 *
 * @api
 */
interface ClockInterface
{
    /**
     * Now, in Unix seconds.
     *
     * @return int
     */
    public function now(): int;
}
