<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\Sattleme\Checkout;

/**
 * A call stopped by its Cancellation (SPEC.md §4). Not a SattlemeException: the platform's
 * own cancellation, never an error of SPEC.md §5.
 */
class CancelledException extends \RuntimeException
{
    public function __construct(string $message = 'The call was cancelled')
    {
        parent::__construct($message);
    }
}
