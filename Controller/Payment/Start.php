<?php

declare(strict_types=1);

namespace Sattleme\Payment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Sattleme\Payment\Model\InvoiceCreator;
use Sattleme\Payment\Model\Orders;
use Sattleme\Payment\Model\PageAccess;

/**
 * Where the checkout sends the customer once the order is placed (the method's
 * order_place_redirect_url): the order of the customer's checkout session gets its invoice, and
 * the customer its payment page. Only the session's own last order: nothing in the request
 * chooses an order. Should the invoice fail, the page says why.
 */
class Start implements HttpGetActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession The customer's checkout session.
     * @param RedirectFactory $redirects Magento's redirects.
     * @param InvoiceCreator $creator The invoices.
     * @param PageAccess $access The pages' secrets.
     * @param Orders $orders The orders.
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirects,
        private readonly InvoiceCreator $creator,
        private readonly PageAccess $access,
        private readonly Orders $orders
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $order = $this->checkoutSession->getLastRealOrder();
        $url = $this->orders->idOf($order) > 0 && $this->orders->isOurs($order) ? $this->access->url($order) : null;
        if ($url === null) {
            return $this->redirects->create()->setPath('checkout/cart');
        }
        // Made now, or the page tells why not.
        $this->creator->tryInvoiceFor($this->orders->idOf($order));

        return $this->redirects->create()->setUrl($url);
    }
}
