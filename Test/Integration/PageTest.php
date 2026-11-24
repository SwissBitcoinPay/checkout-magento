<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Message\MessageInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\TestCase\AbstractController;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\PageAccess;
use Sattleme\Payment\Model\PaymentFailure;
use Sattleme\Payment\Test\Support\Sdk;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Invoice;

/**
 * The customer's payment page and its endpoints, through Magento's front controller: authorized
 * by the order's secret, escaped, never cached nor indexed, without inline script, working
 * without JavaScript; the status endpoint and its rate.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class PageTest extends AbstractController
{
    use OrderFixtures;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModule();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->tearDownModule();
        parent::tearDown();
    }

    public function testThePageDoesNotOpenWithoutASecret(): void
    {
        $order = $this->placeOrder();

        $this->assertNotFound('sattleme/payment/view/order/' . $this->idOf($order));
    }

    public function testThePageDoesNotOpenWithAWrongSecret(): void
    {
        $order = $this->placeOrder();

        $this->assertNotFound('sattleme/payment/view/order/' . $this->idOf($order) . '/token/' . str_repeat('A', 43));
    }

    public function testThePageDoesNotOpenWithTheSecretOfAnotherOrder(): void
    {
        $order = $this->placeOrder();
        $other = $this->placeOrder();

        $this->assertNotFound('sattleme/payment/view/order/' . $this->idOf($order) . '/token/' . $this->token($other));
    }

    public function testThePageDoesNotOpenWithAnOrderThatIsNotANumber(): void
    {
        $order = $this->placeOrder();

        $this->assertNotFound('sattleme/payment/view/order/0' . $this->idOf($order) . '/token/' . $this->token($order));
    }

    public function testThePageShowsTheInvoiceWithoutInlineScriptNeverCachedNorIndexed(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);

        $this->dispatch($this->path($order, 'view'));

        $response = $this->http();
        $this->assertSame(200, $response->getHttpResponseCode());
        $body = $this->body();
        $page = $this->pageOf($body);
        $this->assertStringContainsString('Order ' . $order->getIncrementId() . ': pay with Bitcoin', $page);
        $this->assertStringContainsString('<svg', $page, 'The QR code, an SVG made on the server');
        $this->assertStringContainsString('59,000 sats', $page);
        $this->assertStringContainsString('0.00059 BTC', $page);
        $this->assertStringContainsString('$29.50', $page);
        $this->assertStringContainsString('15:00', $page);
        $this->assertStringContainsString('value="' . Sdk::BOLT11 . '"', $page);
        $this->assertStringContainsString('value="' . Sdk::ADDRESS . '"', $page);
        $this->assertStringContainsString(
            'href="' . htmlspecialchars((string) $attempt->paymentUri(), ENT_QUOTES) . '"',
            $page,
            'The wallet\'s link: the invoice\'s payment URI'
        );
        // Without JavaScript: a form to check again, with its form key.
        $this->assertStringContainsString('action="' . $this->url($order, 'check') . '"', $page);
        $this->assertStringContainsString('name="form_key"', $page);
        // No inline script; the page's script is a file of the module.
        $this->assertStringNotContainsString('<script', $page);
        $this->assertStringContainsString('Sattleme_Payment\/js\/payment-page', html_entity_decode($page));
        // Nothing from another host.
        preg_match_all('/\s(?:src|href|action)="([^"]*)"/', $page, $links);
        foreach ($links[1] as $link) {
            $link = html_entity_decode($link);
            $this->assertMatchesRegularExpression('#^(http://localhost/|bitcoin:|lightning:)#', $link);
        }
        $this->assertStringContainsString('NOINDEX,NOFOLLOW', $body);
        $this->assertStringContainsString('no-store', $this->header('Cache-Control'));
        $this->assertSame('noindex, nofollow', $this->header('X-Robots-Tag'));
        $this->assertSame('no-referrer', $this->header('Referrer-Policy'));
        $this->assertSame([], $this->sdk()->statusCalls, 'Showing the page asks nothing of the SDK');
    }

    public function testWhatThePageShowsIsEscaped(): void
    {
        $order = $this->placeOrder();
        $hostile = 'lnbc1"><script>alert(1)</script>';
        $this->sdk()->creates = [
            fn(array $params): Invoice => Sdk::invoice($params, $this->clock()->now(), true, $hostile),
        ];
        $this->invoiceFor($order);

        $this->dispatch($this->path($order, 'view'));

        $page = $this->pageOf($this->body());
        $this->assertStringNotContainsString($hostile, $page);
        $this->assertStringNotContainsString('<script', $page);
        $this->assertStringContainsString('lnbc1&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $page);
    }

    public function testAnOrderWithoutAnInvoiceShowsAButtonToMakeIt(): void
    {
        $order = $this->placeOrder();

        $this->dispatch($this->path($order, 'view'));

        $page = $this->pageOf($this->body());
        $this->assertStringContainsString('Your order waits for its payment in bitcoin.', $page);
        $this->assertStringContainsString('Show my Bitcoin invoice', $page);
        $this->assertStringContainsString('action="' . $this->url($order, 'create') . '"', $page);
        $this->assertSame([], $this->sdk()->createCalls);
    }

    public function testTheButtonMakesTheInvoice(): void
    {
        $order = $this->placeOrder();

        $this->post($this->path($order, 'create'));

        $this->assertRedirect($this->stringContains($this->path($order, 'view')));
        $this->assertCount(1, $this->sdk()->createCalls);
        $this->assertCount(1, $this->attemptsOf($order));
    }

    public function testAFormWithoutItsFormKeyChangesNothing(): void
    {
        $order = $this->placeOrder();

        $this->post($this->path($order, 'create'), ['form_key' => 'not-the-form-key']);

        $this->assertSame([], $this->sdk()->createCalls);
        $this->assertSame([], $this->attemptsOf($order));
    }

    public function testTheCheckoutLeadsToThePageWithTheInvoice(): void
    {
        $order = $this->placeOrder();
        // QuoteManagement::placeOrder set the checkout session's last order.
        $session = $this->get(CheckoutSession::class);
        $this->assertSame($order->getIncrementId(), $session->getLastRealOrderId());

        $this->dispatch('sattleme/payment/start');

        $this->assertRedirect($this->stringContains($this->path($order, 'view')));
        $this->assertCount(1, $this->sdk()->createCalls);
    }

    public function testTheStartWithoutAnOrderOfTheSessionLeadsToTheCart(): void
    {
        $this->dispatch('sattleme/payment/start');

        $this->assertRedirect($this->stringContains('checkout/cart'));
        $this->assertSame([], $this->sdk()->createCalls);
    }

    public function testTheButtonChecksThePaymentWithoutJavaScript(): void
    {
        $order = $this->placeOrder();
        $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::paidLightning()];

        $this->post($this->path($order, 'check'));

        $this->assertRedirect($this->stringContains($this->path($order, 'view')));
        $this->assertCount(1, $this->sdk()->statusCalls);
        $this->assertSame(Order::STATE_PROCESSING, $this->order($this->idOf($order))->getState());
    }

    public function testThePageOfAPaidOrderThanksTheCustomer(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::paidLightning()];
        $this->get(\Sattleme\Payment\Model\Checker::class)->check($attempt->id, 0, 10);

        $this->dispatch($this->path($order, 'view'));

        $page = $this->pageOf($this->body());
        $this->assertStringContainsString('Thank you: your payment was received.', $page);
        $this->assertStringContainsString('checkout/onepage/success', $page, 'The order of this checkout');
        $this->assertStringNotContainsString('<svg', $page);
        $this->assertStringNotContainsString('sattleme/payment/check', $page);
    }

    public function testThePageOfAPaymentBeingConfirmedSaysSo(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::unconfirmed('59000', 2)];
        $this->get(\Sattleme\Payment\Model\Checker::class)->check($attempt->id, 0, 10);

        $this->dispatch($this->path($order, 'view'));

        $page = $this->pageOf($this->body());
        $this->assertStringContainsString(
            'Your payment came in time. The Bitcoin network is confirming its on-chain transaction '
            . '(2 of 3 confirmations), which takes about 30 minutes.',
            $page
        );
        $this->assertStringNotContainsString('<svg', $page);
        $this->assertStringContainsString('Check the payment', $page);
    }

    public function testThePageOfAnUnderpaymentAsksForTheRest(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::open((int) $attempt->expiresAt, '20000', '39000')];
        $this->get(\Sattleme\Payment\Model\Checker::class)->check($attempt->id, 0, 10);

        $this->dispatch($this->path($order, 'view'));

        $page = $this->pageOf($this->body());
        $this->assertStringContainsString(
            '20,000 of 59,000 sats came. Please pay the rest: 39,000 sats.',
            $page
        );
        $this->assertStringContainsString('<strong>39,000 sats</strong>', $page);
        $this->assertStringContainsString('value="lnbc1resttestinvoice"', $page);
        $this->assertStringContainsString(
            'bitcoin:' . Sdk::ADDRESS . '?amount=0.00039&amp;lightning=lnbc1resttestinvoice',
            $page
        );
    }

    public function testThePageOfAnInvoiceWhoseTimeIsOverWaitsForItsFinalStatus(): void
    {
        $order = $this->placeOrder();
        $this->invoiceFor($order);
        $this->clock()->advance(901);

        $this->dispatch($this->path($order, 'view'));

        $page = $this->pageOf($this->body());
        $this->assertStringContainsString('We are checking your payment.', $page);
        $this->assertStringNotContainsString('<svg', $page);
        $this->assertStringNotContainsString('Choose another payment method', $page);
    }

    public function testTheStatusEndpointChecksThePayment(): void
    {
        $order = $this->placeOrder();
        $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::unconfirmed('59000', 1)];

        $this->dispatch($this->path($order, 'status'));

        $this->assertSame(['phase' => 'confirming'], json_decode($this->body(), true));
        $this->assertStringContainsString('no-store', $this->header('Cache-Control'));
        $this->assertCount(1, $this->sdk()->statusCalls);
    }

    public function testTheStatusEndpointChecksAtMostEverySixSecondsPerInvoice(): void
    {
        $order = $this->placeOrder();
        $this->invoiceFor($order);
        $progress = $this->get(\Sattleme\Payment\Model\Page\Progress::class);
        $phases = [$progress->check($order)];
        $this->clock()->advance(3);
        $phases[] = $progress->check($order);
        $this->assertSame(['payable', 'payable'], $phases);
        $this->clock()->advance(2);
        $this->sdk()->statuses = [Sdk::paidLightning()];

        $this->dispatch($this->path($order, 'status'));

        $this->assertSame(['phase' => 'payable'], json_decode($this->body(), true));
        $this->assertCount(1, $this->sdk()->statusCalls, 'The stored status, within 6 seconds of the last check');
        $this->clock()->advance(1);
        $this->assertSame('paid', $progress->check($order));
        $this->assertCount(2, $this->sdk()->statusCalls);
    }

    public function testTheStatusEndpointOpensOnlyWithTheOrdersSecret(): void
    {
        $order = $this->placeOrder();
        $this->invoiceFor($order);

        $this->dispatch('sattleme/payment/status/order/' . $this->idOf($order) . '/token/' . str_repeat('B', 43));

        $this->assertSame(404, $this->http()->getHttpResponseCode());
        $this->assertSame(['error' => 'not_found'], json_decode($this->body(), true));
        $this->assertSame([], $this->sdk()->statusCalls);
    }

    public function testTheCustomerCannotLeaveAnInvoiceThatMayBePaid(): void
    {
        $order = $this->placeOrder();
        $this->invoiceFor($order);

        $this->post($this->path($order, 'leave'));

        $this->assertRedirect($this->stringContains($this->path($order, 'view')));
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order($this->idOf($order))->getState());
    }

    public function testThePageOfAFailedInvoiceOffersAnotherMethod(): void
    {
        $order = $this->failedOrder();

        $this->dispatch($this->path($order, 'view'));

        $page = $this->pageOf($this->body());
        $this->assertStringContainsString(
            'Bitcoin payments are not available right now. Please choose another payment method.',
            $page
        );
        $this->assertStringContainsString('action="' . $this->url($order, 'leave') . '"', $page);
        $this->assertStringContainsString('Try again', $page);
    }

    public function testTheCustomerMayChooseAnotherMethodWhenNoInvoiceMayBePaid(): void
    {
        $order = $this->failedOrder();

        $this->post($this->path($order, 'leave'));

        $this->assertRedirect($this->stringContains('checkout/cart'));
        $this->assertSame(Order::STATE_CANCELED, $this->order($this->idOf($order))->getState());
        $this->assertSessionMessages(
            $this->containsEqual(
                'Your order ' . $order->getIncrementId()
                . ' was cancelled: you can place it again with another payment method.'
            ),
            MessageInterface::TYPE_NOTICE
        );
        $this->assertContains(
            'The customer chose another payment method: the order was cancelled.',
            $this->notes($order)
        );
    }

    public function testTheOrdersViewLinksToItsPaymentPageWhileItWaitsForIt(): void
    {
        $order = $this->placeOrder();
        $registry = $this->get(\Magento\Framework\Registry::class);
        $registry->register('current_order', $this->order($this->idOf($order)), true);
        $layout = $this->get(\Magento\Framework\View\LayoutInterface::class);
        try {
            $html = $layout->createBlock(\Sattleme\Payment\Block\Order\PayLink::class)->toHtml();
            $this->assertStringContainsString('This order waits for its payment in bitcoin.', $html);
            $this->assertStringContainsString('href="' . $this->url($order, 'view') . '"', $html);

            $attempt = $this->invoiceFor($order);
            $this->sdk()->statuses = [Sdk::paidLightning()];
            $this->get(\Sattleme\Payment\Model\Checker::class)->check($attempt->id, 0, 10);
            $registry->register('current_order', $this->order($this->idOf($order)), true);
            $this->assertSame('', trim($layout->createBlock(\Sattleme\Payment\Block\Order\PayLink::class)->toHtml()));
        } finally {
            $registry->unregister('current_order');
        }
    }

    public function testTheOrdersViewNeverOffersToPayAPaymentBeingConfirmed(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::unconfirmed('59000', 1)];
        $this->get(\Sattleme\Payment\Model\Checker::class)->check($attempt->id, 0, 10);

        $html = $this->orderView($order);

        $this->assertStringContainsString(
            'Your payment came in time. The Bitcoin network is confirming its on-chain transaction, '
            . 'which takes about 30 minutes.',
            $html
        );
        $this->assertStringContainsString('Follow the payment', $html);
        $this->assertStringNotContainsString('Pay with Bitcoin', $html);
    }

    public function testTheOrdersViewSaysWhatCameOfAnUnderpaymentAndOffersNoLink(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $checker = $this->get(\Sattleme\Payment\Model\Checker::class);
        $this->sdk()->statuses = [Sdk::open((int) $attempt->expiresAt, '20000', '39000')];
        $checker->check($attempt->id, 0, 10);
        $this->assertStringContainsString('Pay with Bitcoin', $this->orderView($order), 'Its rest can be paid');
        $this->clock()->advance(900 + Attempt::GRACE_SECONDS);
        $this->sdk()->statuses = [Sdk::expired('20000')];
        $checker->check($attempt->id, 0, 10);
        $this->assertSame(Order::STATE_HOLDED, $this->order($this->idOf($order))->getState());

        $html = $this->orderView($order);

        $this->assertStringContainsString(
            'The time to pay is over, and 20,000 of 59,000 sats came. '
            . 'The shop will contact you to settle the difference.',
            $html
        );
        $this->assertStringNotContainsString('href=', $html);
    }

    /**
     * What the customer's view of $order shows of its bitcoin payment (the block PayLink).
     *
     * @param Order $order The order.
     * @return string
     */
    private function orderView(Order $order): string
    {
        $registry = $this->get(\Magento\Framework\Registry::class);
        $registry->register('current_order', $this->order($this->idOf($order)), true);
        try {
            return $this->get(\Magento\Framework\View\LayoutInterface::class)
                ->createBlock(\Sattleme\Payment\Block\Order\PayLink::class)
                ->toHtml();
        } finally {
            $registry->unregister('current_order');
        }
    }

    /**
     * An order whose invoice could not be made (the SDK refused the key).
     *
     * @return Order
     */
    private function failedOrder(): Order
    {
        $order = $this->placeOrder();
        $this->sdk()->creates = [new InvalidKeyException()];
        try {
            $this->invoiceFor($order);
            $this->fail('An invoice was made');
        } catch (PaymentFailure $e) {
            $this->assertSame(PaymentFailure::UNAVAILABLE, $e->getKind());
        }

        return $order;
    }

    /**
     * Asserts that $path is not found, and asks nothing of the SDK.
     *
     * @param string $path The path.
     * @return void
     */
    private function assertNotFound(string $path): void
    {
        $this->dispatch($path);

        $this->assertSame(404, $this->http()->getHttpResponseCode());
        $this->assertStringNotContainsString('sattleme-payment', $this->body());
        $this->assertSame([], $this->sdk()->createCalls);
        $this->assertSame([], $this->sdk()->statusCalls);
    }

    /**
     * The response, as Magento's HTTP response.
     *
     * @return \Magento\Framework\App\Response\Http
     */
    private function http(): \Magento\Framework\App\Response\Http
    {
        $response = $this->getResponse();
        $this->assertInstanceOf(\Magento\Framework\App\Response\Http::class, $response);

        return $response;
    }

    /**
     * The response's body.
     *
     * @return string
     */
    private function body(): string
    {
        return $this->http()->getBody();
    }

    /**
     * The value of the response's header $name, or "".
     *
     * @param string $name The header.
     * @return string
     */
    private function header(string $name): string
    {
        $header = $this->http()->getHeader($name);

        return $header instanceof \Laminas\Http\Header\HeaderInterface ? $header->getFieldValue() : '';
    }

    /**
     * The secret of $order's page.
     *
     * @param Order $order The order.
     * @return string
     */
    private function token(Order $order): string
    {
        return (string) $this->get(PageAccess::class)->tokenOf($order->getPayment());
    }

    /**
     * The path of $order's page $action, with its secret.
     *
     * @param Order $order The order.
     * @param string $action view, status, check, create or leave.
     * @return string
     */
    private function path(Order $order, string $action): string
    {
        return 'sattleme/payment/' . $action . '/order/' . $this->idOf($order) . '/token/' . $this->token($order);
    }

    /**
     * The link of $order's page $action, as the page writes it.
     *
     * @param Order $order The order.
     * @param string $action The action.
     * @return string
     */
    private function url(Order $order, string $action): string
    {
        return (string) $this->get(PageAccess::class)->url($order, 'sattleme/payment/' . $action);
    }

    /**
     * Posts to $path.
     *
     * @param string $path The path.
     * @param array<string, string> $post The form's values (its form key, by default the session's).
     * @return void
     */
    private function post(string $path, array $post = []): void
    {
        $request = $this->getRequest();
        $this->assertInstanceOf(\Magento\Framework\App\Request\Http::class, $request);
        $request->setMethod('POST');
        $request->setPostValue($post);
        $this->dispatch($path);
    }

    /**
     * The module's part of the page $body.
     *
     * @param string $body The page.
     * @return string
     */
    private function pageOf(string $body): string
    {
        $start = strpos($body, '<div class="sattleme-payment"');
        $this->assertNotFalse($start, 'The payment page');
        $brand = strpos($body, '<p class="sattleme-payment__brand">', $start);
        $this->assertNotFalse($brand);
        $end = strpos($body, '</p>', $brand);
        $this->assertNotFalse($end);

        return substr($body, $start, $end + 4 - $start);
    }
}
