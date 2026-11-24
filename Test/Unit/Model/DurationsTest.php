<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Model\Durations;

class DurationsTest extends TestCase
{
    public function testTheTimeToPayIsTheMerchantsWithinTheSdksBounds(): void
    {
        $durations = new Durations();
        $this->assertSame(900, $durations->seconds(15, null));
        $this->assertSame(60, $durations->seconds(1, null));
        $this->assertSame(3600, $durations->seconds(60, null));
        $this->assertSame(3600, $durations->seconds(600, null));
        $this->assertSame(60, $durations->seconds(0, null));
    }

    public function testAnInvoiceNeverOutlivesTheOrdersDeadlineItsGraceIncluded(): void
    {
        $durations = new Durations();
        // Deadline far: the merchant's time.
        $this->assertSame(900, $durations->seconds(15, 8 * 3600));
        // 10 minutes left: the invoice, its 120 s of grace and the 20 s of its making fit in them.
        $this->assertSame(600 - 140, $durations->seconds(15, 600));
        // Less than a minute of invoice would be left: none.
        $this->assertSame(60, $durations->seconds(15, 200));
        $this->assertNull($durations->seconds(15, 199));
        $this->assertNull($durations->seconds(15, 0));
        $this->assertNull($durations->seconds(15, -50));
    }

    public function testAnInvoiceEndsInTimeWithItsGracePeriod(): void
    {
        $durations = new Durations();
        $this->assertTrue($durations->endsInTime(1_000, null));
        $this->assertTrue($durations->endsInTime(1_000, 1_120));
        $this->assertFalse($durations->endsInTime(1_000, 1_119));
    }

    public function testTheConfiguredMinutesAreReadWithinTheirBounds(): void
    {
        $durations = new Durations();
        $this->assertSame(30, $durations->minutes('30'));
        $this->assertSame(1, $durations->minutes(1));
        $this->assertSame(60, $durations->minutes(' 60 '));
        foreach ([null, '', '0', '61', 'abc', '1.5', -5, '15 minutes'] as $value) {
            $this->assertSame(15, $durations->minutes($value), var_export($value, true));
        }
    }
}
