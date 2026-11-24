<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Model\Schedule;
use Sattleme\Payment\Test\Support\Attempts;

class ScheduleTest extends TestCase
{
    public function testAnInvoiceIsCheckedAtEachRunOfTheCronWhilePayable(): void
    {
        $schedule = new Schedule();
        $expires = 1_800_000_900;
        $attempt = Attempts::withInvoice($expires);
        // Counted from the run's start, a little less than a minute: due before the next run.
        $this->assertSame(1_800_000_050, $schedule->nextCheckAt($attempt, 1_800_000_000));
        $this->assertSame($expires - 250, $schedule->nextCheckAt($attempt, $expires - 300));
        $this->assertSame(Schedule::OPEN_INTERVAL - Schedule::SLACK, 50);
    }

    public function testItIsCheckedOnceInItsGracePeriodAndOnceAfter(): void
    {
        $schedule = new Schedule();
        $expires = 1_800_000_900;
        $end = $expires + 120;
        $attempt = Attempts::withInvoice($expires);
        // Less than a minute before the mark in the grace period: at that mark.
        $this->assertSame($end - 75, $schedule->nextCheckAt($attempt, $expires + 10));
        $this->assertSame($end - 75, $expires + 45);
        // At the mark: the next run, then the mark after the end.
        $this->assertSame($end - 25, $schedule->nextCheckAt($attempt, $end - 75));
        $this->assertSame($end + 10, $schedule->nextCheckAt($attempt, $end - 20));
        // Never sooner than the page's own interval.
        $this->assertSame($end + 8 + Schedule::WEB_INTERVAL, $schedule->nextCheckAt($attempt, $end + 8));
    }

    public function testAfterItsEndEveryFiveMinutesThenLessOftenAfterFailures(): void
    {
        $schedule = new Schedule();
        $expires = 1_800_000_900;
        $after = $expires + 120 + 60;
        $this->assertSame($after + 290, $schedule->nextCheckAt(Attempts::withInvoice($expires), $after));
        $delays = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 20] as $errors) {
            $attempt = Attempts::withInvoice($expires, null, ['errors' => $errors]);
            $delays[] = $schedule->nextCheckAt($attempt, $after) - $after + Schedule::SLACK;
        }
        $this->assertSame([60, 120, 240, 480, 960, 1800, 1800, 1800], $delays);
    }
}
