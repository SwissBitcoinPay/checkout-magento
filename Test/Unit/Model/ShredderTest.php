<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Model\Shredder;
use Sattleme\Payment\Test\Support\Sdk;

class ShredderTest extends TestCase
{
    public function testWhatLeadsToTheCustomersCoinsIsRemoved(): void
    {
        $shredder = new Shredder();
        $invoice = $shredder->invoice(Sdk::invoice([], 1_800_000_000)->toArray());
        $this->assertSame(Shredder::SHREDDED, $invoice['onchainAddress']);
        $this->assertSame(Shredder::SHREDDED, $invoice['paymentUri']);
        $this->assertSame(Sdk::BOLT11, $invoice['bolt11']);
        $this->assertSame(Sdk::sats('24.5'), $invoice['sats']);
        $status = $shredder->status(Sdk::paidMixed('49000')->toArray());
        $this->assertSame([], $status['txids']);
        $this->assertSame([Sdk::PREIMAGE], $status['preimages']);
        $this->assertNull($shredder->status(Sdk::open(1, '100', '900')->toArray())['rest']);
    }

    public function testALightningInvoiceKeepsItsPaymentUri(): void
    {
        $shredder = new Shredder();
        $invoice = Sdk::invoice([], 1_800_000_000, false)->toArray();
        $this->assertSame($invoice, $shredder->invoice($invoice));
        $once = $shredder->invoice(Sdk::invoice([], 1_800_000_000)->toArray());
        $this->assertSame($once, $shredder->invoice($once));
    }
}
