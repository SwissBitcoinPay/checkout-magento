<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Model\Order;
use Sattleme\Payment\Model\Checker;
use Sattleme\Payment\Model\Privacy;
use Sattleme\Payment\Test\Support\Sdk;
use Sattleme\Payment\Vendor\Sattleme\Checkout\Exception\NetworkException;

/**
 * What the merchant sees of a payment in the admin's order view (Block\Info): the reference, the
 * amount and its rate, the address and the transactions (links to mempool.space), the proof of
 * payment, the checks and their errors in plain words; the customer sees the method's title.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class InfoTest extends OrderTestCase
{
    public function testTheAdminSeesThePaymentInFull(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::paidMixed('59000')];
        $this->get(Checker::class)->check($attempt->id, 0, 10);

        $html = $this->infoOf($order);

        $this->assertStringContainsString('Bitcoin', $html);
        $this->assertStringContainsString($attempt->ref, $html);
        $this->assertStringContainsString('Paid on-chain and in Lightning', $html);
        $this->assertStringContainsString('59,000 sats', $html);
        $this->assertStringContainsString('(29.50 USD)', $html);
        $this->assertStringContainsString('1 BTC = ' . Sdk::PRICE . ' USD (coingecko)', $html);
        $this->assertStringContainsString('href="https://mempool.space/address/' . Sdk::ADDRESS . '"', $html);
        $this->assertStringContainsString('href="https://mempool.space/tx/' . Sdk::TXID . '"', $html);
        $this->assertStringContainsString(Sdk::PREIMAGE, $html);
        // Each preimage with the payment hash it proves: its SHA-256.
        $proved = hash('sha256', (string) hex2bin(Sdk::PREIMAGE));
        $this->assertStringContainsString('of the payment hash', $html);
        $this->assertStringContainsString('<code>' . $proved . '</code>', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('Last check', $html);
    }

    public function testTheAdminReadsAFailedCheckInPlainWords(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [new NetworkException()];
        $this->get(Checker::class)->check($attempt->id, 0, 10);

        $html = $this->infoOf($order);

        $this->assertStringContainsString('Not known yet: its first check failed', $html);
        $this->assertStringNotContainsString('Waiting for payment', $html);
        $this->assertStringContainsString(
            'Your relays, Lightning address, Bitcoin node, a rate source or an explorer did not answer. Checks go on.',
            $html
        );
        $this->assertStringContainsString('Next check', $html);
    }

    public function testAnInvoiceThatEndedUnderpaidLeadsToItsAddressForItsTransactions(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->clock()->advance(900 + 120);
        $this->sdk()->statuses = [Sdk::expired('20000')];
        $this->get(Checker::class)->check($attempt->id, 0, 10);

        $html = $this->infoOf($order);

        $this->assertStringContainsString('Ended underpaid', $html);
        $this->assertStringContainsString('20,000 sats', $html);
        // Its status lists no transaction: the address's page on the explorer does.
        $this->assertMatchesRegularExpression(
            '#20,000 sats\s*\(<a href="https://mempool\.space/address/' . Sdk::ADDRESS . '"#',
            $html
        );
        $this->assertStringContainsString('its transactions, at the address', $html);
    }

    public function testAnErasedAddressIsSaidToBeRemoved(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::paidOnchain('59000')];
        $this->get(Checker::class)->check($attempt->id, 0, 10);
        $privacy = $this->get(Privacy::class);
        $privacy->erase($privacy->orders(null, $order->getIncrementId()));

        $html = $this->infoOf($order);

        $this->assertStringContainsString('Removed (personal data)', $html);
        $this->assertStringNotContainsString(Sdk::ADDRESS, $html);
        $this->assertStringNotContainsString(Sdk::TXID, $html);
    }

    /**
     * @magentoAppArea frontend
     */
    public function testTheCustomerSeesTheMethodsTitleOnly(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);

        $html = $this->infoOf($order);

        $this->assertSame('Bitcoin', trim($html));
        $this->assertStringNotContainsString($attempt->ref, $html);
    }

    /**
     * The payment's information of $order: as the admin's order view shows it in the admin, as
     * the customer's emails and account show it in the store.
     *
     * @param Order $order The order.
     * @return string
     */
    private function infoOf(Order $order): string
    {
        $payment = $this->order($this->idOf($order))->getPayment();
        $this->assertInstanceOf(\Magento\Sales\Model\Order\Payment::class, $payment);
        $helper = $this->get(PaymentHelper::class);
        if ($this->get(State::class)->getAreaCode() === Area::AREA_ADMINHTML) {
            return $helper->getInfoBlock($payment)->toHtml();
        }

        return $helper->getInfoBlockHtml($payment, 1);
    }
}
