<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\Sattleme\Checkout\Exception;

/** revoked: the merchant removed the integration. */
class RevokedException extends SattlemeException
{
    public function __construct(string $message = 'The merchant removed this integration', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'revoked', \false, $previous);
    }
}
