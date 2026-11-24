<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\Sattleme\Checkout\Internal;

use Sattleme\Payment\Vendor\Sattleme\Checkout\Cancellation;
/**
 * The cancellation of a call given none.
 *
 * @internal
 */
class NeverCancelled implements Cancellation
{
    public function isCancelled(): bool
    {
        return \false;
    }
}
