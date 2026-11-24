<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Sattleme\Payment\Model\Config;
use Sattleme\Payment\Model\PageAccess;

/**
 * Placing an order with the method, through Magento's checkout: when it is offered, and what the
 * order is once placed.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class PlacementTest extends OrderTestCase
{
    public function testAnOrderPlacedWaitsForItsPaymentWithASecretAndNoEmail(): void
    {
        $order = $this->placeOrder(24.5);

        $this->assertSame(Order::STATE_PENDING_PAYMENT, $order->getState());
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $order->getStatus());
        $this->assertSame('29.5000', (string) $order->getGrandTotal());
        $this->assertSame('USD', $order->getOrderCurrencyCode());
        $this->assertFalse((bool) $order->getEmailSent(), 'The order\'s email waits for its payment');
        $payment = $order->getPayment();
        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertSame(Config::CODE, $payment->getMethod());
        $token = $payment->getAdditionalInformation(PageAccess::TOKEN_KEY);
        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $token);
        $this->assertSame(
            'sattleme/payment/start',
            $payment->getMethodInstance()->getConfigData('order_place_redirect_url')
        );
        $this->assertSame(0.0, (float) $order->getTotalPaid());
        $this->assertCount(0, $order->getInvoiceCollection());
        // No invoice yet: the customer's page makes it.
        $this->assertSame([], $this->attemptsOf($order));
        $this->assertSame([], $this->sdk()->createCalls);
    }

    public function testTwoOrdersHaveTwoSecrets(): void
    {
        $first = $this->placeOrder();
        $second = $this->placeOrder();
        $access = $this->get(PageAccess::class);

        $this->assertNotSame($access->tokenOf($first->getPayment()), $access->tokenOf($second->getPayment()));
        $this->assertFalse($access->opens($first, $access->tokenOf($second->getPayment())));
        $this->assertTrue($access->opens($first, $access->tokenOf($first->getPayment())));
    }

    public function testTheMethodIsOfferedWithAKeyTheSdkTakes(): void
    {
        $quote = $this->cart();

        $this->assertContains(Config::CODE, $this->methodsOf($this->idOfModel($quote)));
        $this->assertSame([\Sattleme\Payment\Test\Support\Sdk::KEY], array_unique($this->clients()->keys));
    }

    public function testTheMethodIsNotOfferedWithoutAKey(): void
    {
        $this->setConfig(Config::PATH_KEY, '');
        $quote = $this->cart();

        $this->assertNotContains(Config::CODE, $this->methodsOf($this->idOfModel($quote)));
    }

    public function testTheMethodIsNotOfferedWithAKeyTheSdkRefuses(): void
    {
        $this->setConfig(Config::PATH_KEY, 'not a key');
        $quote = $this->cart();

        $this->assertNotContains(Config::CODE, $this->methodsOf($this->idOfModel($quote)));
    }

    public function testTheMethodIsNotOfferedWhenDisabled(): void
    {
        $this->setConfig(Config::PATH_ACTIVE, '0');
        $quote = $this->cart();

        $this->assertNotContains(Config::CODE, $this->methodsOf($this->idOfModel($quote)));
    }

    public function testTheMethodFollowsMagentosOwnRules(): void
    {
        $this->setConfig('payment/sattleme/allowspecific', '1');
        $this->setConfig('payment/sattleme/specificcountry', 'FR,DE');
        $quote = $this->cart();
        $this->assertNotContains(Config::CODE, $this->methodsOf($this->idOfModel($quote)), 'Not for Switzerland');

        $this->setConfig('payment/sattleme/specificcountry', 'CH');
        $this->setConfig('payment/sattleme/max_order_total', '20');
        $quote = $this->cart();
        $this->assertNotContains(Config::CODE, $this->methodsOf($this->idOfModel($quote)), 'Above the maximum');

        $this->setConfig('payment/sattleme/max_order_total', '100');
        $quote = $this->cart();
        $this->assertContains(Config::CODE, $this->methodsOf($this->idOfModel($quote)));
    }

    public function testAnOrderCannotBePlacedWithTheMethodWhenItIsNotOffered(): void
    {
        $this->setConfig(Config::PATH_KEY, '');
        $quote = $this->cart();

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->get(\Magento\Quote\Api\CartManagementInterface::class)
            ->placeOrder($this->idOfModel($quote), $this->paymentMethod());
    }

    /**
     * The codes of the methods Magento offers for cart $quoteId.
     *
     * @param int $quoteId The cart.
     * @return list<string>
     */
    private function methodsOf(int $quoteId): array
    {
        $codes = [];
        foreach ($this->get(PaymentMethodManagementInterface::class)->getList($quoteId) as $method) {
            $codes[] = $method->getCode();
        }

        return $codes;
    }
}
