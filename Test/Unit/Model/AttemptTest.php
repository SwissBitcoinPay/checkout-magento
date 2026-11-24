<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Test\Support\Attempts;
use Sattleme\Payment\Test\Support\Sdk;

class AttemptTest extends TestCase
{
    private const EXPIRES = 1_800_000_900;

    public function testItReadsTheSdksInvoice(): void
    {
        $attempt = Attempts::withInvoice(self::EXPIRES);
        $this->assertTrue($attempt->hasInvoice());
        $this->assertFalse($attempt->isDeclared());
        $this->assertSame(Sdk::sats('24.5'), $attempt->sats());
        $this->assertSame(Sdk::BOLT11, $attempt->bolt11());
        $this->assertSame(Sdk::ADDRESS, $attempt->onchainAddress());
        $this->assertStringStartsWith('bitcoin:' . Sdk::ADDRESS . '?amount=', (string) $attempt->paymentUri());
        $this->assertSame(['amount' => '24.50', 'currency' => 'CHF'], $attempt->fiat());
        $this->assertSame(['source' => 'coingecko', 'price' => Sdk::PRICE, 'currency' => 'CHF'], $attempt->rate());
        $this->assertSame(self::EXPIRES + 120, $attempt->end());
        $this->assertSame('0', $attempt->received());
        $this->assertNull($attempt->rest());
    }

    public function testItReadsWhatTheChecksFound(): void
    {
        $rest = $this->attempt(Sdk::open(self::EXPIRES, '30000', '19000')->toArray());
        $this->assertSame('30000', $rest->received());
        $this->assertTrue($rest->hasReceived());
        $this->assertSame('19000', $rest->rest()['sats'] ?? null);
        $this->assertSame('lnbc1resttestinvoice', $rest->rest()['bolt11'] ?? null);
        $confirming = $this->attempt(Sdk::unconfirmed('49000', 2)->toArray());
        $this->assertSame(2, $confirming->confirmations());
        $this->assertSame([Sdk::TXID], $confirming->txids());
        $this->assertSame([Sdk::PREIMAGE], $this->attempt(Sdk::paidLightning()->toArray())->preimages());
        $mixed = $this->attempt(Sdk::paidMixed('49000')->toArray());
        $this->assertSame('mixed', $mixed->method());
        $this->assertSame([Sdk::PREIMAGE], $mixed->preimages());
        $this->assertSame([Sdk::TXID], $mixed->txids());
    }

    public function testAnInvoiceIsLiveUntilItsFinalStatusIsKnownWhateverTheTime(): void
    {
        $long = self::EXPIRES + 86400 * 30;
        $this->assertTrue(Attempts::withInvoice(self::EXPIRES)->isLive(self::EXPIRES - 1));
        // Its time being over is not enough: a check after its end tells.
        $this->assertTrue(Attempts::withInvoice(self::EXPIRES)->isLive($long));
        $this->assertTrue($this->attempt(Sdk::open(self::EXPIRES)->toArray())->isLive($long));
        $this->assertTrue($this->attempt(Sdk::unconfirmed('49000')->toArray())->isLive($long));
        $this->assertTrue($this->attempt(Sdk::paidLightning()->toArray())->isLive($long));
        $this->assertFalse($this->attempt(Sdk::expired()->toArray())->isLive(self::EXPIRES + 200));
        $this->assertFalse($this->attempt(null, ['isFinal' => true, 'outcome' => 'paid'])->isLive(0));
    }

    public function testAnAttemptBeingMadeIsLiveForAMinute(): void
    {
        $attempt = Attempts::make(['createdAt' => 1_000]);
        $this->assertTrue($attempt->isLive(1_059));
        $this->assertFalse($attempt->isLive(1_060));
        $this->assertFalse(Attempts::make(['state' => Attempt::STATE_RETRY])->isLive(1_000));
        $this->assertFalse(Attempts::make(['state' => Attempt::STATE_FAILED, 'isFinal' => true])->isLive(1_000));
    }

    public function testAnInvoiceIsPayableOnlyOpenAndBeforeItsEnd(): void
    {
        $this->assertTrue(Attempts::withInvoice(self::EXPIRES)->isPayable(self::EXPIRES - 1));
        $this->assertFalse(Attempts::withInvoice(self::EXPIRES)->isPayable(self::EXPIRES));
        $this->assertTrue($this->attempt(Sdk::open(self::EXPIRES)->toArray())->isPayable(self::EXPIRES - 1));
        $this->assertFalse($this->attempt(Sdk::unconfirmed('49000')->toArray())->isPayable(self::EXPIRES - 1));
        $this->assertFalse($this->attempt(Sdk::paidLightning()->toArray())->isPayable(self::EXPIRES - 1));
    }

    public function testMalformedValuesReadAsNothing(): void
    {
        $attempt = $this->attempt(
            ['state' => 'open', 'received' => '12abc', 'txids' => ['XYZ', 5], 'rest' => ['sats' => 'x']]
        );
        $this->assertSame('0', $attempt->received());
        $this->assertSame([], $attempt->txids());
        $this->assertNull($attempt->rest());
        $this->assertSame('0', Attempts::make()->sats());
        $this->assertSame(0, Attempts::make()->end());
    }

    /**
     * An attempt whose invoice ends at EXPIRES, its last status $status.
     *
     * @param array<string,mixed>|null $status The status's JSON.
     * @param array{isFinal?: bool, outcome?: string, createdAt?: int, state?: string} $fields Other fields.
     * @return \Sattleme\Payment\Model\Attempt
     */
    private function attempt(?array $status, array $fields = []): \Sattleme\Payment\Model\Attempt
    {
        return Attempts::withInvoice(self::EXPIRES, $status, $fields);
    }
}
