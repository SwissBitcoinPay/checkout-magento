<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model\Page;

use Magento\Framework\Controller\AbstractResult;

/**
 * The headers of every response of the payment page: never cached (by the browser, a proxy or
 * Varnish), never indexed.
 */
class Headers
{
    /**
     * Sets them on $result.
     *
     * @param AbstractResult $result The response.
     * @return void
     */
    public function private(AbstractResult $result): void
    {
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $result->setHeader('Referrer-Policy', 'no-referrer', true);
    }
}
