<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

/**
 * The secret of an order's payment page: 256 random bits made on the server when the order is
 * placed, kept with its payment (additional information), in the page's link, and compared in
 * constant time. The order's number alone never opens the page.
 */
class PageAccess
{
    /**
     * Where the payment keeps it.
     */
    public const TOKEN_KEY = 'sattleme_page_token';

    /**
     * @param UrlInterface $urls Magento's URLs.
     * @param Orders $orders The orders.
     */
    public function __construct(
        private readonly UrlInterface $urls,
        private readonly Orders $orders
    ) {
    }

    /**
     * A new secret: 32 bytes of the CSPRNG, base64url.
     *
     * @return string
     */
    public function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * The secret of $payment's page, or null.
     *
     * @param OrderPaymentInterface|null $payment The order's payment.
     * @return string|null
     */
    public function tokenOf(?OrderPaymentInterface $payment): ?string
    {
        if (!$payment instanceof Payment) {
            return null;
        }
        $token = $payment->getAdditionalInformation(self::TOKEN_KEY);

        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1 ? $token : null;
    }

    /**
     * Whether $given opens the payment page of $order: the order is paid with this method and
     * $given is its secret (compared in constant time).
     *
     * @param Order $order The order.
     * @param mixed $given What the request gave.
     * @return bool
     */
    public function opens(Order $order, mixed $given): bool
    {
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Config::CODE || !is_string($given)) {
            return false;
        }
        $token = $this->tokenOf($payment);

        return $token !== null && hash_equals($token, $given);
    }

    /**
     * The link of a page of $order's payment, in the order's store.
     *
     * $route: the page, its status, its forms.
     *
     * @param Order $order The order.
     * @param string $route The route, such as "sattleme/payment/view".
     * @return string|null Null when the order has no secret.
     */
    public function url(Order $order, string $route = 'sattleme/payment/view'): ?string
    {
        $token = $this->tokenOf($order->getPayment());
        if ($token === null) {
            return null;
        }

        return $this->urls->getUrl(
            $route,
            [
                '_scope' => (int) $order->getStoreId(),
                '_nosid' => true,
                '_secure' => true,
                'order' => $this->orders->idOf($order),
                'token' => $token,
            ]
        );
    }
}
