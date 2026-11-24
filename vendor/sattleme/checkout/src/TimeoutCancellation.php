<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\Sattleme\Checkout;

/** A cancellation that fires once $seconds have passed (on a monotonic clock) since its creation. */
class TimeoutCancellation implements Cancellation
{
    private readonly float $deadline;
    public function __construct(float $seconds)
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new \InvalidArgumentException('A timeout of zero seconds or more is expected');
        }
        $this->deadline = hrtime(\true) / 1000000000.0 + $seconds;
    }
    public function isCancelled(): bool
    {
        return hrtime(\true) / 1000000000.0 >= $this->deadline;
    }
}
