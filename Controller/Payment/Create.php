<?php

declare(strict_types=1);

namespace Sattleme\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Sattleme\Payment\Model\InvoiceCreator;
use Sattleme\Payment\Model\Orders;
use Sattleme\Payment\Model\Page\PageRequest;
use Sattleme\Payment\Model\Page\Progress;
use Sattleme\Payment\Model\PageAccess;
use Sattleme\Payment\Model\PagePhase;

/**
 * The payment page's buttons "Show my Bitcoin invoice" and "Try again": the order's invoice,
 * when it has none that may be paid (InvoiceCreator decides, the order locked). A POST with
 * Magento's form key.
 */
class Create implements HttpPostActionInterface
{
    /**
     * @param RequestInterface $request The request.
     * @param RedirectFactory $redirects Magento's redirects.
     * @param ForwardFactory $forwards Magento's forwards (the "not found" page).
     * @param PageRequest $pageRequest The order of the request.
     * @param Progress $progress The page's phase.
     * @param InvoiceCreator $creator The invoices.
     * @param PageAccess $access The pages' secrets.
     * @param PagePhase $phases The payments' phases.
     * @param Orders $orders The orders.
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirects,
        private readonly ForwardFactory $forwards,
        private readonly PageRequest $pageRequest,
        private readonly Progress $progress,
        private readonly InvoiceCreator $creator,
        private readonly PageAccess $access,
        private readonly PagePhase $phases,
        private readonly Orders $orders
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
        if ($this->phases->canCreate($this->progress->phase($order))) {
            // Made now, or the page tells why not.
            $this->creator->tryInvoiceFor($this->orders->idOf($order));
        }

        return $this->redirects->create()->setUrl((string) $this->access->url($order));
    }
}
