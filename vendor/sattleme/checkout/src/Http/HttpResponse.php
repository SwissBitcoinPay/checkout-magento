<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\Sattleme\Checkout\Http;

/** An HTTP answer: its status and its body, at most 1 MiB. */
class HttpResponse
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
    }
}
