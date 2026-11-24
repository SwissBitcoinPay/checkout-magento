<?php

declare(strict_types=1);

namespace Sattleme\Payment\Block\Order;

use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\Order;
use Sattleme\Payment\Model\Amounts;
use Sattleme\Payment\Model\AttemptRepository;
use Sattleme\Payment\Model\Orders;
use Sattleme\Payment\Model\Page\Progress;
use Sattleme\Payment\Model\PageAccess;
use Sattleme\Payment\Model\PagePhase;

/**
 * In the customer's view of an order (their account, or the guests' "Orders and Returns"), which
 * Magento authorized: where its bitcoin payment is, as its payment page says it.
 *
 * - "Pay with Bitcoin", the link of the payment page, only while the order may get paid there: no
 *   invoice yet, one that can be paid (or its rest), or none that could be made (try again);
 * - while a payment is being checked or confirmed: that, and the link of the page that follows
 *   it, never an offer to pay;
 * - once an invoice ended underpaid (or its checks stopped), while the order waits for the shop:
 *   what came, as the payment page says it, and no link (no new invoice then).
 */
class PayLink extends Template
{
    /**
     * @var string
     */
    protected $_template = 'Sattleme_Payment::order/pay-link.phtml';

    /**
     * @param Context $context The block's context.
     * @param Registry $registry Magento's registry (the order view's current order).
     * @param PageAccess $access The pages' links.
     * @param Progress $progress The payments' phases.
     * @param Orders $orders The orders.
     * @param AttemptRepository $attempts The attempts.
     * @param Amounts $amounts Amounts in sats.
     * @param ResolverInterface $locale The customer's locale.
     * @param array<string,mixed> $data The block's data.
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly PageAccess $access,
        private readonly Progress $progress,
        private readonly Orders $orders,
        private readonly AttemptRepository $attempts,
        private readonly Amounts $amounts,
        private readonly ResolverInterface $locale,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * What to tell the customer of the order's bitcoin payment.
     *
     * Its message, and the payment page's link with its label when there is one to follow; null
     * when there is nothing to say.
     *
     * @return array{message: Phrase, url: string|null, label: Phrase|null, kind: string}|null
     */
    public function getNotice(): ?array
    {
        $order = $this->registry->registry('current_order');
        if (!$order instanceof Order || !$this->orders->isOurs($order)) {
            return null;
        }
        $state = $order->getState();
        $waiting = $state === Order::STATE_PENDING_PAYMENT;
        $phase = $this->progress->phase($order);
        if ($waiting && in_array($phase, [PagePhase::START, PagePhase::PAYABLE, PagePhase::FAILED], true)) {
            return [
                'message' => __('This order waits for its payment in bitcoin.'),
                'url' => $this->access->url($order),
                'label' => __('Pay with Bitcoin'),
                'kind' => 'notice',
            ];
        }
        if ($waiting && in_array($phase, [PagePhase::CHECKING, PagePhase::CONFIRMING], true)) {
            return [
                'message' => $phase === PagePhase::CONFIRMING
                    ? __(
                        'Your payment came in time. The Bitcoin network is confirming its on-chain '
                        . 'transaction, which takes about 30 minutes.'
                    )
                    : __('Your bitcoin payment is being checked.'),
                'url' => $this->access->url($order),
                'label' => __('Follow the payment'),
                'kind' => 'notice',
            ];
        }
        if (!$waiting && $state !== Order::STATE_HOLDED) {
            return null; // Paid, cancelled, or settled by the shop: its own pages say it.
        }

        return match ($phase) {
            PagePhase::UNDERPAID => [
                'message' => $this->underpaid($order),
                'url' => null,
                'label' => null,
                'kind' => 'warning',
            ],
            PagePhase::ABANDONED => [
                'message' => __('This payment could not be confirmed. Please contact the shop.'),
                'url' => null,
                'label' => null,
                'kind' => 'warning',
            ],
            default => null,
        };
    }

    /**
     * The payment page's words for an invoice that ended underpaid: what came, of what it asked.
     *
     * @param Order $order The order.
     * @return Phrase
     */
    private function underpaid(Order $order): Phrase
    {
        $attempt = $this->attempts->latestForOrder($this->orders->idOf($order));
        $locale = $this->locale->getLocale();

        return __(
            'The time to pay is over, and %1 of %2 sats came. The shop will contact you to settle the difference.',
            $this->amounts->sats($attempt?->received() ?? '0', $locale),
            $this->amounts->sats($attempt?->sats() ?? '0', $locale)
        );
    }
}
