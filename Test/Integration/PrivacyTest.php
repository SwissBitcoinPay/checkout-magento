<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Sattleme\Payment\Console\Command\PrivacyCommand;
use Sattleme\Payment\Model\Checker;
use Sattleme\Payment\Model\Privacy;
use Sattleme\Payment\Model\Shredder;
use Sattleme\Payment\Test\Support\Sdk;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A customer's personal data in the module's records: exported, and erased (what leads to their
 * coins: the deposit address and the transactions), through bin/magento sattleme:payment:privacy.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class PrivacyTest extends OrderTestCase
{
    public function testTheCustomersPaymentsAreExported(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);

        $tester = new CommandTester($this->get(PrivacyCommand::class));
        $this->assertSame(0, $tester->execute(['action' => 'export', '--order' => $order->getIncrementId()]));

        $this->assertSame(
            [[
                'order' => $order->getIncrementId(),
                'reference' => $attempt->ref,
                'createdAt' => $attempt->createdAt,
                'invoice' => $attempt->invoice,
                'status' => null,
                'outcome' => '',
            ]],
            json_decode($tester->getDisplay(), true)
        );
        $this->assertSame(Sdk::ADDRESS, $attempt->onchainAddress());

        $byEmail = $this->get(Privacy::class);
        $this->assertNotEmpty($byEmail->export($byEmail->orders('ada@example.com', null)));
    }

    public function testAFinalPaymentLosesItsAddressAndTransactionsNow(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);
        $this->sdk()->statuses = [Sdk::paidOnchain('59000')];
        $this->get(Checker::class)->check($attempt->id, 0, 10);

        $tester = new CommandTester($this->get(PrivacyCommand::class));
        $this->assertSame(0, $tester->execute(['action' => 'erase', '--order' => $order->getIncrementId()]));
        $this->assertStringContainsString(
            '1 order(s): the deposit addresses and transactions of 1 final payment(s) were erased',
            $tester->getDisplay()
        );

        $erased = $this->attempts()->find($attempt->id);
        $this->assertNotNull($erased);
        $this->assertSame(Shredder::SHREDDED, $erased->onchainAddress());
        $this->assertSame(Shredder::SHREDDED, $erased->paymentUri());
        $this->assertSame([], $erased->txids());
        $encoded = json_encode([$erased->invoice, $erased->status], JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString(Sdk::ADDRESS, (string) $encoded);
        $this->assertStringNotContainsString(Sdk::TXID, (string) $encoded);
        // What the merchant's accounting needs stays.
        $this->assertSame($attempt->ref, $erased->ref);
        $this->assertSame('59000', $erased->sats());
        $this->assertSame('paid', $erased->statusState());
    }

    public function testAPaymentStillCheckedLosesThemOnceFinal(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->invoiceFor($order);

        $privacy = $this->get(Privacy::class);
        $this->assertSame(0, $privacy->erase($privacy->orders(null, $order->getIncrementId())));
        $kept = $this->attempts()->find($attempt->id);
        $this->assertNotNull($kept);
        $this->assertSame(Sdk::ADDRESS, $kept->onchainAddress(), 'Still needed to find its payment');
        $this->assertTrue($kept->shredded);

        $this->sdk()->statuses = [Sdk::paidOnchain('59000')];
        $this->get(Checker::class)->check($attempt->id, 0, 10);

        $erased = $this->attempts()->find($attempt->id);
        $this->assertNotNull($erased);
        $this->assertSame(Shredder::SHREDDED, $erased->onchainAddress());
        $this->assertSame([], $erased->txids());
    }

    public function testTheCommandSaysHowItIsUsed(): void
    {
        $tester = new CommandTester($this->get(PrivacyCommand::class));

        $this->assertSame(2, $tester->execute(['action' => 'erase']));
        $this->assertSame(2, $tester->execute(['action' => 'delete', '--order' => '1']));
        $this->assertStringContainsString('Usage:', $tester->getDisplay());
    }
}
