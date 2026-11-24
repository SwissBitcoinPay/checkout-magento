<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\PagePhase;
use Sattleme\Payment\Test\Support\Attempts;
use Sattleme\Payment\Test\Support\Sdk;

class PagePhaseTest extends TestCase
{
    private const EXPIRES = 1_800_000_900;

    public function testThePhaseOfAnInvoiceThatMayStillBePaid(): void
    {
        $phases = new PagePhase();
        $pending = 'pending_payment';
        $open = Attempts::withInvoice(self::EXPIRES);
        $this->assertSame(PagePhase::PAYABLE, $phases->of($pending, $open, $open, self::EXPIRES - 10));
        $this->assertSame(PagePhase::CHECKING, $phases->of($pending, $open, $open, self::EXPIRES + 10));
        $unconfirmed = Attempts::withInvoice(self::EXPIRES, Sdk::unconfirmed('49000')->toArray());
        $this->assertSame(PagePhase::CONFIRMING, $phases->of($pending, $unconfirmed, $unconfirmed, self::EXPIRES - 10));
        $paid = Attempts::withInvoice(self::EXPIRES, Sdk::paidLightning()->toArray());
        $this->assertSame(PagePhase::PAID, $phases->of($pending, $paid, $paid, self::EXPIRES - 10));
        $invoice = ['declaredAt' => 1] + (Attempts::withInvoice(self::EXPIRES)->invoice ?? []);
        $declared = Attempts::withInvoice(self::EXPIRES, null, ['invoice' => $invoice]);
        $this->assertSame(PagePhase::CHECKING, $phases->of($pending, $declared, $declared, self::EXPIRES - 10));
        $making = Attempts::make();
        $this->assertSame(PagePhase::CHECKING, $phases->of($pending, $making, $making, 1_800_000_010));
    }

    public function testThePhaseOnceTheLastInvoiceEnded(): void
    {
        $phases = new PagePhase();
        $final = static fn(string $outcome, string $received): Attempt => Attempts::withInvoice(
            self::EXPIRES,
            Sdk::expired(is_numeric($received) ? $received : '0')->toArray(),
            ['isFinal' => true, 'outcome' => $outcome]
        );
        $this->assertSame(PagePhase::PAID, $phases->of('processing', $final('paid', '0'), null, 0));
        $this->assertSame(PagePhase::EXPIRED, $phases->of('canceled', $final('expired', '0'), null, 0));
        $this->assertSame(PagePhase::UNDERPAID, $phases->of('holded', $final('expired', '30000'), null, 0));
        $this->assertSame(PagePhase::ABANDONED, $phases->of('pending_payment', $final('abandoned', '0'), null, 0));
        $failed = Attempts::make(['state' => Attempt::STATE_FAILED, 'isFinal' => true, 'error' => 'invalid_order']);
        $this->assertSame(PagePhase::FAILED, $phases->of('pending_payment', $failed, null, 0));
        $this->assertSame(PagePhase::CLOSED, $phases->of('canceled', $failed, null, 0));
        $retry = Attempts::make(['state' => Attempt::STATE_RETRY, 'error' => 'network']);
        $this->assertSame(PagePhase::FAILED, $phases->of('pending_payment', $retry, null, 0));
        $this->assertSame(PagePhase::START, $phases->of('pending_payment', null, null, 0));
        $this->assertSame(PagePhase::CLOSED, $phases->of('processing', null, null, 0));
    }

    public function testWhatThePageOffersInEachPhase(): void
    {
        $phases = new PagePhase();
        foreach ([PagePhase::START, PagePhase::FAILED] as $phase) {
            $this->assertTrue($phases->canCreate($phase));
            $this->assertTrue($phases->canLeave($phase));
            $this->assertFalse($phases->isChecked($phase));
        }
        foreach ([PagePhase::PAYABLE, PagePhase::CHECKING, PagePhase::CONFIRMING] as $phase) {
            $this->assertFalse($phases->canCreate($phase));
            $this->assertFalse($phases->canLeave($phase), 'Another method only once it ended unpaid: ' . $phase);
            $this->assertTrue($phases->isChecked($phase));
        }
        $over = [PagePhase::PAID, PagePhase::EXPIRED, PagePhase::UNDERPAID, PagePhase::ABANDONED, PagePhase::CLOSED];
        foreach ($over as $phase) {
            $this->assertFalse($phases->canCreate($phase));
            $this->assertFalse($phases->canLeave($phase));
            $this->assertFalse($phases->isChecked($phase));
        }
    }
}
