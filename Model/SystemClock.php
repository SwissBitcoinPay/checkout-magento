<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

/**
 * The system's clock (synchronized: the SDK refuses an invoice more than 300 s off).
 */
class SystemClock implements ClockInterface
{
    /**
     * @inheritDoc
     */
    public function now(): int
    {
        return time();
    }
}
