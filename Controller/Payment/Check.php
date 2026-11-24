<?php

declare(strict_types=1);

namespace Sattleme\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Sattleme\Payment\Model\Page\PageRequest;
use Sattleme\Payment\Model\Page\Progress;
use Sattleme\Payment\Model\PageAccess;

/**
 * The payment page's button "Check the payment", for browsers without JavaScript: the server's
 * own check, as the status endpoint's, then the page again. A POST with Magento's form key.
 */
class Check implements HttpPostActionInterface
{
    /**
     * @param RequestInterface $request The request.
     * @param RedirectFactory $redirects Magento's redirects.
     * @param ForwardFactory $forwards Magento's forwards (the "not found" page).
     * @param PageRequest $pageRequest The order of the request.
     * @param Progress $progress The checks of the page.
     * @param PageAccess $access The pages' secrets.
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirects,
        private readonly ForwardFactory $forwards,
        private readonly PageRequest $pageRequest,
        private readonly Progress $progress,
        private readonly PageAccess $access
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $order = $this->pageRequest->order($this->request);
        if ($order === null) {
            return $this->forwards->create()->forward('noroute');
        }
        $this->progress->check($order);

        return $this->redirects->create()->setUrl((string) $this->access->url($order));
    }
}
