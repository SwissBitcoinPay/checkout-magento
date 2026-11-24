<?php

declare(strict_types=1);

namespace Sattleme\Payment\ViewModel;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order;
use Sattleme\Payment\Model\Amounts;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\AttemptRepository;
use Sattleme\Payment\Model\ClockInterface;
use Sattleme\Payment\Model\CustomerMessages;
use Sattleme\Payment\Model\Orders;
use Sattleme\Payment\Model\Page\CurrentOrder;
use Sattleme\Payment\Model\PageAccess;
use Sattleme\Payment\Model\PagePhase;
use Sattleme\Payment\Model\PaymentFailure;
use Sattleme\Payment\Model\Qr;
use Sattleme\Payment\Model\Schedule;

/**
 * What the payment page shows, from the order its controller authorized and what the module
 * stored: never from the request. The template escapes every value it prints.
 *
 */
class PaymentPage implements ArgumentInterface
{
    /**
     * @var array{phase: string, attempt: ?Attempt}|null
     */
    private ?array $state = null;

    /**
     * @param CurrentOrder $current The page's order.
     * @param AttemptRepository $attempts The attempts.
     * @param PageAccess $access The pages' secrets and links.
     * @param Qr $qr The QR codes.
     * @param ClockInterface $clock The clock.
     * @param ResolverInterface $locale The customer's locale.
     * @param CheckoutSession $checkoutSession The customer's checkout session.
     * @param CustomerSession $customerSession The customer's session.
     * @param UrlInterface $urls Magento's URLs.
     * @param Json $json Magento's JSON.
     * @param Amounts $amounts Amounts in sats.
     * @param PagePhase $phases The payments' phases.
     * @param CustomerMessages $messages What the customer reads of a failure.
     * @param Orders $orders The orders.
     */
    public function __construct(
        private readonly CurrentOrder $current,
        private readonly AttemptRepository $attempts,
        private readonly PageAccess $access,
        private readonly Qr $qr,
        private readonly ClockInterface $clock,
        private readonly ResolverInterface $locale,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly UrlInterface $urls,
        private readonly Json $json,
        private readonly Amounts $amounts,
        private readonly PagePhase $phases,
        private readonly CustomerMessages $messages,
        private readonly Orders $orders
    ) {
    }

    /**
     * The page's order.
     *
     * @return Order|null
     */
    public function order(): ?Order
    {
        return $this->current->get();
    }

    /**
     * The phase of the payment (PagePhase::*).
     *
     * @return string
     */
    public function phase(): string
    {
        return $this->state()['phase'];
    }

    /**
     * The attempt the page shows: the live one, else the latest.
     *
     * @return Attempt|null
     */
    public function attempt(): ?Attempt
    {
        return $this->state()['attempt'];
    }

    /**
     * The order's number.
     *
     * @return string
     */
    public function orderNumber(): string
    {
        return (string) $this->order()?->getIncrementId();
    }

    /**
     * The QR code of what is to pay as an inline SVG image, or null.
     *
     * The invoice, or the rest of an underpayment.
     *
     * @return string|null
     */
    public function qrSvg(): ?string
    {
        $uri = $this->paymentUri();

        return $uri === null ? null : $this->qr->svg($uri, (string) __('QR code of the Bitcoin invoice'));
    }

    /**
     * What the wallet opens: the invoice's payment URI, or the rest's.
     *
     * BIP-21 with lightning=, or lightning:.
     *
     * @return string|null
     */
    public function paymentUri(): ?string
    {
        $attempt = $this->attempt();
        if ($attempt === null || $this->phase() !== PagePhase::PAYABLE) {
            return null;
        }
        $rest = $attempt->rest();
        if ($rest !== null) {
            return $rest['paymentUri'];
        }

        return $attempt->paymentUri();
    }

    /**
     * The Lightning invoice to copy (the rest's, with an underpayment), or null.
     *
     * @return string|null
     */
    public function bolt11(): ?string
    {
        $attempt = $this->attempt();
        if ($attempt === null || $this->phase() !== PagePhase::PAYABLE) {
            return null;
        }
        $rest = $attempt->rest();

        return $rest !== null ? $rest['bolt11'] : $attempt->bolt11();
    }

    /**
     * The deposit address to copy, or null.
     *
     * @return string|null
     */
    public function address(): ?string
    {
        $attempt = $this->attempt();
        if ($attempt === null || $this->phase() !== PagePhase::PAYABLE) {
            return null;
        }
        $rest = $attempt->rest();
        if ($rest !== null && $this->amounts->isLess($rest['sats'], '1000')) {
            return null; // A rest below 1 000 sats is payable in Lightning only.
        }

        return $attempt->onchainAddress();
    }

    /**
     * The amount to pay now, in sats (the rest, with an underpayment), formatted.
     *
     * @return string
     */
    public function sats(): string
    {
        $attempt = $this->attempt();
        if ($attempt === null) {
            return '0';
        }
        $rest = $attempt->rest();

        return $this->amounts->sats($rest !== null ? $rest['sats'] : $attempt->sats(), $this->locale->getLocale());
    }

    /**
     * The amount to pay now, in bitcoin.
     *
     * @return string
     */
    public function btc(): string
    {
        $attempt = $this->attempt();
        if ($attempt === null) {
            return '0';
        }
        $rest = $attempt->rest();

        return $this->amounts->btc($rest !== null ? $rest['sats'] : $attempt->sats());
    }

    /**
     * The invoice's amount, in sats, formatted (the whole of it, an underpayment's too).
     *
     * @return string
     */
    public function invoiceSats(): string
    {
        return $this->amounts->sats($this->attempt()?->sats() ?? '0', $this->locale->getLocale());
    }

    /**
     * What came already, in sats, formatted (an underpayment, or a payment being confirmed).
     *
     * @return string
     */
    public function receivedSats(): string
    {
        return $this->amounts->sats($this->attempt()?->received() ?? '0', $this->locale->getLocale());
    }

    /**
     * Whether an underpayment's rest is to pay.
     *
     * @return bool
     */
    public function hasRest(): bool
    {
        return $this->attempt()?->rest() !== null && $this->phase() === PagePhase::PAYABLE;
    }

    /**
     * The invoice's price in the order's currency, formatted ("CHF 24.50"), or "".
     *
     * @return string
     */
    public function price(): string
    {
        $fiat = $this->attempt()?->fiat();
        $order = $this->order();
        if ($fiat === null || $order === null || !is_numeric($fiat['amount'])) {
            return '';
        }

        return $order->getOrderCurrency()->formatTxt((float) $fiat['amount']);
    }

    /**
     * When the invoice ends, in Unix seconds, or null.
     *
     * @return int|null
     */
    public function expiresAt(): ?int
    {
        return $this->attempt()?->expiresAt;
    }

    /**
     * The time left to pay, in seconds (0 once over).
     *
     * @return int
     */
    public function secondsLeft(): int
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt === null ? 0 : max(0, $expiresAt - $this->clock->now());
    }

    /**
     * The time left to pay, as "mm:ss" (or "h:mm:ss").
     *
     * @return string
     */
    public function timeLeft(): string
    {
        $left = $this->secondsLeft();
        $hours = intdiv($left, 3600);
        $minutes = intdiv($left % 3600, 60);
        $seconds = $left % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * The confirmations of a payment being confirmed.
     *
     * @return int
     */
    public function confirmations(): int
    {
        return (int) $this->attempt()?->confirmations();
    }

    /**
     * Why no invoice could be made, in the customer's words (the FAILED phase).
     *
     * @return string
     */
    public function failure(): string
    {
        $attempt = $this->attempt();

        return (string) $this->messages->message($this->messages->kindOf($attempt === null ? '' : $attempt->error));
    }

    /**
     * Whether the failure may pass if the customer tries again (the network, the time).
     *
     * @return bool
     */
    public function isTransientFailure(): bool
    {
        $attempt = $this->attempt();

        return $this->messages->kindOf($attempt === null ? '' : $attempt->error) === PaymentFailure::TRANSIENT;
    }

    /**
     * Whether the page offers "Try again" (or "Show my Bitcoin invoice").
     *
     * @return bool
     */
    public function canCreate(): bool
    {
        return $this->phases->canCreate($this->phase());
    }

    /**
     * Whether the page offers "Choose another payment method".
     *
     * @return bool
     */
    public function canLeave(): bool
    {
        return $this->phases->canLeave($this->phase());
    }

    /**
     * Whether the page checks the payment (its script, and its button "Check the payment").
     *
     * @return bool
     */
    public function isChecked(): bool
    {
        return $this->phases->isChecked($this->phase());
    }

    /**
     * The link of a page of this order's payment ($route), or "".
     *
     * @param string $route The route, such as "sattleme/payment/check".
     * @return string
     */
    public function url(string $route): string
    {
        $order = $this->order();

        return $order === null ? '' : (string) $this->access->url($order, $route);
    }

    /**
     * Where "Continue" leads once paid.
     *
     * Magento's checkout success page when the order is the checkout session's own last one,
     * else the customer's order, else the shop.
     *
     * @return string
     */
    public function continueUrl(): string
    {
        $order = $this->order();
        $last = $this->checkoutSession->getLastRealOrderId();
        if ($order !== null && is_scalar($last) && (string) $last === $order->getIncrementId()) {
            return $this->urls->getUrl('checkout/onepage/success');
        }
        if ($order !== null
            && $this->customerSession->isLoggedIn()
            && (int) $this->customerSession->getCustomerId() === (int) $order->getCustomerId()
        ) {
            return $this->urls->getUrl('sales/order/view', ['order_id' => $this->orders->idOf($order)]);
        }

        return $this->urls->getBaseUrl();
    }

    /**
     * The page script's options (data-mage-init), as JSON.
     *
     * @return string
     */
    public function scriptConfig(): string
    {
        $checked = $this->isChecked();

        return (string) $this->json->serialize([
            'Sattleme_Payment/js/payment-page' => [
                'statusUrl' => $checked ? $this->url('sattleme/payment/status') : '',
                'phase' => $this->phase(),
                'secondsLeft' => $this->phase() === PagePhase::PAYABLE ? $this->secondsLeft() : 0,
                'interval' => $this->phase() === PagePhase::CONFIRMING ? 30 : 3,
                'minInterval' => Schedule::WEB_INTERVAL,
            ],
        ]);
    }

    /**
     * The phase and the attempt shown, read once per page.
     *
     * @return array{phase: string, attempt: ?Attempt}
     */
    private function state(): array
    {
        if ($this->state === null) {
            $order = $this->order();
            if ($order === null) {
                return $this->state = ['phase' => PagePhase::CLOSED, 'attempt' => null];
            }
            $orderId = $this->orders->idOf($order);
            $now = $this->clock->now();
            $latest = $this->attempts->latestForOrder($orderId);
            $live = $this->attempts->liveForOrder($orderId, $now);
            $this->state = [
                'phase' => $this->phases->of((string) $order->getState(), $latest, $live, $now),
                'attempt' => $live ?? $latest,
            ];
        }

        return $this->state;
    }
}
