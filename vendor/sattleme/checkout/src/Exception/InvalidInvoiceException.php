<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\Sattleme\Checkout\Exception;

/** invalid_invoice: an invoice, a pay request or a payment answer failed the checks (SPEC.md §18, §22). */
class InvalidInvoiceException extends SattlemeException
{
    public function __construct(string $message = 'Invalid invoice', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'invalid_invoice', \false, $previous);
    }
}
