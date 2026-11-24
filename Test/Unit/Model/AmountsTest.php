<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Model\Amounts;

class AmountsTest extends TestCase
{
    public function testSatsAreGroupedAsTheCustomersLanguageDoes(): void
    {
        $amounts = new Amounts();
        $this->assertSame('49,000', $amounts->sats('49000', 'en_US'));
        $this->assertMatchesRegularExpression('/^49\S000$/u', $amounts->sats('49000', 'de_CH'));
        $this->assertMatchesRegularExpression('/^49\s000$/u', $amounts->sats('49000', 'fr_FR'));
        $this->assertSame('0', $amounts->sats('abc', 'en_US'));
        $this->assertSame('7', $amounts->sats('007', 'en_US'));
    }

    public function testSatsAreBitcoinWithoutAFloat(): void
    {
        $amounts = new Amounts();
        $this->assertSame('0.00049', $amounts->btc('49000'));
        $this->assertSame('1', $amounts->btc('100000000'));
        $this->assertSame('21000000', $amounts->btc('2100000000000000'));
        $this->assertSame('0.00000001', $amounts->btc('1'));
        $this->assertSame('0', $amounts->btc('0'));
    }

    public function testSatsCompareAsNumbers(): void
    {
        $amounts = new Amounts();
        $this->assertTrue($amounts->isLess('999', '1000'));
        $this->assertFalse($amounts->isLess('1000', '999'));
        $this->assertFalse($amounts->isLess('1000', '1000'));
        $this->assertTrue($amounts->isLess('0099', '100'));
    }
}
