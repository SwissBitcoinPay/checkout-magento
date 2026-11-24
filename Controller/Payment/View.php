<?php

declare(strict_types=1);

namespace Sattleme\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Sattleme\Payment\Model\Page\CurrentOrder;
use Sattleme\Payment\Model\Page\Headers;
use Sattleme\Payment\Model\Page\PageRequest;

/**
 * The order's payment page, in the shop's theme and language: authorized by the order's page
 * secret, never cached, not indexed. It works without JavaScript; its script (a file of the
 * module) counts down, copies, and reloads it when the payment moves on.
 */
class View implements HttpGetActionInterface
{
    /**
     * @param RequestInterface $request The request.
     * @param PageFactory $pages Magento's pages.
     * @param ForwardFactory $forwards Magento's forwards (the "not found" page).
     * @param PageRequest $pageRequest The order of the request.
     * @param CurrentOrder $current The order of the page.
     * @param Headers $headers The page's headers.
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $pages,
        private readonly ForwardFactory $forwards,
        private readonly PageRequest $pageRequest,
        private readonly CurrentOrder $current,
        private readonly Headers $headers
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
        $this->current->set($order);
        $page = $this->pages->create();
        $page->getConfig()->getTitle()->set((string) __('Pay with Bitcoin'));
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $this->headers->private($page);

        return $page;
    }
}
