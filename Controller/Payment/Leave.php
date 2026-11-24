<?php

declare(strict_types=1);

namespace Sattleme\Payment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Sattleme\Payment\Model\Page\PageRequest;
use Sattleme\Payment\Model\Page\Progress;
use Sattleme\Payment\Model\PageAccess;

/**
 * The payment page's button "Choose another payment method", offered only while no invoice of
 * the order may be paid (none made, or none could be): the order is cancelled (its stock freed),
 * and the customer's cart given back when it is the checkout session's own last order. A POST
 * with Magento's form key.
 */
class Leave implements HttpPostActionInterface
{
    /**
     * @param RequestInterface $request The request.
     * @param RedirectFactory $redirects Magento's redirects.
     * @param ForwardFactory $forwards Magento's forwards (the "not found" page).
     * @param PageRequest $pageRequest The order of the request.
     * @param Progress $progress The page's phase, and leaving it.
     * @param CheckoutSession $checkoutSession The customer's checkout session.
     * @param ManagerInterface $messages Magento's messages.
     * @param PageAccess $access The pages' secrets.
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirects,
        private readonly ForwardFactory $forwards,
        private readonly PageRequest $pageRequest,
        private readonly Progress $progress,
        private readonly CheckoutSession $checkoutSession,
        private readonly ManagerInterface $messages,
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
        if (!$this->progress->leave($order)) {
            return $this->redirects->create()->setUrl((string) $this->access->url($order));
        }
        $last = $this->checkoutSession->getLastRealOrderId();
        if (is_scalar($last) && (string) $last === $order->getIncrementId()) {
            $this->checkoutSession->restoreQuote();
        }
        $this->messages->addNoticeMessage(
            __(
                'Your order %1 was cancelled: you can place it again with another payment method.',
                $order->getIncrementId()
            )
        );

        return $this->redirects->create()->setPath('checkout/cart');
    }
}
