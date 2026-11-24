<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\Sattleme\Checkout\Internal\Loop;

/**
 * A wait whose deadline passed.
 *
 * @internal
 */
class TimeoutException extends \RuntimeException
{
}
