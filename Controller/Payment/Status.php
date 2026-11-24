<?php

declare(strict_types=1);

namespace Sattleme\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Sattleme\Payment\Model\Page\Headers;
use Sattleme\Payment\Model\Page\PageRequest;
use Sattleme\Payment\Model\Page\Progress;

/**
 * The payment page's news (its script asks every few seconds): the server's own check of the
 * order's invoice, at most every few seconds per invoice whatever the requests, then the page's
 * phase. Authorized as the page is; it changes nothing a request chooses.
 */
class Status implements HttpGetActionInterface
{
    /**
     * @param RequestInterface $request The request.
     * @param JsonFactory $json Magento's JSON results.
     * @param PageRequest $pageRequest The order of the request.
     * @param Progress $progress The checks of the page.
     * @param Headers $headers The page's headers.
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $json,
        private readonly PageRequest $pageRequest,
        private readonly Progress $progress,
        private readonly Headers $headers
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $result = $this->json->create();
        $this->headers->private($result);
        $order = $this->pageRequest->order($this->request);
        if ($order === null) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'not_found']);
        }

        return $result->setData(['phase' => $this->progress->check($order)]);
    }
}
